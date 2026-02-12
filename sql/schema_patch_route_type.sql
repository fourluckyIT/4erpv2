-- Schema patch: Add route_type to routes table (Outbound/Return)
ALTER TABLE routes
    ADD COLUMN route_type ENUM('Outbound', 'Return') NOT NULL DEFAULT 'Outbound' AFTER plan_id;

CREATE INDEX idx_routes_route_type ON routes (route_type);
