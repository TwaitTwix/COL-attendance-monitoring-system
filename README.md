# University Attendance Monitoring System

A PHP + PostgreSQL attendance monitoring backend with Supabase authentication and a lightweight frontend dashboard.

## Project structure

- backend/config: database and authentication configuration
- backend/public/api: all backend endpoints
- backend/public/.htaccess: basic deployment hardening
- frontend: static dashboard UI

## Verified development status

The following checks passed during local validation:

- database connection succeeds
- health endpoint responds successfully
- admin login works with a valid account
- protected admin routes succeed with a fresh JWT
- admin review/approval flow works
- static frontend loads from the local server

## Local run

From the project root:

```bash
cd backend
php -S 127.0.0.1:8000 public
```

Then browse the frontend from the project root via a static file server if needed:

```bash
cd frontend
php -S 127.0.0.1:8001
```

## Environment configuration

Copy the sample file and fill in your real values:

```bash
cp backend/.env.example backend/.env
```

Required values:

- DB_HOST
- DB_PORT
- DB_DATABASE
- DB_USERNAME
- DB_PASSWORD
- DB_SSLMODE
- SUPABASE_URL
- SUPABASE_PUBLISHABLE_KEY

## Deployment readiness

This project is currently development-validated and staging-ready, but not public-production-ready until the following are completed in a staging environment:

- production environment variables are configured
- HTTPS is enabled
- reverse proxy or web server rules are in place
- admin and student auth flow are validated with fresh tokens
- logs and monitoring are reviewed

See [DEPLOYMENT.md](DEPLOYMENT.md) for the full checklist.
