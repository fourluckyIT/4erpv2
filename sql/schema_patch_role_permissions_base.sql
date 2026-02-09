-- Patch: Base role permissions for dashboard gating (agents.md alignment)
-- Grants minimal permissions so role-based widgets can be configured safely

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.code = 'SAL' AND p.code IN ('JOB_VIEW', 'JOB_CREATE', 'JOB_EDIT')
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.code = 'PLN' AND p.code IN ('JOB_VIEW', 'JOB_EDIT', 'JOB_APPROVE', 'JOB_EXTEND', 'JOB_DISPATCH')
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.code = 'PUR' AND p.code IN ('PR_VIEW', 'PR_CREATE', 'PR_APPROVE', 'PO_VIEW', 'PO_CREATE')
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.code = 'WH' AND p.code IN ('JOB_VIEW', 'RETURN_VIEW', 'RETURN_RECEIVE')
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.code = 'ACC' AND p.code IN ('JOB_VIEW', 'PR_VIEW', 'PO_VIEW')
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;
