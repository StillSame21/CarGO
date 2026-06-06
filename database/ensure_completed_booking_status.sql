DROP PROCEDURE IF EXISTS ensure_completed_booking_status;

DELIMITER //

CREATE PROCEDURE ensure_completed_booking_status()
BEGIN
    DECLARE current_column_type TEXT;
    DECLARE existing_enum_values TEXT;

    SELECT COLUMN_TYPE
    INTO current_column_type
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'bookings'
      AND COLUMN_NAME = 'booking_status'
    LIMIT 1;

    IF current_column_type IS NOT NULL
       AND LOCATE('''completed''', current_column_type) = 0 THEN
        SET existing_enum_values = SUBSTRING(current_column_type, 6, CHAR_LENGTH(current_column_type) - 6);
        SET @alter_booking_status_sql = CONCAT(
            'ALTER TABLE bookings MODIFY booking_status ENUM(',
            existing_enum_values,
            ',''completed'') NOT NULL DEFAULT ''pending'''
        );

        PREPARE alter_booking_status FROM @alter_booking_status_sql;
        EXECUTE alter_booking_status;
        DEALLOCATE PREPARE alter_booking_status;
    END IF;
END//

DELIMITER ;

CALL ensure_completed_booking_status();
DROP PROCEDURE ensure_completed_booking_status;
