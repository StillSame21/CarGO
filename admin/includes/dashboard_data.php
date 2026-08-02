<?php

// Data-loading helpers for admin/dashboard.php. Declaration-only (PSR-1 §2.3).

// Fleet counts by status, split by archived/active.
function loadDashboardCarStats(mysqli $conn): array
{
    $result = $conn->query(
        'SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN archived_at IS NULL THEN 1 ELSE 0 END) AS active_cars,
            SUM(CASE WHEN archived_at IS NOT NULL THEN 1 ELSE 0 END) AS archived_cars,
            SUM(CASE WHEN archived_at IS NULL AND status = \'available\' THEN 1 ELSE 0 END) AS available_cars,
            SUM(CASE WHEN archived_at IS NULL AND status = \'unavailable\' THEN 1 ELSE 0 END) AS unavailable_cars,
            SUM(CASE WHEN archived_at IS NULL AND status = \'maintenance\' THEN 1 ELSE 0 END) AS maintenance_cars
         FROM cars'
    );
    $row = $result->fetch_assoc() ?: [];

    return [
        'total' => (int) ($row['total'] ?? 0),
        'available' => (int) ($row['available_cars'] ?? 0),
        'unavailable' => (int) ($row['unavailable_cars'] ?? 0),
        'maintenance' => (int) ($row['maintenance_cars'] ?? 0),
        'archived' => (int) ($row['archived_cars'] ?? 0),
        'active' => (int) ($row['active_cars'] ?? 0),
    ];
}

// Booking counts per status plus a total, zero-filled for statuses with no bookings.
function loadDashboardBookingStats(mysqli $conn, array $bookingStatuses): array
{
    $bookingStats = array_fill_keys($bookingStatuses, 0);
    $bookingStats['total'] = 0;
    $statusCounts = fetchCountMap(
        $conn,
        'SELECT booking_status, COUNT(*) AS total FROM bookings GROUP BY booking_status',
        'booking_status',
        'total'
    );

    foreach ($statusCounts as $status => $count) {
        $bookingStats[$status] = $count;
        $bookingStats['total'] += $count;
    }

    return $bookingStats;
}

// All-time paid revenue split into rental vs late-fee portions.
function loadDashboardRevenue(mysqli $conn): array
{
    if (!tableExists($conn, 'payments')) {
        return [
            'booking' => 0.0,
            'late_fee' => 0.0,
        ];
    }

    $lateFeeJoin = tableExists($conn, 'late_fees')
        ? 'LEFT JOIN (
                SELECT booking_id, SUM(late_fee_amount) AS late_fee_total
                FROM late_fees
                GROUP BY booking_id
           ) fees ON fees.booking_id = b.id'
        : 'LEFT JOIN (
                SELECT NULL AS booking_id, 0 AS late_fee_total
           ) fees ON fees.booking_id = b.id';

    $result = $conn->query(
        'SELECT
            COALESCE(SUM(LEAST(p.amount, b.total_amount)), 0) AS booking_revenue,
            COALESCE(SUM(
                LEAST(
                    GREATEST(p.amount - b.total_amount, 0),
                    COALESCE(fees.late_fee_total, 0)
                )
            ), 0) AS late_fee_revenue
         FROM payments p
         INNER JOIN bookings b ON b.id = p.booking_id
         ' . $lateFeeJoin . '
         WHERE p.payment_status = \'paid\'
           AND b.booking_status NOT IN (\'cancelled\', \'rejected\')'
    );
    $row = $result->fetch_assoc() ?: [];

    return [
        'booking' => (float) ($row['booking_revenue'] ?? 0),
        'late_fee' => (float) ($row['late_fee_revenue'] ?? 0),
    ];
}

/**
 * Counts of work waiting on an admin. Each maps to a filtered view,
 * so the dashboard points at the job rather than just reporting a number.
 */
function loadDashboardQueues(mysqli $conn, int $maintenanceCars): array
{
    // Paid and approved, but nobody has handed the car over yet.
    $result = $conn->query(
        "SELECT COUNT(*) AS total
         FROM bookings b
         INNER JOIN payments p ON p.booking_id = b.id
         WHERE b.booking_status = 'approved'
           AND p.payment_status = 'paid'"
    );
    $awaitingPickup = (int) (($result->fetch_assoc())['total'] ?? 0);

    // Out on rent and already past the agreed return date.
    $result = $conn->query(
        "SELECT COUNT(*) AS total
         FROM bookings
         WHERE booking_status = 'ongoing'
           AND return_date < CURDATE()"
    );
    $overdueReturn = (int) (($result->fetch_assoc())['total'] ?? 0);

    $unpaidLateFees = 0;

    if (tableExists($conn, 'late_fees')) {
        $result = $conn->query(
            "SELECT COUNT(*) AS total
             FROM bookings b
             INNER JOIN (
                SELECT booking_id, SUM(late_fee_amount) AS total_late_fee
                FROM late_fees
                GROUP BY booking_id
             ) fees ON fees.booking_id = b.id
             LEFT JOIN payments p ON p.booking_id = b.id
             WHERE fees.total_late_fee > 0
               AND (
                    p.id IS NULL
                    OR p.payment_status <> 'paid'
                    OR p.amount < (b.total_amount + fees.total_late_fee)
               )"
        );
        $unpaidLateFees = (int) (($result->fetch_assoc())['total'] ?? 0);
    }

    return [
        'awaiting_pickup' => $awaitingPickup,
        'overdue_return' => $overdueReturn,
        'unpaid_late_fees' => $unpaidLateFees,
        'maintenance' => $maintenanceCars,
    ];
}

/**
 * Paid revenue per day for the trailing $days window, zero-filled so the
 * sparkline has one point per day even when nothing was collected.
 *
 * @return array{series: array<int, float>, current: float, previous: float}
 */
function loadDashboardRevenueSeries(mysqli $conn, int $days = 14): array
{
    $series = [];
    $today = new DateTimeImmutable('today');

    for ($i = $days - 1; $i >= 0; $i--) {
        $series[$today->modify("-{$i} days")->format('Y-m-d')] = 0.0;
    }

    if (!tableExists($conn, 'payments')) {
        return ['series' => array_values($series), 'current' => 0.0, 'previous' => 0.0];
    }

    $windowStart = $today->modify('-' . ($days * 2 - 1) . ' days')->format('Y-m-d');

    $stmt = $conn->prepare(
        "SELECT DATE(p.payment_date) AS paid_on, SUM(p.amount) AS total
         FROM payments p
         INNER JOIN bookings b ON b.id = p.booking_id
         WHERE p.payment_status = 'paid'
           AND p.payment_date IS NOT NULL
           AND DATE(p.payment_date) >= ?
           AND b.booking_status NOT IN ('cancelled', 'rejected')
         GROUP BY DATE(p.payment_date)"
    );
    $stmt->bind_param('s', $windowStart);
    $stmt->execute();

    $priorStart = $today->modify('-' . ($days * 2 - 1) . ' days');
    $currentStart = $today->modify('-' . ($days - 1) . ' days');
    $current = 0.0;
    $previous = 0.0;

    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $day = (string) $row['paid_on'];
        $amount = (float) $row['total'];

        if (array_key_exists($day, $series)) {
            $series[$day] = $amount;
        }

        if ($day >= $currentStart->format('Y-m-d')) {
            $current += $amount;
        } elseif ($day >= $priorStart->format('Y-m-d')) {
            $previous += $amount;
        }
    }

    return [
        'series' => array_values($series),
        'current' => $current,
        'previous' => $previous,
    ];
}

/**
 * Build an SVG polyline path for the revenue sparkline.
 *
 * @param array<int, float> $series
 */
function sparklinePoints(array $series, float $width = 320.0, float $height = 56.0): string
{
    $count = count($series);

    if ($count === 0) {
        return '';
    }

    if ($count === 1) {
        $series = [$series[0], $series[0]];
        $count = 2;
    }

    $max = max($series);
    $step = $width / ($count - 1);
    $points = [];

    foreach ($series as $index => $value) {
        $x = round($index * $step, 2);
        // Flat line sits near the base when nothing was collected.
        $ratio = $max > 0 ? $value / $max : 0.0;
        $y = round($height - ($ratio * ($height - 6)) - 3, 2);
        $points[] = $x . ',' . $y;
    }

    return implode(' ', $points);
}

// 5 most recently created bookings, with customer/car details joined in.
function loadRecentDashboardBookings(mysqli $conn): array
{
    $result = $conn->query(
        'SELECT
            b.id,
            b.pickup_date,
            b.return_date,
            b.total_days,
            b.total_amount,
            b.booking_status,
            b.created_at,
            c.name AS customer_name,
            car.brand,
            car.model,
            car.plate_number
         FROM bookings b
         LEFT JOIN customers c ON c.id = b.customer_id
         LEFT JOIN cars car ON car.id = b.car_id
         ORDER BY b.created_at DESC, b.id DESC
         LIMIT 5'
    );

    return $result->fetch_all(MYSQLI_ASSOC);
}

// 5 most recently registered customers.
function loadRecentDashboardCustomers(mysqli $conn): array
{
    $result = $conn->query(
        'SELECT id, name, email, phone, status, created_at
         FROM customers
         ORDER BY created_at DESC, id DESC
         LIMIT 5'
    );

    return $result->fetch_all(MYSQLI_ASSOC);
}

// 5 most recently added, non-archived cars.
function loadRecentDashboardCars(mysqli $conn): array
{
    $result = $conn->query(
        'SELECT id, brand, model, plate_number, car_type, daily_rate, status, created_at
         FROM cars
         WHERE archived_at IS NULL
         ORDER BY created_at DESC, id DESC
         LIMIT 5'
    );

    return $result->fetch_all(MYSQLI_ASSOC);
}
