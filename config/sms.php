<?php
// config/sms.php

return [
    'enabled' => true,

    'providers' => [
        'mtn_momo',
        'airtel_money',
        'custom_gateway',
    ],

    'default_sender' => 'BUSINESS',
];
