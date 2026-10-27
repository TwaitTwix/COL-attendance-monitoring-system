#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="/var/www/attendance-monitoring-system"
REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TARGET_ENV="staging"

if [[ $EUID -ne 0 ]]; then
  echo "This script must be run as root."
  exit 1
fi

if [[ ! -d "$REPO_ROOT/backend" ]]; then
  echo "Project root not found: $REPO_ROOT"
  exit 1
fi

mkdir -p "$APP_ROOT"
cp -a "$REPO_ROOT/." "$APP_ROOT/"

if [[ ! -f "$APP_ROOT/backend/.env" ]]; then
  cp "$APP_ROOT/backend/.env.example" "$APP_ROOT/backend/.env"
  echo "Created backend/.env from example. Update it with staging values before continuing."
fi

cd "$APP_ROOT/backend"
if ! command -v composer >/dev/null 2>&1; then
  echo "Composer is required but not installed."
  exit 1
fi

composer install --no-interaction --prefer-dist --no-progress

cat > "$APP_ROOT/backend/.env" <<EOF
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://staging.attendance.example.com

DB_HOST=your-db-host
DB_PORT=5432
DB_DATABASE=your-db-name
DB_USERNAME=your-db-user
DB_PASSWORD=your-db-password
DB_SSLMODE=require

SUPABASE_URL=https://your-project.supabase.co
SUPABASE_PUBLISHABLE_KEY=your-supabase-publishable-key
EOF

chmod 600 "$APP_ROOT/backend/.env"
chmod 755 "$APP_ROOT/backend/public"
find "$APP_ROOT" -type d -exec chmod 755 {} +
find "$APP_ROOT" -type f -exec chmod 644 {} +

php -l "$APP_ROOT/backend/config/env.php"
php -l "$APP_ROOT/backend/config/database.php"
php -l "$APP_ROOT/backend/config/supabase-auth.php"
php -l "$APP_ROOT/backend/config/authorization.php"

cat <<EOF
Staging deployment files prepared.

Next steps:
1. Update backend/.env with the real staging credentials.
2. Copy the generated Apache or Nginx config to the web server.
3. Restart PHP-FPM / Apache / Nginx.
4. Test: https://staging.attendance.example.com/api/health.php
5. Validate admin login with a fresh staging JWT.
EOF
