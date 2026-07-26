<?php
declare(strict_types=1);

const PROJECT_TEMPLATE_DEFAULTS = [
    ['Company Website', 2, 4, 20000, 50000, 'Waterfall', 'Home Page, About Page, Services Page, Portfolio Gallery, Contact Form, CMS Pages, Blog News, SEO Metadata, Inquiry Notifications, Admin Content Management', 'HTML/CSS, JS, PHP/Laravel, MySQL', '1 Frontend, 1 Backend'],
    ['Portfolio Website', 1, 2, 10000, 25000, 'Waterfall', 'Profile Overview, Project Gallery, Case Studies, Skills Section, Resume Download, Contact Form, Social Links, Testimonials, SEO Metadata, Admin Portfolio Updates', 'HTML, CSS, JS', '1 Frontend'],
    ['E-commerce Website', 8, 16, 100000, 350000, 'Agile', 'Product Catalog, Product Search, Product Filters, Cart, Checkout, Payments, Order Management, Customer Accounts, Inventory Sync, Promotions, Shipping Setup, Admin Dashboard, Sales Reports, Email Notifications', 'React, Laravel, MySQL', '1 Frontend, 2 Backend, 1 UI/UX, 1 QA'],
    ['Mobile Shopping App', 10, 18, 150000, 500000, 'Agile', 'User Login, Product Catalog, Product Search, Product Filters, Cart, Checkout, Payments, Order Tracking, Push Notifications, Wishlist, Ratings Reviews, Address Book, Admin Order Management, Sales Reports', 'Kotlin/Flutter, Node.js, MySQL', '1 Mobile Dev, 2 Backend, 1 UI/UX, 1 QA'],
    ['POS System', 8, 12, 80000, 250000, 'Agile', 'Sales Checkout, Product Management, Inventory Management, Barcode Scanning, Receipt Printing, Discounts, Returns, Cashier Accounts, Shift Closing, Daily Sales Reports, Stock Alerts, Customer Records, Audit Logs', 'Laravel, MySQL', '1 Frontend, 2 Backend, 1 QA'],
    ['Inventory System', 6, 10, 60000, 180000, 'Agile', 'Stock Management, Supplier Management, Purchase Orders, Receiving, Stock Adjustments, Low Stock Alerts, Barcode Support, Warehouse Locations, Item Categories, Inventory Reports, Export Reports, Audit Logs', 'Laravel, MySQL', '1 Frontend, 1 Backend'],
    ['Attendance Tracking System', 4, 8, 40000, 120000, 'Agile', 'Time In Out, QR Attendance, Employee Profiles, Shift Schedule, Late Tracking, Overtime Tracking, Leave Tagging, Daily Attendance Reports, Monthly Attendance Reports, Admin Correction Requests, Role Access, Audit Logs', 'Laravel, MySQL', '1 Frontend, 1 Backend'],
    ['Payroll System', 8, 14, 120000, 300000, 'Agile', 'Employee Salary Profiles, Attendance Import, Deductions, Allowances, Overtime Computation, Tax Computation, Payslip Generation, Payroll Approval, Bank Export, Payroll Reports, Role Access, Audit Logs', 'Laravel, MySQL', '1 Frontend, 2 Backend, 1 QA'],
    ['HR Management System', 10, 18, 150000, 400000, 'Agile', 'Employee Records, Leave Management, Payroll Integration, Attendance Integration, Recruitment Tracking, Onboarding, Performance Reviews, Document Storage, Department Management, HR Reports, Role Access, Audit Logs', 'Laravel, React', '1 Frontend, 2 Backend, 1 QA'],
    ['School Management System', 12, 20, 180000, 500000, 'Agile', 'Student Enrollment, Student Profiles, Class Scheduling, Grades Management, Attendance, Teacher Management, Parent Portal, Billing Records, Announcements, Report Cards, Academic Reports, Role Access', 'Laravel, React', '2 Frontend, 2 Backend, 1 QA'],
    ['Learning Management System (LMS)', 10, 18, 150000, 450000, 'Agile', 'Course Management, Lesson Modules, Quiz Builder, Assignment Submission, Certificates, Student Progress, Instructor Dashboard, Discussion Board, File Resources, Notifications, Reports, Role Access', 'Laravel, React', '2 Frontend, 2 Backend'],
    ['Hospital Management System', 16, 30, 300000, 800000, 'Agile', 'Patient Records, Appointment Scheduling, Doctor Management, Billing, Medical Records, Laboratory Requests, Pharmacy Inventory, Admission Management, Discharge Summary, Reports, Role Access, Audit Logs', 'React, Node.js', '2 Frontend, 3 Backend, 1 QA'],
    ['Clinic Management System', 10, 16, 150000, 350000, 'Agile', 'Patient Profiles, Appointment Scheduling, Doctor Schedule, Consultation Notes, Prescription Records, Billing, Queue Management, SMS Email Reminders, Medical History, Reports, Role Access, Audit Logs', 'Laravel', '1 Frontend, 2 Backend'],
    ['Hotel Reservation System', 10, 16, 150000, 350000, 'Agile', 'Room Listings, Room Availability, Booking Calendar, Guest Profiles, Payments, Check In, Check Out, Housekeeping Status, Promotions, Reservation Reports, Admin Dashboard, Notifications', 'React, Laravel', '2 Frontend, 2 Backend'],
    ['Restaurant Ordering System', 8, 12, 80000, 250000, 'Agile', 'Menu Management, Table Ordering, Online Ordering, Kitchen Queue, Order Status, Payments, Discounts, Receipt Printing, Inventory Alerts, Customer Records, Sales Reports, Admin Dashboard', 'Laravel', '1 Frontend, 2 Backend'],
    ['Food Delivery App', 14, 24, 250000, 700000, 'Agile', 'Customer Ordering, Restaurant Listings, Menu Browsing, Cart, Payment, Delivery Tracking, Driver Assignment, Push Notifications, Ratings Reviews, Promo Codes, Order History, Admin Dashboard, Reports', 'Flutter, Node.js', '2 Mobile, 2 Backend, 1 QA'],
    ['Appointment Booking System', 6, 10, 60000, 180000, 'Agile', 'Service Calendar, Appointment Booking, Availability Management, Customer Profiles, Staff Schedule, Reminders, Rescheduling, Cancellation Rules, Booking Reports, Admin Dashboard, Role Access, Notifications', 'Laravel', '1 Frontend, 1 Backend'],
    ['Clinic Appointment App', 8, 12, 100000, 250000, 'Agile', 'Patient Login, Doctor Directory, Appointment Booking, Doctor Availability, Queue Status, Reminders, Rescheduling, Medical Notes Preview, Push Notifications, Appointment History, Admin Dashboard, Reports', 'Flutter', '1 Mobile, 2 Backend'],
    ['Library Management System', 6, 10, 60000, 180000, 'Waterfall', 'Book Catalog, Borrowing, Returns, Member Records, Due Date Tracking, Fines Management, Reservations, Barcode Support, Inventory Reports, Borrowing Reports, Admin Dashboard, Audit Logs', 'Laravel', '1 Frontend, 1 Backend'],
    ['Event Management System', 8, 12, 80000, 220000, 'Agile', 'Event Listings, Registration, Ticketing, Payments, Attendee Management, QR Check In, Seating Capacity, Email Notifications, Sponsor Management, Event Reports, Admin Dashboard, Export Reports', 'React, Laravel', '1 Frontend, 2 Backend'],
    ['Document Management System', 8, 14, 100000, 250000, 'Agile', 'File Upload, Folder Management, Versioning, Document Search, Access Permissions, Approval Workflow, Comments, Audit Logs, Expiry Alerts, Download Tracking, Backup Support, Reports', 'Laravel', '1 Frontend, 2 Backend'],
    ['Project Management System', 10, 18, 150000, 400000, 'Agile', 'Project Dashboard, Task Management, Kanban Board, Milestones, Team Assignments, Comments, File Attachments, Time Tracking, Notifications, Progress Reports, Role Access, Activity Logs', 'React, Node.js', '2 Frontend, 2 Backend'],
    ['CRM System', 12, 20, 180000, 500000, 'Agile', 'Lead Management, Customer Profiles, Sales Pipeline, Follow Up Tasks, Notes, Email Tracking, Deal Stages, Quotations, Customer Support Tickets, Sales Reports, Admin Dashboard, Role Access', 'React, Laravel', '2 Frontend, 2 Backend'],
    ['ERP System', 24, 40, 600000, 2000000, 'Agile', 'Finance Module, HR Module, Inventory Module, Procurement, Sales Module, Workflow Approvals, Role Access, Reporting Dashboard, Audit Logs, Data Import, Integrations, Notifications, Admin Configuration', 'React, .NET', '3 Frontend, 4 Backend, 2 QA'],
    ['Accounting System', 12, 20, 180000, 450000, 'Agile', 'Chart Of Accounts, General Ledger, Expenses, Invoices, Receipts, Accounts Payable, Accounts Receivable, Bank Reconciliation, Tax Reports, Financial Statements, Audit Logs, Role Access', 'Laravel', '2 Frontend, 2 Backend'],
    ['Courier Tracking System', 12, 18, 180000, 450000, 'Agile', 'Shipment Booking, Parcel Tracking, Delivery Status, Rider Assignment, Route Updates, Proof Of Delivery, Customer Notifications, Branch Management, Rate Calculation, Reports, Admin Dashboard, Audit Logs', 'Flutter, Node.js', '2 Mobile, 2 Backend'],
    ['Logistics Management System', 16, 24, 300000, 700000, 'Agile', 'Fleet Management, Driver Management, GPS Tracking, Dispatch Planning, Delivery Scheduling, Route Optimization, Fuel Logs, Maintenance Records, Shipment Tracking, Customer Portal, Reports, Admin Dashboard', 'React, Node.js', '2 Frontend, 3 Backend'],
    ['Real Estate System', 10, 16, 150000, 350000, 'Agile', 'Property Listings, Property Search, Inquiry Forms, Agent Profiles, Appointment Scheduling, Favorites, Map View, Lead Management, Property Media Gallery, Admin Dashboard, Reports, Notifications', 'React', '1 Frontend, 2 Backend'],
    ['Job Portal', 10, 16, 150000, 350000, 'Agile', 'Job Listings, Job Search, Employer Accounts, Applicant Accounts, Resume Upload, Applications, Shortlisting, Interview Scheduling, Notifications, Admin Moderation, Reports, Role Access', 'Laravel', '2 Frontend, 2 Backend'],
    ['Social Media Platform', 20, 40, 500000, 1500000, 'Agile', 'User Profiles, Posts, News Feed, Comments, Likes, Friends Followers, Chat, Notifications, Media Uploads, Privacy Settings, Content Moderation, Reports, Admin Dashboard', 'React, Node.js', '3 Frontend, 4 Backend, 2 QA'],
    ['Chat Application', 8, 16, 120000, 300000, 'Agile', 'User Login, One To One Chat, Group Chat, Message Status, Media Sharing, Push Notifications, Contact List, Search Messages, Profile Settings, Blocking, Admin Monitoring, Reports', 'Flutter, Firebase', '1 Mobile, 2 Backend'],
    ['Forum System', 8, 12, 80000, 200000, 'Agile', 'Forum Categories, Threads, Comments, User Profiles, Moderation Tools, Search, Tags, Likes, Notifications, Report Abuse, Admin Dashboard, Activity Logs', 'Laravel', '1 Frontend, 2 Backend'],
    ['Help Desk/Ticketing System', 8, 14, 100000, 250000, 'Agile', 'Ticket Creation, Ticket Assignment, SLA Tracking, Priority Levels, Internal Notes, Customer Replies, Status Workflow, Knowledge Base, Notifications, Reports, Admin Dashboard, Audit Logs', 'Laravel', '1 Frontend, 2 Backend'],
    ['QR Code Management System', 4, 8, 40000, 120000, 'Waterfall', 'QR Generation, QR Scan Tracking, Dynamic QR Links, Campaign Management, User Access, Scan Analytics, Export Reports, Bulk QR Creation, Expiry Control, Admin Dashboard, Audit Logs', 'Laravel', '1 Full-Stack'],
    ['Visitor Management System', 6, 10, 60000, 180000, 'Agile', 'Visitor Registration, QR Pass, Host Notification, Check In, Check Out, ID Capture, Visit Purpose, Blacklist Alerts, Visitor Logs, Reports, Admin Dashboard, Role Access', 'Laravel', '1 Frontend, 1 Backend'],
    ['Warehouse Management System', 12, 20, 200000, 500000, 'Agile', 'Inventory Management, Barcode Scanning, Receiving, Putaway, Picking, Packing, Stock Transfer, Warehouse Locations, Cycle Count, Low Stock Alerts, Reports, Admin Dashboard', 'React, Laravel', '2 Frontend, 2 Backend'],
    ['Vehicle Rental System', 8, 14, 100000, 250000, 'Agile', 'Vehicle Listings, Availability Calendar, Reservations, Customer Profiles, Payments, Rental Agreements, Check Out, Return Inspection, Maintenance Logs, Pricing Rules, Reports, Admin Dashboard', 'Laravel', '1 Frontend, 2 Backend'],
    ['Car Rental Mobile App', 12, 18, 200000, 500000, 'Agile', 'User Login, Vehicle Search, Booking, GPS Pickup Location, Payment, Booking History, Push Notifications, Driver Verification, Rental Agreement, Ratings, Admin Dashboard, Reports', 'Flutter', '2 Mobile, 2 Backend'],
    ['Fitness Management System', 8, 12, 80000, 220000, 'Agile', 'Member Profiles, Membership Plans, Attendance, Class Scheduling, Trainer Management, Payments, Workout Plans, Progress Tracking, Renewal Alerts, Reports, Admin Dashboard, Role Access', 'Laravel', '1 Frontend, 2 Backend'],
    ['Donation Platform', 8, 14, 100000, 250000, 'Agile', 'Campaign Management, Donation Checkout, Donor Accounts, Payment Gateway, Recurring Donations, Receipts, Campaign Updates, Donor Wall, Fund Reports, Admin Dashboard, Notifications, Role Access', 'React', '2 Frontend, 2 Backend'],
    ['Online Examination System', 8, 14, 120000, 300000, 'Agile', 'Exam Builder, Question Bank, Timer, Student Accounts, Randomized Questions, Auto Grading, Manual Grading, Results, Certificates, Anti Cheating Controls, Reports, Admin Dashboard', 'Laravel', '1 Frontend, 2 Backend'],
    ['Survey System', 4, 8, 40000, 120000, 'Waterfall', 'Survey Builder, Question Types, Response Collection, Public Links, Anonymous Responses, Analytics, Export Reports, Branching Logic, User Access, Admin Dashboard, Notifications', 'Laravel', '1 Full-Stack'],
    ['Voting System', 6, 10, 80000, 180000, 'Agile', 'Candidate Management, Voter Registration, Secure Voting, Vote Validation, Election Schedule, Results Tallying, Audit Logs, Access Control, Reports, Admin Dashboard, Notifications', 'Laravel', '1 Frontend, 1 Backend'],
    ['Complaint Management System', 6, 10, 60000, 180000, 'Agile', 'Complaint Submission, Complaint Tracking, Category Management, Assignment Workflow, Status Updates, SLA Monitoring, Attachments, Notifications, Resolution Notes, Reports, Admin Dashboard, Audit Logs', 'Laravel', '1 Frontend, 1 Backend'],
    ['Booking System', 6, 10, 60000, 180000, 'Agile', 'Service Listings, Reservations, Calendar Availability, Customer Profiles, Payments, Reminders, Rescheduling, Cancellation Rules, Booking History, Reports, Admin Dashboard, Notifications', 'Laravel', '1 Frontend, 1 Backend'],
];

function projectTemplateNormalizeType(string $type): string
{
    return strtolower(trim((string) preg_replace('/\s+/', ' ', $type)));
}

function projectTemplateListFromCsv(string $value): array
{
    return array_values(array_filter(array_map(static fn(string $item): string => trim($item), explode(',', $value)), static fn(string $item): bool => $item !== ''));
}

function projectTemplateComplexityForRange(int $weeksMax, float $budgetMax, string $team): string
{
    $teamCount = preg_match_all('/\b\d+\b/', $team, $matches) ? array_sum(array_map('intval', $matches[0])) : 1;
    $score = ($weeksMax >= 20 ? 2 : ($weeksMax >= 10 ? 1 : 0))
        + ($budgetMax >= 500000 ? 2 : ($budgetMax >= 150000 ? 1 : 0))
        + ($teamCount >= 6 ? 2 : ($teamCount >= 3 ? 1 : 0));

    if ($score >= 4) {
        return 'high';
    }
    if ($score >= 2) {
        return 'medium';
    }
    return 'low';
}

function projectTemplateRecommendedTeam(string $type, string $features, string $techStack, int $maxWeeks, float $maxBudget): string
{
    $featureCount = max(1, count(projectTemplateListFromCsv($features)));
    $scope = strtolower($type . ' ' . $features . ' ' . $techStack);
    $isMobile = preg_match('/\b(app|mobile|flutter|kotlin|firebase)\b/i', $scope) === 1;
    $isSensitive = preg_match('/\b(patient|medical|health|payroll|accounting|finance|voting|payment|bank|security|audit)\b/i', $scope) === 1;
    $isDataHeavy = preg_match('/\b(inventory|warehouse|analytics|report|tracking|erp|crm|records|management)\b/i', $scope) === 1;
    $hasIntegrations = preg_match('/\b(payment|notification|sms|email|gps|map|tracking|import|export|integration|push)\b/i', $scope) === 1;
    $deliveryWeeks = max(1, $maxWeeks);
    $featuresPerWeek = $featureCount / $deliveryWeeks;
    $schedulePressure = $featuresPerWeek >= 1.25 ? 2 : ($featuresPerWeek >= 0.75 ? 1 : 0);
    $frontendWorkload = max(1, (int) ceil($featureCount / max(5, 8 - $schedulePressure)));
    $backendWorkload = max(1, (int) ceil($featureCount / max(4, 7 - $schedulePressure)));
    $qaWorkload = max(1, (int) ceil($featureCount / max(8, 12 - ($schedulePressure * 2))));

    $roles = [
        'Expert Department' => 1,
        'UI/UX' => max(1, (int) ceil($frontendWorkload / 2)),
        'Frontend' => min(5, $frontendWorkload),
        'Backend' => min(5, $backendWorkload + ($hasIntegrations ? 1 : 0)),
        'QA' => min(3, $qaWorkload + ($isSensitive ? 1 : 0)),
    ];
    if ($isMobile) $roles['Mobile'] = min(4, max(1, (int) ceil($featureCount / max(5, 8 - $schedulePressure))));
    if ($isDataHeavy || $featureCount >= 12) $roles['Database'] = $featureCount >= 20 ? 2 : 1;
    if ($hasIntegrations || $schedulePressure > 0) $roles['DevOps'] = $schedulePressure >= 2 ? 2 : 1;
    if ($isSensitive) $roles['Security'] = 1;

    $parts = [];
    foreach ($roles as $role => $count) $parts[] = $count . ' ' . $role;
    return implode(', ', $parts);
}

function projectTemplateRecommendedMethodology(string $type, string $features, int $maxWeeks, float $maxBudget, string $fallback): string
{
    $scope = strtolower($type . ' ' . $features);
    $regulated = preg_match('/\b(hospital|clinic|medical|patient|payroll|accounting|finance|voting)\b/i', $scope) === 1;
    $largeProduct = $maxWeeks >= 12 || $maxBudget >= 300000 || count(projectTemplateListFromCsv($features)) >= 12;
    if ($regulated) return 'V-Model';
    if ($largeProduct) return 'Scrum';
    return trim($fallback) !== '' ? $fallback : 'Agile';
}

function projectTemplatePhaseFlow(string $methodology): string
{
    return match (strtolower(trim($methodology))) {
        'scrum' => 'Backlog refinement; Sprint setup; Sprint development; Sprint QA and review; Release retrospective',
        'v-model', 'v model' => 'Requirements and acceptance planning; Architecture and test design; Implementation; Integration and system testing; Acceptance and deployment',
        'waterfall' => 'Requirements approval; System design; Implementation; Verification and testing; Deployment and handover',
        default => 'Discovery and backlog; Iteration planning; Iterative development; QA and stakeholder review; Release and retrospective',
    };
}

function projectTemplateDeveloperWorkPlan(string $type, string $features, string $techStack, string $team): array
{
    $featureItems = projectTemplateListFromCsv($features);
    $featureCount = count($featureItems);
    $featureScope = implode(', ', array_slice($featureItems, 0, 3));
    if ($featureCount > 3) $featureScope .= ' +' . ($featureCount - 3) . ' more';
    if ($featureScope === '') $featureScope = $type . ' scope';

    $taskCatalog = [
        'Expert Department' => [
            "Define acceptance criteria for {$featureCount} planned features",
            'Break requirements into estimable work',
            'Validate architecture and department handoffs',
        ],
        'UI/UX' => [
            "Design user flows for {$featureScope}",
            'Prepare responsive screens and component states',
            'Review usability and accessibility',
        ],
        'Frontend' => [
            "Build responsive interfaces for {$featureScope}",
            'Implement client validation and application state',
            'Integrate screens with backend APIs',
        ],
        'Backend' => [
            "Implement APIs and business rules for {$featureScope}",
            'Build authentication, roles, and integrations',
            'Add server validation, logging, and error handling',
        ],
        'Mobile' => [
            "Build mobile flows for {$featureScope}",
            'Integrate APIs, notifications, and device services',
            'Validate mobile states, errors, and offline behavior',
        ],
        'Database' => [
            "Model and migrate data for {$featureScope}",
            'Add indexes, constraints, and audit fields',
            'Prepare backup and recovery checks',
        ],
        'DevOps' => [
            'Prepare development, staging, and production environments',
            'Configure automated build and deployment checks',
            'Set up monitoring, logs, and rollback steps',
        ],
        'Security' => [
            'Review threats, permissions, and sensitive data handling',
            'Validate authentication and access controls',
            'Run security checks before release',
        ],
        'QA' => [
            "Create acceptance tests for {$featureCount} planned features",
            'Run functional, integration, and regression testing',
            'Verify fixes and support user acceptance testing',
        ],
        'AI' => [
            'Prepare and validate model inputs and outputs',
            'Integrate the AI service with application workflows',
            'Measure accuracy, safety, and fallback behavior',
        ],
        'Other Departments' => [
            'Complete the specialist work defined during planning',
            'Document dependencies and handoff requirements',
            'Validate specialist outputs before release',
        ],
    ];

    $counts = [];
    foreach (explode(',', $team) as $part) {
        if (!preg_match('/(\d+)\s+(.+)/', trim($part), $matches)) continue;
        $count = max(0, (int) $matches[1]);
        $roleText = strtolower(trim((string) $matches[2]));
        $roles = match (true) {
            str_contains($roleText, 'expert') => ['Expert Department'],
            str_contains($roleText, 'full') => ['Frontend', 'Backend'],
            str_contains($roleText, 'ui'), str_contains($roleText, 'ux') => ['UI/UX'],
            str_contains($roleText, 'front') => ['Frontend'],
            str_contains($roleText, 'back') => ['Backend'],
            str_contains($roleText, 'mobile') => ['Mobile'],
            str_contains($roleText, 'database') => ['Database'],
            str_contains($roleText, 'devops') => ['DevOps'],
            str_contains($roleText, 'security') => ['Security'],
            str_contains($roleText, 'qa'), str_contains($roleText, 'test') => ['QA'],
            str_contains($roleText, 'ai') => ['AI'],
            default => ['Other Departments'],
        };
        foreach ($roles as $role) $counts[$role] = ($counts[$role] ?? 0) + $count;
    }

    $plan = [];
    foreach ($counts as $role => $count) {
        if ($count <= 0) continue;
        $plan[] = [
            'role' => $role,
            'count' => $count,
            'tasks' => $taskCatalog[$role] ?? $taskCatalog['Other Departments'],
        ];
    }
    return $plan;
}

function projectTemplateDefaultDetails(string $type, string $features, string $techStack, string $team, string $methodology, string $durationLabel): array
{
    $hasPayments = stripos($features, 'Payment') !== false || stripos($features, 'Checkout') !== false || stripos($features, 'Cart') !== false;
    $hasMobile = stripos($type, 'App') !== false || stripos($techStack, 'Flutter') !== false || stripos($techStack, 'Kotlin') !== false;
    $hasReports = stripos($features, 'Reports') !== false || stripos($features, 'Analytics') !== false;
    $database = stripos($techStack, 'Firebase') !== false ? 'Firebase / Firestore' : (stripos($techStack, 'MySQL') !== false ? 'MySQL' : 'Relational database based on selected stack');
    $hosting = stripos($techStack, 'Node') !== false || stripos($techStack, 'React') !== false ? 'Cloud VPS or managed application platform' : 'Shared hosting, VPS, or managed PHP hosting';
    $thirdParty = [];
    if ($hasPayments) {
        $thirdParty[] = 'Payment gateway';
    }
    if ($hasMobile) {
        $thirdParty[] = 'Push notification service';
    }
    if ($hasReports) {
        $thirdParty[] = 'Reporting/export service';
    }

    return [
        'project_description' => "{$type} template covering common discovery, design, development, testing, deployment, and handoff needs.",
        'suggested_milestones' => 'Discovery and requirements; UI/UX and architecture; Core module development; Integration and testing; Deployment and client acceptance',
        'default_project_phases' => projectTemplatePhaseFlow($methodology),
        'functional_requirements' => $features,
        'non_functional_requirements' => 'Responsive interface, usability, maintainability, performance, audit-friendly activity records, and scalable data structure.',
        'third_party_services' => implode(', ', $thirdParty) ?: 'Email/SMS notifications and analytics as needed',
        'hosting_platform' => $hosting,
        'deployment_environment' => 'Staging and production environments with version-controlled releases',
        'database_technology' => $database,
        'auth_method' => 'Role-based authentication and authorization',
        'backup_recovery_strategy' => 'Scheduled database backups, file backup retention, and documented restore procedure.',
        'developer_roles_allocation' => $team,
        'testing_requirements' => 'Functional testing, regression testing, role/access testing, browser/device checks, and acceptance testing.',
        'documentation_requirements' => 'Architecture notes, setup guide, user guide, deployment notes, and administrator handoff documentation.',
        'maintenance_support' => 'Post-deployment monitoring, bug fixes, minor adjustments, and support window based on contract terms.',
        'risk_assessment' => 'Scope changes, third-party service delays, integration complexity, data migration issues, and timeline compression may affect delivery.',
        'implementation_notes' => "Use {$durationLabel} as a planning baseline. Department handoff: Expert Department for requirements and estimation; UI/UX for interaction design; Frontend, Backend, Mobile, and Database as selected for implementation; Security and DevOps for assurance and deployment; QA for final verification. Adjust only after validating client-specific scope.",
    ];
}

function projectTemplatePayload(array $template): array
{
    $features = projectTemplateListFromCsv((string) ($template['core_features'] ?? ''));
    $techStack = projectTemplateListFromCsv((string) ($template['tech_stack'] ?? ''));
    $techStack = array_values(array_unique(array_merge(
        $techStack,
        projectTemplateListFromCsv((string) ($template['third_party_services'] ?? '')),
        projectTemplateListFromCsv((string) ($template['database_technology'] ?? '')),
        projectTemplateListFromCsv((string) ($template['hosting_platform'] ?? '')),
        projectTemplateListFromCsv((string) ($template['auth_method'] ?? ''))
    )));

    $team = (string) (($template['developer_roles_allocation'] ?? '') ?: ($template['team_composition'] ?? ''));
    $developerWorkPlan = projectTemplateDeveloperWorkPlan(
        (string) ($template['project_type'] ?? ''),
        (string) ($template['core_features'] ?? ''),
        (string) ($template['tech_stack'] ?? ''),
        $team
    );
    $plannedDeveloperCount = 0;
    foreach (explode(',', $team) as $teamPart) {
        if (preg_match('/\b(\d+)\b/', trim($teamPart), $teamCountMatch)) {
            $plannedDeveloperCount += max(0, (int) $teamCountMatch[1]);
        }
    }
    $plannedDeveloperCount = max(1, $plannedDeveloperCount);
    $professionalDeveloperMonthlySalary = 60000.0;
    // Sales creates a preliminary budget before the detailed roadmap exists.
    // Roles participate in different phases, so do not price every recommended
    // person as full-time for the entire project. The Expert estimate later
    // replaces this baseline with each developer's actual roadmap contribution.
    $planningUtilizationRate = 0.30;
    $minimumSalaryBudget = $plannedDeveloperCount * $professionalDeveloperMonthlySalary
        * max(1.0, (int) ($template['duration_min_weeks'] ?? 0) / 4.345)
        * $planningUtilizationRate;
    $maximumSalaryBudget = $plannedDeveloperCount * $professionalDeveloperMonthlySalary
        * max(1.0, (int) ($template['duration_max_weeks'] ?? 0) / 4.345)
        * $planningUtilizationRate;
    $budgetMin = max((float) ($template['budget_min'] ?? 0), ceil($minimumSalaryBudget / 1000) * 1000);
    $budgetMax = max($budgetMin, (float) ($template['budget_max'] ?? 0), ceil($maximumSalaryBudget / 1000) * 1000);

    return [
        'planning_model_version' => 3,
        'id' => (int) ($template['id'] ?? 0),
        'template_version' => max(1, (int) ($template['template_version'] ?? 1)),
        'project_type' => (string) ($template['project_type'] ?? ''),
        'duration_label' => (string) ($template['duration_label'] ?? ''),
        'duration_min_weeks' => (int) ($template['duration_min_weeks'] ?? 0),
        'duration_max_weeks' => (int) ($template['duration_max_weeks'] ?? 0),
        'budget_min' => $budgetMin,
        'budget_max' => $budgetMax,
        'budget_basis' => 'Planned team × Professional Developer monthly salary × project duration × 30% phased utilization',
        'budget_planning_utilization_percent' => 30,
        'planned_developer_count' => $plannedDeveloperCount,
        'methodology' => (string) ($template['methodology'] ?? ''),
        'core_features' => (string) ($template['core_features'] ?? ''),
        'feature_items' => $features,
        'tech_stack' => (string) ($template['tech_stack'] ?? ''),
        'tech_items' => $techStack,
        'team_composition' => (string) ($template['team_composition'] ?? ''),
        'complexity_level' => (string) ($template['complexity_level'] ?? 'medium'),
        'payment_terms' => (string) ($template['payment_terms'] ?? ''),
        'sales_notes' => (string) ($template['sales_notes'] ?? ''),
        'integrations' => (string) ($template['integrations'] ?? ''),
        'security_requirements' => (string) ($template['security_requirements'] ?? ''),
        'architecture_notes' => (string) ($template['architecture_notes'] ?? ''),
        'workload_estimate' => (string) ($template['workload_estimate'] ?? ''),
        'project_description' => (string) ($template['project_description'] ?? ''),
        'suggested_milestones' => (string) ($template['suggested_milestones'] ?? ''),
        'default_project_phases' => (string) ($template['default_project_phases'] ?? ''),
        'functional_requirements' => (string) ($template['functional_requirements'] ?? ''),
        'non_functional_requirements' => (string) ($template['non_functional_requirements'] ?? ''),
        'third_party_services' => (string) ($template['third_party_services'] ?? ''),
        'hosting_platform' => (string) ($template['hosting_platform'] ?? ''),
        'deployment_environment' => (string) ($template['deployment_environment'] ?? ''),
        'database_technology' => (string) ($template['database_technology'] ?? ''),
        'auth_method' => (string) ($template['auth_method'] ?? ''),
        'backup_recovery_strategy' => (string) ($template['backup_recovery_strategy'] ?? ''),
        'developer_roles_allocation' => (string) ($template['developer_roles_allocation'] ?? ''),
        'developer_work_plan' => $developerWorkPlan,
        'testing_requirements' => (string) ($template['testing_requirements'] ?? ''),
        'documentation_requirements' => (string) ($template['documentation_requirements'] ?? ''),
        'maintenance_support' => (string) ($template['maintenance_support'] ?? ''),
        'risk_assessment' => (string) ($template['risk_assessment'] ?? ''),
        'implementation_notes' => (string) ($template['implementation_notes'] ?? ''),
        'usage_count' => (int) ($template['usage_count'] ?? 0),
    ];
}

function projectTemplateEnsureSchema(mysqli|PgsqlMysqliCompatConnection $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $textType = DB_DRIVER === 'pgsql' ? 'TEXT' : 'LONGTEXT';
    $idType = DB_DRIVER === 'pgsql' ? 'INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY' : 'INT PRIMARY KEY AUTO_INCREMENT';
    $uniqueSql = DB_DRIVER === 'pgsql' ? 'UNIQUE (normalized_type)' : 'UNIQUE KEY unique_project_template_type (normalized_type)';

    $conn->query("
        CREATE TABLE IF NOT EXISTS project_templates (
            id {$idType},
            project_type VARCHAR(120) NOT NULL,
            normalized_type VARCHAR(120) NOT NULL,
            template_version INT NOT NULL DEFAULT 1,
            duration_label VARCHAR(80) NULL,
            duration_min_weeks INT DEFAULT 0,
            duration_max_weeks INT DEFAULT 0,
            budget_min DECIMAL(15, 2) DEFAULT 0,
            budget_max DECIMAL(15, 2) DEFAULT 0,
            methodology VARCHAR(80) NULL,
            core_features {$textType} NULL,
            tech_stack {$textType} NULL,
            team_composition {$textType} NULL,
            complexity_level VARCHAR(40) DEFAULT 'medium',
            payment_terms VARCHAR(255) NULL,
            sales_notes {$textType} NULL,
            integrations {$textType} NULL,
            security_requirements {$textType} NULL,
            architecture_notes {$textType} NULL,
            workload_estimate VARCHAR(120) NULL,
            project_description {$textType} NULL,
            suggested_milestones {$textType} NULL,
            default_project_phases {$textType} NULL,
            functional_requirements {$textType} NULL,
            non_functional_requirements {$textType} NULL,
            third_party_services {$textType} NULL,
            hosting_platform VARCHAR(120) NULL,
            deployment_environment VARCHAR(120) NULL,
            database_technology VARCHAR(120) NULL,
            auth_method VARCHAR(160) NULL,
            backup_recovery_strategy {$textType} NULL,
            developer_roles_allocation {$textType} NULL,
            testing_requirements {$textType} NULL,
            documentation_requirements {$textType} NULL,
            maintenance_support {$textType} NULL,
            risk_assessment {$textType} NULL,
            implementation_notes {$textType} NULL,
            source VARCHAR(40) DEFAULT 'seed',
            usage_count INT DEFAULT 0,
            learned_from_project_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP" . (DB_DRIVER === 'pgsql' ? '' : ' ON UPDATE CURRENT_TIMESTAMP') . ",
            {$uniqueSql}
        )
    ");

    $templateColumns = [
        'template_version' => 'INT NOT NULL DEFAULT 1',
        'project_description' => "{$textType} NULL",
        'suggested_milestones' => "{$textType} NULL",
        'default_project_phases' => "{$textType} NULL",
        'functional_requirements' => "{$textType} NULL",
        'non_functional_requirements' => "{$textType} NULL",
        'third_party_services' => "{$textType} NULL",
        'hosting_platform' => 'VARCHAR(120) NULL',
        'deployment_environment' => 'VARCHAR(120) NULL',
        'database_technology' => 'VARCHAR(120) NULL',
        'auth_method' => 'VARCHAR(160) NULL',
        'backup_recovery_strategy' => "{$textType} NULL",
        'developer_roles_allocation' => "{$textType} NULL",
        'testing_requirements' => "{$textType} NULL",
        'documentation_requirements' => "{$textType} NULL",
        'maintenance_support' => "{$textType} NULL",
        'risk_assessment' => "{$textType} NULL",
        'implementation_notes' => "{$textType} NULL",
    ];
    foreach ($templateColumns as $column => $definition) {
        if (!dbColumnExists($conn, 'project_templates', $column)) {
            $conn->query("ALTER TABLE project_templates ADD COLUMN {$column} {$definition}");
        }
    }

    $projectColumns = [
        'project_type' => 'VARCHAR(80) NULL',
        'project_template_id' => 'INT NULL',
        'project_template_snapshot' => $textType . ' NULL',
    ];
    foreach ($projectColumns as $column => $definition) {
        if (!dbColumnExists($conn, 'projects', $column)) {
            $conn->query("ALTER TABLE projects ADD COLUMN {$column} {$definition}");
        }
    }

    foreach (PROJECT_TEMPLATE_DEFAULTS as $seed) {
        [$type, $minWeeks, $maxWeeks, $minBudget, $maxBudget, $methodology, $features, $techStack, $team] = $seed;
        $methodology = projectTemplateRecommendedMethodology((string) $type, (string) $features, (int) $maxWeeks, (float) $maxBudget, (string) $methodology);
        $team = projectTemplateRecommendedTeam((string) $type, (string) $features, (string) $techStack, (int) $maxWeeks, (float) $maxBudget);
        $complexity = projectTemplateComplexityForRange((int) $maxWeeks, (float) $maxBudget, (string) $team);
        $durationLabel = $minWeeks . '-' . $maxWeeks . ' weeks';
        $paymentTerms = 'Recommended terms: 40% down payment, 40% development milestone, 20% upon acceptance.';
        $salesNotes = 'Template recommendation based on common ' . $type . ' scope. Confirm client-specific requirements before submission.';
        $integrations = stripos($features, 'Payment') !== false || stripos($features, 'Checkout') !== false
            ? 'Payment gateway and notification services may be required.'
            : 'Confirm third-party integrations during discovery.';
        $security = 'Role-based access, input validation, audit-friendly logs, and secure credential handling.';
        $architectureNotes = 'Use the recommended methodology, stack, and team as a starting point. Adjust for client scale and compliance needs.';
        $workload = $durationLabel . ' with ' . $team;
        $details = projectTemplateDefaultDetails((string) $type, (string) $features, (string) $techStack, (string) $team, (string) $methodology, $durationLabel);
        $normalized = projectTemplateNormalizeType((string) $type);

        $existingTemplate = null;
        $existingStmt = $conn->prepare('SELECT id, source FROM project_templates WHERE normalized_type = ? LIMIT 1');
        if ($existingStmt !== false) {
            $existingStmt->bind_param('s', $normalized);
            $existingStmt->execute();
            $existingTemplate = $existingStmt->get_result()->fetch_assoc() ?: null;
            $existingStmt->close();
        }

        if ($existingTemplate) {
            $templateId = (int) ($existingTemplate['id'] ?? 0);
            $updateStmt = $conn->prepare("
                UPDATE project_templates
                SET duration_label = ?, duration_min_weeks = ?, duration_max_weeks = ?,
                    budget_min = ?, budget_max = ?, methodology = ?, core_features = ?,
                    tech_stack = ?, team_composition = ?, complexity_level = ?, payment_terms = ?,
                    sales_notes = ?, integrations = ?, security_requirements = ?, architecture_notes = ?,
                    workload_estimate = ?, project_description = ?, suggested_milestones = ?,
                    default_project_phases = ?, functional_requirements = ?, non_functional_requirements = ?,
                    third_party_services = ?, hosting_platform = ?, deployment_environment = ?,
                    database_technology = ?, auth_method = ?, backup_recovery_strategy = ?,
                    developer_roles_allocation = ?, testing_requirements = ?, documentation_requirements = ?,
                    maintenance_support = ?, risk_assessment = ?, implementation_notes = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            if ($updateStmt !== false) {
                $types = 'siidd' . str_repeat('s', 28) . 'i';
                $updateStmt->bind_param(
                    $types,
                    $durationLabel,
                    $minWeeks,
                    $maxWeeks,
                    $minBudget,
                    $maxBudget,
                    $methodology,
                    $features,
                    $techStack,
                    $team,
                    $complexity,
                    $paymentTerms,
                    $salesNotes,
                    $integrations,
                    $security,
                    $architectureNotes,
                    $workload,
                    $details['project_description'],
                    $details['suggested_milestones'],
                    $details['default_project_phases'],
                    $details['functional_requirements'],
                    $details['non_functional_requirements'],
                    $details['third_party_services'],
                    $details['hosting_platform'],
                    $details['deployment_environment'],
                    $details['database_technology'],
                    $details['auth_method'],
                    $details['backup_recovery_strategy'],
                    $details['developer_roles_allocation'],
                    $details['testing_requirements'],
                    $details['documentation_requirements'],
                    $details['maintenance_support'],
                    $details['risk_assessment'],
                    $details['implementation_notes'],
                    $templateId
                );
                $updateStmt->execute();
                $updateStmt->close();
            }
            continue;
        }

        $stmt = $conn->prepare("
            INSERT INTO project_templates (
                project_type, normalized_type, duration_label, duration_min_weeks, duration_max_weeks,
                budget_min, budget_max, methodology, core_features, tech_stack, team_composition,
                complexity_level, payment_terms, sales_notes, integrations, security_requirements,
                architecture_notes, workload_estimate, project_description, suggested_milestones,
                default_project_phases, functional_requirements, non_functional_requirements,
                third_party_services, hosting_platform, deployment_environment, database_technology,
                auth_method, backup_recovery_strategy, developer_roles_allocation, testing_requirements,
                documentation_requirements, maintenance_support, risk_assessment, implementation_notes, source
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'seed')
        ");
        $types = 'sssiidd' . str_repeat('s', 28);
        $stmt->bind_param(
            $types,
            $type,
            $normalized,
            $durationLabel,
            $minWeeks,
            $maxWeeks,
            $minBudget,
            $maxBudget,
            $methodology,
            $features,
            $techStack,
            $team,
            $complexity,
            $paymentTerms,
            $salesNotes,
            $integrations,
            $security,
            $architectureNotes,
            $workload,
            $details['project_description'],
            $details['suggested_milestones'],
            $details['default_project_phases'],
            $details['functional_requirements'],
            $details['non_functional_requirements'],
            $details['third_party_services'],
            $details['hosting_platform'],
            $details['deployment_environment'],
            $details['database_technology'],
            $details['auth_method'],
            $details['backup_recovery_strategy'],
            $details['developer_roles_allocation'],
            $details['testing_requirements'],
            $details['documentation_requirements'],
            $details['maintenance_support'],
            $details['risk_assessment'],
            $details['implementation_notes']
        );
        $stmt->execute();
        $stmt->close();
    }
}

function projectTemplateResolveForProject(mysqli|PgsqlMysqliCompatConnection $conn, array $project): ?array
{
    $snapshot = !empty($project['project_template_snapshot'])
        ? json_decode((string) $project['project_template_snapshot'], true)
        : null;
    if (is_array($snapshot) && (int) ($snapshot['planning_model_version'] ?? 0) >= 2) {
        if (empty($snapshot['developer_work_plan']) || !is_array($snapshot['developer_work_plan'])) {
            $snapshot['developer_work_plan'] = projectTemplateDeveloperWorkPlan(
                (string) ($snapshot['project_type'] ?? ($project['project_type'] ?? '')),
                (string) ($snapshot['core_features'] ?? ($project['requested_features'] ?? '')),
                (string) ($snapshot['tech_stack'] ?? ''),
                (string) (($snapshot['developer_roles_allocation'] ?? '') ?: ($snapshot['team_composition'] ?? ''))
            );
        }
        $snapshot['planning_model_version'] = 3;
        return $snapshot;
    }
    return projectTemplateFindByType($conn, (string) ($project['project_type'] ?? ''));
}

function projectTemplateAll(mysqli|PgsqlMysqliCompatConnection $conn): array
{
    projectTemplateEnsureSchema($conn);
    $rows = [];
    $result = $conn->query('SELECT * FROM project_templates ORDER BY project_type ASC, template_version DESC, usage_count DESC');
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = projectTemplatePayload($row);
        }
    }
    return $rows;
}

function projectTemplateFindByType(mysqli|PgsqlMysqliCompatConnection $conn, string $projectType): ?array
{
    projectTemplateEnsureSchema($conn);
    $needle = projectTemplateNormalizeType($projectType);
    if ($needle === '') {
        return null;
    }

    $best = null;
    foreach (projectTemplateAll($conn) as $template) {
        $candidate = projectTemplateNormalizeType((string) $template['project_type']);
        if ($candidate === $needle) {
            return $template;
        }
        if ($best === null && (str_contains($candidate, $needle) || str_contains($needle, $candidate))) {
            $best = $template;
        }
    }

    return $best;
}

function projectTemplateFindById(mysqli|PgsqlMysqliCompatConnection $conn, int $templateId): ?array
{
    projectTemplateEnsureSchema($conn);
    if ($templateId <= 0) {
        return null;
    }
    $stmt = $conn->prepare('SELECT * FROM project_templates WHERE id = ? LIMIT 1');
    if ($stmt === false) {
        return null;
    }
    $stmt->bind_param('i', $templateId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row ? projectTemplatePayload($row) : null;
}

function projectTemplateCaptureProject(mysqli|PgsqlMysqliCompatConnection $conn, int $projectId): void
{
    projectTemplateEnsureSchema($conn);
    $project = $conn->query("SELECT * FROM projects WHERE id = {$projectId} LIMIT 1")->fetch_assoc();
    if (!$project) {
        return;
    }

    $projectType = trim((string) ($project['project_type'] ?? ''));
    if ($projectType === '') {
        return;
    }

    $techItems = [];
    if (dbTableExists($conn, 'tech_recommendations')) {
        $techResult = $conn->query("
            SELECT t.name
            FROM tech_recommendations tr
            JOIN technologies t ON t.id = tr.tech_id
            WHERE tr.project_id = {$projectId}
            ORDER BY t.name ASC
        ");
        if ($techResult) {
            while ($row = $techResult->fetch_assoc()) {
                $techItems[] = (string) $row['name'];
            }
        }
    }

    $teamItems = [];
    if (dbTableExists($conn, 'developer_allocations')) {
        $teamResult = $conn->query("SELECT role, required_count FROM developer_allocations WHERE project_id = {$projectId} ORDER BY role ASC");
        if ($teamResult) {
            while ($row = $teamResult->fetch_assoc()) {
                $teamItems[] = (int) ($row['required_count'] ?? 0) . ' ' . (string) ($row['role'] ?? 'Developer');
            }
        }
    }

    $features = trim((string) ($project['requested_features'] ?? ''));
    $features = preg_replace('/\R{2,}Additional Requirements:.*/is', '', $features) ?: $features;
    $methodology = trim((string) ($project['methodology_template'] ?? '')) ?: 'Agile';
    $budget = (float) ($project['sales_budget'] ?? 0);
    $timelineWeeks = 0;
    if (!empty($project['timeline_start']) && !empty($project['timeline_end'])) {
        $start = strtotime((string) $project['timeline_start']);
        $end = strtotime((string) $project['timeline_end']);
        if ($start !== false && $end !== false && $end >= $start) {
            $timelineWeeks = max(1, (int) ceil(((int) floor(($end - $start) / 86400) + 1) / 7));
        }
    }

    $existing = projectTemplateFindByType($conn, $projectType);
    $normalized = projectTemplateNormalizeType($projectType);
    $templateWeeks = $timelineWeeks > 0 ? $timelineWeeks : (int) ($existing['duration_max_weeks'] ?? 0);
    $durationLabel = $templateWeeks > 0 ? $templateWeeks . ' weeks' : (string) ($existing['duration_label'] ?? '');
    $budgetMin = $budget > 0 ? $budget : (float) ($existing['budget_min'] ?? 0);
    $budgetMax = $budget > 0 ? $budget : (float) ($existing['budget_max'] ?? 0);
    $techStack = implode(', ', array_unique(array_filter($techItems))) ?: (string) ($existing['tech_stack'] ?? '');
    $team = implode(', ', array_unique(array_filter($teamItems))) ?: (string) ($existing['team_composition'] ?? '');
    $complexity = strtolower((string) ($project['complexity_level'] ?? ($existing['complexity_level'] ?? 'medium')));
    $details = projectTemplateDefaultDetails($projectType, $features, $techStack, $team, $methodology, $durationLabel);

    if ($existing && (int) ($existing['id'] ?? 0) > 0) {
        $unchanged = trim((string) ($existing['core_features'] ?? '')) === $features
            && trim((string) ($existing['methodology'] ?? '')) === $methodology
            && trim((string) ($existing['tech_stack'] ?? '')) === $techStack
            && trim((string) ($existing['team_composition'] ?? '')) === $team
            && (float) ($existing['budget_min'] ?? 0) === $budgetMin
            && (int) ($existing['duration_max_weeks'] ?? 0) === $templateWeeks
            && strtolower((string) ($existing['complexity_level'] ?? 'medium')) === $complexity;
        if ($unchanged) {
            $templateId = (int) $existing['id'];
            $usageStmt = $conn->prepare('UPDATE project_templates SET usage_count = usage_count + 1, learned_from_project_id = ? WHERE id = ?');
            if ($usageStmt !== false) {
                $usageStmt->bind_param('ii', $projectId, $templateId);
                $usageStmt->execute();
                $usageStmt->close();
            }
            return;
        }
    }

    $nextVersion = 1;
    if ($existing) {
        $versionStmt = $conn->prepare('SELECT MAX(template_version) AS max_version FROM project_templates WHERE project_type = ?');
        if ($versionStmt !== false) {
            $versionStmt->bind_param('s', $projectType);
            $versionStmt->execute();
            $versionRow = $versionStmt->get_result()->fetch_assoc();
            $nextVersion = max(2, (int) ($versionRow['max_version'] ?? 1) + 1);
            $versionStmt->close();
        }
        $normalized .= '::v' . $nextVersion;
    }

        $stmt = $conn->prepare("
            INSERT INTO project_templates (
                project_type, normalized_type, template_version, duration_label, duration_min_weeks, duration_max_weeks,
                budget_min, budget_max, methodology, core_features, tech_stack, team_composition,
                complexity_level, project_description, suggested_milestones, default_project_phases,
                functional_requirements, non_functional_requirements, third_party_services, hosting_platform,
                deployment_environment, database_technology, auth_method, backup_recovery_strategy,
                developer_roles_allocation, testing_requirements, documentation_requirements,
                maintenance_support, risk_assessment, implementation_notes, source, usage_count,
                learned_from_project_id
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                'learned', 1, ?
            )
        ");
        $types = 'ssi' . 'siidd' . str_repeat('s', 22) . 'i';
        $stmt->bind_param(
            $types,
            $projectType,
            $normalized,
            $nextVersion,
            $durationLabel,
            $templateWeeks,
            $templateWeeks,
            $budgetMin,
            $budgetMax,
            $methodology,
            $features,
            $techStack,
            $team,
            $complexity,
            $details['project_description'],
            $details['suggested_milestones'],
            $details['default_project_phases'],
            $details['functional_requirements'],
            $details['non_functional_requirements'],
            $details['third_party_services'],
            $details['hosting_platform'],
            $details['deployment_environment'],
            $details['database_technology'],
            $details['auth_method'],
            $details['backup_recovery_strategy'],
            $details['developer_roles_allocation'],
            $details['testing_requirements'],
            $details['documentation_requirements'],
            $details['maintenance_support'],
            $details['risk_assessment'],
            $details['implementation_notes'],
            $projectId
        );
    $stmt->execute();
    $stmt->close();
}
