<?php

function ensureCarArchiveColumn(mysqli $conn): void
{
    // DDL now handled by database/migrate.php
}

const CAR_TYPE_FALLBACK = ['Compact', 'Sedan', 'SUV', 'MPV', 'Luxury', 'Sports', 'Hatchback', 'Truck'];

/**
 * Car types straight from the cars.car_type enum.
 *
 * The admin form and the customer filter used to each carry their own
 * hardcoded list, and both had drifted from the schema — editing a car whose
 * type was missing from the list silently rewrote it to the first option.
 * Reading the enum keeps every caller in step with the database.
 *
 * Mirrors bookingStatusValues() in admin/dashboard.php.
 *
 * @return array<int, string>
 */
function carTypeValues(mysqli $conn): array
{
    try {
        $stmt = $conn->prepare(
            'SELECT COLUMN_TYPE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1'
        );
        $table = 'cars';
        $column = 'car_type';
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
    } catch (mysqli_sql_exception $e) {
        return CAR_TYPE_FALLBACK;
    }

    $columnType = (string) ($row['COLUMN_TYPE'] ?? '');

    if (!preg_match("/^enum\((.*)\)$/i", $columnType, $matches)) {
        return CAR_TYPE_FALLBACK;
    }

    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $matches[1], $enumMatches);
    $types = array_map(
        static fn($value) => stripcslashes($value),
        $enumMatches[1] ?? []
    );

    return $types ?: CAR_TYPE_FALLBACK;
}

/**
 * Options for a car-type select, keeping the car's own value selectable even
 * when it is no longer offered — otherwise the browser falls back to the first
 * option and a silent save corrupts the row.
 *
 * @param array<int, string> $types
 * @return array<int, string>
 */
function carTypeOptions(array $types, string $currentType): array
{
    if ($currentType !== '' && !in_array($currentType, $types, true)) {
        $types[] = $currentType;
    }

    return $types;
}
