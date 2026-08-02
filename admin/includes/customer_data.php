<?php

// Data-loading helpers for admin/customers.php. Declaration-only (PSR-1 §2.3).

// Rebuild the list URL keeping search, filters and page intact.
function customerPageUrl(array $state, array $overrides = []): string
{
    $params = array_merge($state, $overrides);

    foreach ($params as $key => $value) {
        if ($value === '' || $value === null || ($key === 'page' && (int) $value <= 1)) {
            unset($params[$key]);
        }
    }

    $query = http_build_query($params);

    return 'customers.php' . ($query !== '' ? '?' . $query : '');
}

// Customer row for the edit form, or null when the id doesn't resolve.
function loadCustomer(mysqli $conn, int $customerId): ?array
{
    $stmt = $conn->prepare(
        'SELECT id, name, email, phone, address, status, created_at
         FROM customers
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $customerId);
    $stmt->execute();

    $customer = $stmt->get_result()->fetch_assoc();

    return $customer ?: null;
}

// True if another customer already uses this email.
function customerEmailExists(mysqli $conn, string $email, int $customerId): bool
{
    $stmt = $conn->prepare('SELECT id FROM customers WHERE email = ? AND id <> ? LIMIT 1');
    $stmt->bind_param('si', $email, $customerId);
    $stmt->execute();

    return (bool) $stmt->get_result()->fetch_assoc();
}

// Booking count, latest booking date, and the 5 most recent bookings for this customer.
function loadCustomerBookingSummary(mysqli $conn, int $customerId): array
{
    $summary = [
        'total_bookings' => 0,
        'latest_booking_at' => null,
        'recent_bookings' => [],
    ];

    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total_bookings, MAX(created_at) AS latest_booking_at
         FROM bookings
         WHERE customer_id = ?'
    );
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $counts = $stmt->get_result()->fetch_assoc();

    if ($counts) {
        $summary['total_bookings'] = (int) $counts['total_bookings'];
        $summary['latest_booking_at'] = $counts['latest_booking_at'];
    }

    $stmt = $conn->prepare(
        'SELECT
            b.id,
            b.pickup_date,
            b.return_date,
            b.total_amount,
            b.booking_status,
            car.brand,
            car.model,
            car.plate_number
         FROM bookings b
         INNER JOIN cars car ON car.id = b.car_id
         WHERE b.customer_id = ?
         ORDER BY b.created_at DESC, b.id DESC
         LIMIT 5'
    );
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $summary['recent_bookings'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    return $summary;
}
