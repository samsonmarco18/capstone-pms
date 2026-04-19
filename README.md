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

Edit `config/.env.php` (already configured for localhost):
```php
// Automatically uses:
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_NAME', 'erni_pms');
```

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
INSERT INTO users (full_name, email, password, role, created_at)
VALUES (
  'Admin User',
  'admin@test.com',
  SHA2('password123', 256),
  'admin',
  NOW()
);
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

See [QUICKSTART_RENDER.md](QUICKSTART_RENDER.md) for detailed steps.

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
