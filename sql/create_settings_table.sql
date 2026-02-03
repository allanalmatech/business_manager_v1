-- Create settings table for business configuration
CREATE TABLE IF NOT EXISTS `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert receipt and business settings
INSERT INTO `settings` (`key`, `value`, `description`) VALUES
('business_name', 'Business Manager POS', 'Business name for receipts and invoices'),
('business_address', '123 Main Street\nKampala, Uganda\nPhone: +256 123 456 789', 'Business address for receipts'),
('business_phone', '+256 123 456 789', 'Business phone number'),
('business_email', 'info@businessmanager.com', 'Business email address'),
('receipt_header', 'THANK YOU FOR SHOPPING WITH US\nPlease come again!', 'Header text for receipts'),
('receipt_footer', 'Thank you for your purchase!\nAll sales are final\nNo returns without receipt\nVisit us again soon!', 'Footer text for receipts'),
('business_logo', '', 'Business logo URL or path'),
('business_website', 'www.businessmanager.com', 'Business website'),
('business_tax_id', 'UG-123456789', 'Business tax identification number'),
('currency_symbol', 'UGX', 'Currency symbol for display'),
('currency_code', 'UGX', 'Currency code (ISO)'),
('decimal_places', '0', 'Number of decimal places for currency'),
('thousands_separator', ',', 'Thousands separator for numbers'),
('decimal_point', '.', 'Decimal point for numbers'),
('receipt_width', '80', 'Receipt width in mm for thermal printers'),
('cash_drawer_port', 'COM1', 'Cash drawer port (if applicable)'),
('printer_name', 'Default Printer', 'Default receipt printer name'),
('auto_print_receipt', '1', 'Auto print receipt after sale (1=on, 0=off)'),
('auto_open_drawer', '1', 'Auto open cash drawer after sale (1=on, 0=off)'),
('show_customer_copy', '1', 'Show customer copy option (1=on, 0=off)'),
('show_cashier_copy', '1', 'Show cashier copy option (1=on, 0=off)');

-- Create indexes for better performance
CREATE INDEX `idx_settings_key` ON `settings` (`key`);
CREATE INDEX `idx_settings_updated` ON `settings` (`updated_at`);
