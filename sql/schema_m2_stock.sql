-- Add quantity column to items for simple inventory tracking (M2)
ALTER TABLE `items` ADD COLUMN `quantity` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Current Stock On Hand' AFTER `min_stock`;
