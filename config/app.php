<?php
// config/app.php

return [
    'app_name'     => 'Business Manager',
    'app_version'  => '1.0.0',
    'timezone'     => 'Africa/Kampala',

    'base_url'     => '', // auto-detect if empty

    'currency'     => 'UGX',
    'date_format'  => 'Y-m-d',
    'time_format'  => 'H:i',

    'uploads_dir'  => __DIR__ . '/../uploads',
    'storage_dir'  => __DIR__ . '/../storage',

    'maintenance'  => false, // toggled during updates
];
