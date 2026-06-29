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
?>
<?php
$pageTitle = 'CarGo Admin Dashboard';
include '../includes/layout_top.php';
include 'header.php';
?>
<main class="dc-main">
    <section class="dc-card-hero">
        <div>
            <div class="dc-mono-subtitle">Admin Dashboard</div>
            <h1 class="dc-h1">Overview</h1>
            <p class="dc-p">Overview of cars, bookings, customers, and rental activity.</p>
        </div>
        <div style="position:relative; aspect-ratio:16/10; border-radius:calc(var(--r,20px) - 4px); overflow:hidden; background:linear-gradient(140deg, #20273b 0%, #11151f 60%, #0a0d14 100%); border:1px solid #1c2233; display:flex; align-items:center; justify-content:center;">
            <div style="position:absolute; inset:0; background-image:repeating-linear-gradient(135deg, rgba(255,255,255,0.04) 0px, rgba(255,255,255,0.04) 1px, transparent 1px, transparent 11px); pointer-events:none; z-index:1;"></div>
            <div style="z-index:2; text-align:center;">
                <span class="dc-stat-number" style="color:#fff; font-size:48px; display:block;">RM <?php echo h(number_format($totalRevenue, 2)); ?></span>
                <span class="dc-stat-label" style="color:rgba(255,255,255,0.7);">Total Revenue</span>
            </div>
        </div>
    </section>

    <?php if ($error !== ''): ?>
        <p class="message error" style="color: #c23a52; background: #fbeaed; padding: 12px; border-radius: 8px; font-weight: 600;"><?php echo h($error); ?></p>
    <?php endif; ?>

    <section class="dc-grid-4">
        <div class="dc-card">
            <div class="dc-stat-header">
                <span class="dc-mono-subtitle small">Active Cars</span>
                <span class="dc-stat-dot blue"></span>
            </div>
            <span class="dc-stat-number"><?php echo h($carStats['active']); ?></span>
            <span class="dc-stat-label">Out of <?php echo h($carStats['total']); ?> total cars</span>
        </div>
        
        <div class="dc-card">
            <div class="dc-stat-header">
                <span class="dc-mono-subtitle small">Ongoing Bookings</span>
                <span class="dc-stat-dot green"></span>
            </div>
            <span class="dc-stat-number"><?php echo h($bookingStats['ongoing'] ?? 0); ?></span>
            <span class="dc-stat-label">Currently active</span>
        </div>

        <div class="dc-card">
            <div class="dc-stat-header">
                <span class="dc-mono-subtitle small">Pending Approvals</span>
                <span class="dc-stat-dot yellow"></span>
            </div>
            <span class="dc-stat-number"><?php echo h($bookingStats['pending'] ?? 0); ?></span>
            <span class="dc-stat-label">Needs attention</span>
        </div>

        <div class="dc-card">
            <div class="dc-stat-header">
                <span class="dc-mono-subtitle small">Maintenance</span>
                <span class="dc-stat-dot gray"></span>
            </div>
            <span class="dc-stat-number"><?php echo h($carStats['maintenance']); ?></span>
            <span class="dc-stat-label">Cars in repair</span>
        </div>
    </section>

    <div class="dc-card padded" style="margin-bottom: 18px;">
        <div class="dc-h2-title">
            <div>
                <div class="dc-mono-subtitle small" style="margin-bottom:8px">Bookings</div>
                <h2 class="dc-h2">Recent Bookings</h2>
            </div>
            <a href="bookings.php" style="font-size:13px; font-weight:700; color:var(--accent); text-decoration:none;">View all</a>
        </div>
        <?php if (count($recentBookings) === 0): ?>
            <p style="font-size:14px; color:#9097a8;">No bookings found.</p>
        <?php else: ?>
            <div class="dc-table-wrap">
                <table class="dc-table">
                    <thead>
                        <tr>
                            <th>Booking ID</th>
                            <th>Customer</th>
                            <th>Car</th>
                            <th>Dates</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentBookings as $booking): ?>
                            <tr>
                                <td><strong>#<?php echo h($booking['id']); ?></strong></td>
                                <td><?php echo h($booking['customer_name'] ?: 'Customer removed'); ?></td>
                                <td>
                                    <div style="font-weight:600;"><?php echo h(trim(($booking['brand'] ?? '') . ' ' . ($booking['model'] ?? '')) ?: 'Car removed'); ?></div>
                                    <div style="font-size:12px; color:#9097a8;"><?php echo h($booking['plate_number'] ?: 'No plate'); ?></div>
                                </td>
                                <td><?php echo h(formatDashboardDate($booking['pickup_date']) . ' - ' . formatDashboardDate($booking['return_date'])); ?></td>
                                <td>RM <?php echo h(number_format((float) $booking['total_amount'], 2)); ?></td>
                                <td><span class="dc-status <?php echo h(strtolower($booking['booking_status'])); ?>"><?php echo h(ucfirst($booking['booking_status'])); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <section class="dc-grid-2-sidebar">
        <div class="dc-card padded">
            <div class="dc-h2-title">
                <div>
                    <div class="dc-mono-subtitle small" style="margin-bottom:8px">Customers</div>
                    <h2 class="dc-h2">Recent Customers</h2>
                </div>
                <a href="customers.php" style="font-size:13px; font-weight:700; color:var(--accent); text-decoration:none;">View all</a>
            </div>
            <div class="dc-list-container">
                <?php if (count($recentCustomers) === 0): ?>
                    <p style="font-size:14px; color:#9097a8;">No customers found.</p>
                <?php else: ?>
                    <?php foreach ($recentCustomers as $customer): ?>
                        <div class="dc-list-item">
                            <div style="display:flex; flex-direction:column; gap:5px;">
                                <span style="font-size:14.5px; font-weight:700;"><?php echo h($customer['name']); ?></span>
                                <span style="font-family:'IBM Plex Mono',monospace; font-size:11.5px; color:#9097a8;"><?php echo h($customer['email']); ?></span>
                            </div>
                            <span class="dc-status <?php echo h(strtolower($customer['status'])); ?>"><?php echo h(ucfirst($customer['status'])); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="dc-card padded">
            <div class="dc-h2-title">
                <div>
                    <div class="dc-mono-subtitle small" style="margin-bottom:8px">Inventory</div>
                    <h2 class="dc-h2">Recent Cars</h2>
                </div>
                <a href="manage_cars.php" style="font-size:13px; font-weight:700; color:var(--accent); text-decoration:none;">View all</a>
            </div>
            <div class="dc-table-wrap">
                <table class="dc-table">
                    <thead>
                        <tr>
                            <th>Vehicle</th>
                            <th>Plate</th>
                            <th>Rate</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentCars as $car): ?>
                            <tr>
                                <td>
                                    <div style="font-weight:600;"><?php echo h($car['brand'] . ' ' . $car['model']); ?></div>
                                    <div style="font-size:12px; color:#9097a8;"><?php echo h($car['car_type']); ?></div>
                                </td>
                                <td><?php echo h($car['plate_number']); ?></td>
                                <td>RM <?php echo h(number_format((float) $car['daily_rate'], 2)); ?></td>
                                <td><span class="dc-status <?php echo h(strtolower($car['status'])); ?>"><?php echo h(ucfirst($car['status'])); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</main>
<?php include '../includes/layout_bottom.php'; ?>
