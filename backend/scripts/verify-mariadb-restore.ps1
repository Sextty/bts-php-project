[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string]$BackupPath,
    [Parameter(Mandatory)]
    [ValidatePattern('^bts_restoreverify_[0-9]+$')]
    [string]$RestoreDatabase,
    [string]$DatabaseHost = '127.0.0.1',
    [ValidateRange(1, 65535)]
    [int]$DatabasePort = 3306,
    [string]$DatabaseUser = 'bts_backup',
    # Read from the process environment / secret store. Never pass this in a shell command or log it.
    [string]$DatabasePassword = $env:MYSQL_PWD,
    [string]$MySqlPath,
    [string]$BackendPath = (Split-Path $PSScriptRoot -Parent),
    [switch]$KeepRestoreDatabase
)

$ErrorActionPreference = 'Stop'

function Resolve-MySql() {
    if ($MySqlPath) {
        if (-not (Test-Path -LiteralPath $MySqlPath -PathType Leaf)) { throw 'mysql.exe was not found.' }
        return (Resolve-Path -LiteralPath $MySqlPath).Path
    }
    $command = Get-Command mysql.exe -ErrorAction SilentlyContinue
    if ($command) { return $command.Source }
    $xampp = 'C:\xampp\mysql\bin\mysql.exe'
    if (Test-Path -LiteralPath $xampp -PathType Leaf) { return $xampp }
    throw 'mysql.exe was not found. Install MariaDB tooling or provide its path.'
}

function Invoke-MySql([string[]]$arguments) {
    & $mysql @arguments
    if ($LASTEXITCODE -ne 0) { throw 'MariaDB command failed.' }
}

$backupRoot = (Resolve-Path -LiteralPath $BackupPath).Path
$manifestPath = Join-Path $backupRoot 'manifest.json'
$sqlPath = Join-Path $backupRoot 'database.sql'
if (-not (Test-Path -LiteralPath $manifestPath -PathType Leaf) -or -not (Test-Path -LiteralPath $sqlPath -PathType Leaf)) { throw 'Backup is missing manifest.json or database.sql.' }
$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
if ((Get-FileHash -LiteralPath $sqlPath -Algorithm SHA256).Hash -ne $manifest.database_sha256) { throw 'Backup SHA-256 verification failed.' }
$mysql = Resolve-MySql
$baseArgs = @("--host=$DatabaseHost", "--port=$DatabasePort", "--user=$DatabaseUser")
$stopwatch = [Diagnostics.Stopwatch]::StartNew()

try {
    $existing = & $mysql @baseArgs --batch --skip-column-names --execute "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '$RestoreDatabase'"
    if ($LASTEXITCODE -ne 0) { throw 'Could not inspect restore target.' }
    if ([int]($existing | Select-Object -First 1) -ne 0) { throw 'Restore database already exists; refusing to overwrite it.' }

    Invoke-MySql ($baseArgs + @('--execute', "CREATE DATABASE $RestoreDatabase CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"))
    $import = Start-Process -FilePath $mysql -ArgumentList ($baseArgs + @($RestoreDatabase)) -NoNewWindow -PassThru -Wait -RedirectStandardInput $sqlPath
    if ($import.ExitCode -ne 0) { throw 'MariaDB restore failed.' }

    foreach ($property in $manifest.representative_table_counts.PSObject.Properties) {
        $count = & $mysql @baseArgs --batch --skip-column-names --execute "SELECT COUNT(*) FROM $($property.Name)" $RestoreDatabase
        if ($LASTEXITCODE -ne 0 -or [int64]($count | Select-Object -First 1) -ne [int64]$property.Value) { throw "Representative table count mismatch: $($property.Name)" }
    }

    $oldEnvironment = @{}
    foreach ($name in @('DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD')) {
        $oldEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
    }
    try {
        $env:DB_CONNECTION = 'mysql'; $env:DB_HOST = $DatabaseHost; $env:DB_PORT = "$DatabasePort"; $env:DB_DATABASE = $RestoreDatabase; $env:DB_USERNAME = $DatabaseUser; $env:DB_PASSWORD = $DatabasePassword
        & php (Join-Path $BackendPath 'artisan') migrate:status --no-interaction
        if ($LASTEXITCODE -ne 0) { throw 'Restored schema migration status failed.' }
        & php (Join-Path $BackendPath 'artisan') audit:verify-integrity
        if ($LASTEXITCODE -ne 0) { throw 'Restored audit integrity verification failed.' }
    } finally {
        foreach ($name in $oldEnvironment.Keys) { [Environment]::SetEnvironmentVariable($name, $oldEnvironment[$name], 'Process') }
    }

    if ($manifest.documents.included) {
        $documentsPath = Join-Path $backupRoot 'documents'
        $files = @(Get-ChildItem -LiteralPath $documentsPath -Recurse -File -Force)
        if ($files.Count -ne [int]$manifest.documents.files) { throw 'Document backup file count mismatch.' }
    }

    Write-Output "Restore verification passed: $RestoreDatabase"
    Write-Output "Duration: $($stopwatch.ElapsedMilliseconds) ms"
} finally {
    if (-not $KeepRestoreDatabase) {
        Invoke-MySql ($baseArgs + @('--execute', "DROP DATABASE IF EXISTS $RestoreDatabase"))
    }
}
