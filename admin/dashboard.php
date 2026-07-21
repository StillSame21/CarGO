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
$totalRevenue = 0.0;
$queues = [
    'awaiting_pickup' => 0,
    'overdue_return' => 0,
    'unpaid_late_fees' => 0,
    'maintenance' => 0,
];
$revenueSeries = ['series' => [], 'current' => 0.0, 'previous' => 0.0];

try {
    $conn = getDbConnection();
    $bookingStatuses = bookingStatusValues($conn);
    $carStats = loadDashboardCarStats($conn);
    $bookingStats = loadDashboardBookingStats($conn, $bookingStatuses);
    $revenue = loadDashboardRevenue($conn);
    $bookingRevenue = $revenue['booking'];
    $lateFeeRevenue = $revenue['late_fee'];
    $totalRevenue = $bookingRevenue + $lateFeeRevenue;
    $queues = loadDashboardQueues($conn, $carStats['maintenance']);
    $revenueSeries = loadDashboardRevenueSeries($conn);
    $recentBookings = loadRecentDashboardBookings($conn);
    $recentCustomers = loadRecentDashboardCustomers($conn);
    $recentCars = loadRecentDashboardCars($conn);
} catch (mysqli_sql_exception $e) {
    $error = 'Could not load dashboard data. Please check the database connection and schema.';
}

$onRent = (int) ($bookingStats['ongoing'] ?? 0);
$fleetTotal = max(1, $carStats['active']);
$utilisation = (int) round(($onRent / $fleetTotal) * 100);

$revenueDelta = null;
if ($revenueSeries['previous'] > 0) {
    $revenueDelta = (($revenueSeries['current'] - $revenueSeries['previous']) / $revenueSeries['previous']) * 100;
}

$sparkPoints = sparklinePoints($revenueSeries['series']);
$totalActionable = $queues['awaiting_pickup'] + $queues['overdue_return'] + $queues['unpaid_late_fees'];
?>
<?php
$pageTitle = 'CarGo Admin Dashboard';
include '../includes/layout_top.php';
include 'header.php';
?>
<main class="dc-main">
    <div class="ov-stack">

        <header class="ov-head">
            <div>
                <div class="dc-mono-subtitle small" style="margin-bottom:8px">Operations</div>
                <h1 class="dc-h1" style="font-size:32px;">Today at CarGo</h1>
            </div>
            <div class="ov-stamp"><?php echo h(strtoupper(date('D d M Y · H:i'))); ?></div>
        </header>

        <?php if ($error !== ''): ?>
            <p class="message error" style="color: var(--stop); background: var(--stop-soft); padding: 12px 16px; border-radius: 8px; font-weight: 600;"><?php echo h($error); ?></p>
        <?php endif; ?>

        <!-- Work waiting on an admin -->
        <section>
            <div class="ov-section-head">
                <h2>Needs you now</h2>
                <?php if ($totalActionable > 0): ?>
                    <span class="ov-stamp"><?php echo h($totalActionable); ?> open</span>
                <?php else: ?>
                    <span class="ov-stamp">All clear</span>
                <?php endif; ?>
            </div>

            <div class="ov-rail">
                <a class="ov-queue <?php echo $queues['awaiting_pickup'] > 0 ? 'is-hot' : 'is-calm'; ?>" href="bookings.php?status=approved">
                    <span class="ov-queue-top"><span class="ov-dot"></span><span class="ov-queue-label">Awaiting pickup</span></span>
                    <span class="ov-queue-n"><?php echo h($queues['awaiting_pickup']); ?></span>
                    <span class="ov-queue-sub">Paid, not handed over</span>
                </a>

                <a class="ov-queue <?php echo $queues['overdue_return'] > 0 ? 'is-hot' : 'is-calm'; ?>" href="bookings.php?status=ongoing">
                    <span class="ov-queue-top"><span class="ov-dot"></span><span class="ov-queue-label">Overdue return</span></span>
                    <span class="ov-queue-n"><?php echo h($queues['overdue_return']); ?></span>
                    <span class="ov-queue-sub">Past return date</span>
                </a>

                <a class="ov-queue <?php echo $queues['unpaid_late_fees'] > 0 ? 'is-due' : 'is-calm'; ?>" href="bookings.php">
                    <span class="ov-queue-top"><span class="ov-dot"></span><span class="ov-queue-label">Unpaid late fees</span></span>
                    <span class="ov-queue-n"><?php echo h($queues['unpaid_late_fees']); ?></span>
                    <span class="ov-queue-sub">Owed by customers</span>
                </a>

                <a class="ov-queue <?php echo $queues['maintenance'] > 0 ? 'is-due' : 'is-calm'; ?>" href="manage_cars.php">
                    <span class="ov-queue-top"><span class="ov-dot"></span><span class="ov-queue-label">In maintenance</span></span>
                    <span class="ov-queue-n"><?php echo h($queues['maintenance']); ?></span>
                    <span class="ov-queue-sub">Off the road</span>
                </a>
            </div>
        </section>

        <!-- Money -->
        <section class="ov-band">
            <div class="dc-card padded">
                <div class="ov-section-head">
                    <h2>Revenue collected</h2>
                    <span class="ov-stamp">Last 14 days</span>
                </div>

                <div class="ov-money-row">
                    <span class="ov-money">RM <?php echo h(number_format($revenueSeries['current'], 2)); ?></span>
                    <?php if ($revenueDelta === null): ?>
                        <span class="ov-delta is-flat">No prior period</span>
                    <?php elseif ($revenueDelta >= 0): ?>
                        <span class="ov-delta">▲ <?php echo h(number_format($revenueDelta, 1)); ?>% vs prior 14d</span>
                    <?php else: ?>
                        <span class="ov-delta is-down">▼ <?php echo h(number_format(abs($revenueDelta), 1)); ?>% vs prior 14d</span>
                    <?php endif; ?>
                </div>

                <?php if ($sparkPoints !== ''): ?>
                    <svg class="ov-spark" viewBox="0 0 320 56" preserveAspectRatio="none" role="img"
                         aria-label="Daily revenue collected over the last 14 days.">
                        <line x1="0" y1="18" x2="320" y2="18" stroke="var(--line)" stroke-width="1"></line>
                        <line x1="0" y1="37" x2="320" y2="37" stroke="var(--line)" stroke-width="1"></line>
                        <polyline points="<?php echo h($sparkPoints); ?>" fill="none" stroke="var(--accent)"
                                  stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></polyline>
                    </svg>
                <?php endif; ?>

                <p class="ov-foot">All time collected: RM <?php echo h(number_format($totalRevenue, 2)); ?></p>
            </div>

            <div class="dc-card padded">
                <div class="ov-section-head"><h2>Where it came from</h2></div>
                <?php $revenueBase = max(0.01, $totalRevenue); ?>
                <div class="ov-split">
                    <div class="ov-split-row">
                        <div class="ov-split-top"><span>Rentals</span><span>RM <?php echo h(number_format($bookingRevenue, 2)); ?></span></div>
                        <div class="ov-meter"><i style="width: <?php echo h(round(($bookingRevenue / $revenueBase) * 100, 1)); ?>%; --m: var(--accent);"></i></div>
                    </div>
                    <div class="ov-split-row">
                        <div class="ov-split-top"><span>Late fees</span><span>RM <?php echo h(number_format($lateFeeRevenue, 2)); ?></span></div>
                        <div class="ov-meter"><i style="width: <?php echo h(round(($lateFeeRevenue / $revenueBase) * 100, 1)); ?>%; --m: var(--wait);"></i></div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Fleet -->
        <section class="dc-card padded">
            <div class="ov-section-head">
                <h2>Fleet right now</h2>
                <a class="ov-link" href="manage_cars.php">Manage cars →</a>
            </div>

            <?php
            $fleetSegments = [
                ['count' => $carStats['available'], 'label' => 'available', 'color' => 'var(--go)'],
                ['count' => $onRent, 'label' => 'on rent', 'color' => 'var(--accent)'],
                ['count' => $carStats['maintenance'], 'label' => 'maintenance', 'color' => 'var(--wait)'],
            ];
            ?>
            <div class="ov-fleet-bar">
                <?php foreach ($fleetSegments as $segment): ?>
                    <?php if ($segment['count'] > 0): ?>
                        <div class="ov-fleet-seg" style="flex: <?php echo h($segment['count']); ?>; background: <?php echo $segment['color']; ?>;">
                            <?php echo h($segment['count'] . ' ' . $segment['label']); ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div class="ov-legend">
                <span class="ov-leg"><span class="ov-chip" style="background:var(--go)"></span> Available <b><?php echo h($carStats['available']); ?></b></span>
                <span class="ov-leg"><span class="ov-chip" style="background:var(--accent)"></span> On rent <b><?php echo h($onRent); ?></b></span>
                <span class="ov-leg"><span class="ov-chip" style="background:var(--wait)"></span> Maintenance <b><?php echo h($carStats['maintenance']); ?></b></span>
                <span class="ov-leg"><span class="ov-chip" style="background:var(--ink-3)"></span> Archived <b><?php echo h($carStats['archived']); ?></b></span>
            </div>

            <p class="ov-foot">
                Utilisation <?php echo h($utilisation); ?>% — <?php echo h($onRent); ?> of <?php echo h($carStats['active']); ?> active cars are out on rent.
            </p>
        </section>

        <!-- Ledger -->
        <section class="dc-card padded">
            <div class="ov-section-head">
                <h2>Latest bookings</h2>
                <a class="ov-link" href="bookings.php">View all →</a>
            </div>
            <?php if (count($recentBookings) === 0): ?>
                <p class="ov-empty">No bookings found.</p>
            <?php else: ?>
                <div class="ov-tbl-scroll">
                    <table class="ov-tbl">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Customer</th>
                                <th>Vehicle</th>
                                <th>Dates</th>
                                <th style="text-align:right">Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentBookings as $booking): ?>
                                <?php
                                $status = (string) $booking['booking_status'];
                                $label = $status === 'approved' ? 'Awaiting pickup' : ucfirst($status);
                                $datesReversed = $booking['pickup_date'] && $booking['return_date']
                                    && $booking['return_date'] < $booking['pickup_date'];
                                ?>
                                <tr>
                                    <td class="ov-id">#<?php echo h($booking['id']); ?></td>
                                    <td><?php echo h($booking['customer_name'] ?: 'Customer removed'); ?></td>
                                    <td>
                                        <div style="font-weight:600;"><?php echo h(trim(($booking['brand'] ?? '') . ' ' . ($booking['model'] ?? '')) ?: 'Car removed'); ?></div>
                                        <div class="ov-sub"><?php echo h($booking['plate_number'] ?: 'No plate'); ?></div>
                                    </td>
                                    <td>
                                        <?php echo h(formatDashboardDate($booking['pickup_date']) . ' – ' . formatDashboardDate($booking['return_date'])); ?>
                                        <?php if ($datesReversed): ?>
                                            <div class="ov-flag">▲ Return before pickup</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="ov-amt">RM <?php echo h(number_format((float) $booking['total_amount'], 2)); ?></td>
                                    <td><span class="status-pill <?php echo h(dashboardStatusClass($status)); ?>"><?php echo h($label); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="ov-pair">
            <div class="dc-card padded">
                <div class="ov-section-head">
                    <h2>Recent customers</h2>
                    <a class="ov-link" href="customers.php">View all →</a>
                </div>
                <div class="dc-list-container">
                    <?php if (count($recentCustomers) === 0): ?>
                        <p class="ov-empty">No customers found.</p>
                    <?php else: ?>
                        <?php foreach ($recentCustomers as $customer): ?>
                            <div class="dc-list-item">
                                <div style="display:flex; flex-direction:column; gap:5px; min-width:0;">
                                    <span style="font-size:14.5px; font-weight:700;"><?php echo h($customer['name']); ?></span>
                                    <span class="ov-sub" style="font-family:'IBM Plex Mono',monospace;"><?php echo h($customer['email']); ?></span>
                                </div>
                                <span class="status-pill <?php echo h(dashboardStatusClass((string) $customer['status'])); ?>"><?php echo h(ucfirst($customer['status'])); ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="dc-card padded">
                <div class="ov-section-head">
                    <h2>Recent cars</h2>
                    <a class="ov-link" href="manage_cars.php">View all →</a>
                </div>
                <?php if (count($recentCars) === 0): ?>
                    <p class="ov-empty">No cars found.</p>
                <?php else: ?>
                    <div class="ov-tbl-scroll">
                        <table class="ov-tbl" style="min-width:0;">
                            <thead>
                                <tr>
                                    <th>Vehicle</th>
                                    <th>Plate</th>
                                    <th style="text-align:right">Rate</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentCars as $car): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight:600;"><?php echo h($car['brand'] . ' ' . $car['model']); ?></div>
                                            <div class="ov-sub"><?php echo h($car['car_type']); ?></div>
                                        </td>
                                        <td><?php echo h($car['plate_number']); ?></td>
                                        <td class="ov-amt">RM <?php echo h(number_format((float) $car['daily_rate'], 2)); ?></td>
                                        <td><span class="status-pill <?php echo h(dashboardStatusClass((string) $car['status'])); ?>"><?php echo h(ucfirst($car['status'])); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>

    </div>
</main>
<?php include '../includes/layout_bottom.php'; ?>
