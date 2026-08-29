[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('start', 'status', 'stop')]
    [string] $Action = 'start',

    [switch] $SkipQueueWorker,

    [switch] $AllowExternalMail
)

$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$runtimeDirectory = Join-Path $projectRoot '.dev-stack'
$logDirectory = Join-Path $runtimeDirectory 'logs'
$statePath = Join-Path $runtimeDirectory 'processes.json'
$expectedApiUrl = 'http://127.0.0.1:8000'

$services = @(
    [pscustomobject]@{
        Name = 'api'
        Port = 8000
        WorkingDirectory = Join-Path $projectRoot 'backend'
        Executable = 'php.exe'
        Arguments = @('artisan', 'serve', '--host=127.0.0.1', '--port=8000')
        Probe = 'http://127.0.0.1:8000/api/health'
    },
    [pscustomobject]@{
        Name = 'queue'
        Port = $null
        WorkingDirectory = Join-Path $projectRoot 'backend'
        Executable = 'php.exe'
        Arguments = @('artisan', 'queue:work', '--sleep=1', '--tries=5', '--backoff=2', '--timeout=60')
        Probe = $null
    },
    [pscustomobject]@{
        Name = 'reverb'
        Port = 6001
        WorkingDirectory = Join-Path $projectRoot 'backend'
        Executable = 'php.exe'
        Arguments = @('artisan', 'reverb:start', '--host=127.0.0.1', '--port=6001')
        Probe = $null
    },
    [pscustomobject]@{
        Name = 'client'
        Port = 3000
        WorkingDirectory = Join-Path $projectRoot 'client'
        Executable = 'npm.cmd'
        Arguments = @('run', 'dev')
        Probe = 'http://localhost:3000/login'
    },
    [pscustomobject]@{
        Name = 'staff'
        Port = 3001
        WorkingDirectory = Join-Path $projectRoot 'staff'
        Executable = 'npm.cmd'
        Arguments = @('run', 'dev')
        Probe = 'http://localhost:3001/login'
    },
    [pscustomobject]@{
        Name = 'admin'
        Port = 3002
        WorkingDirectory = Join-Path $projectRoot 'admin'
        Executable = 'npm.cmd'
        Arguments = @('run', 'dev')
        Probe = 'http://localhost:3002/login'
    },
    [pscustomobject]@{
        Name = 'security-center'
        Port = 3003
        WorkingDirectory = Join-Path $projectRoot 'sc'
        Executable = 'npm.cmd'
        Arguments = @('run', 'dev')
        Probe = 'http://localhost:3003/login'
    }
)

function Get-EnvironmentValue {
    param(
        [Parameter(Mandatory)] [string] $Path,
        [Parameter(Mandatory)] [string] $Name
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        return $null
    }

    $match = Get-Content -LiteralPath $Path |
        Where-Object { $_ -match "^$([regex]::Escape($Name))=" } |
        Select-Object -Last 1

    if (-not $match) {
        return $null
    }

    return (($match -split '=', 2)[1]).Trim().Trim('"').Trim("'")
}

function Get-PortOwner {
    param([Parameter(Mandatory)] [int] $Port)

    return Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue |
        Select-Object -First 1
}

function Test-HttpProbe {
    param([Parameter(Mandatory)] [string] $Uri)

    try {
        $response = Invoke-WebRequest -Uri $Uri -UseBasicParsing -TimeoutSec 3
        return $response.StatusCode -ge 200 -and $response.StatusCode -lt 600
    } catch {
        if ($_.Exception.Response) {
            $statusCode = [int] $_.Exception.Response.StatusCode
            return $statusCode -ge 200 -and $statusCode -lt 600
        }
        return $false
    }
}

function Read-ProcessState {
    if (-not (Test-Path -LiteralPath $statePath)) {
        return @()
    }

    try {
        return @(Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json)
    } catch {
        Write-Warning 'The local process-state file is invalid; it will not be trusted.'
        return @()
    }
}

function Test-RecordedProcess {
    param([Parameter(Mandatory)] $Record)

    $process = Get-Process -Id ([int] $Record.pid) -ErrorAction SilentlyContinue
    if (-not $process) {
        return $false
    }

    try {
        $recordedStart = [datetime]::Parse([string] $Record.startedAt).ToUniversalTime()
        $actualStart = $process.StartTime.ToUniversalTime()
        return [math]::Abs(($actualStart - $recordedStart).TotalSeconds) -lt 5
    } catch {
        return $false
    }
}

function Get-DescendantProcessIds {
    param([Parameter(Mandatory)] [int] $ParentId)

    $children = @(Get-CimInstance Win32_Process -Filter "ParentProcessId=$ParentId" -ErrorAction SilentlyContinue)
    $result = [System.Collections.Generic.List[int]]::new()

    foreach ($child in $children) {
        foreach ($descendantId in @(Get-DescendantProcessIds -ParentId ([int] $child.ProcessId))) {
            $result.Add($descendantId)
        }
        $result.Add([int] $child.ProcessId)
    }

    return $result
}

function Show-Status {
    $records = Read-ProcessState

    foreach ($service in $services) {
        $record = $records | Where-Object { $_.name -eq $service.Name } | Select-Object -First 1
        $recordedRunning = $record -and (Test-RecordedProcess -Record $record)

        if ($service.Port) {
            $listener = Get-PortOwner -Port $service.Port
            if ($listener) {
                $probeState = if ($service.Probe) {
                    if (Test-HttpProbe -Uri $service.Probe) { 'HTTP ready' } else { 'port only' }
                } else {
                    'reachable'
                }
                Write-Output ("{0,-16} RUNNING  port={1} pid={2} {3}" -f $service.Name, $service.Port, $listener.OwningProcess, $probeState)
            } else {
                Write-Output ("{0,-16} STOPPED  port={1}" -f $service.Name, $service.Port)
            }
        } elseif ($recordedRunning) {
            Write-Output ("{0,-16} RUNNING  pid={1}" -f $service.Name, $record.pid)
        } else {
            Write-Output ("{0,-16} UNKNOWN  no network probe and no active launcher record" -f $service.Name)
        }
    }
}

function Assert-StartPrerequisites {
    $errors = [System.Collections.Generic.List[string]]::new()
    $backendEnvironment = Join-Path $projectRoot 'backend/.env'

    if (-not (Get-Command php.exe -ErrorAction SilentlyContinue)) {
        $errors.Add('php.exe is not available in PATH.')
    }
    if (-not (Get-Command npm.cmd -ErrorAction SilentlyContinue)) {
        $errors.Add('npm.cmd is not available in PATH.')
    }
    if (-not (Test-Path -LiteralPath (Join-Path $projectRoot 'backend/vendor/autoload.php'))) {
        $errors.Add('Backend dependencies are missing. Run: cd backend; composer install')
    }
    if (-not (Test-Path -LiteralPath $backendEnvironment)) {
        $errors.Add('backend/.env is missing. Copy backend/.env.example and configure local values.')
    }

    foreach ($portal in @('client', 'staff', 'admin', 'sc')) {
        $nextExecutable = Join-Path $projectRoot "$portal/node_modules/.bin/next.cmd"
        if (-not (Test-Path -LiteralPath $nextExecutable)) {
            $errors.Add("$portal dependencies are missing. Run: cd $portal; npm ci")
        }

        $environmentPath = Join-Path $projectRoot "$portal/.env.local"
        $configuredApiUrl = Get-EnvironmentValue -Path $environmentPath -Name 'NEXT_PUBLIC_API_URL'
        if ($configuredApiUrl -ne $expectedApiUrl) {
            $errors.Add("$portal/.env.local must contain NEXT_PUBLIC_API_URL=$expectedApiUrl")
        }
    }

    if (-not $SkipQueueWorker -and -not $AllowExternalMail) {
        $mailer = Get-EnvironmentValue -Path $backendEnvironment -Name 'MAIL_MAILER'
        if ($mailer -notin @('array', 'log')) {
            $errors.Add("MAIL_MAILER must be log or array for a safe local queue worker (current: $mailer). Configure a safe local mailer, use -SkipQueueWorker, or explicitly pass -AllowExternalMail.")
        }
    }

    if ($errors.Count -gt 0) {
        throw "Local stack prerequisites failed:`n- $($errors -join "`n- ")"
    }
}

function Start-Stack {
    Assert-StartPrerequisites
    New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null

    $existingRecords = Read-ProcessState
    $activeRecords = [System.Collections.Generic.List[object]]::new()

    foreach ($record in $existingRecords) {
        if (Test-RecordedProcess -Record $record) {
            $activeRecords.Add($record)
        }
    }

    foreach ($service in $services) {
        if ($service.Name -eq 'queue' -and $SkipQueueWorker) {
            Write-Output 'queue            SKIPPED  requested by -SkipQueueWorker'
            continue
        }

        $existingRecord = $activeRecords | Where-Object { $_.name -eq $service.Name } | Select-Object -First 1
        if ($existingRecord) {
            Write-Output ("{0,-16} ALREADY RUNNING  pid={1}" -f $service.Name, $existingRecord.pid)
            continue
        }

        if ($service.Port -and (Get-PortOwner -Port $service.Port)) {
            if ($service.Probe -and -not (Test-HttpProbe -Uri $service.Probe)) {
                throw "Port $($service.Port) is occupied, but $($service.Name) did not pass its HTTP probe."
            }
            Write-Output ("{0,-16} ALREADY RUNNING  port={1}" -f $service.Name, $service.Port)
            continue
        }

        $stdoutPath = Join-Path $logDirectory "$($service.Name).out.log"
        $stderrPath = Join-Path $logDirectory "$($service.Name).err.log"
        $startParameters = @{
            FilePath = $service.Executable
            ArgumentList = $service.Arguments
            WorkingDirectory = $service.WorkingDirectory
            WindowStyle = 'Hidden'
            RedirectStandardOutput = $stdoutPath
            RedirectStandardError = $stderrPath
            PassThru = $true
        }
        $process = Start-Process @startParameters

        $record = [pscustomobject]@{
            name = $service.Name
            pid = $process.Id
            startedAt = $process.StartTime.ToUniversalTime().ToString('o')
        }
        $activeRecords.Add($record)
        Write-Output ("{0,-16} STARTED  pid={1}" -f $service.Name, $process.Id)
    }

    @($activeRecords) | ConvertTo-Json | Set-Content -LiteralPath $statePath -Encoding utf8

    $deadline = (Get-Date).AddSeconds(60)
    do {
        $pending = @($services | Where-Object {
            $_.Port -and -not (Get-PortOwner -Port $_.Port)
        })
        if ($pending.Count -eq 0) {
            break
        }
        Start-Sleep -Milliseconds 500
    } while ((Get-Date) -lt $deadline)

    $failed = @($services | Where-Object { $_.Port -and -not (Get-PortOwner -Port $_.Port) })
    if ($failed.Count -gt 0) {
        Show-Status
        throw "Some services did not become ready: $($failed.Name -join ', '). Check $logDirectory."
    }

    Write-Output ''
    Show-Status
    Write-Output ''
    Write-Output 'Client:          http://localhost:3000'
    Write-Output 'Staff:           http://localhost:3001'
    Write-Output 'Admin:           http://localhost:3002'
    Write-Output 'Security Center: http://localhost:3003'
    Write-Output 'API:             http://127.0.0.1:8000'
    Write-Output "Logs:            $logDirectory"
}

function Stop-Stack {
    $records = Read-ProcessState
    if ($records.Count -eq 0) {
        Write-Output 'No processes were recorded by the local launcher.'
        return
    }

    foreach ($record in @($records | Sort-Object { [int] $_.pid } -Descending)) {
        if (-not (Test-RecordedProcess -Record $record)) {
            Write-Output ("{0,-16} NOT STOPPED  stale or already exited" -f $record.name)
            continue
        }

        $descendantIds = @(Get-DescendantProcessIds -ParentId ([int] $record.pid))
        foreach ($processId in $descendantIds) {
            Stop-Process -Id $processId -Force -ErrorAction SilentlyContinue
        }
        Stop-Process -Id ([int] $record.pid) -Force -ErrorAction SilentlyContinue
        Write-Output ("{0,-16} STOPPED" -f $record.name)
    }

    Remove-Item -LiteralPath $statePath -Force -ErrorAction SilentlyContinue
}

switch ($Action) {
    'start' { Start-Stack }
    'status' { Show-Status }
    'stop' { Stop-Stack }
}
