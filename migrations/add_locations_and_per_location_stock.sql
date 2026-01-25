-- migrations/add_locations_and_per_location_stock.sql
-- Run this in your business_manager_v1 database

-- 1. Locations table
CREATE TABLE IF NOT EXISTS locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Insert default locations (adjust names as needed)
INSERT IGNORE INTO locations (id, name) VALUES
(1, 'Store'),
(2, 'Shop'),
(3, 'Warehouse');

-- 2. Add default_location_id to products (optional, can be NULL)
ALTER TABLE products
ADD COLUMN default_location_id INT NULL,
ADD COLUMN FOREIGN KEY (default_location_id) REFERENCES locations(id);

-- 3. Stock by location table (per-location stock ledger)
CREATE TABLE IF NOT EXISTS stock_by_location (
  product_id INT NOT NULL,
  location_id INT NOT NULL,
  qty_base DECIMAL(12,4) NOT NULL DEFAULT 0,
  low_level_base DECIMAL(12,4) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (product_id, location_id),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 4. Add from/to location columns to stock_movements (already there in recent schema; ensure they exist)
ALTER TABLE stock_movements
ADD COLUMN IF NOT EXISTS from_location_id INT NULL,
ADD COLUMN IF NOT EXISTS to_location_id INT NULL,
ADD COLUMN IF NOT EXISTS reference_type VARCHAR(40) NULL,
ADD COLUMN IF NOT EXISTS reference_id VARCHAR(40) NULL;

-- 5. Backfill existing products' stock into stock_by_location for the default location (ID=1)
-- This assumes you have qty_on_hand in products; adjust if you use qty_base
INSERT IGNORE INTO stock_by_location (product_id, location_id, qty_base, low_level_base)
SELECT id, 1, COALESCE(qty_on_hand,0), COALESCE(low_level,0)
FROM products;

-- 6. (Optional) Update existing stock_movements to set from/to location where missing
-- This is a best-effort; you may want to review manually
UPDATE stock_movements
SET from_location_id = NULL,
    to_location_id = 1
WHERE movement_type IN ('stock_in','adjustment')
  AND (from_location_id IS NULL OR to_location_id IS NULL);
