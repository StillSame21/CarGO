DROP PROCEDURE IF EXISTS ensure_car_archived_at;

DELIMITER //

CREATE PROCEDURE ensure_car_archived_at()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'cars'
          AND COLUMN_NAME = 'archived_at'
    ) THEN
        ALTER TABLE cars ADD COLUMN archived_at DATETIME NULL DEFAULT NULL;
    END IF;
END//

DELIMITER ;

CALL ensure_car_archived_at();
DROP PROCEDURE ensure_car_archived_at;
