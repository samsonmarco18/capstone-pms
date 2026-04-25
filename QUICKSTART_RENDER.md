# ERNI PMS Render Quickstart

This repository is now set up for Render as:

- a `Docker` web service
- a linked `Postgres` database
- automatic first-boot schema import from `database.postgres.sql`

## 1. Push the repo

```bash
git add .
git commit -m "Prepare Render deployment"
git push origin main
```

## 2. Create the Render Blueprint

1. In Render, click `New` -> `Blueprint`.
2. Select this repository.
3. Review the generated resources from `render.yaml`:
   - web service: `erni-pms`
   - postgres database: `erni-pms-db`
4. Click `Create Resources`.

## 3. Let the first boot initialize the database

The container now runs `bin/migrate-db.sh` on startup.

On Render with `DB_DRIVER=pgsql`, that script will:

- wait for Postgres to be reachable
- check whether the `users` table already exists
- import `database.postgres.sql` only if the database is still empty

You do not need to manually import the schema for the normal first deploy path anymore.

## 4. Open the app

After the first deploy finishes, open your Render URL:

```text
https://<your-service>.onrender.com
```

## 5. Sign in with the seeded admin account

The PostgreSQL seed file creates:

- email: `admin@erni-pms.com`
- password: `password`

Change that password immediately after the first login.

## Important production notes

- Render free web services use ephemeral filesystem storage.
- Anything written to `uploads/`, `git/`, `git-workspaces/`, or `logs/` can disappear after redeploys or restarts.
- If you need persistent uploads or long-lived Git repositories in production, move to a persistent disk strategy or external storage.

## Useful checks

- App health: `/healthz.php`
- Deployment checklist page: `/check-deployment.php`
- Database check page: `/test-db.php`

## If the first deploy fails

Open the Render logs and look for:

- `Importing database.postgres.sql...`
- `Postgres schema already present. Skipping import.`
- any `psql` connection errors

If needed, redeploy after confirming the Postgres resource is healthy.
