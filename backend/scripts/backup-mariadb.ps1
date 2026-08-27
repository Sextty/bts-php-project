[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [ValidatePattern('^[A-Za-z0-9_]+$')]
    [string]$DatabaseName,
    [Parameter(Mandatory)]
    [string]$Destination,
    [string]$DatabaseHost = '127.0.0.1',
    [ValidateRange(1, 65535)]
    [int]$DatabasePort = 3306,
    [string]$DatabaseUser = 'bts_backup',
    [string]$DocumentsPath = (Join-Path (Split-Path $PSScriptRoot -Parent) 'storage\app\documents'),
    [int]$RetentionDays = 14,
    [switch]$Prune,
    [switch]$SkipDocuments,
    [string]$MySqlDumpPath,
    [string]$MySqlPath
)

$ErrorActionPreference = 'Stop'

function Resolve-MySqlBinary([string]$requested, [string]$name) {
    if ($requested) {
        if (-not (Test-Path -LiteralPath $requested -PathType Leaf)) { throw "$name was not found." }
        return (Resolve-Path -LiteralPath $requested).Path
    }

    $command = Get-Command "$name.exe" -ErrorAction SilentlyContinue
    if ($command) { return $command.Source }
    $xampp = Join-Path 'C:\xampp\mysql\bin' "$name.exe"
    if (Test-Path -LiteralPath $xampp -PathType Leaf) { return $xampp }
    throw "$name.exe was not found. Install MariaDB tooling or provide its path."
}

function Invoke-MySqlScalar([string]$mysql, [string]$sql) {
    $value = & $mysql "--host=$DatabaseHost" "--port=$DatabasePort" "--user=$DatabaseUser" --batch --skip-column-names --execute $sql $DatabaseName
    if ($LASTEXITCODE -ne 0) { throw 'MariaDB metadata query failed.' }
    return [int64]($value | Select-Object -First 1)
}

if ($RetentionDays -lt 1) { throw 'RetentionDays must be at least one day.' }

$dump = Resolve-MySqlBinary $MySqlDumpPath 'mysqldump'
$mysql = Resolve-MySqlBinary $MySqlPath 'mysql'
New-Item -ItemType Directory -Path $Destination -Force | Out-Null
$destinationRoot = (Resolve-Path -LiteralPath $Destination).Path
$backupId = 'bts-backup-' + (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ')
$backupPath = Join-Path $destinationRoot $backupId
if (Test-Path -LiteralPath $backupPath) { throw 'Backup destination already exists.' }

$stopwatch = [Diagnostics.Stopwatch]::StartNew()
New-Item -ItemType Directory -Path $backupPath | Out-Null

try {
    $sqlPath = Join-Path $backupPath 'database.sql'
    $dumpArgs = @(
        "--host=$DatabaseHost", "--port=$DatabasePort", "--user=$DatabaseUser",
        '--single-transaction', '--routines', '--triggers', '--events', '--hex-blob',
        '--default-character-set=utf8mb4', "--result-file=$sqlPath", $DatabaseName
    )
    & $dump @dumpArgs
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $sqlPath)) { throw 'MariaDB dump failed.' }

    $representativeTables = @('users', 'staff_users', 'branches', 'credit_applications', 'appointments', 'documents', 'audit_logs', 'async_outbox_events')
    $tableCounts = [ordered]@{}
    foreach ($table in $representativeTables) {
        $exists = Invoke-MySqlScalar $mysql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$table'"
        if ($exists -eq 1) { $tableCounts[$table] = Invoke-MySqlScalar $mysql "SELECT COUNT(*) FROM $table" }
    }

    $documents = [ordered]@{ included = $false; files = 0; bytes = 0 }
    if (-not $SkipDocuments) {
        if (-not (Test-Path -LiteralPath $DocumentsPath -PathType Container)) { throw 'DocumentsPath does not exist. Use -SkipDocuments only for an explicit database-only backup.' }
        $documentsTarget = Join-Path $backupPath 'documents'
        Copy-Item -LiteralPath $DocumentsPath -Destination $documentsTarget -Recurse -Force
        $files = @(Get-ChildItem -LiteralPath $documentsTarget -Recurse -File -Force)
        $totalBytes = ($files | Measure-Object -Property Length -Sum).Sum
        if ($null -eq $totalBytes) { $totalBytes = 0 }
        $documents = [ordered]@{ included = $true; files = $files.Count; bytes = [int64]$totalBytes }
    }

    $manifest = [ordered]@{
        format_version = 1
        created_at_utc = (Get-Date).ToUniversalTime().ToString('o')
        database = $DatabaseName
        database_sha256 = (Get-FileHash -LiteralPath $sqlPath -Algorithm SHA256).Hash
        database_bytes = (Get-Item -LiteralPath $sqlPath).Length
        representative_table_counts = $tableCounts
        documents = $documents
        duration_ms = $stopwatch.ElapsedMilliseconds
    }
    $manifest | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $backupPath 'manifest.json') -Encoding utf8

    if ($Prune) {
        $cutoff = (Get-Date).AddDays(-$RetentionDays)
        Get-ChildItem -LiteralPath $destinationRoot -Directory -Filter 'bts-backup-*' | Where-Object { $_.LastWriteTime -lt $cutoff } | ForEach-Object {
            $resolved = $_.FullName
            if ($resolved.StartsWith($destinationRoot + [IO.Path]::DirectorySeparatorChar) -and $_.Name -match '^bts-backup-[0-9]{8}T[0-9]{6}Z$') {
                Remove-Item -LiteralPath $resolved -Recurse -Force
            }
        }
    }

    Write-Output "Backup created: $backupPath"
    Write-Output "Duration: $($stopwatch.ElapsedMilliseconds) ms"
    Write-Output 'Integrity: SHA-256 manifest written'
} catch {
    Write-Error $_
    throw
}
