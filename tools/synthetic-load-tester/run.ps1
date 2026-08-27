param(
    [ValidateSet('none', 'small', 'medium')][string]$SyntheticProfile = 'none',
    [ValidateSet('distributed', 'single')][string]$BranchMode = 'distributed',
    [ValidateSet('login', 'application', 'staff_review', 'admin_review', 'appointment', 'chat', 'full', 'read')][string]$Scenario = 'login',
    [ValidateSet('burst', 'ramp', 'sustained', 'spike', 'soak')][string]$Pattern = 'burst',
    [ValidateRange(1, 500)][int]$Accounts = 5,
    [ValidateRange(1, 100)][int]$Concurrency = 5,
    [ValidateRange(1, 3)][int]$ApplicationsPerAccount = 1,
    [ValidateRange(0, 3600)][int]$RampUpSeconds = 0,
    [ValidatePattern('^[0-9]+(s|m|h)$')][string]$Duration = '2m',
    [ValidateRange(0, 60000)][int]$ThinkTimeMs = 0,
    [ValidatePattern('^[A-Za-z0-9._-]{1,64}$')][string]$Seed = '20260824',
    [ValidateRange(0, 1)][double]$MaxErrorRate = 0,
    [ValidateRange(0, 600000)][int]$P95Ms = 0,
    [ValidateRange(0, 600000)][int]$P99Ms = 0,
    [string]$BackendUrl = 'http://127.0.0.1:8200',
    [string]$DatabaseHost = '127.0.0.1',
    [int]$DatabasePort = 3306,
    [string]$DatabaseUser = 'root',
    [string]$DatabasePassword = '',
    [ValidateRange(1024, 65535)][int]$OtpCollectorPort = 8299,
    [ValidateRange(1024, 65535)][int]$ReverbPort = 6201,
    [ValidateRange(1024, 65527)][int]$ApiWorkerPortBase = 8210,
    [switch]$KeepDatabase,
    [switch]$GranularMetrics,
    [switch]$GuiLifecycle,
    [string]$GuiSignalPath = '',
    [int]$GuiParentPid = 0
)

$ErrorActionPreference = 'Stop'
if ($Concurrency -gt $Accounts) { throw 'Concurrency cannot exceed the number of synthetic accounts.' }
if ($BackendUrl -notmatch '^http://(127\.0\.0\.1|localhost):[0-9]{2,5}$') {
    throw 'The local runner accepts only an explicit loopback HTTP target. Dedicated staging must use a reviewed CI configuration.'
}

$guiStartSignal = ''
$guiCancelSignal = ''
$guiCleanupSignal = ''
if ($GuiLifecycle) {
    $temporaryRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
    $resolvedSignal = [IO.Path]::GetFullPath($GuiSignalPath)
    $signalLeaf = Split-Path -Leaf $resolvedSignal
    if (-not $resolvedSignal.StartsWith($temporaryRoot, [StringComparison]::OrdinalIgnoreCase) -or
        $signalLeaf -notmatch '^bts-load-gui-[a-f0-9]{32}\.signal$' -or
        $GuiParentPid -le 0) {
        throw 'Invalid GUI lifecycle control path or parent process.'
    }
    $guiStartSignal = $resolvedSignal + '.start'
    $guiCancelSignal = $resolvedSignal + '.cancel'
    $guiCleanupSignal = $resolvedSignal + '.cleanup'
}

$toolRoot = $PSScriptRoot
$repoRoot = (Resolve-Path (Join-Path $toolRoot '..\..')).Path
$backendRoot = Join-Path $repoRoot 'backend'
$timestamp = (Get-Date).ToUniversalTime().ToString('yyyyMMddHHmmss')
$databaseName = "bts_load_${timestamp}_$PID"
if ($databaseName -notmatch '^bts_load_[0-9]{14}_[0-9]+$') { throw 'Unsafe load-test database name.' }
$runRoot = Join-Path ([IO.Path]::GetTempPath()) ("bts-load-$timestamp-$PID")
$resultRoot = Join-Path $toolRoot ("results\$timestamp-$PID")
$documentRoot = Join-Path $runRoot 'documents'
$manifestPath = Join-Path $runRoot 'manifest.json'
$runLog = Join-Path $resultRoot 'run.log'
$correctnessPath = Join-Path $resultRoot 'correctness.json'
$operationsPath = Join-Path $resultRoot 'operations.json'
$environmentPath = Join-Path $resultRoot 'environment.json'
New-Item -ItemType Directory -Path $runRoot, $resultRoot, $documentRoot -Force | Out-Null

if ($Accounts -ge 100 -or $Concurrency -ge 50 -or $SyntheticProfile -eq 'medium') {
    Write-Warning 'Heavy local campaign requested. Watch RAM, CPU, disk and MariaDB; start with Smoke/20/30 first.'
}

$mysqlCommand = Get-Command mysql.exe -ErrorAction SilentlyContinue
$mysql = if ($mysqlCommand) { $mysqlCommand.Source } elseif (Test-Path -LiteralPath 'C:\xampp\mysql\bin\mysql.exe') { 'C:\xampp\mysql\bin\mysql.exe' } else { $null }
if (-not $mysql) { throw 'mysql.exe not found. Start XAMPP MariaDB on port 3306.' }
$env:Path = (Split-Path -Parent $mysql) + ';' + $env:Path
$k6Command = Get-Command k6.exe -ErrorAction SilentlyContinue
$k6 = if ($k6Command) { $k6Command.Source } elseif (Test-Path -LiteralPath (Join-Path $toolRoot 'bin\k6.exe')) { Join-Path $toolRoot 'bin\k6.exe' } else { $null }
if (-not $k6) { throw 'k6 is not installed. Run tools\synthetic-load-tester\setup.bat first.' }
$python = (Get-Command python.exe -ErrorAction Stop).Source
$php = (Get-Command php.exe -ErrorAction Stop).Source
$processes = [System.Collections.Generic.List[System.Diagnostics.Process]]::new()
$databaseCreated = $false
$env:MYSQL_PWD = $DatabasePassword

$backendUri = [Uri]$BackendUrl
$backendPort = $backendUri.Port
$apiWorkerCount = [Math]::Min([Math]::Max(2, $Concurrency), 8)
$apiWorkerPorts = $ApiWorkerPortBase..($ApiWorkerPortBase + $apiWorkerCount - 1)
$dedicatedPorts = @($backendPort, $OtpCollectorPort, $ReverbPort) + $apiWorkerPorts
if (($dedicatedPorts | Sort-Object -Unique).Count -ne $dedicatedPorts.Count) {
    throw 'Dedicated load-test ports must be distinct.'
}
$occupiedPorts = Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue | Where-Object { $dedicatedPorts -contains $_.LocalPort }
if ($occupiedPorts) {
    throw 'Dedicated load-test ports are occupied: ' + (($occupiedPorts | Select-Object -ExpandProperty LocalPort | Sort-Object -Unique) -join ', ')
}

function Invoke-MySql([string]$Sql) {
    & $mysql '--protocol=tcp' '--host' $DatabaseHost '--port' $DatabasePort '--user' $DatabaseUser '--execute' $Sql
    if ($LASTEXITCODE -ne 0) { throw "MariaDB command failed with exit code $LASTEXITCODE." }
}

function Start-LoadProcess([string]$File, [string[]]$Arguments, [string]$WorkingDirectory, [string]$Name) {
    $process = Start-Process -FilePath $File -ArgumentList $Arguments -WorkingDirectory $WorkingDirectory -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput (Join-Path $runRoot "$Name.out.log") -RedirectStandardError (Join-Path $runRoot "$Name.err.log")
    $processes.Add($process)
    return $process
}

function Wait-Http([string]$Url, [int]$Seconds = 90, [hashtable]$Headers = @{}) {
    $deadline = (Get-Date).AddSeconds($Seconds)
    while ((Get-Date) -lt $deadline) {
        try {
            $response = Invoke-WebRequest -Uri $Url -Headers $Headers -UseBasicParsing -TimeoutSec 3
            if ($response.StatusCode -eq 200) { return }
        } catch { Start-Sleep -Milliseconds 400 }
    }
    throw "Timed out waiting for $Url. Diagnostic logs: $runRoot"
}

function Wait-LoadEnvironment([string]$Url, [string]$ExpectedDatabase, [int]$ExpectedAccounts, [int]$Seconds = 90) {
    $deadline = (Get-Date).AddSeconds($Seconds)
    while ((Get-Date) -lt $deadline) {
        try {
            $payload = Invoke-RestMethod -Uri $Url -TimeoutSec 5
            $data = $payload.data
            if ($data.marker -eq 'SYNTHETIC_LOAD_TEST_ONLY' -and
                $data.database -eq $ExpectedDatabase -and
                [int]$data.synthetic_accounts -ge $ExpectedAccounts -and
                [bool]$data.health.checks.database.ok -and
                [bool]$data.health.checks.queue_worker.ok -and
                [bool]$data.health.checks.reverb.ok) {
                return $data
            }
        } catch { }
        Start-Sleep -Milliseconds 500
    }
    throw "Timed out waiting for the isolated environment readiness checks. Diagnostic logs: $runRoot"
}

function Write-BtsStage([string]$Level, [string]$Message) {
    $payload = [ordered]@{ level = $Level; message = $Message } | ConvertTo-Json -Compress
    Write-Host "BTS_STAGE $payload"
}

function Test-GuiParent {
    if (-not $GuiLifecycle) { return $true }
    return $null -ne (Get-Process -Id $GuiParentPid -ErrorAction SilentlyContinue)
}

function Wait-GuiStart {
    Write-BtsStage 'INFO' 'Environnement prêt. En attente du démarrage de la campagne.'
    while ($true) {
        if (-not (Test-GuiParent)) { throw 'The GUI process exited; guarded cleanup is starting.' }
        if (Test-Path -LiteralPath $guiCancelSignal) {
            Remove-Item -LiteralPath $guiCancelSignal -Force -ErrorAction SilentlyContinue
            throw 'Environment cleanup requested before campaign start.'
        }
        if (Test-Path -LiteralPath $guiStartSignal) {
            Remove-Item -LiteralPath $guiStartSignal -Force -ErrorAction SilentlyContinue
            return
        }
        Start-Sleep -Milliseconds 200
    }
}

function Wait-GuiCleanup {
    Write-BtsStage 'SUCCESS' "Campagne terminée. Résultats disponibles; environnement maintenu jusqu'au nettoyage."
    while ($true) {
        if (-not (Test-GuiParent)) { return }
        if (Test-Path -LiteralPath $guiCleanupSignal) {
            Remove-Item -LiteralPath $guiCleanupSignal -Force -ErrorAction SilentlyContinue
            return
        }
        Start-Sleep -Milliseconds 250
    }
}

try {
    Write-Host 'SYNTHETIC LOAD TEST ONLY'
    Write-Host "Database: $databaseName"
    Write-BtsStage 'INFO' 'Connexion MariaDB et vérification des dépendances.'
    Invoke-MySql "CREATE DATABASE ``$databaseName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    $databaseCreated = $true
    Write-BtsStage 'SUCCESS' "Base synthétique $databaseName créée."

    $keyBytes = New-Object byte[] 32
    $random = [Security.Cryptography.RandomNumberGenerator]::Create()
    $random.GetBytes($keyBytes)
    $random.Dispose()
    $appKey = 'base64:' + [Convert]::ToBase64String($keyBytes)
    $collectorToken = if ($env:BTS_LOAD_GUI_CONTROL_TOKEN -and $env:BTS_LOAD_GUI_CONTROL_TOKEN.Length -ge 32) { $env:BTS_LOAD_GUI_CONTROL_TOKEN } else {
        $tokenBytes = New-Object byte[] 32
        $tokenRandom = [Security.Cryptography.RandomNumberGenerator]::Create()
        $tokenRandom.GetBytes($tokenBytes)
        $tokenRandom.Dispose()
        [Convert]::ToBase64String($tokenBytes)
    }
    $collectorPort = $OtpCollectorPort
    $reverbPort = $ReverbPort

    $env:APP_ENV = 'loadtest'
    $env:APP_DEBUG = 'false'
    $env:APP_KEY = $appKey
    $env:AUDIT_HMAC_KEY = $appKey
    $env:BTS_LOAD_TEST_ENABLED = 'true'
    $env:BTS_LOAD_TEST_OTP_COLLECTOR_URL = "http://127.0.0.1:$collectorPort"
    $env:BTS_LOAD_TEST_OTP_COLLECTOR_TOKEN = $collectorToken
    $env:BTS_LOAD_TEST_OTP_COLLECTOR_PORT = [string]$collectorPort
    $env:BTS_LOAD_PROXY_PORT = [string]$backendPort
    $env:BTS_LOAD_PROXY_TARGETS = ($apiWorkerPorts -join ',')
    $env:DB_CONNECTION = 'mysql'
    $env:DB_HOST = $DatabaseHost
    $env:DB_PORT = [string]$DatabasePort
    $env:DB_DATABASE = $databaseName
    $env:DB_USERNAME = $DatabaseUser
    # On Windows, assigning an empty environment value removes it; Laravel would then fall
    # back to the normal .env password. The dotenv literal `(null)` safely means no password.
    $env:DB_PASSWORD = if ($DatabasePassword) { $DatabasePassword } else { '(null)' }
    $env:CACHE_STORE = 'database'
    $env:QUEUE_CONNECTION = 'database'
    $env:BROADCAST_CONNECTION = 'reverb'
    $env:SMS_PROVIDER = 'loadtest'
    $env:DOCUMENTS_LOCAL_ROOT = $documentRoot
    $env:DOCUMENT_VERIFICATION_PROVIDER = 'local'
    $env:DOCUMENT_MALWARE_SCAN = 'optional'
    $env:QUEUE_WORKER_HEALTH_REQUIRED = 'true'
    $env:SCHEDULER_HEALTH_REQUIRED = 'false'
    $env:REVERB_APP_ID = 'loadtest-app'
    $env:REVERB_APP_KEY = 'loadtest-key'
    $env:REVERB_APP_SECRET = $collectorToken
    $env:REVERB_HOST = '127.0.0.1'
    $env:REVERB_PORT = [string]$reverbPort
    $env:REVERB_SERVER_HOST = '127.0.0.1'
    $env:REVERB_SERVER_PORT = [string]$reverbPort
    $env:REVERB_SCHEME = 'http'
    $env:LOG_CHANNEL = 'single'
    $env:LOG_SINGLE_PATH = Join-Path $runRoot 'backend.log'

    & $php (Join-Path $backendRoot 'artisan') config:clear | Out-Null
    & $php (Join-Path $backendRoot 'artisan') migrate --force
    if ($LASTEXITCODE -ne 0) { throw 'Fresh load-test migrations failed.' }
    Write-BtsStage 'SUCCESS' 'Schéma MariaDB migré.'
    & $php (Join-Path $backendRoot 'artisan') db:seed '--class=Database\Seeders\BranchSeeder' --force
    if ($LASTEXITCODE -ne 0) { throw 'Branch seeding failed.' }
    & $php (Join-Path $backendRoot 'artisan') bts:prepare-load-test "--accounts=$Accounts" "--applications-per-account=$ApplicationsPerAccount" "--branch-mode=$BranchMode" "--seed=$Seed" "--output=$manifestPath"
    if ($LASTEXITCODE -ne 0) { throw 'Synthetic load identity preparation failed.' }
    Write-BtsStage 'SUCCESS' "$Accounts comptes synthétiques prêts."

    if ($SyntheticProfile -ne 'none') {
        & $php (Join-Path $backendRoot 'artisan') bts:generate-data "--profile=$SyntheticProfile" "--seed=$Seed" --metadata-only-documents
        if ($LASTEXITCODE -ne 0) { throw "Synthetic $SyntheticProfile generation failed." }
    }

    Start-LoadProcess $python @((Join-Path $toolRoot 'otp_collector.py')) $toolRoot 'collector' | Out-Null
    foreach ($apiWorkerPort in $apiWorkerPorts) {
        Start-LoadProcess $php @('artisan', 'serve', '--host=127.0.0.1', "--port=$apiWorkerPort") $backendRoot "api-$apiWorkerPort" | Out-Null
    }
    Start-LoadProcess $python @((Join-Path $toolRoot 'reverse_proxy.py')) $toolRoot 'proxy' | Out-Null
    Start-LoadProcess $php @('artisan', 'queue:work', '--sleep=1', '--tries=5', '--backoff=2', '--timeout=90') $backendRoot 'worker' | Out-Null
    Start-LoadProcess $php @('artisan', 'schedule:work') $backendRoot 'scheduler' | Out-Null
    Start-LoadProcess $php @('artisan', 'reverb:start', '--host=127.0.0.1', "--port=$reverbPort") $backendRoot 'reverb' | Out-Null
    Write-BtsStage 'INFO' 'Démarrage API, worker, scheduler et Reverb.'
    Wait-Http "http://127.0.0.1:$collectorPort/control" 90 @{ Authorization = "Bearer $collectorToken" }
    Wait-Http "$BackendUrl/api/load-test/status"

    $loadData = Wait-LoadEnvironment "$BackendUrl/api/load-test/status" $databaseName $Accounts
    $environmentStatus = [ordered]@{
        state = 'ready'
        marker = 'SYNTHETIC_LOAD_TEST_ONLY'
        database = $databaseName
        backend = 'connected'
        mariadb = 'ready'
        queue_worker = 'active'
        reverb = 'active'
        control_url = "http://127.0.0.1:$collectorPort"
        safety = 'SYNTHETIC ONLY'
        synthetic_accounts = [int]$loadData.synthetic_accounts
    }
    Write-Host ('BTS_ENV ' + ($environmentStatus | ConvertTo-Json -Compress))
    Write-BtsStage 'SUCCESS' 'Environnement synthétique isolé prêt.'

    if ($GuiLifecycle) { Wait-GuiStart }
    Write-BtsStage 'INFO' 'Démarrage de la campagne k6.'

    $environment = [ordered]@{
        marker = 'SYNTHETIC_LOAD_TEST_ONLY'
        timestamp = (Get-Date).ToUniversalTime().ToString('o')
        source_revision = (& git -C $repoRoot rev-parse HEAD 2>$null)
        database = $databaseName
        mariadb = (& $mysql --version)
        php = (& $php -r 'echo PHP_VERSION;')
        laravel = (& $php (Join-Path $backendRoot 'artisan') --version)
        node = (& node --version)
        k6 = (& $k6 version | Select-Object -First 1)
        os = [Environment]::OSVersion.VersionString
        cpu = (Get-CimInstance Win32_Processor | Select-Object -First 1 -ExpandProperty Name)
        memory_bytes = (Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory
        configuration = [ordered]@{ profile = $SyntheticProfile; scenario = $Scenario; pattern = $Pattern; accounts = $Accounts; concurrency = $Concurrency; applications_per_account = $ApplicationsPerAccount; duration = $Duration; ramp_up_seconds = $RampUpSeconds; think_time_ms = $ThinkTimeMs; seed = $Seed }
        api_worker_processes = $apiWorkerCount
    }
    $environment | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $environmentPath -Encoding utf8

    $env:BTS_MANIFEST = $manifestPath
    $env:BTS_BASE_URL = $BackendUrl
    $env:BTS_OTP_COLLECTOR_URL = "http://127.0.0.1:$collectorPort"
    $env:BTS_OTP_COLLECTOR_TOKEN = $collectorToken
    $env:BTS_OUTPUT_DIR = ($resultRoot -replace '\\', '/')
    $env:BTS_SCENARIO = $Scenario
    $env:BTS_PATTERN = $Pattern
    $env:BTS_ACCOUNTS = [string]$Accounts
    $env:BTS_CONCURRENCY = [string]$Concurrency
    $env:BTS_APPLICATIONS_PER_ACCOUNT = [string]$ApplicationsPerAccount
    $env:BTS_RAMP_UP_SECONDS = [string]$RampUpSeconds
    $env:BTS_DURATION = $Duration
    $env:BTS_THINK_TIME_MS = [string]$ThinkTimeMs
    $env:BTS_MAX_ERROR_RATE = [string]$MaxErrorRate
    $env:BTS_P95_MS = [string]$P95Ms
    $env:BTS_P99_MS = [string]$P99Ms

    $k6Arguments = @('run', '--log-format=raw', '--log-output=stdout')
    if ($GranularMetrics) { $k6Arguments += @('--out', 'json=' + ((Join-Path $resultRoot 'metrics.jsonl') -replace '\\', '/')) }
    $k6Arguments += (Join-Path $toolRoot 'k6\bts-load-test.js')
    # k6 writes console events to stderr even on success; PowerShell must not promote those
    # structured progress lines to terminating NativeCommandError records.
    $previousErrorAction = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    & $k6 @k6Arguments 2>&1 | Tee-Object -FilePath $runLog
    $k6Exit = $LASTEXITCODE
    $ErrorActionPreference = $previousErrorAction

    $workflowRows = [System.Collections.Generic.List[object]]::new()
    foreach ($line in Get-Content -LiteralPath $runLog) {
        if ($line -match 'BTS_RESULT\s+(\{.*\})') {
            try { $workflowRows.Add(($Matches[1] | ConvertFrom-Json)) } catch { }
        }
    }
    $workflowRows | ConvertTo-Json -Depth 10 | Set-Content -LiteralPath (Join-Path $resultRoot 'workflows.json') -Encoding utf8
    $workflowRows | Select-Object synthetic_user, scenario, status, response_code, duration_ms, requests, error, `
        @{Name='application_numbers'; Expression={ ($_.applications.applicationNumber -join ';') }}, `
        @{Name='application_statuses'; Expression={ ($_.applications.status -join ';') }}, `
        @{Name='branches'; Expression={ ($_.applications.branchName -join ';') }} | Export-Csv -LiteralPath (Join-Path $resultRoot 'workflows.csv') -NoTypeInformation -Encoding utf8

    # A successful HTTP campaign is not enough: durable notification/broadcast work must
    # be observed draining instead of being killed with the worker during cleanup.
    $drainStarted = Get-Date
    $drainDeadline = $drainStarted.AddSeconds(180)
    $asyncDrained = $false
    $operations = $null
    do {
        $operationsJson = (& $php (Join-Path $backendRoot 'artisan') operations:status --json | Out-String).Trim()
        try { $operations = $operationsJson | ConvertFrom-Json } catch { $operations = $null }
        if ($operations) {
            $queueDetail = [string]$operations.checks.queue.detail
            $outboxDetail = [string]$operations.checks.async_outbox.detail
            $ready = if ($queueDetail -match 'ready=(\d+)') { [int]$Matches[1] } else { -1 }
            $reserved = if ($queueDetail -match 'reserved=(\d+)') { [int]$Matches[1] } else { -1 }
            $pending = if ($outboxDetail -match 'pending=(\d+)') { [int]$Matches[1] } else { -1 }
            $processing = if ($outboxDetail -match 'processing=(\d+)') { [int]$Matches[1] } else { -1 }
            $failed = if ($outboxDetail -match 'failed=(\d+)') { [int]$Matches[1] } else { -1 }
            $asyncDrained = $ready -eq 0 -and $reserved -eq 0 -and $pending -eq 0 -and $processing -eq 0 -and $failed -eq 0
        }
        if (-not $asyncDrained) { Start-Sleep -Seconds 1 }
    } while (-not $asyncDrained -and (Get-Date) -lt $drainDeadline)
    $drainSeconds = [math]::Round(((Get-Date) - $drainStarted).TotalSeconds, 3)

    & $php (Join-Path $backendRoot 'artisan') bts:verify-load-test "--manifest=$manifestPath" "--scenario=$Scenario" "--accounts=$Accounts" "--applications-per-account=$ApplicationsPerAccount" "--json=$correctnessPath"
    $verifyExit = $LASTEXITCODE
    & $php (Join-Path $backendRoot 'artisan') operations:status --json | Set-Content -LiteralPath $operationsPath -Encoding utf8

    $summary = Get-Content -LiteralPath (Join-Path $resultRoot 'summary.json') -Raw | ConvertFrom-Json
    $correctness = Get-Content -LiteralPath $correctnessPath -Raw | ConvertFrom-Json
    $summary | Add-Member -NotePropertyName environment -NotePropertyValue $environment
    $summary | Add-Member -NotePropertyName correctness -NotePropertyValue $correctness
    $summary | Add-Member -NotePropertyName k6_exit_code -NotePropertyValue $k6Exit
    $summary | Add-Member -NotePropertyName verification_exit_code -NotePropertyValue $verifyExit
    $summary | Add-Member -NotePropertyName async_drain -NotePropertyValue ([ordered]@{ drained = $asyncDrained; seconds = $drainSeconds; timeout_seconds = 180 })
    $summary | ConvertTo-Json -Depth 20 | Set-Content -LiteralPath (Join-Path $resultRoot 'report.json') -Encoding utf8

    $report = @(
        '# BTS Bank Phase 5 load campaign'
        ''
        "- Marker: **SYNTHETIC LOAD TEST ONLY**"
        "- Campaign: ``$($summary.campaign_id)``"
        "- Database profile: ``$SyntheticProfile``"
        "- Scenario/pattern: ``$Scenario`` / ``$Pattern``"
        "- Accounts/concurrency/applications: $Accounts / $Concurrency / $ApplicationsPerAccount"
        "- Workflows completed/failed: $($summary.metrics.workflows_completed) / $($summary.metrics.workflows_failed)"
        "- Requests/s: $([math]::Round([double]$summary.metrics.requests_per_second, 2))"
        "- HTTP p50/p95/p99 ms: $([math]::Round([double]$summary.metrics.http_latency_ms.p50, 1)) / $([math]::Round([double]$summary.metrics.http_latency_ms.p95, 1)) / $([math]::Round([double]$summary.metrics.http_latency_ms.p99, 1))"
        "- Error rate: $([math]::Round([double]$summary.metrics.error_rate * 100, 2))%"
        "- Business correctness: $($correctness.passed)"
        "- Async queue/outbox drained: $asyncDrained in $drainSeconds s"
        "- k6/verification exit: $k6Exit / $verifyExit"
        ''
        'This is a local-machine measurement, not a production capacity promise.'
    )
    $report | Set-Content -LiteralPath (Join-Path $resultRoot 'report.md') -Encoding utf8
    Write-Host "BTS_OUTPUT $resultRoot"

    if ($k6Exit -ne 0 -or $verifyExit -ne 0 -or -not $asyncDrained) { throw 'The campaign, async drain, or business-correctness verification failed. Results were preserved.' }
    if ($GuiLifecycle) {
        $campaignDone = [ordered]@{ success = $true; output = $resultRoot } | ConvertTo-Json -Compress
        Write-Host "BTS_CAMPAIGN_DONE $campaignDone"
        Wait-GuiCleanup
    }
} catch {
    Write-BtsStage 'ERROR' $_.Exception.Message
    throw
} finally {
    Write-BtsStage 'INFO' "Nettoyage contrôlé de l'environnement isolé."
    foreach ($process in $processes) {
        if (-not $process.HasExited) { & taskkill.exe /PID $process.Id /T /F 2>$null | Out-Null }
    }
    if (Test-Path -LiteralPath $manifestPath) { Remove-Item -LiteralPath $manifestPath -Force }
    if ($databaseCreated -and -not $KeepDatabase -and $databaseName -match '^bts_load_[0-9]{14}_[0-9]+$') {
        try { Invoke-MySql "DROP DATABASE IF EXISTS ``$databaseName``" } catch { Write-Warning $_ }
        $markerPath = Join-Path $backendRoot "storage\app\private\load-testing\$databaseName.json"
        if (Test-Path -LiteralPath $markerPath) { Remove-Item -LiteralPath $markerPath -Force }
    }
    $resolvedTempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
    $resolvedRunRoot = [IO.Path]::GetFullPath($runRoot)
    $runLeaf = Split-Path -Leaf $resolvedRunRoot
    if ($resolvedRunRoot.StartsWith($resolvedTempRoot, [StringComparison]::OrdinalIgnoreCase) -and
        $runLeaf -match '^bts-load-[0-9]{14}-[0-9]+$' -and
        (Test-Path -LiteralPath $resolvedRunRoot)) {
        try { Remove-Item -LiteralPath $resolvedRunRoot -Recurse -Force -ErrorAction Stop }
        catch { Write-Warning "Could not remove isolated temporary run directory: $($_.Exception.Message)" }
    }
    Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
    foreach ($signal in @($guiStartSignal, $guiCancelSignal, $guiCleanupSignal)) {
        if ($signal -and (Test-Path -LiteralPath $signal)) { Remove-Item -LiteralPath $signal -Force -ErrorAction SilentlyContinue }
    }
    Write-BtsStage 'SUCCESS' 'Environnement isolé fermé sans toucher à la base BTS normale.'
    Write-Host "Results: $resultRoot"
    if ($KeepDatabase) { Write-Warning "Preserved isolated database: $databaseName" }
}
