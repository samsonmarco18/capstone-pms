# ERNI Project Management System (ERNI-PMS)

## Capstone Project
**Title:** A Decision-Driven Project Management Support System for ERNI Development Center Philippines, Incorporation

**Description:**
ERNI Development Center Philippines, Incorporation is a company engaged in providing software solutions to various clients. This system aims to develop a Decision-Driven Project Management Support System to improve the efficiency, accuracy, and reliability of project-related decisions.

---

## System Overview

### Features
- **Role-Based Access Control:** 6 different user roles with dedicated dashboards and features
- **Project Management:** Complete project lifecycle management from requirements to completion
- **Task Assignment & Tracking:** Assign tasks, track progress, and monitor performance
- **Real-Time Messaging:** Integrated chat system with group and direct messaging
- **File Management:** Organized file storage by client, project, role, and team member
- **Cost Estimation:** Azure-based infrastructure cost calculator
- **Architecture Documentation:** Support for uploading architecture plans and diagrams
- **Audit Logging:** Complete audit trail for all system actions
- **Dark Mode:** Professional dark mode support for all pages
- **Responsive Design:** Mobile-first responsive design

---

## Technology Stack

### Backend
- **PHP 7.4+** - Server-side programming
- **MySQL 5.7+** - Database management
- **XAMPP** - Local development environment

### Frontend
- **HTML5** - Semantic markup
- **CSS3** - Professional styling
- **Bootstrap 5.1.3** - Responsive framework
- **Chart.js** - Data visualization
- **Frappe Gantt** - Gantt chart for task scheduling
- **jQuery 3.6.0** - DOM manipulation
- **Font Awesome 6.0.0** - Icons

### Design
- **Skyblue/Teal Theme:** Professional corporate colors
- **Color Scheme:**
  - Primary: #0d556b
  - Secondary: #1a7e8f
  - Success: #28a745
  - Danger: #dc3545

---

## System Roles & Responsibilities

### 1. Administrator
- **Dashboard:** System overview with statistics and graphs
- **Tech Stack Management:** Add, edit, delete, and manage technologies/services
- **User Management:** Create, edit, delete user accounts
- **Audit Log:** Monitor all system activities
- **Settings:** System configuration

### 2. Sales Manager
- **Dashboard:** Activity overview, client stats, and schedule
- **Clients Management:** Manage client information and project progress
- **Reports:** Generate cost and resource reports
- **Receipts:** Generate and download payment receipts

### 3. System Architect
- **Dashboard:** Project overview and schedule
- **Projects Management:**
  - Project Review: Analyze requirements
  - Technology Stack: Recommend tools and technologies
  - Developer Allocation: Identify required development team
  - Architecture Documentation: Upload diagrams and plans
  - Summary: Review and submit for approval

### 4. Service Unit Leader (SUL)
- **Dashboard:** Team overview and task assignments
- **Task Allocation:** Assign tasks to experts/developers
- **Resource Management:** Monitor team availability and workload
- **Performance Report:** Track team performance and metrics

### 5. Expert/Senior
- **Dashboard:** Project estimation overview
- **Feature Estimation:** Estimate effort for features (3-point estimation)
- **Team Composition:** Define required team structure
- **Cost Estimation:** Calculate Azure infrastructure costs

### 6. Developer
- **Dashboard:** Task overview, performance metrics, and schedule
- **Task Management:** Manage assigned tasks with kanban board
- **Task Completion:** Update task status and submit deliverables

---

## Installation Guide

### Prerequisites
- XAMPP (with PHP 7.4+, MySQL 5.7+)
- Modern web browser
- Git (optional)

### Step 1: Setup Database

1. **Start XAMPP Services**
   - Start Apache and MySQL modules

2. **Create Database**
   - Open phpMyAdmin: `http://localhost/phpmyadmin`
   - Import the `database.sql` file:
     - Click "Import" tab
     - Select `database.sql` file
     - Click "Go"

3. **Verify Database Creation**
   ```sql
   USE erni_pms;
   SHOW TABLES;
   ```

### Step 2: Configure Application

1. **Update Database Configuration** (if needed)
   - Edit `config/database.php`
   - Ensure credentials match your XAMPP MySQL setup

2. **Default Credentials**
   ```
   Email: admin@erni-pms.com
   Password: (Use password_hash('password123', PASSWORD_BCRYPT))
   Role: Admin
   ```

### Step 3: Access Application

1. **Open in Browser**
   ```
   http://localhost/erni-pms/
   ```

2. **Login**
   - Use the sample credentials provided
   - Or create new accounts via User Management

---

## Sample Test Accounts

### Admin
```
Email: admin@erni-pms.com
Password: test123
```

### Sales Manager
```
Email: sales@erni-pms.com
Password: test123
```

### Architect
```
Email: architect@erni-pms.com
Password: test123
```

### Service Unit Leader
```
Email: sul@erni-pms.com
Password: test123
```

### Expert/Senior
```
Email: expert@erni-pms.com
Password: test123
```

### Developer
```
Email: developer@erni-pms.com
Password: test123
```

---

## Project Structure

```
erni-pms/
├── api/                          # API endpoints
│   ├── change-password.php
│   ├── get-profile.php
│   ├── chat/
│   ├── folders/
│   └── notifications/
├── assets/                       # Static assets
│   ├── css/
│   │   ├── style.css            # Main styles
│   │   └── dark-mode.css        # Dark mode styles
│   ├── images/
│   ├── js/
│   │   ├── main.js
│   │   ├── chat.js
│   │   └── charts.js
├── config/                       # Configuration files
│   └── database.php
├── includes/                     # Reusable components
│   ├── auth.php                 # Authentication functions
│   ├── header.php               # HTML header
│   ├── navbar.php               # Navigation bar
│   ├── sidebar.php              # Sidebar menu
│   ├── footer.php               # Footer
│   └── chat-widget.php          # Chat widget
├── modules/                      # Role-based modules
│   ├── admin/
│   │   ├── dashboard.php
│   │   ├── techstack.php
│   │   ├── user-management.php
│   │   ├── audit-log.php
│   │   └── settings.php
│   ├── sales/
│   │   ├── dashboard.php
│   │   ├── clients.php
│   │   ├── report.php
│   │   └── receipt.php
│   ├── architect/
│   │   ├── dashboard.php
│   │   ├── projects.php
│   │   ├── project-review.php
│   │   ├── tech-recommendation.php
│   │   ├── developer-allocation.php
│   │   ├── upload-diagram.php
│   │   └── summary.php
│   ├── sul/
│   │   ├── dashboard.php
│   │   ├── task-allocation.php
│   │   ├── resource-management.php
│   │   └── performance-report.php
│   ├── expert/
│   │   ├── dashboard.php
│   │   ├── feature-estimation.php
│   │   ├── team-composition.php
│   │   └── cost-estimation.php
│   └── developer/
│       ├── dashboard.php
│       └── task.php
├── uploads/                      # Uploaded files
│   ├── architecture-plans/
│   ├── diagrams/
│   └── receipts/
├── database.sql                 # Database schema
├── login.php                    # Login page
├── logout.php                   # Logout handler
├── index.php                    # Main entry point
└── README.md                    # This file
```

---

## Key Features Implementation

### Authentication & Authorization
- Role-based access control (RBAC)
- Session management
- Activity logging
- Password hashing with bcrypt

### Database Design
- Normalized schema with proper relationships
- Indexed tables for performance
- Comprehensive audit logging
- Transaction support for critical operations

### User Interface
- Professional responsive design
- Mobile-first approach
- Accessibility compliance
- Dark mode support
- Smooth animations and transitions

### Communication
- Real-time messaging system
- Group chat support
- Direct messaging
- Message notifications

### File Management
- Organized folder structure
- File versioning
- Upload tracking
- Access control

---

## Security Considerations

1. **Password Security**
   - Bcrypt hashing with cost factor 10
   - No plain text storage

2. **Input Validation**
   - Server-side validation for all inputs
   - Prepared statements to prevent SQL injection

3. **Session Management**
   - Secure session handling
   - Session timeout implementation
   - CSRF token protection (recommended)

4. **Access Control**
   - Role-based permissions
   - Resource-level access checks
   - Audit logging of sensitive operations

---

## Performance Optimization

1. **Database**
   - Proper indexing on frequently queried columns
   - Query optimization
   - Connection pooling

2. **Frontend**
   - Minified CSS and JavaScript
   - Image optimization
   - Lazy loading for heavy components
   - Caching strategies

3. **Code**
   - Object-oriented PHP
   - Reusable components
   - Efficient DOM manipulation

---

## Troubleshooting

### Database Connection Error
- **Solution:** Verify MySQL is running and credentials in `config/database.php` are correct

### Login Issues
- **Solution:** Ensure database is properly imported and sample data is inserted

### Image Not Loading
- **Solution:** Check the `assets/` folder permissions and path references

### Chat Not Working
- **Solution:** Verify API endpoints exist and are accessible

---

## Future Enhancements

1. Email notification system
2. Advanced reporting with export to PDF/Excel
3. Integration with Azure API for real-time cost calculation
4. Mobile application
5. Advanced search and filtering
6. Custom dashboard widgets
7. Workflow automation
8. Resource allocation optimization
9. Advanced analytics and reporting
10. Integration with third-party services (Slack, Teams, etc.)

---

## Support & Maintenance

### Regular Maintenance
- Database backups
- Log file cleanup
- Performance monitoring
- Security updates

### Documentation
- API documentation
- User manual
- Administrator guide
- Developer guide

---

## License & Credits

**Project:** ERNI Development Center Philippines, Incorporation
**Study Type:** Capstone Project
**Academic Institution:** [Your Institution Name]
**Development Year:** 2026

---

## Contact & Support

For support, issues, or contributions:
- **Email:** support@erni-pms.com
- **Website:** www.erni-pms.com

---

**Last Updated:** March 23, 2026
**Version:** 1.0.0
