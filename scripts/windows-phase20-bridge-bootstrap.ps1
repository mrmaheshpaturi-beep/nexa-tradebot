#Requires -Version 5.1
<#
.SYNOPSIS
  Phase 20 Windows bootstrap: clone verify, .env from example, pull coordinated Hostinger bridge token.

.NOTES
  - Does NOT copy C:\Users\<you>\.env
  - Does NOT print the service token
  - Does NOT enable LIVE / DEMO execution gates
  - Requires: python; git OR zip download; (for token pull) OpenSSH + Hostinger ed25519 key
#>
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$Dest = if ($env:NEXA_TRADEBOT_ROOT) { $env:NEXA_TRADEBOT_ROOT } else { Join-Path $env:USERPROFILE 'nexa-tradebot' }
$Branch = 'cursor/phase-20-demo-release-candidate-56f9'
$Repo = 'https://github.com/mrmaheshpaturi-beep/nexa-tradebot.git'
$ZipUrl = "https://github.com/mrmaheshpaturi-beep/nexa-tradebot/archive/refs/heads/$Branch.zip"
$HostingerHost = 'u366409319@92.113.19.122'
$HostingerPort = 65002
$KeyCandidates = @(
  (Join-Path $env:USERPROFILE '.ssh\nexa_hostinger_ed25519'),
  (Join-Path $env:USERPROFILE '.ssh\id_ed25519'),
  (Join-Path $env:USERPROFILE '.ssh\id_rsa')
)

function Write-Step([string]$Msg) { Write-Output "=== $Msg ===" }
function Test-Command([string]$Name) {
  return [bool](Get-Command $Name -ErrorAction SilentlyContinue)
}

Write-Step '1) Obtain / verify Phase 20 tree'
if (-not (Test-Path (Join-Path $Dest 'trading-engine\start.ps1'))) {
  if (Test-Path $Dest) { throw "Path exists but is not a complete checkout: $Dest" }
  if (Test-Command 'git') {
    git clone --branch $Branch --single-branch $Repo $Dest
    Write-Output 'SOURCE=git-clone'
  } else {
    Write-Output 'SOURCE=zip (git not on PATH)'
    $zip = Join-Path $env:TEMP 'nexa-tradebot-phase20.zip'
    $extractRoot = Join-Path $env:TEMP 'nexa-tradebot-phase20-extract'
    if (Test-Path $zip) { Remove-Item $zip -Force }
    if (Test-Path $extractRoot) { Remove-Item $extractRoot -Recurse -Force }
    Invoke-WebRequest -Uri $ZipUrl -OutFile $zip -UseBasicParsing
    Expand-Archive -Path $zip -DestinationPath $extractRoot -Force
    $inner = Get-ChildItem -Path $extractRoot -Directory | Select-Object -First 1
    if (-not $inner) { throw 'Zip extract produced no folder' }
    Move-Item $inner.FullName $Dest
    Remove-Item $zip -Force -ErrorAction SilentlyContinue
    Remove-Item $extractRoot -Recurse -Force -ErrorAction SilentlyContinue
  }
}
Set-Location $Dest
if (Test-Command 'git' -and (Test-Path .\.git)) {
  $sha = (git rev-parse --short HEAD).Trim()
  $br = (git branch --show-current).Trim()
  Write-Output "SHA=$sha"
  Write-Output "BRANCH=$br"
  if ($br -ne $Branch) { throw "Unexpected branch: $br" }
} else {
  Write-Output 'SHA=zip-tree'
  Write-Output "BRANCH=$Branch"
}
Write-Output "start_ps1=$(Test-Path .\trading-engine\start.ps1)"
Write-Output "env_example=$(Test-Path .\trading-engine\.env.example)"
if (-not (Test-Path .\trading-engine\start.ps1)) { throw 'trading-engine\start.ps1 missing after obtain' }

Write-Step '2) trading-engine .env from .env.example only'
Set-Location (Join-Path $Dest 'trading-engine')
if (-not (Test-Path .\.env)) {
  Copy-Item .\.env.example .\.env
  Write-Output 'ENV_CREATED=from_example'
} else {
  Write-Output 'ENV_EXISTS=kept (not overwritten)'
}

# Ensure fail-closed mode/host/port; leave LOGIN/PASSWORD/SERVER empty
function Set-EnvKey([string]$Key, [string]$Value) {
  $lines = [System.Collections.Generic.List[string]]::new()
  $found = $false
  foreach ($line in [IO.File]::ReadAllLines("$PWD\.env")) {
    if ($line -match ("^" + [regex]::Escape($Key) + "=")) {
      $lines.Add("$Key=$Value")
      $found = $true
    } else {
      $lines.Add($line)
    }
  }
  if (-not $found) { $lines.Add("$Key=$Value") }
  [IO.File]::WriteAllLines("$PWD\.env", $lines)
}

Set-EnvKey 'NEXA_MT5_MODE' 'mock'
Set-EnvKey 'NEXA_MT5_HOST' '127.0.0.1'
Set-EnvKey 'NEXA_MT5_PORT' '8765'

$raw = [IO.File]::ReadAllText("$PWD\.env")
if ($raw.Length -gt 0 -and [int][char]$raw[0] -eq 0xFEFF) { $raw = $raw.Substring(1) }
$tokenLen = 0
foreach ($line in ($raw -split "`r?`n")) {
  $t = $line.Trim()
  if ($t.StartsWith('NEXA_MT5_SERVICE_TOKEN=')) {
    $tokenLen = $t.Substring(23).Trim().Length
    break
  }
}
Write-Output "NEXA_MT5_MODE=mock"
Write-Output "TOKEN_LEN=$tokenLen"

Write-Step '3) venv + install'
if (-not (Test-Path .\.venv\Scripts\python.exe)) {
  python -m venv .venv
}
& .\.venv\Scripts\python.exe -m pip install -U pip
& .\.venv\Scripts\python.exe -m pip install -e '.[windows]'
Write-Output "venv=$(Test-Path .\.venv\Scripts\python.exe)"

Write-Step '4) Pull coordinated Hostinger token (no echo)'
$key = $KeyCandidates | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $key) {
  Write-Output 'TOKEN_PULL=SKIPPED (no SSH key found under ~/.ssh for Hostinger)'
  Write-Output 'Place nexa_hostinger_ed25519 in %USERPROFILE%\.ssh\ then re-run this script.'
  Write-Output 'Hostinger already has TRADING_BRIDGE_SERVICE_TOKEN set + ~/secure/bridge-token-once.txt'
  exit 0
}

$tmp = Join-Path $env:TEMP 'nexa-bridge-token-once.txt'
if (Test-Path $tmp) { Remove-Item $tmp -Force }
$scpArgs = @(
  '-i', $key, '-P', "$HostingerPort", '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=accept-new',
  "${HostingerHost}:secure/bridge-token-once.txt", $tmp
)
& scp @scpArgs
if (-not (Test-Path $tmp)) { throw 'scp did not create token file' }
$tok = [IO.File]::ReadAllText($tmp).Trim()
Remove-Item $tmp -Force
if ($tok.Length -lt 32) { throw 'pulled token too short — abort; do not invent a local token' }
Set-EnvKey 'NEXA_MT5_SERVICE_TOKEN' $tok
Write-Output "TOKEN_LEN=$($tok.Length)"
Remove-Variable tok -ErrorAction SilentlyContinue

& ssh -i $key -o IdentitiesOnly=yes -p $HostingerPort $HostingerHost 'rm -f ~/secure/bridge-token-once.txt; echo ONCE_REMOVED=1'

Write-Step '5) Next (manual)'
Write-Output 'Open MT5 on XM DEMO, then start bridge with process-only real mode:'
Write-Output '  cd ' + (Join-Path $Dest 'trading-engine')
Write-Output '  (load token from .env into $env:NEXA_MT5_SERVICE_TOKEN; set NEXA_MT5_MODE=real; .\start.ps1)'
Write-Output 'Do not unlock LIVE / allow_demo until /v1/account shows DEMO and BTC/ETH quotes work.'
Write-Output 'BOOTSTRAP_OK=1'
