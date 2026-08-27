param([string]$Version = 'v1.5.0')

$ErrorActionPreference = 'Stop'
$toolRoot = $PSScriptRoot
$binRoot = Join-Path $toolRoot 'bin'
$k6Path = Join-Path $binRoot 'k6.exe'
New-Item -ItemType Directory -Path $binRoot -Force | Out-Null

if (-not (Get-Command python.exe -ErrorAction SilentlyContinue)) {
    throw 'Python 3 with Tkinter is required for the desktop interface.'
}

if (-not (Test-Path -LiteralPath $k6Path)) {
    $archiveName = "k6-$Version-windows-amd64.zip"
    $releaseRoot = "https://github.com/grafana/k6/releases/download/$Version"
    $archive = Join-Path ([IO.Path]::GetTempPath()) $archiveName
    $checksums = Join-Path ([IO.Path]::GetTempPath()) "k6-$Version-checksums.txt"
    Invoke-WebRequest -Uri "$releaseRoot/$archiveName" -OutFile $archive
    Invoke-WebRequest -Uri "$releaseRoot/k6-$Version-checksums.txt" -OutFile $checksums
    $expectedLine = Get-Content -LiteralPath $checksums | Where-Object { $_ -match [regex]::Escape($archiveName) } | Select-Object -First 1
    if (-not $expectedLine) { throw 'Official k6 checksum entry was not found.' }
    $expected = ($expectedLine -split '\s+')[0].ToLowerInvariant()
    $actual = (Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actual -ne $expected) { throw 'k6 archive checksum verification failed.' }
    $extract = Join-Path ([IO.Path]::GetTempPath()) ('bts-k6-' + $PID)
    Expand-Archive -LiteralPath $archive -DestinationPath $extract -Force
    $downloaded = Get-ChildItem -LiteralPath $extract -Filter k6.exe -Recurse | Select-Object -First 1
    if (-not $downloaded) { throw 'k6.exe was not found in the verified archive.' }
    Copy-Item -LiteralPath $downloaded.FullName -Destination $k6Path -Force
}

& $k6Path version
python -c "import tkinter; print('Tkinter ready')"
Write-Host "Setup complete: $k6Path"
