#Requires -Version 5.1
<#
.SYNOPSIS
  Import Hostinger bridge token from a locally downloaded once-file (no chat paste).

.PARAMETER TokenFile
  Optional full path to the downloaded bridge-token-once.txt
#>
param(
  [string]$TokenFile = ''
)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$Engine = Join-Path $env:USERPROFILE 'nexa-tradebot\trading-engine'

function Find-TokenFile {
  if ($TokenFile -and (Test-Path -LiteralPath $TokenFile)) { return (Resolve-Path -LiteralPath $TokenFile).Path }

  $exact = @(
    (Join-Path $env:USERPROFILE 'Downloads\bridge-token-once.txt'),
    (Join-Path $env:USERPROFILE 'Desktop\bridge-token-once.txt'),
    (Join-Path $env:USERPROFILE 'Documents\bridge-token-once.txt'),
    (Join-Path $env:TEMP 'bridge-token-once.txt')
  ) | Where-Object { Test-Path -LiteralPath $_ }
  if ($exact) { return $exact[0] }

  foreach ($root in @(
      (Join-Path $env:USERPROFILE 'Downloads'),
      (Join-Path $env:USERPROFILE 'Desktop'),
      (Join-Path $env:USERPROFILE 'Documents')
    )) {
    if (-not (Test-Path $root)) { continue }
    $hit = Get-ChildItem -Path $root -Filter '*bridge-token*.txt' -File -ErrorAction SilentlyContinue |
      Sort-Object LastWriteTime -Descending |
      Select-Object -First 1
    if ($hit) { return $hit.FullName }
  }
  return $null
}

Write-Output 'Searching for bridge-token-once.txt ...'
$src = Find-TokenFile
if (-not $src) {
  Write-Output 'NOT_FOUND=1'
  Write-Output 'Download from Hostinger File Manager first:'
  Write-Output '  hPanel → Files → home folder → open "secure" → download bridge-token-once.txt'
  Write-Output 'Save to Downloads, then re-run this script.'
  Write-Output 'Or: powershell -File .\scripts\windows-import-bridge-token-file.ps1 -TokenFile "C:\full\path\bridge-token-once.txt"'
  throw 'bridge-token-once.txt not found on this PC yet'
}

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
Remove-Item -LiteralPath $src -Force
Write-Output 'ONCE_LOCAL_REMOVED=1'
Write-Output 'Next: open MT5 XM DEMO, then run scripts\windows-start-demo-bridge-readonly.ps1'
