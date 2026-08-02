<?php

// Optional extras a customer can attach to a booking. Prices are copied onto booking_addons
// at purchase time, so a later addon price change never rewrites an existing booking's charge.

// Every add-on offered at checkout, cheapest/oldest first.
function loadAvailableAddons(mysqli $conn): array
{
    $result = $conn->query('SELECT id, name, price, description FROM addons ORDER BY id ASC');

    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Keep only the submitted ids that match a real add-on.
 * @param array<int|string> $selectedIds Raw ids from the checkout form.
 * @param array<int, array> $addons Rows from loadAvailableAddons().
 * @return array<int, array>
 */
function filterSelectedAddons(array $selectedIds, array $addons): array
{
    $selectedIds = array_map('intval', $selectedIds);

    return array_values(array_filter(
        $addons,
        static fn(array $addon): bool => in_array((int) $addon['id'], $selectedIds, true)
    ));
}

/**
 * @param array<int, array> $selectedAddons Rows from filterSelectedAddons().
 */
function addonsTotalAmount(array $selectedAddons, int $totalDays): float
{
    $total = 0.0;

    foreach ($selectedAddons as $addon) {
        $total += (float) $addon['price'] * $totalDays;
    }

    return round($total, 2);
}

/**
 * @param array<int, array> $selectedAddons Rows from filterSelectedAddons().
 */
function saveBookingAddons(mysqli $conn, int $bookingId, array $selectedAddons, int $totalDays): void
{
    if ($selectedAddons === []) {
        return;
    }

    $stmt = $conn->prepare(
        'INSERT INTO booking_addons (booking_id, addon_id, unit_price, days)
         VALUES (?, ?, ?, ?)'
    );

    foreach ($selectedAddons as $addon) {
        $addonId = (int) $addon['id'];
        $unitPrice = (float) $addon['price'];
        $stmt->bind_param('iidi', $bookingId, $addonId, $unitPrice, $totalDays);
        $stmt->execute();
    }
}

// Add-ons attached to a booking, with each one's name for display.
function loadBookingAddons(mysqli $conn, int $bookingId): array
{
    $stmt = $conn->prepare(
        'SELECT ba.addon_id, ba.unit_price, ba.days, a.name
         FROM booking_addons ba
         INNER JOIN addons a ON a.id = ba.addon_id
         WHERE ba.booking_id = ?
         ORDER BY a.id ASC'
    );
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();

    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
