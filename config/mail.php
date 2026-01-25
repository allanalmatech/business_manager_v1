<?php
// config/mail.php

return [
    'enabled' => true,

    'default' => [
        'from_email' => 'no-reply@example.com',
        'from_name'  => 'Business Manager',
    ],

    // Actual SMTP credentials are loaded from DB
];
