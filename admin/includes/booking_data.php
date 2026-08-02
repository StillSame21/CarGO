<?php

// Data-loading helpers for admin/bookings.php. Declaration-only (PSR-1 §2.3).

// Rebuild the list URL keeping search, filters and page intact.
function bookingListUrl(array $state, array $overrides = []): string
{
    $params = array_merge($state, $overrides);

    foreach ($params as $key => $value) {
        if ($value === '' || $value === null || ($key === 'page' && (int) $value <= 1)) {
            unset($params[$key]);
        }
    }

    $query = http_build_query($params);

    return 'bookings.php' . ($query !== '' ? '?' . $query : '');
}

// Turns the submitted filter values into a WHERE clause plus its bound types/params.
function buildAdminBookingFilters(array $filters): array
{
    $where = [];
    $types = '';
    $params = [];

    if ($filters['q'] !== '') {
        $searchTerm = '%' . $filters['q'] . '%';
        $where[] = '(
            CAST(b.id AS CHAR) LIKE ?
            OR c.name LIKE ?
            OR c.email LIKE ?
            OR car.brand LIKE ?
            OR car.model LIKE ?
            OR car.plate_number LIKE ?
            OR b.pickup_location LIKE ?
        )';
        $types .= 'sssssss';
        array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
    }

    if ($filters['status'] !== 'all') {
        $where[] = 'b.booking_status = ?';
        $types .= 's';
        $params[] = $filters['status'];
    }

    foreach (
        [
        'pickup_from' => ['b.pickup_date >= ?', 's'],
        'pickup_to' => ['b.pickup_date <= ?', 's'],
        'return_from' => ['b.return_date >= ?', 's'],
        'return_to' => ['b.return_date <= ?', 's'],
        'min_amount' => ['b.total_amount >= ?', 'd'],
        'max_amount' => ['b.total_amount <= ?', 'd'],
        ] as $key => [$condition, $type]
    ) {
        if ($filters[$key] !== '') {
            $where[] = $condition;
            $types .= $type;
            $params[] = $type === 'd' ? (float) $filters[$key] : $filters[$key];
        }
    }

    return [
        'where_sql' => $where ? ' WHERE ' . implode(' AND ', $where) : '',
        'types' => $types,
        'params' => $params,
    ];
}

// One page of the filtered, sorted booking list with customer/car/payment details joined in.
function loadAdminBookings(mysqli $conn, array $filters, string $sort, int $limit, int $offset): array
{
    $sortOptions = [
        'newest' => 'b.created_at DESC, b.id DESC',
        'oldest' => 'b.created_at ASC, b.id ASC',
        'pickup_asc' => 'b.pickup_date ASC, b.id ASC',
        'pickup_desc' => 'b.pickup_date DESC, b.id DESC',
        'amount_asc' => 'b.total_amount ASC, b.id ASC',
        'amount_desc' => 'b.total_amount DESC, b.id DESC',
    ];
    $filterSql = buildAdminBookingFilters($filters);
    $orderBy = $sortOptions[$sort] ?? $sortOptions['newest'];
    $types = $filterSql['types'] . 'ii';
    $params = array_merge($filterSql['params'], [$limit, $offset]);
    $stmt = $conn->prepare(
        'SELECT
            b.id,
            b.pickup_date,
            b.return_date,
            b.total_amount,
            b.booking_status,
            c.name AS customer_name,
            c.email AS customer_email,
            car.brand,
            car.model,
            car.plate_number,
            p.payment_status,
            COALESCE(fees.total_late_fee, 0) AS total_late_fee
         FROM bookings b
         LEFT JOIN customers c ON c.id = b.customer_id
         LEFT JOIN cars car ON car.id = b.car_id
         LEFT JOIN payments p ON p.booking_id = b.id
         LEFT JOIN (
            SELECT booking_id, SUM(late_fee_amount) AS total_late_fee
            FROM late_fees
            GROUP BY booking_id
         ) fees ON fees.booking_id = b.id
         ' . $filterSql['where_sql'] . '
         ORDER BY ' . $orderBy . '
         LIMIT ? OFFSET ?'
    );
    bindStatementParams($stmt, $types, $params);
    $stmt->execute();

    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Total bookings matching the filters, for pagination.
function countAdminBookings(mysqli $conn, array $filters): int
{
    $filterSql = buildAdminBookingFilters($filters);
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM bookings b
         LEFT JOIN customers c ON c.id = b.customer_id
         LEFT JOIN cars car ON car.id = b.car_id
         ' . $filterSql['where_sql']
    );
    bindStatementParams($stmt, $filterSql['types'], $filterSql['params']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return (int) ($row['total'] ?? 0);
}

// Full booking detail (customer, car, payment, late fees) for the detail view, or null if not found.
function loadAdminBooking(mysqli $conn, int $bookingId): ?array
{
    $stmt = $conn->prepare(
        'SELECT
            b.id,
            b.customer_id,
            b.car_id,
            b.handled_by_admin_id,
            b.pickup_date,
            b.return_date,
            b.actual_return_date,
            b.pickup_location,
            b.total_days,
            b.total_amount,
            b.booking_status,
            b.created_at,
            c.name AS customer_name,
            c.email AS customer_email,
            c.phone AS customer_phone,
            c.address AS customer_address,
            car.brand,
            car.model,
            car.plate_number,
            car.car_type,
            car.transmission,
            car.fuel_type,
            car.seats,
            car.daily_rate,
            car.image,
            car.status AS car_status,
            p.amount AS payment_amount,
            p.payment_method,
            p.payment_status,
            p.payment_date,
            COALESCE(fees.total_late_days, 0) AS total_late_days,
            COALESCE(fees.total_late_fee, 0) AS total_late_fee
         FROM bookings b
         INNER JOIN customers c ON c.id = b.customer_id
         INNER JOIN cars car ON car.id = b.car_id
         LEFT JOIN payments p ON p.booking_id = b.id
         LEFT JOIN (
            SELECT
                booking_id,
                SUM(late_days) AS total_late_days,
                SUM(late_fee_amount) AS total_late_fee
            FROM late_fees
            GROUP BY booking_id
         ) fees ON fees.booking_id = b.id
         WHERE b.id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();

    $booking = $stmt->get_result()->fetch_assoc();

    return $booking ?: null;
}
