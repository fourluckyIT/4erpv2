-- Patch: Route permissions & role grants (agents.md §4.5)
-- Adds ROUTE permissions so roles.php can display/manage them

INSERT INTO `permissions` (`code`, `name`, `entity_type`, `action`, `description`) VALUES
('ROUTE_VIEW', 'View Route', 'ROUTE', 'view', 'View route details'),
('ROUTE_CREATE', 'Create Route', 'ROUTE', 'create', 'Create route'),
('ROUTE_EDIT', 'Edit Route', 'ROUTE', 'edit', 'Edit route'),
('ROUTE_CONFIRM', 'Confirm Route', 'ROUTE', 'approve', 'Confirm route (photos required)'),
('ROUTE_DISPATCH', 'Dispatch Route', 'ROUTE', 'dispatch', 'Dispatch route'),
('ROUTE_VOID', 'Void Route', 'ROUTE', 'void', 'Void route'),
('ROUTE_ADD_PHOTO', 'Add Route Photos', 'ROUTE', 'edit', 'Upload route evidence photos')
ON DUPLICATE KEY UPDATE `code` = `code`;

-- Grants: View
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM roles r, permissions p
WHERE r.code IN ('ADM', 'PLN', 'WH', 'MGR')
  AND p.code = 'ROUTE_VIEW'
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;

-- Grants: Create/Edit
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM roles r, permissions p
WHERE r.code IN ('ADM', 'PLN')
  AND p.code IN ('ROUTE_CREATE', 'ROUTE_EDIT')
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;

-- Grants: Confirm/Dispatch/Add Photo
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM roles r, permissions p
WHERE r.code IN ('ADM', 'PLN', 'WH')
  AND p.code IN ('ROUTE_CONFIRM', 'ROUTE_DISPATCH', 'ROUTE_ADD_PHOTO')
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;

-- Grants: Void
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM roles r, permissions p
WHERE r.code IN ('ADM', 'MGR')
  AND p.code = 'ROUTE_VOID'
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;
