<?php
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../util/car_archive.php';
require_once __DIR__ . '/../util/car_display.php';
require_once __DIR__ . '/../util/payment.php';

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$customerId = (int) ($_SESSION['customer_id'] ?? 0);
$customerName = $_SESSION['customer_name'] ?? $_SESSION['user_email'] ?? 'Customer';
$firstName = trim(explode(' ', (string) $customerName)[0]);
$firstName = $firstName !== '' ? $firstName : 'there';

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
    ensureCarArchiveColumn($conn);

    $result = $conn->query(
        'SELECT COUNT(*) AS available_cars
         FROM cars
         WHERE status = \'available\' AND archived_at IS NULL'
    );
    $availableCarCount = (int) (($result->fetch_assoc())['available_cars'] ?? 0);

    $result = $conn->query(
        'SELECT id, brand, model, plate_number, car_type, transmission, fuel_type, seats, daily_rate, image
         FROM cars
         WHERE status = \'available\' AND archived_at IS NULL
         ORDER BY created_at DESC, id DESC
         LIMIT 3'
    );
    $availableCars = $result->fetch_all(MYSQLI_ASSOC);

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
            'SELECT b.id, b.booking_status, b.pickup_date, car.brand, car.model
             FROM bookings b
             INNER JOIN cars car ON car.id = b.car_id
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
<div class="dashboard-shell customer-home">
    <?php if ($dashboardError !== ''): ?>
        <p class="message error"><?php echo h($dashboardError); ?></p>
    <?php endif; ?>

    <section class="customer-home-hero">
        <div class="customer-home-copy">
            <p class="eyebrow">Customer Dashboard</p>
            <h1>Welcome back, <?php echo h($firstName); ?>.</h1>
            <p>
                Review your trips, continue payments, and find your next rental from one calm workspace.
            </p>
            <div class="hero-actions">
                <a class="primary-action" href="browse_cars.php">Browse Cars</a>
                <a class="secondary-action" href="my_bookings.php">My Bookings</a>
            </div>
        </div>

        <div class="customer-home-visual" aria-label="Dashboard summary">
            <img
                src="https://images.unsplash.com/photo-1492144534655-ae79c964c9d7?auto=format&fit=crop&w=900&q=80"
                alt="Featured CarGo rental car"
            >
        </div>
    </section>

    <section class="customer-overview-grid" aria-label="Dashboard overview">
        <article class="customer-overview-card">
            <span>Available Cars</span>
            <strong><?php echo h($availableCarCount); ?></strong>
            <p>Ready to browse today</p>
        </article>

        <article class="customer-overview-card">
            <span>Active Trips</span>
            <strong><?php echo h($activeTripCount); ?></strong>
            <p>Approved or ongoing</p>
        </article>

        <article class="customer-overview-card">
            <span>Pending Requests</span>
            <strong><?php echo h($pendingCount); ?></strong>
            <p>Waiting for approval</p>
        </article>

        <article class="customer-overview-card attention-card">
            <span>Payment Attention</span>
            <strong><?php echo h($paymentAttention['count']); ?></strong>
            <p>RM <?php echo h(number_format($paymentAttention['amount_due'], 2)); ?> due</p>
        </article>
    </section>

    <div class="dashboard-split">
        <section class="customer-panel dashboard-recent-bookings">
            <div class="customer-panel-heading">
                <div>
                    <p class="eyebrow">Your Activity</p>
                    <h2>Recent Bookings</h2>
                </div>
                <a class="customer-section-link" href="my_bookings.php">View all</a>
            </div>
            <?php if (count($recentBookings) === 0): ?>
                <p class="customer-panel-note">You don't have any recent bookings.</p>
            <?php else: ?>
                <div class="recent-booking-list">
                    <?php foreach ($recentBookings as $rb): ?>
                        <a href="booking.php?id=<?php echo h($rb['id']); ?>" class="recent-booking-item">
                            <div>
                                <strong><?php echo h($rb['brand'] . ' ' . $rb['model']); ?></strong>
                                <span><?php echo h(date('M d, Y', strtotime($rb['pickup_date']))); ?></span>
                            </div>
                            <span class="booking-status-pill <?php echo h(strtolower($rb['booking_status'])); ?>">
                                <?php echo h(ucfirst($rb['booking_status'])); ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

    <section class="customer-panel">
        <div class="customer-panel-heading">
            <div>
                <p class="eyebrow">Recommended Cars</p>
                <h2>Available for your next booking</h2>
            </div>
            <a class="customer-section-link" href="browse_cars.php">View all</a>
        </div>

        <?php if (count($availableCars) === 0): ?>
            <p class="customer-panel-note">No cars are available right now. Please check again later.</p>
        <?php else: ?>
            <div class="recommended-car-grid">
                <?php foreach ($availableCars as $car): ?>
                    <a class="recommended-car-card" href="car_detail.php?id=<?php echo h($car['id']); ?>">
                        <img
                            src="<?php echo h(carImageUrl($car['image'], 'https://images.unsplash.com/photo-1549317661-bd32c8ce0db2?auto=format&fit=crop&w=700&q=80')); ?>"
                            alt="<?php echo h($car['brand'] . ' ' . $car['model']); ?>"
                        >
                        <div>
                            <span><?php echo h($car['car_type']); ?></span>
                            <h3><?php echo h($car['brand'] . ' ' . $car['model']); ?></h3>
                            <p><?php echo h($car['transmission']); ?> / <?php echo h($car['fuel_type']); ?> / <?php echo h($car['seats']); ?> seats</p>
                            <strong>RM <?php echo h(number_format((float) $car['daily_rate'], 2)); ?>/day</strong>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    </div>
</div>
<script src="../js/dashboard.js"></script>
