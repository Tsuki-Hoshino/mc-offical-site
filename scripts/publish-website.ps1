param(
    [Parameter(Mandatory = $true)]
    [string]$HostName,
    [int]$Port = 22,
    [string]$UserName = 'root',
    [Parameter(Mandatory = $true)]
    [string]$RemoteRoot,
    [string]$IdentityFile = ''
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$websiteRoot = Join-Path $projectRoot 'website'
if (-not (Test-Path -LiteralPath $websiteRoot)) {
    throw "Website directory not found: $websiteRoot"
}

foreach ($command in 'tar', 'ssh', 'scp') {
    if (-not (Get-Command $command -ErrorAction SilentlyContinue)) {
        throw "Command not found: $command"
    }
}

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$archive = Join-Path $env:TEMP "mc-official-site-$stamp.tar.gz"
$remoteArchive = "/tmp/mc-official-site-$stamp.tar.gz"
$remoteStage = "/tmp/mc-official-site-$stamp"
$sshOptions = @('-p', [string] $Port)
$scpOptions = @('-P', [string] $Port)
if ($IdentityFile) {
    $sshOptions += @('-i', $IdentityFile)
    $scpOptions += @('-i', $IdentityFile)
}

try {
    & tar -czf $archive `
        --exclude='./config/sync.php' `
        --exclude='./config/database.php' `
        --exclude='./data' `
        -C $websiteRoot .
    if ($LASTEXITCODE -ne 0) {
        throw 'Failed to create website archive.'
    }

    & scp @scpOptions $archive "${UserName}@${HostName}:$remoteArchive"
    if ($LASTEXITCODE -ne 0) {
        throw 'Failed to upload website archive.'
    }

    $remoteCommand = @"
set -eu
mkdir -p '$remoteStage' '$RemoteRoot/.deploy-backups'
tar -xzf '$remoteArchive' -C '$remoteStage'
if [ -d '$RemoteRoot' ]; then
  tar -czf '$RemoteRoot/.deploy-backups/site-before-$stamp.tar.gz' --exclude='./.deploy-backups' --exclude='./data' -C '$RemoteRoot' . || true
fi
mkdir -p '$RemoteRoot'
rsync -a --delete --delay-updates --exclude='config/sync.php' --exclude='config/database.php' --exclude='data/' '$remoteStage/' '$RemoteRoot/'
rm -rf '$remoteStage' '$remoteArchive'
"@

    & ssh @sshOptions "${UserName}@${HostName}" $remoteCommand
    if ($LASTEXITCODE -ne 0) {
        throw 'Remote deployment failed.'
    }

    Write-Host "Deployment complete: ${HostName}:$RemoteRoot"
    Write-Host 'Private configs, data, and HTTPS server settings were not changed.'
} finally {
    Remove-Item -LiteralPath $archive -Force -ErrorAction SilentlyContinue
}
