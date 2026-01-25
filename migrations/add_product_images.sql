-- Add images column to products
ALTER TABLE products
ADD COLUMN images JSON NULL;

-- Create uploads/products folder if not exists (run in your filesystem)
-- Ensure web server has write permissions to uploads/products/
