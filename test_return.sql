-- Test Return Data
-- Run this to create sample returns for testing the edit/delete functionality

-- First, make sure the sale_returns table exists (run the main migration first)
-- Then insert some test data

INSERT INTO sale_returns (sale_id, return_no, reason, refund_amount, status, selling_location_id, created_by, refunded) VALUES
(1, 'RET-2026-001', 'Customer changed mind - wrong size', 25.50, 'pending', 1, 1, 0),
(2, 'RET-2026-002', 'Defective product returned', 120.00, 'approved', 1, 1, 1),
(3, 'RET-2026-003', 'Damaged during shipping', 75.25, 'completed', 2, 1, 1);

-- Insert some return items if the table exists
INSERT IGNORE INTO return_items (return_id, product_id, quantity, unit_price, total) VALUES
(1, 1, 1, 25.50, 25.50),
(2, 2, 2, 60.00, 120.00),
(3, 3, 1, 75.25, 75.25);

-- This will give you 3 test returns with different statuses:
-- - RET-2026-001: pending (can edit and delete)
-- - RET-2026-002: approved (can edit, cannot delete)  
-- - RET-2026-003: completed (cannot edit or delete)
