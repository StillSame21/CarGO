<?php

// Shared mysqli query helpers for admin pages. Declaration-only (PSR-1 §2.3).

// bind_param() takes args by reference, so build a by-reference array before splatting it.
function bindStatementParams(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '') {
        return;
    }

    $refs = [];
    foreach ($params as $key => $value) {
        $refs[$key] = $value;
    }

    $bindValues = [$types];
    foreach ($refs as $key => &$value) {
        $bindValues[] = &$value;
    }

    call_user_func_array([$stmt, 'bind_param'], $bindValues);
}

// Valid booking_status values straight from the enum, so filters never drift from the schema.
function adminBookingStatusValues(mysqli $conn): array
{
    $stmt = $conn->prepare(
        'SELECT COLUMN_TYPE
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1'
    );
    $table = 'bookings';
    $column = 'booking_status';
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $columnType = (string) ($row['COLUMN_TYPE'] ?? '');

    if (!preg_match("/^enum\((.*)\)$/", $columnType, $matches)) {
        return ['pending', 'approved', 'rejected', 'ongoing'];
    }

    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $matches[1], $enumMatches);
    $statuses = array_map(
        static fn($value) => stripcslashes($value),
        $enumMatches[1]
    );

    return $statuses ?: ['pending', 'approved', 'rejected', 'ongoing'];
}

// True if $value is a real calendar date in Y-m-d form, for validating a date filter param.
function isAdminDateFilter(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }

    $parts = explode('-', $value);

    return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
}

// True if $tableName exists in the current database.
function tableExists(mysqli $conn, string $tableName): bool
{
    $stmt = $conn->prepare(
        'SELECT TABLE_NAME
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
         LIMIT 1'
    );
    $stmt->bind_param('s', $tableName);
    $stmt->execute();

    return (bool) $stmt->get_result()->fetch_assoc();
}

// Runs $sql and returns it as a [keyColumn => valueColumn] map.
function fetchCountMap(mysqli $conn, string $sql, string $keyColumn, string $valueColumn): array
{
    $result = $conn->query($sql);
    $map = [];

    while ($row = $result->fetch_assoc()) {
        $map[(string) $row[$keyColumn]] = (int) $row[$valueColumn];
    }

    return $map;
}
