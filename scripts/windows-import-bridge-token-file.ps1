#Requires -Version 5.1
<#
.SYNOPSIS
  Import Hostinger bridge token from a locally downloaded once-file (no chat paste).
  Download ~/secure/bridge-token-once.txt via Hostinger hPanel File Manager into Downloads, then run.
#>
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$Engine = Join-Path $env:USERPROFILE 'nexa-tradebot\trading-engine'
$candidates = @(
  (Join-Path $env:USERPROFILE 'Downloads\bridge-token-once.txt'),
  (Join-Path $env:USERPROFILE 'Desktop\bridge-token-once.txt'),
  (Join-Path $env:TEMP 'bridge-token-once.txt')
)
$src = $candidates | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $src) { throw 'Place bridge-token-once.txt in Downloads or Desktop (from Hostinger secure/), then re-run' }

Set-Location $Engine
if (-not (Test-Path .\.env)) { Copy-Item .\.env.example .\.env }

$tok = [IO.File]::ReadAllText($src).Trim()
if ($tok.Length -lt 32) { throw 'Token file too short — abort' }

$lines = [System.Collections.Generic.List[string]]::new()
$found = $false
foreach ($line in [IO.File]::ReadAllLines("$PWD\.env")) {
  if ($line -match '^NEXA_MT5_SERVICE_TOKEN=') {
    $lines.Add("NEXA_MT5_SERVICE_TOKEN=$tok")
    $found = $true
  } else { $lines.Add($line) }
}
if (-not $found) { $lines.Add("NEXA_MT5_SERVICE_TOKEN=$tok") }
[IO.File]::WriteAllLines("$PWD\.env", $lines)
Write-Output "TOKEN_LEN=$($tok.Length)"
Write-Output "IMPORTED_FROM=$src"
Remove-Variable tok -ErrorAction SilentlyContinue
Remove-Item $src -Force
Write-Output 'ONCE_LOCAL_REMOVED=1'
Write-Output 'Next: open MT5 XM DEMO, then run scripts\windows-start-demo-bridge-readonly.ps1'
