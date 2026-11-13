<#
.SYNOPSIS
    Stvara dva zadatka u Windows Task Scheduleru:
      1. "Cjenik poslovnica - izvoz iz Wanda"   svaki dan u 05:30 (wand-izvoz-cjenika.ps1)
      2. "Cjenik poslovnica - slanje na server" svaki dan od 05:45 do 08:45 svakih 20 minuta
         (posalji-cjenike.ps1); izvoz ga pokreće i odmah nakon što završi.

.DESCRIPTION
    Pokreni jednom, kao administrator, na računalu na kojem radi 4D Wand:
        powershell -ExecutionPolicy Bypass -File .\instaliraj-zadatak.ps1

    Pita za Windows korisnika pod kojim će se zadaci izvršavati (lozinku upisuješ sam u Windows dijalog).
    Taj korisnik mora imati pristup instalaciji Wanda, podacima tvrtke i mapi C:\Cjenici,
    a u Wandu mora imati postavljenu ispravnu tvrtku i godinu za automatske exporte.
    Ponovno pokretanje zamjenjuje postojeće zadatke.
#>
param(
    [string]$VrijemeIzvoza = '05:30',
    [string]$VrijemeSlanja = '05:45',
    # Mapa sa skriptama. Prazno = mapa u kojoj je ova skripta.
    [string]$Mapa = ''
)

$ErrorActionPreference = 'Stop'
# $PSScriptRoot je u Windows PowerShellu 5.1 prazan u zadanim vrijednostima parametara, pa se mapa traži ovdje.
if (-not $Mapa) { $Mapa = if ($PSScriptRoot) { $PSScriptRoot } else { Split-Path -Parent $MyInvocation.MyCommand.Definition } }
$izvoz  = Join-Path $Mapa 'wand-izvoz-cjenika.ps1'
$slanje = Join-Path $Mapa 'posalji-cjenike.ps1'
foreach ($f in @($izvoz, $slanje, (Join-Path $Mapa 'kljuc.txt'))) {
    if (-not (Test-Path -LiteralPath $f)) { throw "Ne postoji: $f" }
}

function Akcija([string]$skripta) {
    New-ScheduledTaskAction -Execute 'powershell.exe' `
        -Argument ('-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "{0}"' -f $skripta) `
        -WorkingDirectory $Mapa
}
function Postavke([int]$sati) {
    New-ScheduledTaskSettingsSet -StartWhenAvailable `
        -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
        -ExecutionTimeLimit (New-TimeSpan -Hours $sati) -MultipleInstances IgnoreNew
}

$kor = Get-Credential -Message 'Windows korisnik pod kojim rade zadaci (npr. DOMENA\korisnik)'
$lozinka = $kor.GetNetworkCredential().Password

# 1. Izvoz iz Wanda
Register-ScheduledTask -TaskName 'Cjenik poslovnica - izvoz iz Wanda' -Force `
    -Action (Akcija $izvoz) -Trigger (New-ScheduledTaskTrigger -Daily -At $VrijemeIzvoza) `
    -Settings (Postavke 1) -User $kor.UserName -Password $lozinka -RunLevel Limited | Out-Null

# 2. Slanje, s ponavljanjem svakih 20 minuta kroz 3 sata (ponovni pokušaj ako veza ne radi)
$okidac = New-ScheduledTaskTrigger -Daily -At $VrijemeSlanja
$okidac.Repetition = (New-ScheduledTaskTrigger -Once -At $VrijemeSlanja `
    -RepetitionInterval (New-TimeSpan -Minutes 20) -RepetitionDuration (New-TimeSpan -Hours 3)).Repetition
Register-ScheduledTask -TaskName 'Cjenik poslovnica - slanje na server' -Force `
    -Action (Akcija $slanje) -Trigger $okidac `
    -Settings (Postavke 1) -User $kor.UserName -Password $lozinka -RunLevel Limited | Out-Null

Write-Host "Zadaci su stvoreni za korisnika $($kor.UserName):"
Write-Host "  izvoz iz Wanda      svaki dan u $VrijemeIzvoza"
Write-Host "  slanje na server    svaki dan od $VrijemeSlanja, svakih 20 min do 3 sata"
Write-Host ''
Write-Host "Probno: Start-ScheduledTask -TaskName 'Cjenik poslovnica - izvoz iz Wanda'"
