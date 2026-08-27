$ErrorActionPreference = 'Stop'

$serverPath = Join-Path $env:LOCALAPPDATA 'Programs\MariaDB\11.4.10\mariadb-11.4.10-winx64\bin\mariadbd.exe'
$configPath = Join-Path $env:LOCALAPPDATA 'MariaDB\data-11.4\my.ini'

if (Get-NetTCPConnection -State Listen -LocalPort 3307 -ErrorAction SilentlyContinue) {
    Write-Output 'MariaDB 11.4 est deja active sur le port 3307.'
    exit 0
}

if (-not (Test-Path -LiteralPath $serverPath)) {
    throw "MariaDB 11.4 est introuvable : $serverPath"
}

if (-not (Test-Path -LiteralPath $configPath)) {
    throw "Configuration MariaDB introuvable : $configPath"
}

$process = Start-Process -FilePath $serverPath -ArgumentList "--defaults-file=`"$configPath`"" -WindowStyle Hidden -PassThru

for ($attempt = 0; $attempt -lt 20; $attempt++) {
    Start-Sleep -Milliseconds 500

    if (Get-NetTCPConnection -State Listen -LocalPort 3307 -ErrorAction SilentlyContinue) {
        Write-Output "MariaDB 11.4 demarree sur le port 3307 (PID $($process.Id))."
        exit 0
    }
}

throw 'MariaDB 11.4 ne répond pas sur le port 3307.'
