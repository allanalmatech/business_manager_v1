-- Banking Module Tables
-- Create bank_accounts and bank_transactions tables

-- Table structure for bank_accounts
CREATE TABLE `bank_accounts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `account_name` varchar(100) NOT NULL,
  `account_number` varchar(50) NOT NULL,
  `bank_name` varchar(100) NOT NULL,
  `branch` varchar(100) DEFAULT NULL,
  `account_type` enum('current','savings','fixed_deposit','business') NOT NULL DEFAULT 'current',
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `opening_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `current_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_account_number` (`account_number`),
  KEY `idx_bank_name` (`bank_name`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table structure for bank_transactions
CREATE TABLE `bank_transactions` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) NOT NULL,
  `transaction_date` date NOT NULL,
  `type` enum('debit','credit') NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `reference` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `reconciled` tinyint(1) NOT NULL DEFAULT 0,
  `reconciliation_date` datetime DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_transaction_date` (`transaction_date`),
  KEY `idx_type` (`type`),
  KEY `idx_reconciled` (`reconciled`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_bank_transactions_account` FOREIGN KEY (`account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert some sample bank accounts (optional)
INSERT INTO `bank_accounts` (`account_name`, `account_number`, `bank_name`, `branch`, `account_type`, `currency`, `opening_balance`, `current_balance`, `created_by`) VALUES
('Main Business Account', '1234567890', 'First National Bank', 'Main Branch', 'current', 'USD', 10000.00, 10000.00, 1),
('Petty Cash Account', '9876543210', 'First National Bank', 'Main Branch', 'current', 'USD', 1000.00, 1000.00, 1),
('Savings Account', '5555666677', 'First National Bank', 'Main Branch', 'savings', 'USD', 5000.00, 5000.00, 1);

-- Insert some sample transactions (optional)
INSERT INTO `bank_transactions` (`account_id`, `transaction_date`, `type`, `amount`, `reference`, `description`, `category`, `created_by`) VALUES
(1, CURDATE() - INTERVAL 7 DAY, 'credit', 5000.00, 'DEP001', 'Customer Deposit - Invoice #1001', 'deposit', 1),
(1, CURDATE() - INTERVAL 5 DAY, 'debit', 1500.00, 'PAY001', 'Supplier Payment - Office Supplies', 'expense', 1),
(2, CURDATE() - INTERVAL 3 DAY, 'debit', 500.00, 'PETTY001', 'Petty Cash Withdrawal', 'transfer', 1),
(1, CURDATE() - INTERVAL 1 DAY, 'credit', 2500.00, 'DEP002', 'Customer Deposit - Invoice #1002', 'deposit', 1);
