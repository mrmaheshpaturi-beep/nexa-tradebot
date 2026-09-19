# DEMO Release Checklist — NEXA-TRADEBOT-RC1

Date: 2026-09-19T16:02:05Z

## Allowed statuses

`NOT_READY` | `READY_FOR_CONTROLLED_DEMO` | `DEMO_RELEASE_CANDIDATE`  
Forbidden: `LIVE_READY` | `REAL_MONEY_READY` | `PROFIT_CERTIFIED`

## Software / safety gates

| Gate | Status |
|---|---|
| Feature freeze | YES |
| PHPUnit 249/250 (1 skip) | PASS |
| Vitest 27/27 | PASS |
| pytest 25/25 | PASS |
| tsc / eslint / build | PASS |
| Migrations clean | PASS |
| Sole order_send | PASS |
| LIVE / LIVE_AUTO blocked | PASS |
| Local backup+restore | PASS |
| Defect P0 = 0 | PASS |

## Controlled DEMO prerequisites (operator)

| Item | Status |
|---|---|
| Windows MT5 terminal connected | PENDING |
| XM DEMO account verified | PENDING |
| Real DEMO order evidence | PENDING |
| Soak 24h minimum elapsed | PENDING |
| VPS/Hostinger restore drill | PENDING |

## Decision rule applied

Software + safety PASS, external DEMO/soak/VPS PENDING → **READY_FOR_CONTROLLED_DEMO**  
(Full **DEMO_RELEASE_CANDIDATE** requires broker evidence + soak elapsed + operator restore.)
