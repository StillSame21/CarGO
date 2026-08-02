<?php
require_once __DIR__ . '/../includes/security.php';
require_once '../db_connect.php';
require_once __DIR__ . '/includes/auth.php';
// Query + view helpers live in declaration-only includes so this page stays side-effect-only (PSR-1 §2.3).
require_once __DIR__ . '/../util/html.php';
require_once __DIR__ . '/../util/booking.php';
require_once __DIR__ . '/includes/format.php';
require_once __DIR__ . '/includes/query.php';
require_once __DIR__ . '/includes/dashboard_data.php';

startSecureSession();

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
    $bookingStatuses = adminBookingStatusValues($conn);
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

        <?php if ($error !== '') : ?>
            <p class="message error" style="color: var(--stop); background: var(--stop-soft); padding: 12px 16px; border-radius: 8px; font-weight: 600;"><?php echo h($error); ?></p>
        <?php endif; ?>

        <!-- Work waiting on an admin -->
        <section>
            <div class="ov-section-head">
                <h2>Needs you now</h2>
                <?php if ($totalActionable > 0) : ?>
                    <span class="ov-stamp"><?php echo h($totalActionable); ?> open</span>
                <?php else : ?>
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
                    <?php if ($revenueDelta === null) : ?>
                        <span class="ov-delta is-flat">No prior period</span>
                    <?php elseif ($revenueDelta >= 0) : ?>
                        <span class="ov-delta">▲ <?php echo h(number_format($revenueDelta, 1)); ?>% vs prior 14d</span>
                    <?php else : ?>
                        <span class="ov-delta is-down">▼ <?php echo h(number_format(abs($revenueDelta), 1)); ?>% vs prior 14d</span>
                    <?php endif; ?>
                </div>

                <?php if ($sparkPoints !== '') : ?>
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
                <?php foreach ($fleetSegments as $segment) : ?>
                    <?php if ($segment['count'] > 0) : ?>
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
            <?php if (count($recentBookings) === 0) : ?>
                <p class="ov-empty">No bookings found.</p>
            <?php else : ?>
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
                            <?php foreach ($recentBookings as $booking) : ?>
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
                                        <?php echo h(formatBookingDate($booking['pickup_date']) . ' – ' . formatBookingDate($booking['return_date'])); ?>
                                        <?php if ($datesReversed) : ?>
                                            <div class="ov-flag">▲ Return before pickup</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="ov-amt">RM <?php echo h(number_format((float) $booking['total_amount'], 2)); ?></td>
                                    <td><span class="status-pill <?php echo h(statusPillClass($status)); ?>"><?php echo h($label); ?></span></td>
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
                    <?php if (count($recentCustomers) === 0) : ?>
                        <p class="ov-empty">No customers found.</p>
                    <?php else : ?>
                        <?php foreach ($recentCustomers as $customer) : ?>
                            <div class="dc-list-item">
                                <div style="display:flex; flex-direction:column; gap:5px; min-width:0;">
                                    <span style="font-size:14.5px; font-weight:700;"><?php echo h($customer['name']); ?></span>
                                    <span class="ov-sub" style="font-family:'IBM Plex Mono',monospace;"><?php echo h($customer['email']); ?></span>
                                </div>
                                <span class="status-pill <?php echo h(statusPillClass((string) $customer['status'])); ?>"><?php echo h(ucfirst($customer['status'])); ?></span>
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
                <?php if (count($recentCars) === 0) : ?>
                    <p class="ov-empty">No cars found.</p>
                <?php else : ?>
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
                                <?php foreach ($recentCars as $car) : ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight:600;"><?php echo h($car['brand'] . ' ' . $car['model']); ?></div>
                                            <div class="ov-sub"><?php echo h($car['car_type']); ?></div>
                                        </td>
                                        <td><?php echo h($car['plate_number']); ?></td>
                                        <td class="ov-amt">RM <?php echo h(number_format((float) $car['daily_rate'], 2)); ?></td>
                                        <td><span class="status-pill <?php echo h(statusPillClass((string) $car['status'])); ?>"><?php echo h(ucfirst($car['status'])); ?></span></td>
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
