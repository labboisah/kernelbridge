<?php

return [
    'channels' => ['database' => true, 'mail' => true, 'sms' => false, 'whatsapp' => false],
    'license_reminder_days' => (int) env('LICENSE_REMINDER_DAYS', 30),
    'stale_lead_days' => (int) env('STALE_LEAD_DAYS', 3),
    'demo_session_retention_hours' => (int) env('DEMO_SESSION_RETENTION_HOURS', 24),
];
