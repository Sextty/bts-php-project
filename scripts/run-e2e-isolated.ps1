param(
    [string]$DatabaseHost = '127.0.0.1',
    [int]$DatabasePort = 3306,
    [string]$DatabaseUser = 'root',
    [string]$DatabasePassword = '',
    [switch]$KeepDatabase
)

$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$backendRoot = Join-Path $repoRoot 'backend'
$databaseName = 'bts_e2e_' + $PID
if ($databaseName -notmatch '^bts_e2e_[0-9]+$') { throw 'Unsafe E2E database name.' }

$mysqlCommand = Get-Command mysql.exe -ErrorAction SilentlyContinue
$mysql = if ($mysqlCommand) { $mysqlCommand.Source } else { $null }
if (-not $mysql) {
    $xamppMysql = 'C:\xampp\mysql\bin\mysql.exe'
    if (Test-Path -LiteralPath $xamppMysql) { $mysql = $xamppMysql }
}
if (-not $mysql) { throw 'mysql.exe not found. Start XAMPP MariaDB/MySQL and add its bin directory to PATH.' }
$env:Path = (Split-Path -Parent $mysql) + ';' + $env:Path

$runRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('bts-e2e-' + $PID)
New-Item -ItemType Directory -Path $runRoot -Force | Out-Null
$processes = [System.Collections.Generic.List[System.Diagnostics.Process]]::new()
$distDirectoryName = '.next-e2e'
$portalRoots = @('client', 'staff', 'admin', 'sc') | ForEach-Object { Join-Path $repoRoot $_ }
$dedicatedPorts = @(3100, 3101, 3102, 3103, 6101, 8100)
$occupiedPorts = Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue | Where-Object { $dedicatedPorts -contains $_.LocalPort }
if ($occupiedPorts) {
    throw 'Dedicated E2E ports are already occupied: ' + (($occupiedPorts | Select-Object -ExpandProperty LocalPort | Sort-Object -Unique) -join ', ')
}

function Invoke-MySql([string]$sql) {
    $arguments = @('--host', $DatabaseHost, '--port', $DatabasePort, '--user', $DatabaseUser, '--protocol=tcp')
    if ($DatabasePassword) { $arguments += "--password=$DatabasePassword" }
    $arguments += @('--execute', $sql)
    & $mysql @arguments
    if ($LASTEXITCODE -ne 0) { throw "MariaDB command failed with exit code $LASTEXITCODE." }
}

function Start-HiddenProcess([string]$file, [string[]]$arguments, [string]$workingDirectory, [string]$name) {
    $process = Start-Process -FilePath $file -ArgumentList $arguments -WorkingDirectory $workingDirectory `
        -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput (Join-Path $runRoot "$name.out.log") `
        -RedirectStandardError (Join-Path $runRoot "$name.err.log")
    $processes.Add($process)
}

function Wait-Http([string]$url, [int]$seconds = 120) {
    $deadline = (Get-Date).AddSeconds($seconds)
    while ((Get-Date) -lt $deadline) {
        try {
            $response = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 3
            if ($response.StatusCode -lt 500) { return }
        } catch { Start-Sleep -Milliseconds 500 }
    }
    throw "Timed out waiting for $url. Logs: $runRoot"
}

try {
    Invoke-MySql "CREATE DATABASE ``$databaseName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

    $keyBytes = New-Object byte[] 32
    $random = [Security.Cryptography.RandomNumberGenerator]::Create()
    $random.GetBytes($keyBytes)
    $random.Dispose()
    $appKey = 'base64:' + [Convert]::ToBase64String($keyBytes)
    $env:APP_ENV = 'e2e'
    $env:APP_KEY = $appKey
    $env:AUDIT_HMAC_KEY = $appKey
    $env:DB_CONNECTION = 'mysql'
    $env:DB_HOST = $DatabaseHost
    $env:DB_PORT = [string]$DatabasePort
    $env:DB_DATABASE = $databaseName
    $env:DB_USERNAME = $DatabaseUser
    $env:DB_PASSWORD = $DatabasePassword
    $env:CACHE_STORE = 'database'
    $env:QUEUE_CONNECTION = 'database'
    $env:BROADCAST_CONNECTION = 'reverb'
    $env:SMS_PROVIDER = 'e2e'
    $env:E2E_OTP_FILE = Join-Path $runRoot 'otp.jsonl'
    $env:DOCUMENT_VERIFICATION_PROVIDER = 'local'
    $env:DOCUMENT_MALWARE_SCAN = 'optional'
    $env:LOG_CHANNEL = 'single'
    $env:LOG_SINGLE_PATH = Join-Path $runRoot 'backend.log'
    $env:QUEUE_WORKER_HEALTH_REQUIRED = 'true'
    $env:SCHEDULER_HEALTH_REQUIRED = 'false'
    $env:REVERB_APP_ID = 'e2e-app'
    $env:REVERB_APP_KEY = 'e2e-reverb-key'
    $env:REVERB_APP_SECRET = 'e2e-reverb-secret'
    $env:REVERB_HOST = '127.0.0.1'
    $env:REVERB_PORT = '6101'
    $env:REVERB_SERVER_HOST = '127.0.0.1'
    $env:REVERB_SERVER_PORT = '6101'
    $env:REVERB_SCHEME = 'http'
    $env:CORS_ALLOWED_ORIGINS = 'http://127.0.0.1:3100,http://127.0.0.1:3101,http://127.0.0.1:3102,http://127.0.0.1:3103'
    $env:CORS_ALLOW_LOCAL_DEVELOPMENT = 'false'
    $env:NEXT_PUBLIC_API_URL = 'http://127.0.0.1:8100'
    $env:NEXT_DIST_DIR = $distDirectoryName
    $env:NEXT_PUBLIC_REVERB_APP_KEY = 'e2e-reverb-key'
    $env:NEXT_PUBLIC_REVERB_HOST = '127.0.0.1'
    $env:NEXT_PUBLIC_REVERB_PORT = '6101'
    $env:NEXT_PUBLIC_REVERB_SCHEME = 'http'
    $env:E2E_API_URL = 'http://127.0.0.1:8100'
    $env:E2E_ISOLATED = '1'
    $env:E2E_CLIENT_URL = 'http://127.0.0.1:3100'
    $env:E2E_STAFF_URL = 'http://127.0.0.1:3101'
    $env:E2E_ADMIN_URL = 'http://127.0.0.1:3102'
    $env:E2E_SECURITY_URL = 'http://127.0.0.1:3103'
    $env:E2E_STATE_FILE = Join-Path $runRoot 'state.json'

    & php (Join-Path $backendRoot 'artisan') migrate --force
    if ($LASTEXITCODE -ne 0) { throw 'E2E migrations failed.' }
    & php (Join-Path $backendRoot 'artisan') db:seed --class='Database\Seeders\E2ETestSeeder' --force
    if ($LASTEXITCODE -ne 0) { throw 'E2E fixture seeding failed.' }

    Start-HiddenProcess 'php' @('artisan', 'serve', '--host=127.0.0.1', '--port=8100') $backendRoot 'api'
    Start-HiddenProcess 'php' @('artisan', 'queue:work', '--sleep=1', '--tries=3', '--backoff=5', '--timeout=60') $backendRoot 'worker'
    Start-HiddenProcess 'php' @('artisan', 'reverb:start', '--host=127.0.0.1', '--port=6101') $backendRoot 'reverb'

    $npm = (Get-Command npm.cmd).Source
    Start-HiddenProcess $npm @('exec', '--', 'next', 'dev', '-p', '3100') (Join-Path $repoRoot 'client') 'client'
    Start-HiddenProcess $npm @('exec', '--', 'next', 'dev', '-p', '3101') (Join-Path $repoRoot 'staff') 'staff'
    Start-HiddenProcess $npm @('exec', '--', 'next', 'dev', '-p', '3102') (Join-Path $repoRoot 'admin') 'admin'
    Start-HiddenProcess $npm @('exec', '--', 'next', 'dev', '-p', '3103') (Join-Path $repoRoot 'sc') 'security'

    Wait-Http 'http://127.0.0.1:8100/api/health'
    Wait-Http 'http://127.0.0.1:3100/login'
    Wait-Http 'http://127.0.0.1:3101/login'
    Wait-Http 'http://127.0.0.1:3102/login'
    Wait-Http 'http://127.0.0.1:3103/login'

    $playwrightArguments = @('test')
    if ($env:E2E_PLAYWRIGHT_GREP) { $playwrightArguments += @('--grep', $env:E2E_PLAYWRIGHT_GREP) }
    & (Join-Path $repoRoot 'node_modules\.bin\playwright.cmd') @playwrightArguments
    if ($LASTEXITCODE -ne 0) { throw "Playwright failed with exit code $LASTEXITCODE. Logs: $runRoot" }
}
finally {
    foreach ($process in $processes) {
        if (-not $process.HasExited) {
            & taskkill.exe /PID $process.Id /T /F 2>$null | Out-Null
        }
    }
    foreach ($portalRoot in $portalRoots) {
        $distPath = Join-Path $portalRoot $distDirectoryName
        if (Test-Path -LiteralPath $distPath) {
            $resolvedPortal = (Resolve-Path -LiteralPath $portalRoot).Path
            $resolvedDist = (Resolve-Path -LiteralPath $distPath).Path
            if ($resolvedDist.StartsWith($resolvedPortal + [IO.Path]::DirectorySeparatorChar) -and (Split-Path -Leaf $resolvedDist) -eq $distDirectoryName) {
                Remove-Item -LiteralPath $resolvedDist -Recurse -Force
            }
        }
    }
    $otpPath = Join-Path $runRoot 'otp.jsonl'
    if (Test-Path -LiteralPath $otpPath) {
        Remove-Item -LiteralPath $otpPath -Force
    }
    if (-not $KeepDatabase -and $databaseName -match '^bts_e2e_[0-9]+$') {
        try { Invoke-MySql "DROP DATABASE IF EXISTS ``$databaseName``" } catch { Write-Warning $_ }
    }
    Write-Host "E2E logs: $runRoot"
}
