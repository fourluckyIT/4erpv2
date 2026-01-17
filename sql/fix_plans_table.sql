ALTER TABLE plans 
ADD COLUMN confirmed_at DATETIME AFTER notes,
ADD COLUMN confirmed_by INT UNSIGNED AFTER confirmed_at,
ADD COLUMN cancelled_at DATETIME AFTER confirmed_by,
ADD COLUMN cancelled_by INT UNSIGNED AFTER cancelled_at,
ADD COLUMN cancel_reason TEXT AFTER cancelled_by;
