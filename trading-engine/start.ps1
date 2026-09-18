$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

if (-not $env:NEXA_MT5_SERVICE_TOKEN) {
    throw "NEXA_MT5_SERVICE_TOKEN must be supplied through the process environment."
}

$env:NEXA_MT5_HOST = if ($env:NEXA_MT5_HOST) { $env:NEXA_MT5_HOST } else { "127.0.0.1" }
$env:NEXA_MT5_PORT = if ($env:NEXA_MT5_PORT) { $env:NEXA_MT5_PORT } else { "8765" }

if (-not (Test-Path ".venv\Scripts\python.exe")) {
    throw "Create .venv and install the project before starting the bridge."
}

& ".venv\Scripts\python.exe" -m uvicorn nexa_mt5.api:app `
    --host $env:NEXA_MT5_HOST `
    --port $env:NEXA_MT5_PORT `
    --no-access-log
