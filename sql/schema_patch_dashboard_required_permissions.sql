-- Patch: add required_permissions to dashboard_widgets
ALTER TABLE `dashboard_widgets`
    ADD COLUMN `required_permissions` JSON NULL AFTER `allowed_roles`;
