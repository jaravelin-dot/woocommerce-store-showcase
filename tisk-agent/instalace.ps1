# Zaregistruje program do Plánovače úloh, aby běžel po přihlášení.
# Spustit jednou, v PowerShellu pod účtem, který se na prodejně přihlašuje.

$slozka = Join-Path $env:APPDATA "shop-tisk"
New-Item -ItemType Directory -Force -Path $slozka | Out-Null

$cesta_config = Join-Path $slozka "config.ini"

if (-not (Test-Path $cesta_config)) {
    Copy-Item (Join-Path $PSScriptRoot "config.ini.vzor") $cesta_config
    Write-Host "Doplň heslo do $cesta_config a spusť instalaci znovu." -ForegroundColor Yellow
    exit 1
}

# Soubor existuje, ale může v něm pořád být placeholder ze vzoru — to samo
# o sobě instalaci nezastaví (heslo je syntakticky v pořádku), program by
# se pak jen nikdy nepřihlásil a hlásil by to jedině do tisk.log.
if ((Get-Content $cesta_config -Raw) -match "sem-aplikacni-heslo") {
    Write-Host "V $cesta_config je pořád vzorové heslo. Doplň skutečné aplikační heslo uživatele 'tisk-agent' a spusť instalaci znovu." -ForegroundColor Yellow
    exit 1
}

$python = (Get-Command pythonw.exe).Source
$akce = New-ScheduledTaskAction -Execute $python -Argument "-m shop_tisk" -WorkingDirectory $PSScriptRoot
$spousteni = New-ScheduledTaskTrigger -AtLogOn
$nastaveni = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 5)

try {
    Register-ScheduledTask -TaskName "Shop tisk objednavek" -Action $akce -Trigger $spousteni -Settings $nastaveni -Force -ErrorAction Stop | Out-Null
} catch {
    Write-Host "Registrace do Plánovače úloh selhala: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host "Zkus spustit PowerShell jako správce (pravé tlačítko -> Spustit jako správce) a instalaci zopakovat." -ForegroundColor Red
    exit 1
}

# Register-ScheduledTask umí selhat i "tiše" (nezastavující chyba by prošla
# i bez -ErrorAction Stop) — proto se úspěch ověřuje ještě dotazem, ne jen
# tím, že příkaz doběhl bez výjimky.
$uloha = Get-ScheduledTask -TaskName "Shop tisk objednavek" -ErrorAction SilentlyContinue

if (-not $uloha) {
    Write-Host "Registrace do Plánovače úloh selhala: úloha 'Shop tisk objednavek' se po registraci nenašla." -ForegroundColor Red
    Write-Host "Zkus spustit PowerShell jako správce (pravé tlačítko -> Spustit jako správce) a instalaci zopakovat." -ForegroundColor Red
    exit 1
}

Write-Host "Hotovo. Program pobezi po kazdem prihlaseni." -ForegroundColor Green
