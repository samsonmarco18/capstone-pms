# ERNI PMS - Enterprise Resource Management System

A comprehensive PHP-based project management system with version control integration, resource allocation, cost estimation, and team management.

## 🚀 Quick Links

- **Deploy to Render** → [QUICKSTART_RENDER.md](QUICKSTART_RENDER.md) (5-minute setup)
- **Detailed Render Guide** → [RENDER_DEPLOYMENT.md](RENDER_DEPLOYMENT.md)
- **Local Setup** → See below

## ✨ Features

- **Project Management**: Create, manage, and track projects
- **Version Control**: Git integration with automated hooks
- **Resource Allocation**: Allocate developers to projects
- **Cost Estimation**: Professional and feature-based estimations
- **Team Management**: Manage users, roles, and permissions
- **Performance Tracking**: Monitor team performance and productivity
- **Chat System**: Team communication and collaboration
- **File Management**: Organize project files and documents
- **Audit Logging**: Track all system actions and changes
- **6 User Roles**: Admin, Sales, Architect, SUL, Expert, Developer

## 📋 System Requirements

### Minimum
- PHP 8.0+
- MySQL 5.7+
- 256MB RAM
- Git 2.0+ (for version control features)

### Recommended
- PHP 8.1+
- MySQL 8.0+
- 512MB RAM
- Git 2.40+
- Apache/Nginx web server

## 💻 Local Development Setup

### 1. Prerequisites

```bash
# Windows with XAMPP
- PHP 8.0+
- MySQL 5.7+
- Git for Windows

# macOS
brew install php mysql git

# Linux (Ubuntu/Debian)
sudo apt install php mysql-server git
```

### 2. Clone Repository

```bash
cd /path/to/projects
git clone https://github.com/samsonmarco18/erni-pms.git
cd erni-pms
```

### 3. Database Setup

```bash
# Import database schema
mysql -u root -p < database.sql

# Or via phpMyAdmin:
# 1. Create database: erni_pms
# 2. Import database.sql
```

### 4. Configure Environment

The tracked `config/.env.php` is now safe to share and uses:
- local defaults for XAMPP development
- environment variables for production secrets
- an optional untracked `config/.env.local.php` override file for per-machine settings

To customize locally, create `config/.env.local.php` from `config/.env.local.example.php`.

Example:
```php
<?php

return [
  'DB_HOST' => 'localhost',
  'DB_USER' => 'root',
  'DB_PASS' => '',
  'DB_NAME' => 'erni_pms',
  'APP_URL' => 'http://localhost/erni-pms',
];
```

Optional Gmail SMTP for automatic email sending:
```php
<?php

return [
  'MAIL_ENABLED' => '1',
  'MAIL_DELIVERY_METHOD' => 'smtp',
  'MAIL_HOST' => 'smtp.gmail.com',
  'MAIL_PORT' => '587',
  'MAIL_ENCRYPTION' => 'tls',
  'MAIL_USERNAME' => 'altheapalentinos@gmail.com',
  'MAIL_PASSWORD' => 'your-google-app-password',
  'MAIL_FROM_EMAIL' => 'altheapalentinos@gmail.com',
  'MAIL_FROM_NAME' => 'Erni PMS',
  'MAIL_TIMEOUT' => '8',
];
```

Important for Gmail:
- enable 2-Step Verification on the Google account
- create a Google App Password
- use the App Password here, not the normal Gmail password

Render may not be able to connect to Gmail SMTP ports `587` or `465`. If test email fails with connection timeouts, use Gmail API over HTTPS instead:

```text
MAIL_DELIVERY_METHOD=gmail_api
MAIL_ENABLED=1
MAIL_USERNAME=your-gmail-address
MAIL_FROM_EMAIL=your-gmail-address
GMAIL_CLIENT_ID=your-google-oauth-client-id
GMAIL_CLIENT_SECRET=your-google-oauth-client-secret
GMAIL_REFRESH_TOKEN=your-google-oauth-refresh-token
GMAIL_USER=your-gmail-address
```

For Gmail API, the Google OAuth consent/app must include the Gmail send scope:

```text
https://www.googleapis.com/auth/gmail.send
```

Mailer environment checklist:
- `MAIL_ENABLED`: set to `1` to send real email.
- `MAIL_DELIVERY_METHOD`: use `smtp` for local Gmail SMTP, or `gmail_api` for Render/hosts that block SMTP ports.
- `MAIL_HOST`: `smtp.gmail.com` for Gmail SMTP.
- `MAIL_PORT`: use `465` with `MAIL_ENCRYPTION=ssl`, or `587` with `MAIL_ENCRYPTION=tls`.
- `MAIL_USERNAME`: the Gmail address used to send mail.
- `MAIL_PASSWORD`: a Google App Password for SMTP. Do not use the normal Gmail password.
- `MAIL_FROM_EMAIL`: usually the same Gmail address as `MAIL_USERNAME`.
- `MAIL_FROM_NAME`: sender label shown to recipients, for example `ERNI PMS`.
- `MAIL_VERIFY_PEER`: keep `1` unless a local certificate issue temporarily blocks SMTP testing.
- `MAIL_TIMEOUT`: keep between `5` and `10`.
- `MAIL_DEBUG`: keep `0`; set to `1` only while diagnosing mail configuration.

If using `MAIL_DELIVERY_METHOD=gmail_api`, also fill:
- `GMAIL_CLIENT_ID`
- `GMAIL_CLIENT_SECRET`
- `GMAIL_REFRESH_TOKEN`
- `GMAIL_USER`

How to add missing mail data locally:
1. Open `config/.env.local.php`.
2. Fill the missing values in the returned array.
3. Save the file.
4. Log in as Admin and open `mail-diagnostic.php` in the browser.
5. To send a test email, open `mail-diagnostic.php?send_test=1&to=your-email@example.com`.

How to add missing mail data on Render:
1. Open the Render service dashboard.
2. Go to Environment.
3. Add the same keys as environment variables.
4. Redeploy the service.
5. Test with `https://your-app.onrender.com/mail-diagnostic.php?send_test=1&to=your-email@example.com` while logged in as Admin.

Methodology workflow data checklist:
- Sales must create the client/project with project start and end dates.
- Architect must create feature tasks from the project requirements.
- Architect must finish technology recommendation.
- Architect must finish developer allocation.
- Architect must open Roadmap and choose a methodology template such as Agile, Scrum, Waterfall, Kanban, Iterative, Spiral, or V-Model.
- The selected methodology template generates phases inside the project timeline.
- For every phase, Architect must enter phase dates and the number of developers present.
- For every feature task, Architect must add a roadmap task and select the matching workflow feature task.
- Every roadmap task date must stay inside its phase date range.
- Experts can update people count and component notes for roadmap tasks.
- Architect must upload the required Architecture Plan and Workflow Diagram.
- Summary can be submitted to SUL only after every feature task is scheduled in the roadmap.

### 5. Start Application

**Option A: Using PHP Built-in Server**
```bash
cd /path/to/erni-pms
php -S localhost:8000
# Visit: http://localhost:8000
```

**Option B: Using XAMPP**
- Copy folder to `C:\xampppp\htdocs\erni-pms`
- Start Apache and MySQL
- Visit: `http://localhost/erni-pms`

Optional HTTPS on localhost:
- Run `powershell -ExecutionPolicy Bypass -File .\bin\setup-local-https.ps1`
- Trust and install the generated certificate on your own machine only

### 6. First Login

Find admin credentials in database:
```sql
-- In MySQL
SELECT email, full_name FROM users WHERE role = 'admin' LIMIT 1;

-- Default might be:
-- Email: admin@example.com
-- Password: Check database.sql for initial setup
```

Or create a test user:
```sql
z
```

## 🌍 Cloud Deployment

### Deploy to Render (Recommended - Free Tier Available)

**Easiest deployment method:**

1. Push to GitHub:
   ```bash
   git add .
   git commit -m "Ready for deployment"
   git push origin main
   ```

2. Go to [Render.com](https://render.com)
3. Sign in with GitHub
4. Click **"New"** → **"Blueprint"**
5. Select your `erni-pms` repository
6. Follow the setup wizard
7. Done! ✅

See [QUICKSTART_RENDER.md](QUICKSTART_RENDER.md) for the short path and [RENDER_DEPLOYMENT.md](RENDER_DEPLOYMENT.md) for the full guide.

For the Render path, this repo now includes:
- `render.yaml` for a Docker web service plus Render Postgres
- `database.postgres.sql` for the PostgreSQL schema and seed data
- automatic first-boot Postgres initialization inside the container
- dual-driver support so local MySQL development can continue

### Deploy to Other Platforms

- **InfinityFree**: `.env.php` auto-detects and uses InfinityFree credentials
- **Traditional Hosting**: FTP/SFTP upload with `.env.php` configuration
- **Docker**: Dockerfile can be created on request
- **AWS/Azure**: Standard PHP hosting setup

## 📁 Project Structure

```
erni-pms/
├── config/              # Configuration files
│   ├── database.php     # Database connection
│   └── .env.php         # Environment variables
├── includes/            # Core functionality
│   ├── auth.php         # Authentication
│   ├── version-control.php
│   └── ...
├── modules/             # Features by role
│   ├── admin/
│   ├── architect/
│   ├── developer/
│   ├── expert/
│   ├── sales/
│   ├── sul/
│   └── version-control/
├── api/                 # REST endpoints
│   ├── chat/
│   ├── folders/
│   ├── notifications/
│   └── version-control/
├── assets/              # CSS, JS, images
├── uploads/             # User uploads (files, diagrams)
├── git/                 # Git repositories storage
├── database.sql         # Database schema
├── index.php            # Entry point
├── render.yaml          # Render deployment config
└── Procfile             # Web server spec
```

## 🔐 Security

### Features
- Password hashing with SHA-256
- Session-based authentication
- Role-based access control (RBAC)
- CSRF protection (headers)
- XSS prevention (output escaping)
- SQL injection prevention (prepared statements)
- HTTPS enforcement in production

### Best Practices

**Never commit:**
- `.env` files with real credentials
- Database backups
- User files from uploads folder
- Git repositories storage

**Always:**
- Use strong passwords (12+ chars, mixed case, numbers, symbols)
- Enable HTTPS in production
- Keep PHP updated
- Regular database backups
- Review audit logs monthly

## 🛠️ Development

### Useful Commands

```bash
# Local development server
php -S localhost:8000

# Database backup
mysqldump -u root -p erni_pms > backup.sql

# Database restore
mysql -u root -p erni_pms < backup.sql

# Check PHP version
php -v

# Check enabled extensions
php -m

# Git status
git status
git log --oneline -n 10

# Deploy to Render
git push origin main  # Automatic deployment
```

### File Organization

- **API endpoints**: `api/` folder
- **UI pages**: `modules/` and root `*.php` files
- **Business logic**: `includes/` folder
- **Database**: `database.sql`
- **Assets**: `assets/` folder

## 🐛 Troubleshooting

### Database Connection Error
```bash
# Check MySQL is running
# Windows: Check XAMPP Control Panel
# macOS/Linux: sudo systemctl status mysql

# Test connection
mysql -u root -p -e "SELECT 1"
```

### Git Operations Not Available
- Server has `exec()` function disabled
- Solution: Upgrade to VPS or use file upload workflow
- See: [GIT_OPERATIONS_GUIDE.md](GIT_OPERATIONS_GUIDE.md)

### Permission Denied Errors
```bash
# Fix file permissions
chmod -R 755 git git-workspaces uploads logs
chmod 644 config/.env.php
```

### Session Errors
- Clear cookies: `Ctrl+Shift+Delete`
- Check PHP session storage: `/tmp` (Linux) or temp folder (Windows)
- Verify `SESSION_TIMEOUT` in `.env.php`

### Large File Uploads Fail
- Check `upload_max_filesize` in PHP config
- Increase in `UPLOAD_MAX_SIZE` constant
- Default: 50MB

## 📊 Database

### Schema Summary
- **32 tables** covering all business logic
- **1:N relationships** for projects, resources, tasks
- **M:N relationships** for allocations, team composition
- **Audit logging** for compliance
- **Full-text indexing** for search performance

### Key Tables
```sql
-- Core
users, clients, projects, folders

-- Version Control
project_repositories, repository_branches, repository_commits

-- Operations
tasks, task_comments, repository_operations

-- Team
developer_allocations, team_compositions

-- Business
cost_estimations, sales_reports, performance_metrics

-- Communication
messages, chat_groups, notifications
```

## 🚀 Performance Optimization

### Caching (Render)
```php
define('CACHE_ENABLED', true);
define('CACHE_DURATION', 3600);  // 1 hour
```

### Database Indexes
- Created for all foreign keys
- Full-text indexes on searchable fields
- Unique constraints on identifiers

### Assets
- CSS/JS minification (optional)
- Image optimization recommended
- Gzip compression (server-level)

## 📝 License & Attribution

[Add your license here]

## 🤝 Contributing

1. Fork the repository
2. Create feature branch: `git checkout -b feature/my-feature`
3. Commit: `git commit -m "Add feature"`
4. Push: `git push origin feature/my-feature`
5. Create Pull Request

## 📞 Support

- **Documentation**: See `.md` files in root directory
- **Issues**: Report on GitHub
- **Render Help**: [Render Docs](https://render.com/docs)
- **PHP Help**: [PHP Manual](https://www.php.net/manual/)

## 🗺️ Roadmap

- [ ] REST API documentation
- [ ] Mobile app (React Native)
- [ ] Advanced reporting
- [ ] Slack integration
- [ ] Automated testing
- [ ] CI/CD pipeline

## 📅 Last Updated

April 19, 2026

---

**Ready to deploy?** → [Quick Render Setup](QUICKSTART_RENDER.md)

**Questions?** → Check the detailed guides in the root directory
