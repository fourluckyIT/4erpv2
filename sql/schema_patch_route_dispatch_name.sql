-- Schema patch: Add dispatch name to routes
-- ERP v2 - Route dispatch metadata

ALTER TABLE routes ADD COLUMN dispatched_by_name VARCHAR(100) NULL AFTER dispatched_by;
