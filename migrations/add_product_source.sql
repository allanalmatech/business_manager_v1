-- Add source column to products
ALTER TABLE products
ADD COLUMN source VARCHAR(255) NULL AFTER description;
