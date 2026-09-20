#Requires -Version 5.1
<#
.SYNOPSIS
  Start trading-engine in process-only real mode for XM DEMO read-only quotes.
  Does not enable LIVE / allow_demo. Does not print the service token.
#>
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$Engine = Join-Path $env:USERPROFILE 'nexa-tradebot\trading-engine'
Set-Location $Engine
if (-not (Test-Path .\start.ps1)) { throw "Missing $Engine\start.ps1" }
if (-not (Test-Path .\.venv\Scripts\python.exe)) { throw 'Missing .venv — run windows-phase20-bridge-bootstrap.ps1 first' }
if (-not (Test-Path .\.env)) { throw 'Missing .env — run bootstrap first' }

$raw = [IO.File]::ReadAllText("$PWD\.env")
if ($raw.Length -gt 0 -and [int][char]$raw[0] -eq 0xFEFF) { $raw = $raw.Substring(1) }
$tok = $null
foreach ($line in ($raw -split "`r?`n")) {
  $t = $line.Trim()
  if ($t.StartsWith('NEXA_MT5_SERVICE_TOKEN=')) { $tok = $t.Substring(23).Trim(); break }
}
if (-not $tok -or $tok.Length -lt 32) {
  throw 'NEXA_MT5_SERVICE_TOKEN missing/short — pull Hostinger once-file first (bootstrap or File Manager import). Do not invent a token.'
}
Write-Output "TOKEN_LEN=$($tok.Length)"
$env:NEXA_MT5_SERVICE_TOKEN = $tok
$env:NEXA_MT5_MODE = 'real'
$env:NEXA_MT5_HOST = '127.0.0.1'
$env:NEXA_MT5_PORT = '8765'
Remove-Variable tok, raw -ErrorAction SilentlyContinue
Write-Output 'PROCESS_MODE=real (not persisted) — ensure MT5 desktop is logged into XM DEMO'
Write-Output 'STARTING bridge on 127.0.0.1:8765'
powershell -ExecutionPolicy Bypass -File .\start.ps1
