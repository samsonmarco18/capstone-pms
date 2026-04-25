# ERNI PMS Render Deployment Guide

This app is prepared for Render using a Docker web service and a Render Postgres database.

## What is configured in this repo

- `render.yaml`
  Creates a Docker web service and a linked Postgres database.
- `Dockerfile`
  Builds the PHP 8.2 Apache runtime and installs the Postgres client.
- `docker/apache-render-port.sh`
  Binds Apache to Render's `PORT` and runs database bootstrap on startup.
- `bin/migrate-db.sh`
  Selects the correct bootstrap path by database driver.
- `bin/migrate-db-postgres.sh`
  Waits for Postgres, checks whether the schema exists, and imports `database.postgres.sql` only when needed.
- `database.postgres.sql`
  PostgreSQL schema plus seed data for Render.

## Deployment flow

### Option A: Blueprint

This is the recommended path.

1. Push the repository to GitHub.
2. In Render, create a new `Blueprint`.
3. Select this repository.
4. Let Render create both resources from `render.yaml`.

The Blueprint provisions:

- web service: `erni-pms`
- Postgres database: `erni-pms-db`

### Option B: Manual service creation

If you do not use Blueprints:

1. Create a new `Postgres` database in Render.
2. Create a new `Web Service`.
3. Set the runtime to `Docker`.
4. Point it at this repository and root `Dockerfile`.
5. Set these environment variables on the web service:

```text
APP_ENV=production
DEBUG_MODE=false
AUTO_INIT_DB=true
DB_DRIVER=pgsql
DATABASE_URL=<Render internal Postgres URL>
```

## Database bootstrap behavior

On every container start, the app runs:

```text
bin/migrate-db.sh
```

For Render Postgres, that script:

1. waits for the database connection
2. checks whether `public.users` already exists
3. imports `database.postgres.sql` only if the schema is missing

That makes the startup path safe for:

- first deploys
- ordinary redeploys
- restarts after the database is already initialized

## Seeded login

The Postgres seed file creates an admin account:

- email: `admin@erni-pms.com`
- password: `password`

Change the password immediately after the first login.

## Runtime behavior on Render

- The app listens on Render's assigned `PORT`.
- Health checks are served from `/healthz.php`.
- The app uses the Postgres compatibility layer in `config/database-compat.php` for MySQL-style queries still used by the codebase.
- Managed Git repositories are served through Apache smart HTTP at `/git/<slug>.git`.
- Clone and fetch work over HTTPS, and push works over HTTPS when the Git client uses the repository token as the HTTP password.

## Storage limitations

Free Render web services do not keep local filesystem changes between deploys.

That affects:

- `uploads/`
- `git/`
- `git-workspaces/`
- `logs/`

If those paths matter in production, use a persistent disk or move the data to external storage.

## Local verification before pushing

Run:

```bash
sh bin/verify-render.sh
```

You can also check:

- `php -l config/database-compat.php`
- `php -l config/database.php`
- `php -l check-deployment.php`

## Troubleshooting

### App starts but login fails

- Confirm the Postgres import completed in the first deploy logs.
- Open `/test-db.php` and verify tables exist.

### Database import did not run

Check the service logs for:

- `Checking Render Postgres readiness...`
- `Importing database.postgres.sql...`
- `Postgres schema already present. Skipping import.`

### Database connection errors

Confirm:

- the web service and database are in the same Render region
- `DATABASE_URL` points to the Render Postgres instance
- `DB_DRIVER=pgsql`

### Version-control or upload data disappears

That is expected on the free web service filesystem. Use persistent storage before treating those paths as durable production data.
