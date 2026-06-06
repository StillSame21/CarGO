<?php

function ensureCarArchiveColumn(mysqli $conn): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $result = $conn->query(
        "SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'cars'
           AND COLUMN_NAME = 'archived_at'
         LIMIT 1"
    );

    if ($result->num_rows === 0) {
        $conn->query('ALTER TABLE cars ADD COLUMN archived_at DATETIME NULL DEFAULT NULL');
    }

    $ensured = true;
}
