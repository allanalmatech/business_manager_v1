-- Quick backfill: copy legacy qty_on_hand to stock_by_location for Store (id=1)
INSERT IGNORE INTO stock_by_location (product_id, location_id, qty_base, low_level_base)
SELECT id, 1, COALESCE(qty_on_hand,0), COALESCE(low_level,0)
FROM products
WHERE COALESCE(qty_on_hand,0) > 0 OR COALESCE(low_level,0) > 0;
