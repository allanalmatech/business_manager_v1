<?php
// Seed settings for POS receipts
// Run this script once to populate the settings table

require_once __DIR__ . '/../includes/bootstrap.php';

$settings = [
    'business_name' => 'Business Manager POS',
    'business_address' => "123 Main Street\nKampala, Uganda\nPhone: +256 123 456 789",
    'business_phone' => '+256 123 456 789',
    'business_email' => 'info@businessmanager.com',
    'receipt_header' => 'THANK YOU FOR SHOPPING WITH US\nPlease come again!',
    'receipt_footer' => 'Thank you for your purchase!\nAll sales are final\nNo returns without receipt\nVisit us again soon!',
    'business_logo' => '',
    'business_website' => 'www.businessmanager.com',
    'business_tax_id' => 'UG-123456789',
    'currency_symbol' => 'UGX',
    'currency_code' => 'UGX',
    'decimal_places' => '0',
    'thousands_separator' => ',',
    'decimal_point' => '.',
    'receipt_width' => '80',
    'cash_drawer_port' => 'COM1',
    'printer_name' => 'Default Printer',
    'auto_print_receipt' => '1',
    'auto_open_drawer' => '1',
    'show_customer_copy' => '1',
    'show_cashier_copy' => '1'
];

$db = $GLOBALS['db'] ?? null;
if (!$db instanceof mysqli) {
    die("Database not available\n");
}

// Create settings table if it doesn't exist
$createTable = "
CREATE TABLE IF NOT EXISTS `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

if (!$db->query($createTable)) {
    die("Error creating settings table: " . $db->error . "\n");
}

echo "Settings table created or already exists.\n";

// Insert settings if they don't exist
$inserted = 0;
$updated = 0;

foreach ($settings as $key => $value) {
    // Check if setting exists
    $check = $db->prepare("SELECT id FROM settings WHERE `key` = ? LIMIT 1");
    $check->bind_param('s', $key);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc();
    $check->close();
    
    if ($exists) {
        echo "Setting '$key' already exists.\n";
        continue;
    }
    
    // Insert new setting
    $insert = $db->prepare("INSERT INTO settings (`key`, `value`, `description`) VALUES (?, ?, ?)");
    $description = getDescription($key);
    $insert->bind_param('sss', $key, $value, $description);
    
    if ($insert->execute()) {
        $inserted++;
        echo "✓ Added setting: $key\n";
    } else {
        echo "✗ Error adding setting $key: " . $db->error . "\n";
    }
    $insert->close();
}

echo "\nSummary:\n";
echo "- Inserted: $inserted settings\n";
echo "- Updated: $updated settings\n";
echo "- Total settings: " . count($settings) . "\n";

function getDescription($key) {
    $descriptions = [
        'business_name' => 'Business name for receipts and invoices',
        'business_address' => 'Business address for receipts',
        'business_phone' => 'Business phone number',
        'business_email' => 'Business email address',
        'receipt_header' => 'Header text for receipts',
        'receipt_footer' => 'Footer text for receipts',
        'business_logo' => 'Business logo URL or path',
        'business_website' => 'Business website',
        'business_tax_id' => 'Business tax identification number',
        'currency_symbol' => 'Currency symbol for display',
        'currency_code' => 'Currency code (ISO)',
        'decimal_places' => 'Number of decimal places for currency',
        'thousands_separator' => 'Thousands separator for numbers',
        'decimal_point' => 'Decimal point for numbers',
        'receipt_width' => 'Receipt width in mm for thermal printers',
        'cash_drawer_port' => 'Cash drawer port (if applicable)',
        'printer_name' => 'Default receipt printer name',
        'auto_print_receipt' => 'Auto print receipt after sale (1=on, 0=off)',
        'auto_open_drawer' => 'Auto open cash drawer after sale (1=on, 0=off)',
        'show_customer_copy' => 'Show customer copy option (1=on, 0=off)',
        'show_cashier_copy' => 'Show cashier copy option (1=on, 0=off)'
    ];
    
    return $descriptions[$key] ?? '';
}

echo "\nSettings seeded successfully!\n";
echo "You can now access the POS preview and receipt functionality.\n";
?>
