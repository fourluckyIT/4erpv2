-- Ensure required doc types exist in doc_number_settings
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

INSERT INTO doc_number_settings (doc_type, prefix, current_year, next_number, padding, reset_yearly)
VALUES
    ('JOB', 'JOB-', YEAR(NOW()), 1, 5, 1),
    ('PR', 'PR-', YEAR(NOW()), 1, 5, 1),
    ('PO', 'PO-', YEAR(NOW()), 1, 5, 1),
    ('GR', 'GR-', YEAR(NOW()), 1, 5, 1),
    ('INV', 'INV-', YEAR(NOW()), 1, 5, 1),
    ('PAY', 'PAY-', YEAR(NOW()), 1, 5, 1),
    ('DN', 'DN-', YEAR(NOW()), 1, 5, 1),
    ('RN', 'RN-', YEAR(NOW()), 1, 5, 1),
    ('PLAN', 'PLAN-', YEAR(NOW()), 1, 5, 1),
    ('ROUTE', 'ROUTE-', YEAR(NOW()), 1, 5, 1),
    ('DO', 'DO-', YEAR(NOW()), 1, 5, 1),
    ('SR', 'SR-', YEAR(NOW()), 1, 5, 1),
    ('RTN', 'RTN-', YEAR(NOW()), 1, 5, 1),
    ('DMG', 'DMG-', YEAR(NOW()), 1, 5, 1),
    ('CN', 'CN-', YEAR(NOW()), 1, 5, 1),
    ('EMP', 'EMP-', YEAR(NOW()), 1, 5, 0),
    ('EXT', 'EXT-', YEAR(NOW()), 1, 5, 0),
    ('DEV', 'DEV-', YEAR(NOW()), 1, 5, 0),
    ('EQP', 'EQP-', YEAR(NOW()), 1, 5, 0),
    ('VEH', 'VEH-', YEAR(NOW()), 1, 5, 0),
    ('CON', 'CON-', YEAR(NOW()), 1, 5, 0)
ON DUPLICATE KEY UPDATE doc_type = doc_type;

SET FOREIGN_KEY_CHECKS = 1;
