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
    role ENUM('admin', 'sales', 'architect', 'sul', 'qa', 'expert', 'developer') NOT NULL,
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    profile_pic VARCHAR(255),
    phone VARCHAR(20),
    address TEXT,
    designation VARCHAR(100),
    password_changed_at DATETIME NULL,
    last_login DATETIME NULL,
    email_notifications BOOLEAN DEFAULT TRUE,
    task_reminders BOOLEAN DEFAULT TRUE,
    project_updates BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_role (role),
    INDEX idx_department (department),
    INDEX idx_role_department_status (role, department, status),
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
    timeline_start_time TIME NULL,
    timeline_end DATE,
    timeline_end_time TIME NULL,
    project_phases LONGTEXT,
    methodology_template VARCHAR(80) NULL,
    meeting_notes TEXT,
    mom TEXT,
    requested_features LONGTEXT,
    included_departments LONGTEXT,
    sales_budget DECIMAL(15, 2) NULL,
    cloud_platform VARCHAR(100),
    database_server VARCHAR(100),
    storage_requirements VARCHAR(255),
    additional_services TEXT,
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
    complexity_level ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_project_id (project_id),
    INDEX idx_role (role),
    INDEX idx_project_role (project_id, role)
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
-- METHODOLOGY ROADMAP PHASES TABLE
-- =====================================================
CREATE TABLE methodology_phases (
    id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    developer_count INT DEFAULT 0,
    sequence_no INT DEFAULT 0,
    status ENUM('planned', 'in_progress', 'completed', 'on_hold') DEFAULT 'planned',
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_methodology_phase_project (project_id),
    INDEX idx_methodology_phase_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- METHODOLOGY ROADMAP TASKS TABLE
-- =====================================================
CREATE TABLE methodology_tasks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    phase_id INT NOT NULL,
    project_id INT NOT NULL,
    task_id INT NULL,
    title VARCHAR(255) NOT NULL,
    component_name VARCHAR(150),
    department VARCHAR(100),
    description TEXT,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    assigned_developer_id INT NULL,
    expert_people_count INT DEFAULT 0,
    expert_developer_type VARCHAR(100) NULL,
    expert_notes TEXT,
    status ENUM('planned', 'in_progress', 'completed', 'blocked') DEFAULT 'planned',
    created_by INT NULL,
    updated_by INT NULL,
    expert_updated_by INT NULL,
    expert_updated_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (phase_id) REFERENCES methodology_phases(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_developer_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (expert_updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_methodology_task_project (project_id),
    INDEX idx_methodology_task_phase (phase_id),
    INDEX idx_methodology_task_workflow_task (task_id),
    INDEX idx_methodology_task_dates (start_date, end_date),
    INDEX idx_methodology_task_developer (assigned_developer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- ARCHITECTURE DOCUMENTS TABLE
-- =====================================================
CREATE TABLE architecture_documents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    document_type ENUM('architecture_plan', 'workflow_diagram', 'system_flow', 'deployment_diagram', 'database_schema') NOT NULL,
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
    status ENUM('todo', 'in_progress', 'in_review', 'review', 'for_review', 'sul_revision', 'qa_testing', 'qa_failed', 'qa_passed', 'architect_review', 'merged', 'ready_for_release', 'completed', 'overdue', 'blocked') DEFAULT 'todo',
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
    sales_approved TINYINT(1) DEFAULT 0,
    sales_approved_by INT,
    sales_approved_at DATETIME,
    sales_estimate_choice ENUM('min','likely','max') DEFAULT NULL,
    sales_estimate_days INT DEFAULT NULL,
    pr_submitted_at DATETIME NULL,
    sul_reviewed_by INT NULL,
    sul_reviewed_at DATETIME NULL,
    qa_reviewed_by INT NULL,
    qa_reviewed_at DATETIME NULL,
    architect_reviewed_by INT NULL,
    architect_reviewed_at DATETIME NULL,
    merged_at DATETIME NULL,
    release_notified_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_project_id (project_id),
    INDEX idx_project_status_assigned (project_id, status, assigned_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TASK DEPARTMENT ARTIFACTS TABLE
-- =====================================================
CREATE TABLE task_department_artifacts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    task_id INT NOT NULL,
    project_id INT NOT NULL,
    user_id INT NULL,
    department VARCHAR(100) NOT NULL,
    artifact_type VARCHAR(50) NOT NULL DEFAULT 'department_handoff',
    title VARCHAR(255) NOT NULL,
    file_path VARCHAR(500),
    original_file_name VARCHAR(255),
    file_type VARCHAR(100),
    file_size BIGINT,
    external_url VARCHAR(500),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_task_id (task_id),
    INDEX idx_project_id (project_id),
    INDEX idx_department (department)
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
    phase_name VARCHAR(150),
    involvement_plan_json LONGTEXT,
    component_plan_json LONGTEXT,
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
-- FEATURE ESTIMATION MANUAL RECOMMENDATIONS TABLE
-- =====================================================
CREATE TABLE feature_estimation_manual_recommendations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    feature_signature VARCHAR(190) NOT NULL,
    feature_title VARCHAR(255) NOT NULL,
    recommendation_name VARCHAR(255) NOT NULL,
    source_project_id INT NULL,
    source_task_id INT NULL,
    phase_name VARCHAR(150),
    expectation_description LONGTEXT,
    min_days INT NOT NULL,
    likely_days INT NOT NULL,
    max_days INT NOT NULL,
    involvement_plan_json LONGTEXT,
    component_plan_json LONGTEXT,
    created_by INT NOT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_manual_feature_recommendation (created_by, feature_signature, recommendation_name),
    KEY idx_manual_feature_signature (feature_signature),
    FOREIGN KEY (source_project_id) REFERENCES projects(id) ON DELETE SET NULL,
    FOREIGN KEY (source_task_id) REFERENCES tasks(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- FEATURE ESTIMATION RECOMMENDATION FEEDBACK TABLE
-- =====================================================
CREATE TABLE feature_estimation_recommendation_feedback (
    id INT PRIMARY KEY AUTO_INCREMENT,
    task_id INT NOT NULL,
    source_type ENUM('history', 'manual') NOT NULL,
    source_id INT NOT NULL,
    action ENUM('accepted', 'rejected') NOT NULL,
    acted_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_task_recommendation_feedback (task_id, source_type, source_id),
    KEY idx_feedback_task (task_id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (acted_by) REFERENCES users(id) ON DELETE CASCADE
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
    last_read_message_id INT NOT NULL DEFAULT 0,
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
-- QA CHECKLIST ITEMS TABLE
-- =====================================================
CREATE TABLE qa_checklist_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    task_id INT NOT NULL,
    feature_name VARCHAR(150) NOT NULL,
    item_text VARCHAR(255) NOT NULL,
    status ENUM('pending', 'passed', 'failed', 'needs_revision') DEFAULT 'pending',
    notes TEXT,
    developer_id INT NULL,
    qa_user_id INT NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (developer_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (qa_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_task_id (task_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TASK GIT UPDATES TABLE
-- =====================================================
CREATE TABLE task_git_updates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    task_id INT NOT NULL,
    user_id INT NOT NULL,
    repository_url VARCHAR(255),
    branch_name VARCHAR(150) NOT NULL,
    commit_hash VARCHAR(64),
    commit_message VARCHAR(255),
    pull_request_url VARCHAR(255),
    git_action ENUM('branch_created', 'commit_pushed', 'pull_request_opened', 'merged', 'deployed', 'note') DEFAULT 'commit_pushed',
    status ENUM('active', 'in_review', 'merged', 'closed') DEFAULT 'active',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_task_id (task_id),
    INDEX idx_user_id (user_id),
    INDEX idx_branch_name (branch_name),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- PROJECT REPOSITORIES TABLE
-- =====================================================
CREATE TABLE project_repositories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    project_id INT NOT NULL,
    parent_repository_id INT NULL,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(180) NOT NULL,
    description TEXT,
    external_remote_url VARCHAR(255),
    sync_mode ENUM('manual', 'git_hook', 'provider_webhook') DEFAULT 'manual',
    sync_provider VARCHAR(50),
    webhook_token VARCHAR(80) NOT NULL,
    visibility ENUM('private', 'internal', 'public') DEFAULT 'internal',
    default_branch_name VARCHAR(150) DEFAULT 'main',
    repository_status ENUM('active', 'archived') DEFAULT 'active',
    last_activity_at DATETIME NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_project_repo_slug (project_id, slug),
    UNIQUE KEY unique_repository_webhook_token (webhook_token),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_repository_id) REFERENCES project_repositories(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_project_id (project_id),
    INDEX idx_created_by (created_by),
    INDEX idx_repo_status (repository_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- REPOSITORY BRANCHES TABLE
-- =====================================================
CREATE TABLE repository_branches (
    id INT PRIMARY KEY AUTO_INCREMENT,
    repository_id INT NOT NULL,
    source_branch_id INT NULL,
    name VARCHAR(150) NOT NULL,
    last_commit_hash VARCHAR(64),
    branch_status ENUM('active', 'merged', 'deleted') DEFAULT 'active',
    is_default BOOLEAN DEFAULT FALSE,
    is_protected BOOLEAN DEFAULT FALSE,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_repository_branch (repository_id, name),
    FOREIGN KEY (repository_id) REFERENCES project_repositories(id) ON DELETE CASCADE,
    FOREIGN KEY (source_branch_id) REFERENCES repository_branches(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_repository_id (repository_id),
    INDEX idx_branch_status (branch_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- REPOSITORY COMMITS TABLE
-- =====================================================
CREATE TABLE repository_commits (
    id INT PRIMARY KEY AUTO_INCREMENT,
    repository_id INT NOT NULL,
    branch_id INT NOT NULL,
    parent_commit_id INT NULL,
    commit_hash VARCHAR(64) NOT NULL UNIQUE,
    commit_title VARCHAR(255) NOT NULL,
    commit_message TEXT,
    files_changed INT DEFAULT 0,
    lines_added INT DEFAULT 0,
    lines_removed INT DEFAULT 0,
    commit_kind ENUM('commit', 'merge', 'sync') DEFAULT 'commit',
    authored_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (repository_id) REFERENCES project_repositories(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES repository_branches(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_commit_id) REFERENCES repository_commits(id) ON DELETE SET NULL,
    FOREIGN KEY (authored_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_commit_repository_id (repository_id),
    INDEX idx_commit_branch_id (branch_id),
    INDEX idx_commit_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- REPOSITORY COMMIT FILES TABLE
-- =====================================================
CREATE TABLE repository_commit_files (
    id INT PRIMARY KEY AUTO_INCREMENT,
    commit_id INT NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    previous_file_path VARCHAR(500) NULL,
    change_type ENUM('added', 'modified', 'deleted', 'renamed') DEFAULT 'modified',
    additions INT DEFAULT 0,
    deletions INT DEFAULT 0,
    diff_excerpt LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (commit_id) REFERENCES repository_commits(id) ON DELETE CASCADE,
    INDEX idx_commit_file_commit_id (commit_id),
    INDEX idx_commit_file_change_type (change_type),
    INDEX idx_commit_file_path (file_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- REPOSITORY MERGES TABLE
-- =====================================================
CREATE TABLE repository_merges (
    id INT PRIMARY KEY AUTO_INCREMENT,
    repository_id INT NOT NULL,
    source_branch_id INT NOT NULL,
    target_branch_id INT NOT NULL,
    merge_commit_id INT NULL,
    merged_by INT NOT NULL,
    merge_status ENUM('pending', 'merged', 'rejected') DEFAULT 'merged',
    summary VARCHAR(255),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (repository_id) REFERENCES project_repositories(id) ON DELETE CASCADE,
    FOREIGN KEY (source_branch_id) REFERENCES repository_branches(id) ON DELETE RESTRICT,
    FOREIGN KEY (target_branch_id) REFERENCES repository_branches(id) ON DELETE RESTRICT,
    FOREIGN KEY (merge_commit_id) REFERENCES repository_commits(id) ON DELETE SET NULL,
    FOREIGN KEY (merged_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_merge_repository_id (repository_id),
    INDEX idx_merge_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- REPOSITORY OPERATIONS TABLE
-- =====================================================
CREATE TABLE repository_operations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    repository_id INT NOT NULL,
    branch_id INT NULL,
    commit_id INT NULL,
    user_id INT NOT NULL,
    operation_type ENUM('create_repository', 'fork_repository', 'create_branch', 'commit', 'push', 'pull', 'merge') NOT NULL,
    related_repository_id INT NULL,
    related_branch_id INT NULL,
    metadata LONGTEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (repository_id) REFERENCES project_repositories(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES repository_branches(id) ON DELETE SET NULL,
    FOREIGN KEY (commit_id) REFERENCES repository_commits(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (related_repository_id) REFERENCES project_repositories(id) ON DELETE SET NULL,
    FOREIGN KEY (related_branch_id) REFERENCES repository_branches(id) ON DELETE SET NULL,
    INDEX idx_operation_repository_id (repository_id),
    INDEX idx_operation_branch_id (branch_id),
    INDEX idx_operation_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- INSERT SAMPLE DATA FOR TESTING
-- =====================================================

-- Insert default admin user (password: password)
INSERT INTO users (username, email, password, full_name, department, role, status, designation) VALUES
('admin', 'admin@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', NULL, 'admin', 'active', 'Admin');

-- Insert Sample Users for Each Role (password: password)
INSERT INTO users (username, email, password, full_name, department, role, status, designation) VALUES
('sales_user', 'sales@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'John Sales Manager', NULL, 'sales', 'active', 'Sales Manager'),
('architect', 'architect@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Maria Architect', NULL, 'architect', 'active', 'System Architect'),
('sul_user', 'sul@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Robert Frontend SUL', 'Frontend Developer', 'sul', 'active', 'Service Unit Leader'),
('qa_user', 'qa@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Quinn QA Engineer', 'QA Engineer', 'qa', 'active', 'QA Engineer'),
('expert', 'expert@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Dr. Frontend Expert', 'Frontend Developer', 'expert', 'active', 'Frontend Expert'),
('developer', 'developer@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'David Frontend Developer', 'Frontend Developer', 'developer', 'active', 'Frontend Developer');

-- Department dummy teams (password: password)
INSERT INTO users (username, email, password, full_name, department, role, status, designation) VALUES
('expert_sul_1', 'expert.sul@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Evelyn Expert SUL', 'Expert Department', 'sul', 'active', 'Expert Service Unit Leader'),
('expert_dept_1', 'expert.dept1@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Rafael Estimation Expert', 'Expert Department', 'expert', 'active', 'Solution Estimation Expert'),
('expert_dept_2', 'expert.dept2@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Mina Senior Estimator', 'Expert Department', 'expert', 'active', 'Senior Estimation Expert'),
('frontend_sul_2', 'frontend.sul2@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Ana Frontend SUL', 'Frontend Developer', 'sul', 'active', 'Service Unit Leader'),
('frontend_dev_2', 'frontend.dev2@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Liam Frontend Developer', 'Frontend Developer', 'developer', 'active', 'Frontend Developer'),
('frontend_dev_3', 'frontend.dev3@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Mia Frontend Developer', 'Frontend Developer', 'developer', 'active', 'Frontend Developer'),
('backend_sul_1', 'backend.sul1@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Noel Backend SUL', 'Backend Developer', 'sul', 'active', 'Service Unit Leader'),
('backend_sul_2', 'backend.sul2@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Rina Backend SUL', 'Backend Developer', 'sul', 'active', 'Service Unit Leader'),
('backend_expert_1', 'backend.expert1@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Elena Backend Expert', 'Backend Developer', 'expert', 'active', 'Backend Expert'),
('backend_dev_1', 'backend.dev1@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Carlo Backend Developer', 'Backend Developer', 'developer', 'active', 'Backend Developer'),
('backend_dev_2', 'backend.dev2@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Jessa Backend Developer', 'Backend Developer', 'developer', 'active', 'Backend Developer'),
('backend_dev_3', 'backend.dev3@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Paolo Backend Developer', 'Backend Developer', 'developer', 'active', 'Backend Developer'),
('fullstack_sul_1', 'fullstack.sul1@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Grace Full Stack SUL', 'Full Stack Developer', 'sul', 'active', 'Service Unit Leader'),
('fullstack_sul_2', 'fullstack.sul2@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Marco Full Stack SUL', 'Full Stack Developer', 'sul', 'active', 'Service Unit Leader'),
('fullstack_expert_1', 'fullstack.expert1@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Sofia Full Stack Expert', 'Full Stack Developer', 'expert', 'active', 'Full Stack Expert'),
('fullstack_dev_1', 'fullstack.dev1@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Arvin Full Stack Developer', 'Full Stack Developer', 'developer', 'active', 'Full Stack Developer'),
('fullstack_dev_2', 'fullstack.dev2@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Bianca Full Stack Developer', 'Full Stack Developer', 'developer', 'active', 'Full Stack Developer'),
('fullstack_dev_3', 'fullstack.dev3@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Ken Full Stack Developer', 'Full Stack Developer', 'developer', 'active', 'Full Stack Developer'),
('qaeng_sul_1', 'qaeng.sul1@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Iris QA SUL', 'QA Engineer', 'sul', 'active', 'Service Unit Leader'),
('qaeng_sul_2', 'qaeng.sul2@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Owen QA SUL', 'QA Engineer', 'sul', 'active', 'Service Unit Leader'),
('qaeng_expert_1', 'qaeng.expert1@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Nina QA Expert', 'QA Engineer', 'expert', 'active', 'QA Expert'),
('qaeng_dev_1', 'qaeng.dev1@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Sam QA Developer', 'QA Engineer', 'developer', 'active', 'QA Automation Developer'),
('qaeng_dev_2', 'qaeng.dev2@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Tara QA Developer', 'QA Engineer', 'developer', 'active', 'QA Automation Developer'),
('qaeng_dev_3', 'qaeng.dev3@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Vince QA Developer', 'QA Engineer', 'developer', 'active', 'QA Automation Developer'),
('uiux_sul_1', 'uiux.sul1@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Lara UI UX SUL', 'UI/UX Designer', 'sul', 'active', 'Service Unit Leader'),
('uiux_sul_2', 'uiux.sul2@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Ramon UI UX SUL', 'UI/UX Designer', 'sul', 'active', 'Service Unit Leader'),
('uiux_expert_1', 'uiux.expert1@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Celine UI UX Expert', 'UI/UX Designer', 'expert', 'active', 'UI/UX Expert'),
('uiux_dev_1', 'uiux.dev1@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Diane UI UX Developer', 'UI/UX Designer', 'developer', 'active', 'UI/UX Developer'),
('uiux_dev_2', 'uiux.dev2@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Evan UI UX Developer', 'UI/UX Designer', 'developer', 'active', 'UI/UX Developer'),
('uiux_dev_3', 'uiux.dev3@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Faye UI UX Developer', 'UI/UX Designer', 'developer', 'active', 'UI/UX Developer'),
('devops_sul_1', 'devops.sul1@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Gio DevOps SUL', 'DevOps Engineer', 'sul', 'active', 'Service Unit Leader'),
('devops_sul_2', 'devops.sul2@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Hanna DevOps SUL', 'DevOps Engineer', 'sul', 'active', 'Service Unit Leader'),
('devops_expert_1', 'devops.expert1@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Ivan DevOps Expert', 'DevOps Engineer', 'expert', 'active', 'DevOps Expert'),
('devops_dev_1', 'devops.dev1@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Jude DevOps Developer', 'DevOps Engineer', 'developer', 'active', 'DevOps Developer'),
('devops_dev_2', 'devops.dev2@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Kara DevOps Developer', 'DevOps Engineer', 'developer', 'active', 'DevOps Developer'),
('devops_dev_3', 'devops.dev3@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Luis DevOps Developer', 'DevOps Engineer', 'developer', 'active', 'DevOps Developer'),
('database_sul_1', 'database.sul1@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Mara Database SUL', 'Database Administrator', 'sul', 'active', 'Service Unit Leader'),
('database_sul_2', 'database.sul2@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Nico Database SUL', 'Database Administrator', 'sul', 'active', 'Service Unit Leader'),
('database_expert_1', 'database.expert1@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Olive Database Expert', 'Database Administrator', 'expert', 'active', 'Database Expert'),
('database_dev_1', 'database.dev1@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Pia Database Developer', 'Database Administrator', 'developer', 'active', 'Database Developer'),
('database_dev_2', 'database.dev2@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Quincy Database Developer', 'Database Administrator', 'developer', 'active', 'Database Developer'),
('database_dev_3', 'database.dev3@erni-pms.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Reese Database Developer', 'Database Administrator', 'developer', 'active', 'Database Developer');

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
INSERT INTO projects (client_id, name, description, status, timeline_start, timeline_end, requested_features, included_departments, complexity_level, created_by, architect_id, sul_id) VALUES
(1, 'E-Commerce Platform', 'Complete e-commerce solution with payment gateway', 'requirements_gathering', '2026-03-01', '2026-12-31', 'Product Catalog
Cart and Checkout
Payment Gateway Integration
Loyalty Rewards', '["Expert Department","Frontend Developer","Backend Developer","QA Engineer","UI/UX Designer","DevOps Engineer","Database Administrator"]', 'complex', 1, 3, 4),
(2, 'Patient Management System', 'Hospital management and patient tracking system', 'pending', '2026-04-01', '2027-03-31', 'Patient Registration
Appointment Scheduling
Medical Records
Insurance Export', '["Expert Department","Frontend Developer","Backend Developer","QA Engineer","UI/UX Designer","Database Administrator"]', 'complex', 1, NULL, NULL);

-- Sample project feature checklist data
INSERT INTO tasks (project_id, title, description, task_type, assigned_to, assigned_by, status, priority, deadline, completed_at, architect_reviewed_by, architect_reviewed_at, merged_at, release_notified_at) VALUES
(1, 'Product Catalog', 'Customer can browse products by category and search terms.', 'feature', (SELECT id FROM users WHERE username = 'developer'), 3, 'completed', 'high', '2026-06-15', NOW(), 3, NOW(), NOW(), NOW()),
(1, 'Cart and Checkout', 'Customer can manage cart items and complete checkout.', 'feature', (SELECT id FROM users WHERE username = 'frontend_dev_2'), 3, 'completed', 'critical', '2026-07-15', NOW(), 3, NOW(), NOW(), NOW()),
(1, 'Payment Gateway Integration', 'Online payment provider integration with transaction logging.', 'feature', (SELECT id FROM users WHERE username = 'backend_dev_1'), 3, 'in_progress', 'critical', '2026-08-15', NULL, NULL, NULL, NULL, NULL),
(1, 'Additional Loyalty Rewards', 'Additional client request: points-based rewards and vouchers.', 'enhancement', (SELECT id FROM users WHERE username = 'developer'), 3, 'qa_passed', 'medium', '2026-09-15', NULL, NULL, NULL, NULL, NULL),
(2, 'Patient Registration', 'Staff can register patient profiles and emergency contacts.', 'feature', (SELECT id FROM users WHERE username = 'backend_dev_2'), 3, 'completed', 'high', '2026-07-20', NOW(), 3, NOW(), NOW(), NOW()),
(2, 'Appointment Scheduling', 'Clinic staff can create and update appointment schedules.', 'feature', (SELECT id FROM users WHERE username = 'frontend_dev_3'), 3, 'in_progress', 'high', '2026-08-30', NULL, NULL, NULL, NULL, NULL),
(2, 'Medical Records', 'Secure medical history and consultation notes module.', 'feature', (SELECT id FROM users WHERE username = 'database_dev_1'), 3, 'todo', 'critical', '2026-10-15', NULL, NULL, NULL, NULL, NULL),
(2, 'Additional Insurance Export', 'Additional architect request: export insurance claim summaries.', 'enhancement', (SELECT id FROM users WHERE username = 'database_dev_2'), 3, 'completed', 'medium', '2026-11-15', NOW(), 3, NOW(), NOW(), NOW());

-- Default Settings
INSERT INTO settings (setting_key, setting_value, description, setting_type) VALUES
('company_name', 'ERNI Development Center Philippines Inc.', 'Company official name', 'system'),
('system_theme', 'light', 'System theme preference', 'system'),
('notification_email', 'noreply@erni-pms.com', 'System notification email', 'system'),
('timezone', 'Asia/Manila', 'Default system timezone', 'system'),
('currency', 'PHP', 'Default currency for pricing', 'system');
