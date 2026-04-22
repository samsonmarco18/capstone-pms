# ERNI PMS - Render Deployment Guide

## Quick Start

## Important Notes

- Use a new `Docker` web service or a Blueprint. An existing `Node` service will not run this PHP app correctly.
- The checked-in `render.yaml` no longer tries to create MySQL because Render Blueprints reserve `databases:` for Postgres.
- Create MySQL separately, then provide `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, and `DB_PORT` to the web service.
- Free web services do not provide persistent storage for `uploads/`, `git/`, or `git-workspaces/`.

### Step 1: Prepare Your Repository

1. Ensure `render.yaml` and `Procfile` are in the root directory
2. Commit all changes:
   ```bash
   git add .
   git commit -m "Add Render deployment configuration"
   git push origin main
   ```

### Step 2: Create Render Account & Dashboard

1. Go to [https://render.com](https://render.com)
2. Sign up or log in with GitHub
3. Click **"New +"** → **"Blueprint"** or **"Web Service"**

### Step 3: Deploy with Blueprint (Recommended)

1. In Render dashboard, click **"New"** → **"Blueprint"**
2. Select your GitHub repository: `https://github.com/samsonmarco18/erni-pms`
3. Configure these environment variables:
   - `APP_ENV`: `production` (auto-set in render.yaml)
   - `DEBUG_MODE`: `false` (auto-set in render.yaml)
   - `DB_HOST`: your MySQL host
   - `DB_USER`: your MySQL username
   - `DB_PASS`: your MySQL password
   - `DB_NAME`: `erni_pms` (or your actual database name)
   - `DB_PORT`: `3306`

4. Click **"Create Resource"**
5. Render will build and deploy your application

### Step 4: Access Your Application

After deployment (5-10 minutes):
- Visit: `https://erni-pms-xxx.onrender.com`
- Default credentials (same as development):
  - Email: (use your created account)
  - Password: (your password)

---

## Manual Deployment (Alternative)

If you prefer manual setup without blueprint:

### Create Web Service
1. Click **"New"** → **"Web Service"**
2. Connect your GitHub repository
3. Set the runtime/language to `Docker`
4. Render will use the repo `Dockerfile` automatically

### Provision Database
1. Create a MySQL service separately, or use an existing external MySQL database
2. Copy the connection credentials

### Configure Environment Variables
Add these in Web Service settings:
```
DB_HOST=<from MySQL info>
DB_USER=<from MySQL info>
DB_PASS=<from MySQL info>
DB_NAME=erni_pms
DB_PORT=3306
APP_ENV=production
DEBUG_MODE=false
APP_URL=https://your-domain.onrender.com
```

---

## Troubleshooting

### Application Won't Start

1. **Check logs**: In Render dashboard, click service → "Logs"
2. **View error logs**: Logs appear in real-time
3. **Common issues**:
   - Database not initialized: Check `migrate-db.sh` output
   - Permission denied: Render automatically sets permissions
   - PHP extensions missing: Supported by default

### Database Connection Failed

1. Verify credentials match MySQL service info
2. Wait 2-3 minutes after MySQL creation (initialization time)
3. Check firewall: Render handles this automatically
4. Review `migrate-db.sh` output in deployment logs

### High Memory Usage

- **Free tier limit**: 0.5GB RAM
- **Solution**: Upgrade to standard tier or optimize queries

### URL Not Working

1. Custom domain setup:
   - In Render dashboard: Service → Settings → Custom Domains
   - Add domain: `yourdomain.com`
   - Update DNS to point to Render

2. SSL certificate:
   - Render auto-generates for `.onrender.com` domains
   - Custom domains use free Let's Encrypt

---

## Environment Variables Reference

| Variable | Default | Notes |
|----------|---------|-------|
| `APP_ENV` | production | Set by render.yaml |
| `DEBUG_MODE` | false | Keep false in production |
| `DB_HOST` | Required | Your MySQL host |
| `DB_USER` | Required | Your MySQL username |
| `DB_PASS` | Required | Your MySQL password |
| `DB_NAME` | erni_pms | Change if your DB uses a different name |
| `DB_PORT` | 3306 | Default MySQL port |
| `APP_URL` | Auto-detected | Usually no manual value needed |

---

## Database Management

### Initial Setup
- Database created automatically
- Schema imported via `database.sql` on first deployment
- Tables created: 32 (from your schema)

### Backup & Restore
- Render provides MySQL backup functionality
- Access via dashboard: Service → Data
- Download backups for local testing

### SSH Access to Database
```bash
# Using Render's provided connection string
mysql -h<host> -u<user> -p<password> erni_pms
```

---

## Performance Optimization (For Production)

### Enable Caching
```php
// In config/database.php (already included)
define('CACHE_ENABLED', true);
define('CACHE_DURATION', 3600);
```

### Optimize Database Indexes
```sql
-- Already in your database.sql schema
-- Indexes created for common queries
```

### Set Up CDN (Optional)
- Render supports integration with Cloudflare
- Improves asset delivery globally

---

## Upgrading Plans

### Free Plan Limits
- 0.5GB RAM
- MySQL: 1GB storage
- Auto-suspend after 15 min inactivity
- Suitable for: Development, testing, small projects

### Standard Plan ($7+/month)
- 2GB RAM
- MySQL: 10GB storage  
- Always running (no suspension)
- Suitable for: Production applications

### Advanced Plans
- Dedicated resources
- Priority support
- Custom SLAs

**Recommendation**: Start with Free, upgrade to Standard for production use

---

## Support & Additional Resources

- **Render Docs**: https://render.com/docs
- **PHP Documentation**: https://www.php.net/manual/
- **MySQL Documentation**: https://dev.mysql.com/doc/
- **GitHub Issues**: Report issues on your repository

---

## Local Testing Before Deployment

Test your app locally with Render-like environment:

```bash
# Install required PHP modules
# (Ensure you have all extensions installed)

# Set environment variables
export APP_ENV=production
export DEBUG_MODE=false
export DB_HOST=localhost
export DB_USER=root
export DB_PASS=
export DB_NAME=erni_pms_test

# Run application
php -S 0.0.0.0:8000

# Visit http://localhost:8000
```

---

## Success Checklist

- [x] Repository pushed to GitHub
- [x] render.yaml configured
- [x] Procfile added
- [x] build.sh and migrate-db.sh present
- [x] database.sql in root directory
- [x] Environment variables set
- [x] MySQL service created
- [x] Web service connected
- [x] Application deployed
- [x] Database initialized
- [x] Login successful
- [x] Version control working

---

**Last Updated**: April 19, 2026
**Version**: 1.0
