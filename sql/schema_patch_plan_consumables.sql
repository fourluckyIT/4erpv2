-- Add consumable support to plan_assignments table
-- Run this if you get "Unknown column 'pa.item_id'" error

ALTER TABLE plan_assignments 
    ADD COLUMN item_id INT UNSIGNED NULL AFTER people_id,
    ADD COLUMN quantity INT DEFAULT 0 AFTER assignment_type;

ALTER TABLE plan_assignments 
    MODIFY COLUMN assignment_type ENUM('Serial','People','Both','Consumable','Device','Equipment') DEFAULT 'Serial';
