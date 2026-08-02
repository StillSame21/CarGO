<?php
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../util/car_archive.php';
require_once __DIR__ . '/../util/car_display.php';
require_once __DIR__ . '/../util/payment.php';
require_once __DIR__ . '/../util/recommendation.php';

if (!function_exists('h')) {
    // HTML-escapes a value for safe output.
    function h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$customerId = (int) ($_SESSION['customer_id'] ?? 0);
$customerName = $_SESSION['customer_name'] ?? $_SESSION['user_email'] ?? 'Customer';
$firstName = trim(explode(' ', (string) $customerName)[0]);
$firstName = $firstName !== '' ? $firstName : 'there';

$currentHour = (int) date('G');
$greeting = $currentHour < 12 ? 'Good morning' : ($currentHour < 18 ? 'Good afternoon' : 'Good evening');

$availableCars = [];
$dashboardError = '';
$availableCarCount = 0;
$recentBookings = [];
$bookingCounts = [
    'pending' => 0,
    'approved' => 0,
    'ongoing' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'rejected' => 0,
];
$paymentAttention = [
    'count' => 0,
    'amount_due' => 0.0,
];

try {
    $conn = getDbConnection();

    $result = $conn->query(
        'SELECT COUNT(*) AS available_cars
         FROM cars
         WHERE status = \'available\' AND archived_at IS NULL'
    );
    $availableCarCount = (int) (($result->fetch_assoc())['available_cars'] ?? 0);

    $availableCars = loadRecommendedCars($conn, recommendationSeed());

    if ($customerId > 0) {
        $stmt = $conn->prepare(
            'SELECT booking_status, COUNT(*) AS total
             FROM bookings
             WHERE customer_id = ?
             GROUP BY booking_status'
        );
        $stmt->bind_param('i', $customerId);
        $stmt->execute();

        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $status = (string) ($row['booking_status'] ?? '');
            if (array_key_exists($status, $bookingCounts)) {
                $bookingCounts[$status] = (int) $row['total'];
            }
        }

        $stmt = $conn->prepare(
            'SELECT
                COUNT(*) AS attention_count,
                COALESCE(SUM(GREATEST((b.total_amount + COALESCE(fees.total_late_fee, 0)) - COALESCE(p.amount, 0), 0)), 0) AS amount_due
             FROM bookings b
             LEFT JOIN payments p ON p.booking_id = b.id
             LEFT JOIN (
                SELECT booking_id, SUM(late_fee_amount) AS total_late_fee
                FROM late_fees
                GROUP BY booking_id
             ) fees ON fees.booking_id = b.id
             WHERE b.customer_id = ?
               AND b.booking_status NOT IN (\'cancelled\', \'rejected\')
               AND (
                    p.id IS NULL
                    OR p.payment_status <> ?
                    OR p.amount < (b.total_amount + COALESCE(fees.total_late_fee, 0))
               )'
        );
        $paidStatus = PAYMENT_STATUS_PAID;
        $stmt->bind_param('is', $customerId, $paidStatus);
        $stmt->execute();
        $paymentRow = $stmt->get_result()->fetch_assoc() ?: [];
        $paymentAttention = [
            'count' => (int) ($paymentRow['attention_count'] ?? 0),
            'amount_due' => (float) ($paymentRow['amount_due'] ?? 0),
        ];

        $stmt = $conn->prepare(
            'SELECT 
                b.id, b.booking_status, b.pickup_date, 
                car.brand, car.model,
                p.payment_status,
                COALESCE(fees.total_late_fee, 0) AS total_late_fee
             FROM bookings b
             INNER JOIN cars car ON car.id = b.car_id
             LEFT JOIN payments p ON p.booking_id = b.id
             LEFT JOIN (
                 SELECT booking_id, SUM(late_fee_amount) AS total_late_fee
                 FROM late_fees
                 GROUP BY booking_id
             ) fees ON fees.booking_id = b.id
             WHERE b.customer_id = ?
             ORDER BY b.created_at DESC LIMIT 3'
        );
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $recentBookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
} catch (mysqli_sql_exception $e) {
    $dashboardError = 'Could not load the latest dashboard data. Please check the database connection.';
}

$activeTripCount = $bookingCounts['approved'] + $bookingCounts['ongoing'];
$pendingCount = $bookingCounts['pending'];
?>
<?php if ($dashboardError !== ''): ?>
    <p class="cd-alert"><?php echo h($dashboardError); ?></p>
<?php endif; ?>

<header class="cd-head">
    <div>
        <div class="dc-mono-subtitle small">Customer Dashboard</div>
        <h1 class="cd-greeting"><?php echo h($greeting); ?>, <?php echo h($firstName); ?>.</h1>
    </div>
    <div class="dc-btn-group">
        <a href="browse_cars.php" class="dc-btn-primary">Browse Cars</a>
        <a href="my_bookings.php" class="dc-btn-secondary">My Bookings</a>
    </div>
</header>

<section class="cd-stats">
    <div class="dc-card">
        <span class="dc-mono-subtitle small">Active trips</span>
        <span class="dc-stat-number"><?php echo h($activeTripCount); ?></span>
        <span class="dc-stat-label">Approved or ongoing</span>
    </div>
    <div class="dc-card">
        <span class="dc-mono-subtitle small">Pending requests</span>
        <span class="dc-stat-number"><?php echo h($pendingCount); ?></span>
        <span class="dc-stat-label">Waiting for approval</span>
    </div>
    <?php $paymentDue = $paymentAttention['amount_due'] > 0; ?>
    <div class="dc-card<?php echo $paymentDue ? ' cd-card-attention' : ''; ?>">
        <span class="dc-mono-subtitle small">Payment due</span>
        <span class="dc-stat-number">RM <?php echo h(number_format($paymentAttention['amount_due'], 2)); ?></span>
        <?php if ($paymentDue): ?>
            <a class="dc-stat-label cd-stat-action" href="my_payments.php">Settle <?php echo h($paymentAttention['count']); ?> booking<?php echo $paymentAttention['count'] === 1 ? '' : 's'; ?> &rarr;</a>
        <?php else: ?>
            <span class="dc-stat-label">You're all settled</span>
        <?php endif; ?>
    </div>
</section>

<section class="dc-card padded">
    <div class="dc-h2-title">
        <div>
            <div class="dc-mono-subtitle small" style="margin-bottom:8px">Your activity</div>
            <h2 class="dc-h2">Recent bookings</h2>
        </div>
        <a href="my_bookings.php" class="cd-link">View all</a>
    </div>
    <div class="dc-list-container">
        <?php if (count($recentBookings) === 0): ?>
            <p class="cd-empty">You don't have any recent bookings.</p>
        <?php else: ?>
            <?php foreach ($recentBookings as $rb): ?>
                <a class="dc-list-item cd-booking-row" href="booking.php?id=<?php echo h($rb['id']); ?>">
                    <span class="cd-booking-car"><?php echo h($rb['brand'] . ' ' . $rb['model']); ?></span>
                    <span class="cd-booking-date"><?php echo h(date('M d, Y', strtotime($rb['pickup_date']))); ?></span>
                    <?php $displayStatus = bookingDisplayStatus((string) $rb['booking_status'], $rb['payment_status'] ?? null, (float) ($rb['total_late_fee'] ?? 0)); ?>
                    <span class="booking-status-pill cd-booking-pill <?php echo h($displayStatus['class']); ?>">
                        <?php echo h($displayStatus['label']); ?>
                    </span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<section class="dc-card padded">
    <div class="dc-h2-title">
        <div>
            <div class="dc-mono-subtitle small" style="margin-bottom:8px"><?php echo h($availableCarCount); ?> available</div>
            <h2 class="dc-h2">Recommended for you</h2>
        </div>
        <a href="browse_cars.php" class="cd-link">View all</a>
    </div>
    <div class="dc-grid-3">
        <?php if (count($availableCars) === 0): ?>
            <p class="cd-empty" style="grid-column:1/-1;">No cars are available right now. Please check again later.</p>
        <?php else: ?>
            <?php foreach ($availableCars as $carIndex => $car): ?>
                <a href="car_detail.php?id=<?php echo h($car['id']); ?>" class="dc-car-card">
                    <div class="dc-car-img-wrap">
                        <span class="dc-car-tag"><?php echo h($car['car_type']); ?></span>
                        <?php echo carImageTag(
                            $car['image'],
                            $car['brand'] . ' ' . $car['model'],
                            '(max-width: 700px) 100vw, 400px',
                            $carIndex < 4
                                ? ['loading' => 'eager', 'fetchpriority' => 'high']
                                : ['loading' => 'lazy']
                        ); ?>
                    </div>
                    <div class="dc-car-details">
                        <span class="dc-car-title"><?php echo h($car['brand'] . ' ' . $car['model']); ?></span>
                        <span class="dc-car-specs"><?php echo h($car['transmission']); ?> &middot; <?php echo h($car['fuel_type']); ?> &middot; <?php echo h($car['seats']); ?> seats</span>
                        <div class="dc-car-price-row">
                            <span class="dc-car-price">
                                <strong>RM <?php echo h(number_format((float) $car['daily_rate'], 0)); ?></strong>
                                <span>/ day</span>
                            </span>
                            <span class="dc-btn-icon">&rarr;</span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>
