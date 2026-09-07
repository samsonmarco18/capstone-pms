-- Dashboard demo data for Render Postgres. Safe to run on every deployment.

INSERT INTO clients (
    name, contact_person, email, phone, address, company_type, industry,
    status, deadline, created_by, created_at
)
SELECT *
FROM (
    VALUES
        ('Northstar Retail Group', 'Ava Reyes', 'ava.reyes@northstar.example', '+63 917 555 0101', 'Makati, Philippines', 'Corporation', 'Retail', 'active', CURRENT_DATE + INTERVAL '4 months', 1, CURRENT_TIMESTAMP - INTERVAL '5 months'),
        ('BrightCare Clinics', 'Miguel Santos', 'miguel.santos@brightcare.example', '+63 917 555 0102', 'Quezon City, Philippines', 'Company', 'Healthcare', 'active', CURRENT_DATE + INTERVAL '4 months', 1, CURRENT_TIMESTAMP - INTERVAL '4 months'),
        ('Summit Logistics', 'Leah Cruz', 'leah.cruz@summitlogistics.example', '+63 917 555 0103', 'Taguig, Philippines', 'Corporation', 'Logistics', 'active', CURRENT_DATE + INTERVAL '5 months', 1, CURRENT_TIMESTAMP - INTERVAL '3 months'),
        ('Harbor Hospitality', 'Noah Garcia', 'noah.garcia@harborhospitality.example', '+63 917 555 0104', 'Pasay, Philippines', 'Corporation', 'Hospitality', 'active', CURRENT_DATE + INTERVAL '5 months', 1, CURRENT_TIMESTAMP - INTERVAL '2 months'),
        ('Verde Services', 'Isla Mendoza', 'isla.mendoza@verdeservices.example', '+63 917 555 0105', 'Cebu City, Philippines', 'Company', 'Professional Services', 'active', CURRENT_DATE + INTERVAL '6 months', 1, CURRENT_TIMESTAMP - INTERVAL '1 month'),
        ('Atlas Field Operations', 'Ethan Lim', 'ethan.lim@atlasfield.example', '+63 917 555 0106', 'Davao City, Philippines', 'Corporation', 'Field Services', 'active', CURRENT_DATE + INTERVAL '6 months', 1, CURRENT_TIMESTAMP - INTERVAL '12 days')
) AS seed(name, contact_person, email, phone, address, company_type, industry, status, deadline, created_by, created_at)
WHERE NOT EXISTS (SELECT 1 FROM clients WHERE clients.email = seed.email);

INSERT INTO projects (
    client_id, name, description, status, timeline_start, timeline_end,
    project_type, methodology_template, sales_budget, complexity_level,
    created_by, architect_id, sul_id, created_at
)
SELECT
    client.id, seed.name, seed.description, seed.status, seed.timeline_start,
    seed.timeline_end, seed.project_type, seed.methodology_template,
    seed.sales_budget, seed.complexity_level, 1, 3, 4, seed.created_at
FROM (
    VALUES
        ('ava.reyes@northstar.example', 'Inventory Visibility Portal', 'Sample completed project for dashboard reporting.', 'completed', CURRENT_DATE - INTERVAL '7 months', CURRENT_DATE - INTERVAL '5 months', 'Business Portal', 'agile', 680000.00, 'medium', CURRENT_TIMESTAMP - INTERVAL '5 months'),
        ('miguel.santos@brightcare.example', 'Patient Appointment Kiosk', 'Sample completed project for dashboard reporting.', 'completed', CURRENT_DATE - INTERVAL '7 months', CURRENT_DATE - INTERVAL '5 months', 'Healthcare Portal', 'scrum', 720000.00, 'medium', CURRENT_TIMESTAMP - INTERVAL '5 months'),
        ('ava.reyes@northstar.example', 'Supplier Onboarding Hub', 'Sample active project for dashboard reporting.', 'in_progress', CURRENT_DATE - INTERVAL '5 months', CURRENT_DATE + INTERVAL '1 month', 'Business Portal', 'agile', 850000.00, 'medium', CURRENT_TIMESTAMP - INTERVAL '4 months'),
        ('miguel.santos@brightcare.example', 'Clinic Billing Upgrade', 'Sample completed project for dashboard reporting.', 'completed', CURRENT_DATE - INTERVAL '6 months', CURRENT_DATE - INTERVAL '3 months', 'Healthcare System', 'waterfall', 910000.00, 'medium', CURRENT_TIMESTAMP - INTERVAL '4 months'),
        ('leah.cruz@summitlogistics.example', 'Customer Support Workspace', 'Sample active project for dashboard reporting.', 'review', CURRENT_DATE - INTERVAL '4 months', CURRENT_DATE + INTERVAL '1 month', 'Support Platform', 'scrum', 760000.00, 'medium', CURRENT_TIMESTAMP - INTERVAL '3 months'),
        ('miguel.santos@brightcare.example', 'Medical Records Archive', 'Sample completed project for dashboard reporting.', 'completed', CURRENT_DATE - INTERVAL '5 months', CURRENT_DATE - INTERVAL '2 months', 'Healthcare System', 'waterfall', 980000.00, 'complex', CURRENT_TIMESTAMP - INTERVAL '3 months'),
        ('noah.garcia@harborhospitality.example', 'Sales Performance Dashboard', 'Sample completed project for dashboard reporting.', 'completed', CURRENT_DATE - INTERVAL '4 months', CURRENT_DATE - INTERVAL '1 month', 'Analytics Dashboard', 'agile', 640000.00, 'medium', CURRENT_TIMESTAMP - INTERVAL '2 months'),
        ('miguel.santos@brightcare.example', 'Teleconsultation Scheduler', 'Sample active project for dashboard reporting.', 'in_development', CURRENT_DATE - INTERVAL '3 months', CURRENT_DATE + INTERVAL '2 months', 'Healthcare Portal', 'scrum', 1250000.00, 'complex', CURRENT_TIMESTAMP - INTERVAL '2 months'),
        ('isla.mendoza@verdeservices.example', 'Partner Reporting Center', 'Sample completed project for dashboard reporting.', 'completed', CURRENT_DATE - INTERVAL '3 months', CURRENT_DATE - INTERVAL '1 month', 'Reporting Portal', 'agile', 590000.00, 'medium', CURRENT_TIMESTAMP - INTERVAL '1 month'),
        ('miguel.santos@brightcare.example', 'Laboratory Results Viewer', 'Sample active project for dashboard reporting.', 'requirements_gathering', CURRENT_DATE - INTERVAL '1 month', CURRENT_DATE + INTERVAL '3 months', 'Healthcare Portal', 'agile', 870000.00, 'medium', CURRENT_TIMESTAMP - INTERVAL '1 month'),
        ('ethan.lim@atlasfield.example', 'Field Service Mobile Portal', 'Sample active project for dashboard reporting.', 'architectural_review', CURRENT_DATE, CURRENT_DATE + INTERVAL '4 months', 'Mobile Portal', 'scrum', 1020000.00, 'complex', CURRENT_TIMESTAMP - INTERVAL '12 days'),
        ('miguel.santos@brightcare.example', 'Patient Feedback Dashboard', 'Sample completed project for dashboard reporting.', 'completed', CURRENT_DATE - INTERVAL '2 months', CURRENT_DATE - INTERVAL '5 days', 'Analytics Dashboard', 'agile', 480000.00, 'simple', CURRENT_TIMESTAMP - INTERVAL '4 days')
) AS seed(client_email, name, description, status, timeline_start, timeline_end, project_type, methodology_template, sales_budget, complexity_level, created_at)
JOIN clients AS client ON client.email = seed.client_email
WHERE NOT EXISTS (SELECT 1 FROM projects WHERE projects.name = seed.name);
