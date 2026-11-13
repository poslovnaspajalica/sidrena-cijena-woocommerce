<#
.SYNOPSIS
    Dnevni izvoz cjenika poslovnica iz 4D Wanda u mapu s datumom.

.DESCRIPTION
    1. Pokreće W_EXE.EXE ANA=142 SF2="..." (automatski izvoz sidrenih cjenika, bez dijaloga).
    2. Čeka da Wand završi i da se nove CSV datoteke prestanu mijenjati.
    3. Provjerava svaku datoteku (zaglavlje, broj redaka) i da je stigla datoteka za svako skladište.
    4. Premješta datoteke u <ArhivaMapa>\GGGG-MM-DD\ i kopira ih u <ArhivaMapa>\za_slanje\,
       odakle ih drugi zadatak šalje na server.
    5. Sve zapisuje u <ArhivaMapa>\log\wand-izvoz-GGGG-MM.log.

    Izlazni kod: 0 = sve u redu, 1 = greška ili nedostaje datoteka (vidi log),
    2 = izvoz je već u tijeku.

.NOTES
    Wand uvijek piše u mapu koja je zapamćena pri zadnjem ručnom izvozu (Export window).
    Tu mapu jednom ručno postavi na vrijednost parametra -WandMapa i više je ne mijenjaj.
#>
[CmdletBinding()]
param(
    # Puna putanja do W_EXE.EXE u LOKALNOJ (klijentskoj) instalaciji Wanda, ista mapa kao WAND.EXE iz ikone.
    # Kopija na serverskom disku (W:\4D\Wand\SYS) javlja "Podatak o lokaciji servera 4D Wanda nije upisan".
    [string]$WandExe = 'C:\4d\Wand\SYS\W_EXE.EXE',

    # Mapa u koju Wand zapisuje izvoz (postavlja se jednom, ručnim izvozom u Wandu).
    [string]$WandMapa = 'C:\Cjenici\_wand',

    # Glavna mapa: ovdje nastaju podmape s datumom, za_slanje i log.
    [string]$ArhivaMapa = 'C:\Cjenici',

    # Šifre skladišta i naziv za log. Šifre moraju biti točne, s vodećim nulama.
    [System.Collections.IDictionary]$Skladista = [ordered]@{
        '0000112' = 'Maloprodaja Zagreb'
        '0000114' = 'Maloprodaja Osijek'
        '0000110' = 'Maloprodaja Split'
    },

    # Koliko najdulje čekati da Wand završi (minute). Nakon toga se proces prekida.
    [int]$CekanjeMin = 20,

    # Koliko još čekati na datoteke nakon što Wand završi (minute).
    [int]$CekanjeDatotekaMin = 10,

    # Najmanji broj redaka (bez zaglavlja) da bi se datoteka smatrala ispravnom.
    [int]$MinRedaka = 100,

    # Mape s datumom starije od ovoliko dana brišu se. 0 = ništa se ne briše.
    [int]$ZadrziDana = 90,

    # Zadatak za slanje koji se pokreće odmah nakon izvoza (prazno = ne pokreći).
    [string]$ZadatakSlanja = 'Cjenik poslovnica - slanje na server'
)

$ErrorActionPreference = 'Stop'
$danas    = Get-Date -Format 'yyyy-MM-dd'
$logMapa  = Join-Path $ArhivaMapa 'log'
$dnevna   = Join-Path $ArhivaMapa $danas
$zaSlanje = Join-Path $ArhivaMapa 'za_slanje'
$logFile  = Join-Path $logMapa ('wand-izvoz-{0}.log' -f (Get-Date -Format 'yyyy-MM'))

foreach ($m in @($ArhivaMapa, $logMapa, $zaSlanje, $WandMapa)) {
    if (-not (Test-Path -LiteralPath $m)) { New-Item -ItemType Directory -Path $m -Force | Out-Null }
}

function Log([string]$poruka, [string]$razina = 'INFO') {
    $red = '{0} [{1}] {2}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $razina, $poruka
    Write-Host $red
    # Log ne smije srušiti skriptu (npr. kad datoteku drži drugi korisnik ili nema prava pisanja).
    try { Add-Content -LiteralPath $logFile -Value $red -Encoding UTF8 -ErrorAction Stop }
    catch {
        if (-not $script:logGreska) {
            $script:logGreska = $true
            Write-Host ("UPOZORENJE: ne mogu pisati u log {0}: {1}" -f $logFile, $_.Exception.Message)
        }
    }
}

# Samo jedan izvoz u isto vrijeme.
$mutex = New-Object System.Threading.Mutex($false, 'WandIzvozCjenika')
if (-not $mutex.WaitOne(0)) {
    Log 'Izvoz je već u tijeku, ovo pokretanje se preskače.' 'UPOZORENJE'
    exit 2
}

$greske = 0
try {
    Log ('==== Početak izvoza, korisnik {0}\{1} ====' -f $env:USERDOMAIN, $env:USERNAME)

    if (-not (Test-Path -LiteralPath $WandExe)) {
        throw "Ne postoji W_EXE.EXE na putanji: $WandExe"
    }

    # Stanje mape prije izvoza, da se prepoznaju samo nove datoteke.
    $prije = @{}
    Get-ChildItem -LiteralPath $WandMapa -Filter '*.csv' -File | ForEach-Object {
        $prije[$_.Name] = $_.LastWriteTimeUtc
    }
    if ($prije.Count -gt 0) {
        Log ("U mapi Wanda već postoji {0} CSV datoteka iz ranijih izvoza; one se ne diraju." -f $prije.Count) 'UPOZORENJE'
    }

    # Pokretanje Wanda.
    $sifre = ($Skladista.Keys | ForEach-Object { $_ }) -join ';'
    $argumenti = 'ANA=142 SF2="{0}"' -f $sifre
    Log "Pokrećem: `"$WandExe`" $argumenti"
    $start = (Get-Date).ToUniversalTime().AddSeconds(-5)
    $sp = @{
        FilePath         = $WandExe
        ArgumentList     = $argumenti
        WorkingDirectory = (Split-Path -Parent $WandExe)
        PassThru         = $true
    }
    if ($PSVersionTable.PSEdition -eq 'Desktop' -or $IsWindows) { $sp['WindowStyle'] = 'Hidden' }
    $p = Start-Process @sp

    if (-not $p.WaitForExit($CekanjeMin * 60 * 1000)) {
        Log "Wand nije završio u $CekanjeMin min (možda čeka neki dijalog). Proces se prekida." 'GRESKA'
        try { $p.Kill() } catch { }
        $greske++
    } else {
        Log ("Wand je završio, izlazni kod: {0}" -f $p.ExitCode)
    }

    # Nove datoteke i čekanje da se prestanu mijenjati.
    $nove = @()
    $rok = (Get-Date).AddMinutes($CekanjeDatotekaMin)
    do {
        $nove = @(Get-ChildItem -LiteralPath $WandMapa -Filter '*.csv' -File | Where-Object {
            (-not $prije.ContainsKey($_.Name)) -or ($_.LastWriteTimeUtc -gt $prije[$_.Name]) -or ($_.LastWriteTimeUtc -ge $start)
        })
        $velicine = @{}
        foreach ($f in $nove) { $velicine[$f.FullName] = $f.Length }
        Start-Sleep -Seconds 5
        $stabilno = $true
        foreach ($f in $nove) {
            $f.Refresh()
            if ($f.Length -ne $velicine[$f.FullName]) { $stabilno = $false }
        }
    } while (((-not $stabilno) -or ($nove.Count -lt $Skladista.Count)) -and ((Get-Date) -lt $rok))

    if ($nove.Count -eq 0) {
        throw 'Wand nije stvorio nijednu CSV datoteku. Provjeri log Wanda, tvrtku i godinu za automatske exporte.'
    }

    # Provjera sadržaja (Windows-1250, točka-zarez, prvi red zaglavlje).
    $cp1250 = [System.Text.Encoding]::GetEncoding(1250)
    $ispravne = @()
    $neispravne = @()
    foreach ($f in $nove) {
        $redovi = [System.IO.File]::ReadAllLines($f.FullName, $cp1250)
        $broj = [Math]::Max(0, $redovi.Count - 1)
        $zaglavlje = if ($redovi.Count -gt 0) { $redovi[0].ToLowerInvariant() } else { '' }
        if ($zaglavlje -notmatch 'naziv' -or $zaglavlje -notmatch 'maloprodajna_cijena') {
            Log ("{0}: neočekivano zaglavlje, datoteka se ne šalje. Zaglavlje: {1}" -f $f.Name, $redovi[0]) 'GRESKA'
            $greske++
            $neispravne += $f
            continue
        }
        if ($broj -lt $MinRedaka) {
            Log ("{0}: samo {1} redaka (najmanje {2}), datoteka se ne šalje." -f $f.Name, $broj, $MinRedaka) 'GRESKA'
            $greske++
            $neispravne += $f
            continue
        }
        Log ("{0}: {1} redaka, {2:N0} KB" -f $f.Name, $broj, ($f.Length / 1KB))
        $ispravne += $f
    }

    # Je li stigla datoteka za svako skladište. Šifra je dio naziva datoteke, npr.
    # "Prodavaonica_Gundulićeva 7, 012101 Centar_PJ2-0000112_2_20261113_111226.csv";
    # traži se kao cijeli broj (ne smije biti dio duljeg niza znamenki).
    foreach ($sifra in $Skladista.Keys) {
        $uzorak = '(?<!\d){0}(?!\d)' -f [regex]::Escape($sifra)
        $ima = @($ispravne | Where-Object { $_.Name -match $uzorak })
        if ($ima.Count -eq 0) {
            Log ("Nema ispravne datoteke za skladište {0} ({1})." -f $sifra, $Skladista[$sifra]) 'GRESKA'
            $greske++
        }
    }

    # Premještanje u mapu s datumom i kopija za slanje.
    if (-not (Test-Path -LiteralPath $dnevna)) { New-Item -ItemType Directory -Path $dnevna -Force | Out-Null }
    foreach ($f in $ispravne) {
        $cilj = Join-Path $dnevna $f.Name
        if (Test-Path -LiteralPath $cilj) {
            $cilj = Join-Path $dnevna ('{0}_{1}{2}' -f $f.BaseName, (Get-Date -Format 'HHmmss'), $f.Extension)
        }
        Move-Item -LiteralPath $f.FullName -Destination $cilj
        Copy-Item -LiteralPath $cilj -Destination (Join-Path $zaSlanje (Split-Path -Leaf $cilj)) -Force
        Log ("Spremljeno: {0}" -f $cilj)
    }

    # Neispravne datoteke ostaju uz dnevnu arhivu radi provjere, ali se ne šalju.
    if ($neispravne.Count -gt 0) {
        $losa = Join-Path $dnevna 'neispravne'
        if (-not (Test-Path -LiteralPath $losa)) { New-Item -ItemType Directory -Path $losa -Force | Out-Null }
        foreach ($f in $neispravne) {
            Move-Item -LiteralPath $f.FullName -Destination (Join-Path $losa $f.Name) -Force
            Log ("Neispravna datoteka premještena u: {0}" -f $losa) 'UPOZORENJE'
        }
    }

    # Čišćenje starih mapa s datumom.
    if ($ZadrziDana -gt 0) {
        $granica = (Get-Date).Date.AddDays(-$ZadrziDana)
        Get-ChildItem -LiteralPath $ArhivaMapa -Directory | Where-Object {
            $_.Name -match '^\d{4}-\d{2}-\d{2}$' -and [datetime]::ParseExact($_.Name, 'yyyy-MM-dd', $null) -lt $granica
        } | ForEach-Object {
            Remove-Item -LiteralPath $_.FullName -Recurse -Force
            Log ("Obrisana stara mapa: {0}" -f $_.Name)
        }
    }
}
catch {
    Log $_.Exception.Message 'GRESKA'
    $greske++
}
finally {
    # Slanje odmah, bez čekanja sljedećeg termina zadatka za slanje.
    if ($ZadatakSlanja -ne '' -and @(Get-ChildItem -LiteralPath $zaSlanje -File -ErrorAction SilentlyContinue).Count -gt 0) {
        try {
            Start-ScheduledTask -TaskName $ZadatakSlanja -ErrorAction Stop
            Log "Pokrenut zadatak slanja: $ZadatakSlanja"
        } catch {
            Log "Zadatak slanja nije pokrenut ($($_.Exception.Message)); poslat će se u sljedećem terminu." 'UPOZORENJE'
        }
    }
    if ($greske -gt 0) { Log ("==== Kraj izvoza: {0} grešaka ====" -f $greske) 'GRESKA' }
    else { Log '==== Kraj izvoza: sve u redu ====' }
    $mutex.ReleaseMutex()
    $mutex.Dispose()
}

if ($greske -gt 0) { exit 1 } else { exit 0 }
