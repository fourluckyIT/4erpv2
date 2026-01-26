-- Add qty_in column to route_items for tracking consumable returns
-- ERP v2 - Consumable partial return support

ALTER TABLE `route_items` 
ADD COLUMN `qty_in` DECIMAL(10,2) DEFAULT NULL COMMENT 'Quantity returned (consumables)' 
AFTER `qty_used`;
