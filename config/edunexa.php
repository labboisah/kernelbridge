<?php

return [
    'currency' => 'NGN',
    'core_module' => 'core-school-operations',
    'modules' => [
        [
            'key' => 'core-school-operations', 'name' => 'Core School Operations', 'status' => 'ready', 'monthly_price' => 250, 'setup_fee' => 500,
            'description' => 'Essential school administration for users, roles, classes, sections, academic calendar and dashboard operations.',
            'features' => [
                ['name' => 'User and access management', 'status' => 'ready', 'description' => 'Admin users, roles, permissions and secure access control.'],
                ['name' => 'Academic sessions and terms', 'status' => 'ready', 'description' => 'Academic year configuration, session management and term setup.'],
                ['name' => 'Sections and class management', 'status' => 'ready', 'description' => 'School structures, classes, streams and allocation controls.'],
                ['name' => 'School dashboard and reports', 'status' => 'ready', 'description' => 'Summary reporting and operational visibility for administrators.'],
            ],
        ],
        [
            'key' => 'admission-and-enrollment', 'name' => 'Admission and Enrollment', 'status' => 'ready', 'monthly_price' => 180, 'setup_fee' => 400,
            'description' => 'Application intake, enrollment, student data capture and student onboarding workflow.',
            'features' => [
                ['name' => 'Student application intake', 'status' => 'ready', 'description' => 'Capture prospective student information and application records.'],
                ['name' => 'Enrollment workflow', 'status' => 'ready', 'description' => 'Track enrolment decisions, approvals and student activation.'],
                ['name' => 'Student identity and profile management', 'status' => 'ready', 'description' => 'Maintain student records and profile information.'],
                ['name' => 'Document and admission tracking', 'status' => 'not_ready', 'description' => 'Bulk document verification and admission checklist tracking.'],
            ],
        ],
        [
            'key' => 'academic-records-and-results', 'name' => 'Academic Records and Results', 'status' => 'ready', 'monthly_price' => 260, 'setup_fee' => 600,
            'description' => 'Academic classification, subjects, continuous assessments, examinations and report card generation.',
            'features' => [
                ['name' => 'Subjects and class allocations', 'status' => 'ready', 'description' => 'Manage subjects and assign them to classes.'],
                ['name' => 'Continuous assessment and exam uploads', 'status' => 'ready', 'description' => 'Collect and process assessments and exam results.'],
                ['name' => 'Result publication and report cards', 'status' => 'ready', 'description' => 'Generate report cards and publish student results.'],
                ['name' => 'Versioned grading and remark policy', 'status' => 'not_ready', 'description' => 'Per-school, per-session and historical grading configuration.'],
            ],
        ],
        [
            'key' => 'finance-and-fees', 'name' => 'Finance and Fees', 'status' => 'ready', 'monthly_price' => 220, 'setup_fee' => 450,
            'description' => 'Fees, payments, receipts, discounts and reconciliation workflows.',
            'features' => [
                ['name' => 'Fee configuration', 'status' => 'ready', 'description' => 'Configure fee items and class-based billing rules.'],
                ['name' => 'Payment collection and receipts', 'status' => 'ready', 'description' => 'Record payments, print receipts and reconcile cash flows.'],
                ['name' => 'Advanced discounts and waivers', 'status' => 'not_ready', 'description' => 'Flexible discount rules, scholarship structures and waiver approvals.'],
                ['name' => 'Finance audit trail', 'status' => 'not_ready', 'description' => 'Immutable transaction logs and approval history for financial records.'],
            ],
        ],
        [
            'key' => 'inventory-and-stock-control', 'name' => 'Inventory and Stock Control', 'status' => 'ready', 'monthly_price' => 180, 'setup_fee' => 350,
            'description' => 'Inventory items, stock movement, sales and rental records for school resources.',
            'features' => [
                ['name' => 'Inventory items and stock batches', 'status' => 'ready', 'description' => 'Track stock items and batch records.'],
                ['name' => 'Sales and rentals', 'status' => 'ready', 'description' => 'Record item sales and rental movements.'],
                ['name' => 'Usage tracking and reconciliation', 'status' => 'not_ready', 'description' => 'Detailed usage records, cost reconciliation and audit review.'],
            ],
        ],
        [
            'key' => 'parent-and-guardian-portal', 'name' => 'Parent and Guardian Portal', 'status' => 'ready', 'monthly_price' => 140, 'setup_fee' => 300,
            'description' => 'A secure parent communication and student access experience.',
            'features' => [
                ['name' => 'Guardian relationship records', 'status' => 'not_ready', 'description' => 'Verified guardian-to-child mapping and record access.'],
                ['name' => 'Result access and notifications', 'status' => 'not_ready', 'description' => 'Parent access to results, notices and reporting updates.'],
                ['name' => 'Consent and communication preferences', 'status' => 'not_ready', 'description' => 'Manage communication channels, consent and alerts.'],
            ],
        ],
        [
            'key' => 'timetable-and-attendance', 'name' => 'Timetable and Attendance', 'status' => 'ready', 'monthly_price' => 200, 'setup_fee' => 400,
            'description' => 'Class timetables, room allocation, attendance and time tracking.',
            'features' => [
                ['name' => 'Schedule planning', 'status' => 'not_ready', 'description' => 'Create class and subject timetable schedules.'],
                ['name' => 'Attendance tracking', 'status' => 'not_ready', 'description' => 'Capture daily attendance across classes.'],
                ['name' => 'Absence monitoring and alerts', 'status' => 'not_ready', 'description' => 'Generate alerts for absenteeism and follow-up actions.'],
            ],
        ],
        [
            'key' => 'staff-hr-and-payroll', 'name' => 'Staff HR and Payroll', 'status' => 'ready', 'monthly_price' => 220, 'setup_fee' => 500,
            'description' => 'Staff records, leave, contracts, payroll interfaces and performance workflows.',
            'features' => [
                ['name' => 'Staff profiles and contracts', 'status' => 'not_ready', 'description' => 'Manage staff identity, employment records and contracts.'],
                ['name' => 'Leave and attendance tracking', 'status' => 'not_ready', 'description' => 'Track leave requests and staffing attendance.'],
                ['name' => 'Payroll and payout workflows', 'status' => 'not_ready', 'description' => 'Prepare payroll interfaces and compensation reporting.'],
            ],
        ],
        [
            'key' => 'multi-school-tenancy-and-saas', 'name' => 'Multi-School Tenancy and SaaS', 'status' => 'ready', 'monthly_price' => 300, 'setup_fee' => 800,
            'description' => 'Tenant-level isolation, multi-school configuration, billing and enterprise SaaS controls.',
            'features' => [
                ['name' => 'School tenant boundaries', 'status' => 'not_ready', 'description' => 'Strict tenant isolation and per-school configuration.'],
                ['name' => 'Billing and customer management', 'status' => 'not_ready', 'description' => 'Subscription billing, plan controls and customer lifecycle support.'],
                ['name' => 'Role and data isolation', 'status' => 'not_ready', 'description' => 'Tenant-aware permissions and protected data separation.'],
            ],
        ],
        [
            'key' => 'api-integrations-and-analytics', 'name' => 'API, Integrations and Analytics', 'status' => 'ready', 'monthly_price' => 180, 'setup_fee' => 400,
            'description' => 'Exports, APIs, integrations and analytics for enterprise growth and interoperability.',
            'features' => [
                ['name' => 'CSV and Excel import/export', 'status' => 'not_ready', 'description' => 'Standardized import and export templates for bulk operations.'],
                ['name' => 'API access and webhooks', 'status' => 'not_ready', 'description' => 'Stable API and event integration endpoints.'],
                ['name' => 'Institution analytics and dashboards', 'status' => 'not_ready', 'description' => 'Advanced reporting dashboards and business intelligence views.'],
            ],
        ],
    ],
];