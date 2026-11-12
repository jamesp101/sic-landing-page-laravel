<?php

return [
    // Site origin used for canonical URLs, sitemap and Open Graph.
    'site_url' => rtrim((string) env('APP_URL', 'http://localhost:8000'), '/'),
    'name' => env('APP_NAME', 'San Isidro College'),

    // Path Vite looks at for a running dev server instead of public/hot (see AppServiceProvider).
    'vite_hot_file' => env('VITE_HOT_FILE'),

    'contact_to' => env('CONTACT_TO_ADDRESS', 'info@sic.edu.ph'),

    'storage' => [
        // local | s3
        'driver' => env('STORAGE_DRIVER', 'local'),
        // Laravel disk per driver (config/filesystems.php): `uploads` (local) and `uploads-s3`.
        'public_url' => rtrim((string) env('S3_PUBLIC_URL', ''), '/'),
    ],

    // No default here: DemoData::seedPassword falls back to `password` outside production and refuses in production.
    'seed' => [
        'superadmin_password' => env('SEED_SUPERADMIN_PASSWORD'),
        'department_password' => env('SEED_DEPARTMENT_PASSWORD'),
        'allow_remote' => (bool) env('SEED_ALLOW_REMOTE', false),
    ],
];
