<?php

return [
    [
        'title' => 'Background and digital skills',
        'questions' => [
            ['key' => 'background_areas', 'type' => 'multiple', 'label' => 'Which areas are you familiar with?', 'options' => ['Healthcare', 'Education', 'Retail and commerce', 'Software and technology', 'Other']],
            ['key' => 'digital_skills', 'type' => 'multiple', 'label' => 'Which digital skills do you have?', 'options' => ['Email and internet', 'Office applications', 'Data entry', 'Software development', 'Technical support', 'Other']],
            ['key' => 'experience_level', 'type' => 'single', 'label' => 'How would you describe your overall experience?', 'options' => ['Beginner', 'Intermediate', 'Advanced']],
        ],
    ],
    [
        'title' => 'Installation and connectivity',
        'questions' => [
            ['key' => 'installation_experience', 'type' => 'yes_no', 'label' => 'Have you installed software or hardware before?', 'options' => ['Yes', 'No']],
            ['key' => 'installation_skills', 'type' => 'multiple', 'label' => 'Which installation tasks have you done?', 'options' => ['Install applications', 'Set up computers or peripherals', 'Configure operating systems', 'Troubleshoot installations', 'Other']],
            ['key' => 'networking_experience', 'type' => 'yes_no', 'label' => 'Have you set up or supported networks or internet connections?', 'options' => ['Yes', 'No']],
            ['key' => 'networking_skills', 'type' => 'multiple', 'label' => 'Which connectivity tasks have you done?', 'options' => ['Set up Wi-Fi', 'Configure routers', 'Set up a local network', 'Troubleshoot internet connections', 'Other']],
        ],
    ],
    [
        'title' => 'Business and management',
        'questions' => [
            ['key' => 'business_skills', 'type' => 'multiple', 'label' => 'Which business skills do you have?', 'options' => ['Sales', 'Marketing', 'Customer service', 'Business development', 'Finance or bookkeeping', 'Other']],
            ['key' => 'management_experience', 'type' => 'yes_no', 'label' => 'Have you managed people or projects?', 'options' => ['Yes', 'No']],
            ['key' => 'management_skills', 'type' => 'multiple', 'label' => 'Which management activities have you done?', 'options' => ['Team leadership', 'Project coordination', 'Planning and budgeting', 'Training', 'Operations management', 'Other']],
        ],
    ],
];
