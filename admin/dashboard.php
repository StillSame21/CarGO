<?php
require_once __DIR__ . '/../includes/security.php';
require_once '../db_connect.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatDashboardDate(?string $date): string
{
    if ($date === null || $date === '') {
        return 'Not set';
    }

    return date('d M Y', strtotime($date));
}

function dashboardStatusClass(string $status): string
{
    return 'status-' . preg_replace('/[^a-z0-9-]/', '-', strtolower($status));
}

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

function bookingStatusValues(mysqli $conn): array
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
        $enumMatches[1] ?? []
    );

    return $statuses ?: ['pending', 'approved', 'rejected', 'ongoing'];
}

function fetchCountMap(mysqli $conn, string $sql, string $keyColumn, string $valueColumn): array
{
    $result = $conn->query($sql);
    $map = [];

    while ($row = $result->fetch_assoc()) {
        $map[(string) $row[$keyColumn]] = (int) $row[$valueColumn];
    }

    return $map;
}

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

requireAdminLogin();

$error = '';
$carStats = [
    'total' => 0,
    'available' => 0,
    'unavailable' => 0,
    'maintenance' => 0,
    'archived' => 0,
    'active' => 0,
];
$bookingStats = [];
$bookingStatuses = ['pending', 'approved', 'rejected', 'ongoing'];
$bookingRevenue = 0.0;
$lateFeeRevenue = 0.0;
$recentBookings = [];
$recentCustomers = [];
$recentCars = [];

try {
    $conn = getDbConnection();
    $bookingStatuses = bookingStatusValues($conn);
    $carStats = loadDashboardCarStats($conn);
    $bookingStats = loadDashboardBookingStats($conn, $bookingStatuses);
    $revenue = loadDashboardRevenue($conn);
    $bookingRevenue = $revenue['booking'];
    $lateFeeRevenue = $revenue['late_fee'];
    $recentBookings = loadRecentDashboardBookings($conn);
    $recentCustomers = loadRecentDashboardCustomers($conn);
    $recentCars = loadRecentDashboardCars($conn);
} catch (mysqli_sql_exception $e) {
    $error = 'Could not load dashboard data. Please check the database connection and schema.';
}

$totalRevenue = $bookingRevenue + $lateFeeRevenue;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CarGo Admin Dashboard</title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body>
    <main class="dashboard-page">
        <header class="dashboard-header">
            <?php include 'header.php'; ?>
        </header>

        <section class="dashboard-content">
            <div class="dashboard-shell">
                <header class="page-heading">
                    <div>
                        <p class="eyebrow">Admin Dashboard</p>
                        <h1>Admin Dashboard</h1>
                        <p class="dashboard-heading-subtitle">Overview of cars, bookings, customers, and rental activity.</p>
                    </div>
                </header>

                <?php if ($error !== ''): ?>
                    <p class="message error"><?php echo h($error); ?></p>
                <?php endif; ?>

                <section class="dashboard-overview-grid">
                    <section class="dashboard-overview-panel">
                        <div class="admin-section-heading">
                            <p class="eyebrow">Cars</p>
                            <h2>Car Status Overview</h2>
                        </div>
                        <div class="dashboard-status-list">
                            <?php foreach (['available', 'unavailable', 'maintenance'] as $status): ?>
                                <div>
                                    <span class="status-pill <?php echo h(dashboardStatusClass($status)); ?>"><?php echo h(ucfirst($status)); ?></span>
                                    <strong><?php echo h($carStats[$status]); ?></strong>
                                </div>
                            <?php endforeach; ?>
                            <div>
                                <span class="status-pill status-inactive">Archived</span>
                                <strong><?php echo h($carStats['archived']); ?></strong>
                            </div>
                        </div>
                    </section>

                    <section class="dashboard-overview-panel">
                        <div class="admin-section-heading">
                            <p class="eyebrow">Bookings</p>
                            <h2>Booking Status Overview</h2>
                        </div>
                        <div class="dashboard-status-list">
                            <?php foreach ($bookingStats as $status => $count): ?>
                                <?php if ($status === 'total') {
                                    continue;
                                } ?>
                                <div>
                                    <span class="status-pill <?php echo h(dashboardStatusClass($status)); ?>"><?php echo h(ucfirst($status)); ?></span>
                                    <strong><?php echo h($count); ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                </section>

                <section class="dashboard-overview-panel dashboard-revenue-panel">
                    <div class="admin-section-heading">
                        <p class="eyebrow">Revenue</p>
                        <h2>Revenue Overview</h2>
                    </div>
                    <div class="dashboard-revenue-list">
                        <div>
                            <span>Booking revenue</span>
                            <strong>RM <?php echo h(number_format($bookingRevenue, 2)); ?></strong>
                        </div>
                        <div>
                            <span>Late fee revenue</span>
                            <strong>RM <?php echo h(number_format($lateFeeRevenue, 2)); ?></strong>
                        </div>
                        <div class="dashboard-revenue-total">
                            <span>Total revenue</span>
                            <strong>RM <?php echo h(number_format($totalRevenue, 2)); ?></strong>
                        </div>
                    </div>
                </section>

                <section class="dashboard-table-grid">
                    <section class="cars-list-panel">
                        <header class="panel-heading">
                            <p class="eyebrow">Bookings</p>
                            <h2>Recent Bookings</h2>
                        </header>

                        <?php if (count($recentBookings) === 0): ?>
                            <p class="empty-table-message">No bookings found.</p>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Booking</th>
                                            <th>Customer</th>
                                            <th>Car</th>
                                            <th>Dates</th>
                                            <th>Days</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Created</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentBookings as $booking): ?>
                                            <tr>
                                                <td><strong>#<?php echo h($booking['id']); ?></strong></td>
                                                <td><?php echo h($booking['customer_name'] ?: 'Customer removed'); ?></td>
                                                <td>
                                                    <strong><?php echo h(trim(($booking['brand'] ?? '') . ' ' . ($booking['model'] ?? '')) ?: 'Car removed'); ?></strong>
                                                    <span><?php echo h($booking['plate_number'] ?: 'No plate'); ?></span>
                                                </td>
                                                <td><?php echo h(formatDashboardDate($booking['pickup_date']) . ' - ' . formatDashboardDate($booking['return_date'])); ?></td>
                                                <td><?php echo h($booking['total_days']); ?></td>
                                                <td>RM <?php echo h(number_format((float) $booking['total_amount'], 2)); ?></td>
                                                <td><span class="status-pill <?php echo h(dashboardStatusClass($booking['booking_status'])); ?>"><?php echo h(ucfirst($booking['booking_status'])); ?></span></td>
                                                <td><?php echo h(formatDashboardDate($booking['created_at'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="cars-list-panel">
                        <header class="panel-heading">
                            <p class="eyebrow">Customers</p>
                            <h2>Recent Customers</h2>
                        </header>

                        <?php if (count($recentCustomers) === 0): ?>
                            <p class="empty-table-message">No customers found.</p>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Name</th>
                                            <th>Email</th>
                                            <th>Phone</th>
                                            <th>Status</th>
                                            <th>Created</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentCustomers as $customer): ?>
                                            <tr>
                                                <td>#<?php echo h($customer['id']); ?></td>
                                                <td><strong><?php echo h($customer['name']); ?></strong></td>
                                                <td><?php echo h($customer['email']); ?></td>
                                                <td><?php echo h($customer['phone'] ?: 'Not provided'); ?></td>
                                                <td><span class="status-pill <?php echo h(dashboardStatusClass($customer['status'])); ?>"><?php echo h(ucfirst($customer['status'])); ?></span></td>
                                                <td><?php echo h(formatDashboardDate($customer['created_at'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="cars-list-panel">
                        <header class="panel-heading">
                            <p class="eyebrow">Inventory</p>
                            <h2>Recent Cars</h2>
                        </header>

                        <?php if (count($recentCars) === 0): ?>
                            <p class="empty-table-message">No active cars found.</p>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Vehicle</th>
                                            <th>Plate</th>
                                            <th>Type</th>
                                            <th>Rate</th>
                                            <th>Status</th>
                                            <th>Created</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentCars as $car): ?>
                                            <tr>
                                                <td>#<?php echo h($car['id']); ?></td>
                                                <td><strong><?php echo h($car['brand'] . ' ' . $car['model']); ?></strong></td>
                                                <td><?php echo h($car['plate_number']); ?></td>
                                                <td><?php echo h($car['car_type']); ?></td>
                                                <td>RM <?php echo h(number_format((float) $car['daily_rate'], 2)); ?></td>
                                                <td><span class="status-pill <?php echo h(dashboardStatusClass($car['status'])); ?>"><?php echo h(ucfirst($car['status'])); ?></span></td>
                                                <td><?php echo h(formatDashboardDate($car['created_at'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>
                </section>
            </div>
        </section>
    </main>
</body>
</html>
