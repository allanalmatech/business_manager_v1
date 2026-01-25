-- Add low alert columns to locations
ALTER TABLE locations
ADD COLUMN low_alert_qty DECIMAL(12,4) NOT NULL DEFAULT 0,
ADD COLUMN low_alert_type VARCHAR(20) NOT NULL DEFAULT 'pieces';

-- Insert permissions for stores management
INSERT IGNORE INTO permissions (name) VALUES
('stores.view'),
('stores.create'),
('stores.update'),
('stores.delete');

-- Assign stores permissions to admin role (adjust role_id as needed)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = 'admin' AND p.name IN ('stores.view','stores.create','stores.update','stores.delete');
