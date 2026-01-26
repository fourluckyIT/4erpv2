-- Add pr_type column to purchase_requests table
-- Run this if column doesn't exist

ALTER TABLE purchase_requests 
ADD COLUMN IF NOT EXISTS pr_type ENUM('Item', 'Manpower') DEFAULT 'Item' AFTER pr_number;

-- Add index for filtering
CREATE INDEX IF NOT EXISTS idx_pr_type ON purchase_requests(pr_type);
