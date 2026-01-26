-- Schema patch: Add receive columns to routes table
-- ERP v2 - Route receive at site functionality

-- Add receive columns
ALTER TABLE routes ADD COLUMN received_by_name VARCHAR(100) NULL AFTER wh_received_by;
ALTER TABLE routes ADD COLUMN received_at DATETIME NULL AFTER received_by_name;
ALTER TABLE routes ADD COLUMN receive_notes TEXT NULL AFTER received_at;

-- Update status enum to include Received
ALTER TABLE routes MODIFY COLUMN status ENUM('Draft','Confirmed','Dispatched','Received','InProgress','Returned','WHReceived','Cancelled') NOT NULL DEFAULT 'Draft';
