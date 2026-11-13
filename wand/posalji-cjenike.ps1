<#
.SYNOPSIS
    Šalje cjenike iz mape za_slanje na web (plugin "Sidrena cijena - cjenik poslovnica").

.DESCRIPTION
    Za svaku datoteku u <ArhivaMapa>\za_slanje:
      - server ju je objavio (ili je već objavljena)  -> datoteka se briše iz za_slanje
        (original ostaje u mapi s datumom),
      - server ju je odbio jer je neispravna           -> premješta se u za_slanje\odbijeno,
      - greška veze, server zauzet, pogrešan ključ     -> ostaje u za_slanje za sljedeći pokušaj.
    Sve zapisuje u <ArhivaMapa>\log\slanje-GGGG-MM.log.

    Izlazni kod: 0 = sve poslano (ili nema što slati), 1 = nešto nije poslano.

    Provjera veze i ključa bez slanja:
        powershell -ExecutionPolicy Bypass -File posalji-cjenike.ps1 -Provjera
#>
[CmdletBinding()]
param(
    [string]$Url = 'https://dancingbear.hr/wp-json/sidrena-poslovnice/v1/cjenik',

    # Datoteka s ključem iz postavki plugina (Postavke > Automatski prijem cjenika).
    [string]$KljucDatoteka = (Join-Path $PSScriptRoot 'kljuc.txt'),

    [string]$ArhivaMapa = 'C:\Cjenici',

    # Šifre skladišta; šalju se uz datoteku kako bi server sigurno prepoznao poslovnicu.
    [string[]]$Skladista = @('0000112', '0000114', '0000110'),

    [switch]$Provjera
)

$ErrorActionPreference = 'Stop'
$zaSlanje = Join-Path $ArhivaMapa 'za_slanje'
$odbijeno = Join-Path $zaSlanje 'odbijeno'
$logMapa  = Join-Path $ArhivaMapa 'log'
$logFile  = Join-Path $logMapa ('slanje-{0}.log' -f (Get-Date -Format 'yyyy-MM'))
foreach ($m in @($zaSlanje, $odbijeno, $logMapa)) {
    if (-not (Test-Path -LiteralPath $m)) { New-Item -ItemType Directory -Path $m -Force | Out-Null }
}

function Log([string]$poruka, [string]$razina = 'INFO') {
    $red = '{0} [{1}] {2}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $razina, $poruka
    Add-Content -LiteralPath $logFile -Value $red -Encoding UTF8
    Write-Host $red
}

function Odgovor($resp) {
    $tekst = $resp.Content.ReadAsStringAsync().GetAwaiter().GetResult()
    try { return ($tekst | ConvertFrom-Json) } catch { return [pscustomobject]@{ message = $tekst } }
}

# Naziv bez dijakritike za zaglavlje zahtjeva (server ga koristi samo za dnevnik i vrstu datoteke).
function AsciiNaziv([string]$naziv) {
    $n = $naziv.Replace('đ', 'd').Replace('Đ', 'D').Normalize([Text.NormalizationForm]::FormD)
    $n = -join ($n.ToCharArray() | Where-Object { [Globalization.CharUnicodeInfo]::GetUnicodeCategory($_) -ne 'NonSpacingMark' })
    return ($n -replace '[^A-Za-z0-9._ -]', '_')
}

if (-not (Test-Path -LiteralPath $KljucDatoteka)) {
    Log "Nema datoteke s ključem: $KljucDatoteka" 'GRESKA'
    exit 1
}
$kljuc = (Get-Content -LiteralPath $KljucDatoteka -Raw).Trim()

Add-Type -AssemblyName System.Net.Http
[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
$client = New-Object System.Net.Http.HttpClient
$client.Timeout = [TimeSpan]::FromMinutes(5)
$client.DefaultRequestHeaders.Add('X-SCP-Kljuc', $kljuc)
$client.DefaultRequestHeaders.Add('User-Agent', 'WandCjenik/1.0 (Poslovna spajalica)')

$greske = 0
try {
    if ($Provjera) {
        $statusUrl = $Url -replace '/cjenik/?$', '/status'
        $resp = $client.GetAsync($statusUrl).GetAwaiter().GetResult()
        $o = Odgovor $resp
        if ($resp.IsSuccessStatusCode) {
            Log ("Veza i ključ su u redu ({0}, plugin {1})." -f $statusUrl, $o.verzija)
            foreach ($p in $o.poslovnice) { Log ("  {0}: skladište '{1}', današnji cjenik: {2}" -f $p.poslovnica, $p.skladiste, $(if ($p.danas) { 'DA' } else { 'ne' })) }
            exit 0
        }
        Log ("Provjera nije uspjela: HTTP {0} {1}" -f [int]$resp.StatusCode, $o.message) 'GRESKA'
        exit 1
    }

    $datoteke = @(Get-ChildItem -LiteralPath $zaSlanje -File | Where-Object { $_.Extension -match '^\.(csv|xlsx?|txt)$' } | Sort-Object LastWriteTime)
    if ($datoteke.Count -eq 0) { exit 0 }
    Log ("==== Slanje: {0} datoteka ====" -f $datoteke.Count)

    foreach ($f in $datoteke) {
        # Šifra u nazivu, npr. "..._PJ2-0000112_2_20261113_111226.csv", kao cijeli broj.
        $sifra = $Skladista | Where-Object { $f.Name -match ('(?<!\d){0}(?!\d)' -f [regex]::Escape($_)) } | Select-Object -First 1
        $sadrzaj = New-Object System.Net.Http.MultipartFormDataContent
        $bajtovi = New-Object System.Net.Http.ByteArrayContent(, [IO.File]::ReadAllBytes($f.FullName))
        $bajtovi.Headers.ContentType = [System.Net.Http.Headers.MediaTypeHeaderValue]::Parse('application/octet-stream')
        $sadrzaj.Add($bajtovi, 'datoteka', (AsciiNaziv $f.Name))
        if ($sifra) { $sadrzaj.Add((New-Object System.Net.Http.StringContent($sifra)), 'skladiste') }

        try {
            $resp = $client.PostAsync($Url, $sadrzaj).GetAwaiter().GetResult()
        } catch {
            Log ("{0}: greška veze ({1}), ostaje za sljedeći pokušaj." -f $f.Name, $_.Exception.GetBaseException().Message) 'UPOZORENJE'
            $greske++
            continue
        } finally {
            $sadrzaj.Dispose()
        }
        $kod = [int]$resp.StatusCode
        $o = Odgovor $resp

        if ($kod -eq 200) {
            if ($o.status -eq 'vec_objavljeno') { Log ("{0}: već objavljeno ({1})." -f $f.Name, $o.poslovnica) }
            else { Log ("{0}: objavljeno za {1}, {2} artikala, {3}" -f $f.Name, $o.poslovnica, $o.artikala, $o.csv) }
            Remove-Item -LiteralPath $f.FullName -Force
        } elseif ($kod -in 400, 413, 415, 422) {
            Log ("{0}: server je odbio datoteku (HTTP {1}): {2}" -f $f.Name, $kod, $o.message) 'GRESKA'
            Move-Item -LiteralPath $f.FullName -Destination (Join-Path $odbijeno $f.Name) -Force
            $greske++
        } elseif ($kod -in 401, 403) {
            Log ("HTTP {0}: {1}. Provjeri ključ i je li prijem uključen u postavkama plugina. Slanje se prekida." -f $kod, $o.message) 'GRESKA'
            $greske++
            break
        } else {
            Log ("{0}: HTTP {1} {2}, ostaje za sljedeći pokušaj." -f $f.Name, $kod, $o.message) 'UPOZORENJE'
            $greske++
        }
    }
}
finally {
    $client.Dispose()
}

if ($greske -gt 0) { Log ("==== Kraj slanja: {0} grešaka ====" -f $greske) 'GRESKA'; exit 1 }
Log '==== Kraj slanja: sve u redu ===='
exit 0
