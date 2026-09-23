<?php

return [
    'base_url' => env('TRADING_BRIDGE_URL', 'http://127.0.0.1:8765'),
    'service_token' => env('TRADING_BRIDGE_SERVICE_TOKEN'),
    // Shared hosts without a TUN device can reach the private bridge through a
    // local Unix-socket relay. Leave empty for a direct HTTP connection.
    'unix_socket' => env('TRADING_BRIDGE_UNIX_SOCKET'),
    'connect_timeout' => (float) env('TRADING_BRIDGE_CONNECT_TIMEOUT', 1.0),
    'timeout' => (float) env('TRADING_BRIDGE_TIMEOUT', 3.0),
    'read_retries' => (int) env('TRADING_BRIDGE_READ_RETRIES', 2),
    'retry_delay_ms' => (int) env('TRADING_BRIDGE_RETRY_DELAY_MS', 100),
    'circuit_failure_threshold' => (int) env('TRADING_BRIDGE_CIRCUIT_FAILURES', 3),
    'circuit_open_seconds' => (int) env('TRADING_BRIDGE_CIRCUIT_OPEN_SECONDS', 30),
    'cache_seconds' => (int) env('TRADING_BRIDGE_CACHE_SECONDS', 2),
    'history_max_days' => (int) env('TRADING_BRIDGE_HISTORY_MAX_DAYS', 90),
    'history_max_records' => (int) env('TRADING_BRIDGE_HISTORY_MAX_RECORDS', 500),
    'market_stale_after_seconds' => (float) env('TRADING_BRIDGE_MARKET_STALE_AFTER', 15),
    'write_timeout' => (float) env('TRADING_BRIDGE_WRITE_TIMEOUT', 8.0),
    // http | fake — CI always uses fake via AppServiceProvider unless explicitly set to http with token
    'demo_client' => env('TRADING_BRIDGE_DEMO_CLIENT', 'fake'),
    'demo_integration_enabled' => (bool) env('NEXA_MT5_DEMO_INTEGRATION', false),
];
