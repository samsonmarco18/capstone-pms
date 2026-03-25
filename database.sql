-- Create database
DROP DATABASE IF EXISTS erni_pms;
CREATE DATABASE erni_pms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE erni_pms;

-- =====================================================
-- USERS AND ROLES TABLE
-- =====================================================
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    department VARCHAR(100),
    role ENUM('admin', 'sales', 'architect', 'sul', 'expert', 'developer') NOT NULL,
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    profile_pic VARCHAR(255),
    phone VARCHAR(20),
    address TEXT,
    designation VARCHAR(100),
    last_login DATETIME NULL,
    email_notifications BOOLEAN DEFAULT TRUE,
    task_reminders BOOLEAN DEFAULT TRUE,
    project_updates BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_role (role),
    INDEX idx_status (status),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TECHNOLOGIES AND SERVICES TABLE
-- =====================================================
CREATE TABLE technologies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    category VARCHAR(100),
    price DECIMAL(12, 2),
    status ENUM('active', 'expiring', 'expired', 'inactive') DEFAULT 'active',
    expiry_date DATE,
    renewal_cost DECIMAL(12, 2),
    description TEXT,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_status (status),
    INDEX idx_category (category),
    INDEX idx_expiry_date (expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- CLIENTS TABLE
-- =====================================================
CREATE TABLE clients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    contact_person VARCHAR(100),
    email VARCHAR(100),
    phone VARCHAR(20),
    address TEXT,
    company_type VARCHAR(100),
    industry VARCHAR(100),
    status ENUM('active', 'inactive', 'suspended', 'pending') DEFAULT 'active',
    progress INT DEFAULT 0,
    deadline DATE,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- PROJECTS TABLE
-- =====================================================
CREATE TABLE projects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    status ENUM('pending', 'requirements_gathering', 'architectural_review', 'tech_recommendation', 'developer_allocation', 'arch_documentation', 'in_development', 'review', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
    timeline_start DATE,
    timeline_end DATE,
    meeting_notes TEXT,
    mom TEXT,
    requested_features LONGTEXT,
    complexity_level ENUM('simple', 'medium', 'complex') DEFAULT 'medium',
    created_by INT NOT NULL,
    architect_id INT,
    sul_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (architect_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (sul_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_client_id (client_id),
    INDEX idx_architect_id (architect_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TECHNOLOGY RECOMMENDATIONS TABLE
-- =====================================================
CREATE TABLE tech_recommendations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    tech_id INT NOT NULL,
    service_type VARCHAR(100),
    estimated_cost DECIMAL(12, 2),
    annual_cost DECIMAL(12, 2),
    quantity INT DEFAULT 1,
    notes TEXT,
    createdby INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_project_tech (project_id, tech_id),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (tech_id) REFERENCES technologies(id) ON DELETE RESTRICT,
    FOREIGN KEY (createdby) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- DEVELOPER ALLOCATION TABLE
-- =====================================================
CREATE TABLE developer_allocations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    role VARCHAR(100) NOT NULL,
    skill_level VARCHAR(50),
    required_count INT DEFAULT 1,
    allocated_count INT DEFAULT 0,
    complexity_level ENUM('simple', 'medium', 'complex') DEFAULT 'medium',
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_project_id (project_id),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- DEVELOPER ALLOCATION ASSIGNMENTS TABLE
-- =====================================================
CREATE TABLE developer_allocation_assignments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    allocation_id INT NOT NULL,
    developer_id INT NOT NULL,
    assigned_by INT NOT NULL,
    allocation_percentage INT DEFAULT 100,
    status ENUM('assigned', 'accepted', 'rejected', 'completed') DEFAULT 'assigned',
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME,
    completed_at DATETIME,
    FOREIGN KEY (allocation_id) REFERENCES developer_allocations(id) ON DELETE CASCADE,
    FOREIGN KEY (developer_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- ARCHITECTURE DOCUMENTS TABLE
-- =====================================================
CREATE TABLE architecture_documents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    document_type ENUM('architecture_plan', 'workflow_diagram', 'system_design', 'technical_spec') NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    description TEXT,
    uploaded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_project_id (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TASKS AND ESTIMATION TABLE
-- =====================================================
CREATE TABLE tasks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description LONGTEXT,
    task_type ENUM('feature', 'bug_fix', 'enhancement', 'documentation') DEFAULT 'feature',
    assigned_to INT,
    assigned_by INT,
    status ENUM('todo', 'in_progress', 'in_review', 'review', 'completed', 'overdue', 'blocked') DEFAULT 'todo',
    priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    estimated_days_min INT,
    estimated_days_likely INT,
    estimated_days_max INT,
    actual_days INT,
    estimated_effort_hours INT,
    actual_effort_hours INT,
    deadline DATE,
    started_at DATETIME,
    completed_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_project_id (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- FEATURE ESTIMATIONS TABLE (FOR EXPERTS)
-- =====================================================
CREATE TABLE feature_estimations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    task_id INT NOT NULL,
    expert_id INT NOT NULL,
    min_days INT,
    likely_days INT,
    max_days INT,
    expectation_description LONGTEXT,
    status ENUM('pending', 'in_progress', 'completed', 'submitted') DEFAULT 'pending',
    submitted_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (expert_id) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY unique_task_expert (task_id, expert_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TEAM COMPOSITION TABLE
-- =====================================================
CREATE TABLE team_compositions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    role VARCHAR(100) NOT NULL,
    required_count INT DEFAULT 1,
    allocation_percentage INT DEFAULT 100,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_project_id (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- COST ESTIMATIONS TABLE
-- =====================================================
CREATE TABLE cost_estimations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    component VARCHAR(150) NOT NULL,
    azure_service VARCHAR(150),
    monthly_cost DECIMAL(12, 2),
    annual_cost DECIMAL(12, 2),
    estimated_monthly_cost DECIMAL(12, 2),
    estimated_annual_cost DECIMAL(12, 2),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_project_id (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- SALES REPORTS TABLE
-- =====================================================
CREATE TABLE sales_reports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    client_id INT NOT NULL,
    report_date DATE DEFAULT (CURDATE()),
    technology_cost DECIMAL(12, 2) DEFAULT 0,
    developer_cost DECIMAL(12, 2) DEFAULT 0,
    resource_cost DECIMAL(12, 2) DEFAULT 0,
    other_fees JSON,
    total_cost DECIMAL(12, 2) DEFAULT 0,
    currency VARCHAR(10) DEFAULT 'PHP',
    status ENUM('draft', 'submitted', 'approved', 'invoiced') DEFAULT 'draft',
    notes TEXT,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_status (status),
    INDEX idx_project_id (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- RECEIPTS TABLE
-- =====================================================
CREATE TABLE receipts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    report_id INT NOT NULL,
    receipt_number VARCHAR(50) UNIQUE NOT NULL,
    client_name VARCHAR(150),
    client_email VARCHAR(100),
    file_path VARCHAR(500),
    file_name VARCHAR(255),
    amount_due DECIMAL(12, 2),
    amount_paid DECIMAL(12, 2) DEFAULT 0,
    due_date DATE,
    status ENUM('draft', 'sent', 'viewed', 'paid', 'overdue') DEFAULT 'draft',
    generated_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (report_id) REFERENCES sales_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_status (status),
    INDEX idx_due_date (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- NOTIFICATIONS TABLE
-- =====================================================
CREATE TABLE notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT,
    notification_type ENUM('task', 'project', 'deadline', 'system', 'approval', 'message', 'group') DEFAULT 'system',
    related_table VARCHAR(50),
    related_id INT,
    is_read BOOLEAN DEFAULT FALSE,
    action_url VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_is_read (is_read),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- SCHEDULES/CALENDAR TABLE
-- =====================================================
CREATE TABLE schedules (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    project_id INT,
    task_id INT,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    schedule_type ENUM('meeting', 'deadline', 'milestone', 'review', 'holiday', 'personal') DEFAULT 'meeting',
    start_date DATE,
    start_time TIME,
    end_date DATE,
    end_time TIME,
    location VARCHAR(255),
    color_code VARCHAR(7) DEFAULT '#007bff',
    status ENUM('scheduled', 'in_progress', 'completed', 'cancelled') DEFAULT 'scheduled',
    reminder_days INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_start_date (start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- FOLDERS TABLE (FILE MANAGEMENT)
-- =====================================================
CREATE TABLE folders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    parent_id INT,
    name VARCHAR(255) NOT NULL,
    folder_type ENUM('client', 'role', 'user', 'project', 'general') DEFAULT 'general',
    client_id INT,
    project_id INT,
    role VARCHAR(100),
    user_id INT,
    description TEXT,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES folders(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_client_id (client_id),
    INDEX idx_project_id (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- FILES TABLE (FILE MANAGEMENT)
-- =====================================================
CREATE TABLE files (
    id INT PRIMARY KEY AUTO_INCREMENT,
    folder_id INT NOT NULL,
    original_file_name VARCHAR(500) NOT NULL,
    stored_file_name VARCHAR(500) NOT NULL,
    file_type VARCHAR(100),
    file_size BIGINT,
    file_path VARCHAR(500) NOT NULL,
    description TEXT,
    version INT DEFAULT 1,
    uploaded_by INT NOT NULL,
    downloaded_count INT DEFAULT 0,
    is_archived BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_folder_id (folder_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- CHAT GROUPS TABLE
-- =====================================================
CREATE TABLE chat_groups (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    group_type ENUM('direct', 'group', 'project_team') DEFAULT 'group',
    project_id INT,
    profile_image VARCHAR(255),
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- CHAT GROUP MEMBERS TABLE
-- =====================================================
CREATE TABLE chat_group_members (
    id INT PRIMARY KEY AUTO_INCREMENT,
    group_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('member', 'moderator', 'admin') DEFAULT 'member',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    left_at DATETIME,
    UNIQUE KEY unique_group_user (group_id, user_id),
    FOREIGN KEY (group_id) REFERENCES chat_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- MESSAGES (CHAT) TABLE
-- =====================================================
CREATE TABLE messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sender_id INT NOT NULL,
    receiver_id INT,
    group_id INT,
    message LONGTEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    file_id INT,
    message_type ENUM('text', 'file', 'image', 'notification') DEFAULT 'text',
    read_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (group_id) REFERENCES chat_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE SET NULL,
    INDEX idx_group_id (group_id),
    INDEX idx_sender_id (sender_id),
    INDEX idx_receiver_id (receiver_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- AUDIT LOG TABLE
-- =====================================================
CREATE TABLE audit_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    table_name VARCHAR(100),
    record_id INT,
    old_data JSON,
    new_data JSON,
    ip_address VARCHAR(45),
    user_agent VARCHAR(500),
    status ENUM('success', 'failed', 'warning') DEFAULT 'success',
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- SETTINGS TABLE
-- =====================================================
CREATE TABLE settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value LONGTEXT,
    description TEXT,
    setting_type ENUM('system', 'user', 'role') DEFAULT 'system',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- PERFORMANCE METRICS TABLE
-- =====================================================
CREATE TABLE performance_metrics (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    project_id INT,
    metric_date DATE DEFAULT (CURDATE()),
    tasks_completed INT DEFAULT 0,
    tasks_overdue INT DEFAULT 0,
    tasks_in_progress INT DEFAULT 0,
    hours_logged INT DEFAULT 0,
    completion_rate DECIMAL(5, 2) DEFAULT 0,
    quality_score DECIMAL(5, 2) DEFAULT 0,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_metric_date (metric_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TASK COMMENTS TABLE
-- =====================================================
CREATE TABLE task_comments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    task_id INT NOT NULL,
    user_id INT NOT NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_task_id (task_id),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- INSERT SAMPLE DATA FOR TESTING
-- =====================================================

-- Insert default admin user (password: password)
INSERT INTO users (username, email, password, full_name, department, role, status, designation) VALUES
('admin', 'admin@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'Administration', 'admin', 'active', 'Admin');

-- Insert Sample Users for Each Role (password: password)
INSERT INTO users (username, email, password, full_name, department, role, status, designation) VALUES
('sales_user', 'sales@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'John Sales Manager', 'Sales', 'sales', 'active', 'Sales Manager'),
('architect', 'architect@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Maria Architect', 'Architecture', 'architect', 'active', 'System Architect'),
('sul_user', 'sul@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Robert SUL', 'Service Unit', 'sul', 'active', 'Service Unit Leader'),
('expert', 'expert@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Dr. Expert Senior', 'Development', 'expert', 'active', 'Senior Expert'),
('developer', 'developer@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'David Developer', 'Development', 'developer', 'active', 'Software Developer');

-- Insert Technologies
INSERT INTO technologies (name, category, price, status, expiry_date, renewal_cost, description, created_by) VALUES
('Azure Web App Service', 'Cloud Services', 150.00, 'active', '2027-03-23', 150.00, 'Scalable web app hosting', 1),
('SQL Server Database', 'Databases', 500.00, 'active', '2027-03-23', 500.00, 'Enterprise database solution', 1),
('Azure Storage', 'Cloud Services', 100.00, 'active', '2027-03-23', 100.00, 'Cloud storage solution', 1),
('React.js Framework', 'Frontend', 0.00, 'active', '2030-12-31', 0.00, 'JavaScript library for UI', 1),
('Node.js Runtime', 'Backend', 0.00, 'active', '2030-12-31', 0.00, 'JavaScript runtime', 1),
('Azure App Service', 'Cloud Platform', 100.00, 'active', DATE_ADD(NOW(), INTERVAL 6 MONTH), 100.00, 'Cloud app hosting', 1),
('Azure SQL Database', 'Database', 150.00, 'active', DATE_ADD(NOW(), INTERVAL 8 MONTH), 150.00, 'SQL Database service', 1);

-- Insert Sample Clients
INSERT INTO clients (name, contact_person, email, phone, address, company_type, industry, status, deadline, created_by) VALUES
('TechCorp Solutions', 'Juan Dela Cruz', 'juan@techcorp.com', '+63 2 1234 5678', 'Manila, Philippines', 'Corporation', 'Technology', 'active', '2026-12-31', 1),
('Healthcare Inc', 'Maria Santos', 'maria@healthcare.com', '+63 917 1234 567', 'Quezon City, Philippines', 'Company', 'Healthcare', 'active', '2026-12-15', 1);

-- Insert Sample Projects
INSERT INTO projects (client_id, name, description, status, timeline_start, timeline_end, complexity_level, created_by, architect_id, sul_id) VALUES
(1, 'E-Commerce Platform', 'Complete e-commerce solution with payment gateway', 'requirements_gathering', '2026-03-01', '2026-12-31', 'complex', 1, 3, 4),
(2, 'Patient Management System', 'Hospital management and patient tracking system', 'pending', '2026-04-01', '2027-03-31', 'complex', 1, NULL, NULL);

-- Default Settings
INSERT INTO settings (setting_key, setting_value, description, setting_type) VALUES
('company_name', 'ERNI Development Center Philippines Inc.', 'Company official name', 'system'),
('system_theme', 'light', 'System theme preference', 'system'),
('notification_email', 'noreply@erni-pms.com', 'System notification email', 'system'),
('timezone', 'Asia/Manila', 'Default system timezone', 'system'),
('currency', 'PHP', 'Default currency for pricing', 'system');
