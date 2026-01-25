-- Add item_id and quantity columns for consumables in route_items
ALTER TABLE route_items 
    ADD COLUMN item_id INT UNSIGNED NULL AFTER people_id,
    ADD COLUMN quantity INT DEFAULT 0 AFTER qty_used;
