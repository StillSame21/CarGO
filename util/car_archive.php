<?php

const CAR_TYPE_FALLBACK = ['Compact', 'Sedan', 'SUV', 'MPV', 'Luxury', 'Sports', 'Hatchback', 'Truck'];

/**
 * Car types straight from the cars.car_type enum, so admin form and customer filter never drift from the schema.
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
        $enumMatches[1]
    );

    return $types ?: CAR_TYPE_FALLBACK;
}

/**
 * Options for a car-type select, keeping the car's own value selectable even when no longer offered.
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
