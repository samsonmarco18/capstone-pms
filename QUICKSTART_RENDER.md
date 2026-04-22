# ERNI PMS - Deploy to Render in 5 Minutes

This guide will walk you through deploying ERNI PMS to Render (a modern cloud platform).

## Important Before You Deploy

- This project should be deployed on Render as a `Docker` web service, not a `Node` service.
- This repo now includes a free Render Postgres Blueprint path.
- Import `database.postgres.sql` into the Postgres database after the first deploy.
- Free Render web services use ephemeral storage, so uploaded files and server-side git repositories will not persist across redeploys unless you move to a paid plan with persistent disks.

## What is Render?

[Render](https://render.com) is a cloud platform that:
- ✅ Supports PHP with automatic scaling
- ✅ Provides free tier to start
- ✅ Manages databases (MySQL/PostgreSQL)
- ✅ Auto-deploys from GitHub
- ✅ Provides free SSL certificates
- ✅ No credit card required for free tier

## Prerequisites

1. **GitHub Account** - [Sign up here](https://github.com/signup)
2. **Push this repository to GitHub** - Commands below
3. **Render Account** - [Sign up here](https://render.com) (connects with GitHub)

## Quick Start (5 Minutes)

### Step 1: Prepare and Push to GitHub

```bash
# 1a. Create a repository on GitHub
# Visit: https://github.com/new
# Create repo name: "erni-pms"

# 1b. Initialize git and push code
cd ~/path/to/erni-pms

# Stage all files
git add .

# Commit
git commit -m "Initial commit: Add Render deployment configuration"

# Add GitHub as remote (replace with your repo URL)
git remote add origin https://github.com/YOUR_USERNAME/erni-pms.git

# Push to GitHub
git branch -M main
git push -u origin main
```

### Step 2: Deploy on Render

1. **Open Render Dashboard**
   - Visit: https://render.com/dashboard
   - Sign in with GitHub (allow permissions)

2. **Create a Blueprint**
   - Click: **"New"** → **"Blueprint"**
   - Select your GitHub account
   - Find and select: **erni-pms** repository
   - Click: **"Connect"**

3. **Review Configuration**
   - Render automatically reads `render.yaml`
   - Resources to create: 1 Docker Web Service + 1 Free Postgres Database
   - `DB_DRIVER` and `DATABASE_URL` are wired by the Blueprint
   - Click: **"Create Resource"**

4. **Wait for Deployment**
   - Watch the deployment progress
   - Takes 5-10 minutes typically
   - You'll see logs updating in real-time

5. **Access Your App**
   - After deployment completes
   - Click on the web service name
   - Copy the URL: `https://erni-pms-xxx.onrender.com`
   - Visit in browser
   - Import `database.postgres.sql` into the Postgres database

**That's it! 🎉**

### If You Already Created a Node Service

Your screenshot shows the existing Render service was created with the `Node` runtime. That service will keep failing for this PHP project.

Create a new Render service using one of these paths instead:

1. `New` → `Blueprint` and let Render read `render.yaml`
2. `New` → `Web Service`, then set the runtime/language to `Docker`

---

## First Login

1. Check your email for any setup notifications
2. Look at the application logs (Render dashboard → Logs tab)
3. Default login:
   - **Email**: admin account (from your database.sql)
   - **Password**: Check database.sql for initial credentials

**First time?** Create a new account:
- Visit: `https://your-app.onrender.com/register` (if available)
- Or set up admin user via MySQL

---

## Project Structure

```
erni-pms/
├── render.yaml              # ← Render infrastructure config
├── Procfile                 # ← Web server specification
├── bin/
│   ├── build.sh            # Build script
│   ├── migrate-db.sh       # Database initialization
│   └── verify-render.sh    # Pre-deployment check
├── config/
│   ├── .env.php            # Environment detection
│   └── database.php        # Database connection
├── database.sql            # Database schema
├── index.php               # Application entry point
└── RENDER_DEPLOYMENT.md    # Detailed guide (this file)
```

---

## Environment Detection

The app automatically detects where it's running:

| Platform | Location | Database |
|----------|----------|----------|
| **Local** | localhost:8000 | localhost/root |
| **Render** | *.onrender.com | Render MySQL |
| **InfinityFree** | ernie.is-great.org | InfinityFree MySQL |

No code changes needed - it works everywhere! ✨

---

## Database Management

### Initial Import
- Automatic on first deployment
- `database.sql` imported via `migrate-db.sh`
- 32 tables created
- Indexes and relations configured

### Connect to Database
After deployment, view your database:

1. **Via Render Dashboard**
   - Service → MySQL → Database
   - See connection info
   - Export/backup options

2. **Via Command Line**
```bash
# Get credentials from Render dashboard
mysql -h <host> -u <user> -p <database_name>
# Enter password when prompted
```

3. **Query Examples**
```sql
-- View all tables
SHOW TABLES;

-- View users
SELECT id, full_name, email, role FROM users;

-- Update admin password (if needed)
UPDATE users SET password = SHA2('newpassword', 256) WHERE role = 'admin';
```

---

## Troubleshooting

### ❌ Deployment Failed

**Check logs** (most common fix):
1. Go to Render dashboard
2. Click your service
3. Click **"Logs"** tab
4. Scroll through error messages

**Common issues:**

| Error | Fix |
|-------|-----|
| `database.sql not found` | Ensure file is in root directory & committed to git |
| `Permission denied in git/` | Render creates directories automatically - it will fix this |
| `Connection refused` | Wait 2-3 minutes for MySQL to start, then redeploy |
| `Port 5432 not available` | Change to MySQL (default) - PostgreSQL not needed |

### ❌ Application Won't Start

1. **Check PHP errors**
   ```bash
   # In Render logs, look for:
   - Parse error
   - Fatal error
   - Warning: require_once
   ```

2. **Verify config loaded**
   - Visit: `/check-deployment.php` (if available)
   - Shows PHP extensions, database status

3. **Check environment variables**
   - Render dashboard → Service → Environment
   - Verify all `DB_*` variables are set

### ❌ Can't Access After Deployment

1. **Is deployment complete?**
   - Watch Render dashboard - "Active" status
   - Check "Deploy" tab for errors

2. **Check URL**
   - Example: `https://erni-pms-xyz.onrender.com`
   - NOT `http://` (must be HTTPS)

3. **Clear browser cache**
   ```bash
   Ctrl+Shift+Delete (Windows)
   Cmd+Shift+Delete (Mac)
   ```

### ❌ Database Error "too many connections"

**Upgrade plan** or reduce connections:
- Free tier: Limited connections
- Solution: Go to Render → Plan → Upgrade to Standard ($7/mo)

---

## Performance Tips

### 1. Enable Caching
```php
// Already configurable via environment variable
// In config/.env.php
define('CACHE_ENABLED', true);
```

### 2. Optimize Database
- Render MySQL has indexing
- Queries optimized in `database.sql`
- Monitor via Render dashboard

### 3. Monitor Resource Usage
```
Free Plan Limits:
- Memory: 0.5 GB
- Storage: 1 GB MySQL
- CPU: Shared

If running out of resources:
- Render dashboard → Plan → Upgrade
- Auto-suspends after 15 min inactivity (free tier)
- Restart: Click service → "Restart"
```

### 4. Upgrade When Ready
| Plan | RAM | DB | Price |
|------|-----|----|----|
| Free | 0.5GB | 1GB | $0/mo |
| Starter | 0.5GB | 1GB | $7/mo |
| Standard | 2GB | 10GB | $25/mo |
| Plus | 4GB | 100GB | $50/mo |

---

## Custom Domain (Optional)

Want `yourcompany.com` instead of `*.onrender.com`?

1. **Buy a domain**
   - GoDaddy, Namecheap, Google Domains, etc.
   - Cost: $10-15/year

2. **Configure Render**
   - Render dashboard → Service → Settings
   - Scroll to "Custom Domains"
   - Add: `yourdomain.com` and `www.yourdomain.com`
   - Get CNAME records

3. **Update DNS**
   - Your domain registrar DNS settings
   - Add CNAME records from step 2
   - Wait 24-48 hours for DNS propagation

4. **SSL Certificate**
   - Automatic (free Let's Encrypt)
   - Auto-renews yearly

---

## Maintenance & Updates

### Regular Backups
```bash
# Via Render dashboard
1. Go to MySQL service
2. Click "Backup" tab
3. Download latest backup
4. Store safely
```

### Pull Latest Code
```bash
# Redeploy after code changes:
1. Push to GitHub: git push origin main
2. Render auto-detects and redeploys
3. Watch "Deploy" tab for status
```

### Database Migrations
```bash
# If you need to run queries:
1. Backup first!
2. SSH into container (if available)
3. Run: mysql -h ... -u ... -D erni_pms
4. Execute SQL
5. Commit & push if schema changed
```

---

## Support & Resources

### Documentation
- [Render PHP Documentation](https://render.com/docs/deploy-php)
- [Render MySQL Documentation](https://render.com/docs/deploy-mysql)
- [Render Dashboard](https://render.com/dashboard)

### Debugging
- Logs available in Render dashboard
- Email support included
- Community support: Render Discord

### Limits (Free Tier)
- RAM: 0.5GB (auto-suspend after 15 min idle)
- Storage: 1GB MySQL
- CPU: Shared
- **Suitable for**: Development, demos, testing

### When to Upgrade
- Traffic > 10 users/day
- Database > 100MB
- Need always-on service
- Production environment

---

## Deployment Checklist

Before you deploy:

- [ ] Repository pushed to GitHub
- [ ] `render.yaml` in root directory
- [ ] `Procfile` in root directory
- [ ] `bin/build.sh` and `bin/migrate-db.sh` present
- [ ] `database.sql` in root directory
- [ ] `.env.php` configured for multi-platform
- [ ] `.gitignore` excludes sensitive files
- [ ] All changes committed to git
- [ ] GitHub remote configured
- [ ] Render account created
- [ ] GitHub connected to Render

## Post-Deployment Checklist

After deployment:

- [ ] App loads without errors
- [ ] Login page appears
- [ ] Can create an account
- [ ] Database tables accessible
- [ ] Version control module works
- [ ] File uploads work
- [ ] Email/notifications functional
- [ ] Logs show no errors

---

## Summary

You now have:

✅ **Local Development**
- Run locally on `localhost:8000`
- Use local MySQL
- Same code, no changes needed

✅ **Cloud Production**
- Deployed on `*.onrender.com`
- Automatic scaling
- Free SSL certificates
- Git-based deployments
- Easy to upgrade

✅ **Zero Configuration**
- App auto-detects environment
- Environment variables managed by Render
- Database automatically provisioned

🎉 **You're all set!**

---

**Questions?** See `RENDER_DEPLOYMENT.md` for detailed guidance.

**Last Updated**: April 19, 2026
