# ERNI PMS - Deployment Guide

## InfinityFree Hosting Deployment

### **Step 1: Prepare Your Application**

```bash
# 1. Copy config/.env.php template (already created)
# 2. Update database credentials for InfinityFree
# 3. Import database.sql in your hosting control panel
```

### **Step 2: Upload to InfinityFree**

1. **Connect via FTP** (get credentials from InfinityFree panel)
   - Host: `ftpupload.net`
   - Username: Your InfinityFree username
   - Password: Your FTP password

2. **Upload Files**
   ```
   /htdocs/public_html/erni-pms/
   ├── api/
   ├── assets/
   ├── config/
   ├── includes/
   ├── modules/
   ├── uploads/
   ├── git/
   ├── git-workspaces/
   ├── index.php
   ├── login.php
   ├── logout.php
   ├── .htaccess (IMPORTANT!)
   └── database.sql
   ```

3. **Set Permissions**
   - Right-click `uploads/` → Properties → Permissions → 755
   - Right-click `git/` → Properties → Permissions → 755
   - Right-click `git-workspaces/` → Properties → Permissions → 755

### **Step 3: Setup Database**

1. Go to InfinityFree Control Panel
2. Click **"MySQL Databases"**
3. Click **"Create New Database"**
4. Note your database name (usually prefixed with your username)
5. Update `config/.env.php`:
   ```php
   define('DB_NAME', 'your_username_database_name');
   define('DB_USER', 'your_username_user');
   define('DB_PASS', 'your_password');
   ```

6. **Import Database Schema**
   - Go to **phpMyAdmin** in control panel
   - Select your database
   - Go to **"Import"** tab
   - Upload `database.sql`
   - Click **"Import"**

### **Step 4: Verify Setup**

1. Visit: `https://yourdomain.infinityfree.com/erni-pms/`
2. You should see login page (not 403 error)
3. Login with test credentials
4. If you see 403, check:
   - [ ] `.htaccess` file uploaded
   - [ ] Database imported successfully
   - [ ] Database credentials correct
   - [ ] File permissions are 755

### **Troubleshooting 403 Error**

**If you still get 403:**

1. **Check file permissions via FTP**
   - Right-click any PHP file → Properties
   - Permissions should be 644 (not 755)
   - Directories should be 755

2. **Disable .htaccess (temporary test)**
   - Rename `.htaccess` to `.htaccess.bak`
   - Try accessing site
   - If it works, your `.htaccess` has syntax errors

3. **Check database connection**
   - Create a test file: `test-db.php`
   - Add: `<?php require 'config/database.php'; echo 'Connected!'; ?>`
   - Visit `test-db.php`
   - Delete after testing

4. **Contact InfinityFree support**
   - They can check server logs
   - Ask them to verify mod_rewrite is enabled
   - Ask about PHP version compatibility

### **Step 5: Post-Deployment**

1. Create admin user via database
2. Configure git binary path (if available)
3. Test all roles and dashboards
4. Enable HTTPS in control panel
5. Set up automated backups

---

## Other Hosting Providers

### **Hostinger**
- Same steps as InfinityFree
- FTP: `ftp.yourdomain.com`
- MySQL included in most plans

### **Bluehost**
- Use their cPanel to manage files and database
- Upload to `public_html/erni-pms/`

### **AWS/DigitalOcean** (VPS)
- Full control - no restrictions
- Run database setup script provided

---

## Environment Variables (InfinityFree)

InfinityFree doesn't support `.env` files directly, so use `config/.env.php` instead:

```php
// Automatically detected:
$isInfinityFree = strpos(gethostname(), 'infinityfree') !== false;

// If detected, uses InfinityFree defaults
// Otherwise, uses your custom values
```

---

## Testing Checklist

- [ ] Site loads (no 403)
- [ ] Login page displays
- [ ] Can login with test user
- [ ] Admin dashboard works
- [ ] Database queries execute
- [ ] File uploads work (test folders.php)
- [ ] Git functionality works (if git binary available)
- [ ] CSS/JS assets load correctly
- [ ] Email notifications work (if configured)
- [ ] Logs are being written

---

## Common InfinityFree Issues

| Issue | Solution |
|-------|----------|
| 403 Forbidden | Check `.htaccess`, file permissions, database |
| Database connection error | Verify credentials in `config/.env.php` |
| Files not loading (CSS/JS) | Check BASE_URL in `config/database.php` |
| Uploads not working | Ensure `uploads/` has 755 permissions |
| White screen of death | Check `logs/error.log` |

