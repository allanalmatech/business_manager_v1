<?php
// config/constants.php

return [

    // Roles
    'ROLES' => [
        'super_admin',
        'cashier',
        'accountant',
    ],

    // Payment methods
    'PAYMENT_METHODS' => [
        'cash',
        'mobile_money',
        'bank',
    ],

    // Document types
    'DOCUMENT_TYPES' => [
        'receipt',
        'invoice',
        'delivery_note',
    ],

    // Installment status
    'INSTALLMENT_STATUS' => [
        'active',
        'due_soon',
        'overdue',
        'completed',
        'extended',
        'discontinued',
    ],

    // Contact types
    'CONTACT_TYPES' => [
        'customer',
        'supplier',
        'staff',
    ],

    // Sale status
    'SALE_STATUS' => [
        'paid',
        'partial',
        'unpaid',
    ],
];
