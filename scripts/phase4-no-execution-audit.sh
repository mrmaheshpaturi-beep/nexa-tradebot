#!/usr/bin/env bash
set -euo pipefail
# Phase 4 historical audit retained; Phase 10 supersedes with scripts/phase10-execution-audit.sh
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
exec "$ROOT/scripts/phase10-execution-audit.sh"
