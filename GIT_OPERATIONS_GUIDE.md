# Git Operations on Shared Hosting

## Issue: Git Operations Not Available

If you see the message **"Git Operations Not Available"** on the Version Control page, it means your hosting provider has disabled the `exec()` PHP function.

### Why This Happens

Shared hosting providers like InfinityFree often disable `exec()`, `shell_exec()`, and other system execution functions for security reasons. These functions are required to run Git commands from PHP.

### What's Affected

When `exec()` is disabled, you **cannot**:
- Create repositories via ERNI PMS UI
- Create working folders automatically  
- Merge branches through the web interface
- Sync repositories automatically

### What You Can Still Do

✅ **You can still**:
- Clone repositories to your local machine using the **HTTP Clone URL** (provided by ERNI PMS)
- Use Git commands locally on your computer
- Manually push changes back to the server via SFTP/FTP
- View repository history and commits in ERNI PMS

### Solutions

#### Option 1: Clone Repository Locally (Recommended)

1. Go to Version Control → Repositories → select your project
2. Find the **HTTP Clone URL** 
3. On your local machine, run:
   ```bash
   git clone [HTTP_CLONE_URL]
   cd [repository-name]
   git remote add origin [HTTP_CLONE_URL]
   ```
4. Make changes, commit, and push:
   ```bash
   git add .
   git commit -m "Your message"
   git push origin main
   ```

#### Option 2: Request Hosting Upgrade

Contact your hosting provider:
- **InfinityFree**: Create a support ticket requesting `exec()` to be enabled
- **Other providers**: Request `exec`, `shell_exec`, `passthru`, and `system` functions to be enabled for your account

#### Option 3: Switch to a VPS/Dedicated Server

Providers that typically support Git operations:
- **Heroku** - Easy git deployments (free tier available)
- **DigitalOcean** - Affordable VPS ($4-5/month)
- **Linode** - Powerful VPS ($5+/month)
- **AWS EC2** - Scalable cloud hosting
- **Contabo** - Budget VPS ($3.99+/month)

### Manual Repository Setup on Shared Hosting

If you need git operations on shared hosting, you can:

1. **Initialize repository manually via FTP/SFTP**:
   - Upload git directory structure to server
   - Configure `.git` hooks manually
   - Use FTP/SFTP for file uploads instead of git push

2. **Use ERNI PMS file upload features**:
   - Upload files through the web interface
   - Use the Folders module for file management
   - ERNI PMS will track changes in the database

### Testing Your Server

To check if `exec()` is available:

1. Visit `https://yourdomain.com/check-deployment.php`
2. Look for the line checking "exec function"
3. If it shows red/disabled, git operations won't work

### Workaround: HTTP-Only Workflow

Without git execution, use this workflow:

1. **Development**: Work locally with git
2. **Testing**: Deploy to staging server via upload
3. **Production**: Use FTP/SFTP to upload tested files
4. **Tracking**: Record versions/deployments in ERNI PMS

### Questions?

- **For ERNI PMS issues**: Contact your application administrator
- **For hosting issues**: Contact your hosting provider's support
- **For git questions**: Visit [Git Documentation](https://git-scm.com/doc)

---

**Note**: This is a common limitation on shared hosting. Most professional deployments use VPS or dedicated servers where full system access is available.
