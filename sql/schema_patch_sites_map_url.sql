-- Add map_url column to sites table for Google Maps link
ALTER TABLE `sites` ADD COLUMN `map_url` VARCHAR(500) NULL AFTER `longitude`;
