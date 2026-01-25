<?php
// config/env.php

return [
    'env' => 'development', // development | production

    'development' => [
        'display_errors' => true,
        'error_reporting' => E_ALL,
    ],

    'production' => [
        'display_errors' => false,
        'error_reporting' => E_ALL & ~E_NOTICE & ~E_DEPRECATED,
    ],
];
