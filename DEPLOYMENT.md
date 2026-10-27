# Deployment Checklist

This project is currently in a development/staging-ready state. It has been validated locally for database connectivity, health checks, and authenticated API access using a real admin account. Before public deployment, complete the staging checklist below.

## 1. Environment setup

1. Copy backend/.env.example to backend/.env.
2. Fill in your staging or production database credentials.
3. Set APP_ENV to local, staging, or production and keep APP_DEBUG disabled in staging/production.
4. Fill in the valid Supabase project URL and publishable key.
5. Keep secrets out of source control.
6. Use separate values for local, staging, and production environments.

Example:

```bash
cp backend/.env.example backend/.env
```

Then update the values in backend/.env before deployment.

## 2. Server requirements

- PHP 8.2+
- PostgreSQL-compatible database
- Composer dependencies installed
- HTTPS enabled in production
- Correct environment variables loaded at runtime
- `APP_ENV` and `APP_DEBUG` set explicitly for the target environment

## 2.1 Staging server configuration

Use a reverse proxy with TLS termination and keep the PHP application behind a non-public web root.

- Apache sample: `deployment/staging-apache.conf`
- Nginx sample: `deployment/staging-nginx.conf`

These examples assume:

- application files are served from `/var/www/attendance-monitoring-system/backend/public`
- the environment file is stored outside the public web root
- TLS is enabled at the proxy layer
- `.env` files remain inaccessible from the browser

## 3. Required validation

Run the following checks before deployment:

- php -l backend/config/database.php
- php -l backend/public/api/login.php
- php -l backend/public/api/admin/*.php
- php -l backend/public/api/student/*.php
- GET http://your-server/api/health.php
- POST login with a valid approved admin account
- GET a protected admin endpoint with a fresh bearer token
- GET a protected student endpoint with a fresh bearer token

## 4. Security hardening

- Do not expose .env in the web root.
- Use HTTPS only.
- Restrict directory access if using Apache or Nginx.
- Rotate credentials before production release.
- Keep Supabase keys scoped to the correct environment.
- Review Authorization checks before exposing the app publicly.

## 5. Recommended final staging flow

1. Deploy to a staging environment.
2. Validate database connectivity.
3. Validate the login flow with a test admin account.
4. Validate one admin endpoint and one student endpoint with a fresh JWT.
5. Test event/session creation and QR flow if used in production.
6. Review logs and error handling.
7. Only then move to production.

## 5.1 Next server-side staging command sequence

Run the following on the staging VPS or host:

```bash
sudo apt-get update
sudo apt-get install -y php8.2 php8.2-pgsql php8.2-curl php8.2-mbstring composer unzip git
sudo mkdir -p /var/www/attendance-monitoring-system
sudo cp -a /path/to/project/. /var/www/attendance-monitoring-system/
cd /var/www/attendance-monitoring-system/backend
composer install --no-interaction --prefer-dist --no-progress
cp .env.example .env
nano .env
```

Then set the staging values for:

- `APP_ENV=staging`
- `APP_DEBUG=false`
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- `DB_SSLMODE`
- `SUPABASE_URL`
- `SUPABASE_PUBLISHABLE_KEY`

After the file is updated, validate:

```bash
php -l /var/www/attendance-monitoring-system/backend/config/env.php
php -l /var/www/attendance-monitoring-system/backend/config/database.php
php -r "require '/var/www/attendance-monitoring-system/backend/config/env.php'; echo envValue('APP_ENV', 'production', true);"
```

Then enable the Apache or Nginx config and restart the web server.

## 6. Production release note

This project has passed local development validation, but it is not yet considered fully production-hardened until the checks above are completed in a staging environment using real production credentials.

## 7. Useful commands

```bash
cd backend
composer install
php -S 127.0.0.1:8000 public
```

Then test:

```bash
curl http://127.0.0.1:8000/api/health.php
```

```bash
curl -X POST http://127.0.0.1:8000/api/login.php \
  -H "Content-Type: application/json" \
  -d '{"email":"your@email.com","password":"your-password"}'
```
