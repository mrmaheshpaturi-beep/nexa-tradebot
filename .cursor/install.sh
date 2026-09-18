#!/usr/bin/env bash
# Cloud Agent install script for Nexa TradeBot.
# Idempotent: safe to run repeatedly. Provisions the PHP toolchain when the
# base image does not already provide it, then restores dependencies and
# prepares the Laravel SQLite backend.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

log() { printf '\n[install] %s\n' "$1"; }

# 1. Ensure PHP 8.3 CLI + extensions required by Laravel 13 / this backend.
if ! command -v php >/dev/null 2>&1; then
  log "PHP not found; installing php8.3 and extensions via apt"
  sudo apt-get update -y
  sudo DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
    php8.3-cli php8.3-sqlite3 php8.3-mbstring php8.3-xml php8.3-curl \
    php8.3-bcmath php8.3-intl php8.3-zip unzip
else
  log "PHP already present: $(php -v | head -n1)"
fi

# 2. Ensure Composer 2 is available.
if ! command -v composer >/dev/null 2>&1; then
  log "Composer not found; installing Composer 2"
  php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
  sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
else
  log "Composer already present: $(composer --version)"
fi

# 3. Frontend dependencies (React 19 / Vite).
log "Installing frontend dependencies (npm install)"
npm install

# 4. Backend dependencies + Laravel bootstrap.
cd backend

log "Installing backend dependencies (composer install)"
composer install --no-interaction --no-progress

if [ ! -f .env ]; then
  log "Creating .env from .env.example"
  cp .env.example .env
fi

# Local SQLite database file (path referenced by config/database.php).
touch database/database.sqlite

if ! grep -q '^APP_KEY=base64:' .env; then
  log "Generating application key"
  php artisan key:generate --force
fi

log "Running database migrations"
php artisan migrate --force

# Development seed only runs when a seed password is provided (12+ chars).
# Set DEV_SUPER_ADMIN_PASSWORD as an environment secret to create/refresh the
# admin@nexa.local SUPER_ADMIN account for login.
if [ -n "${DEV_SUPER_ADMIN_PASSWORD:-}" ]; then
  log "Seeding development data (DEV_SUPER_ADMIN_PASSWORD is set)"
  php artisan db:seed --force
else
  log "Skipping seed: DEV_SUPER_ADMIN_PASSWORD not set"
fi

log "Install complete"
