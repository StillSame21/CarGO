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
<div class="dc-main">
    <?php if ($dashboardError !== ''): ?>
        <p class="message error" style="color: #c23a52; background: #fbeaed; padding: 12px; border-radius: 8px; font-weight: 600;"><?php echo h($dashboardError); ?></p>
    <?php endif; ?>

    <section class="dc-card-hero">
        <div>
            <div class="dc-mono-subtitle">Customer Dashboard</div>
            <h1 class="dc-h1">Good morning, <?php echo h($firstName); ?>.</h1>
            <p class="dc-p">Review your trips, continue payments, and find your next rental — all from one calm workspace.</p>
            <div class="dc-btn-group">
                <a href="browse_cars.php" class="dc-btn-primary">Browse Cars</a>
                <a href="my_bookings.php" class="dc-btn-secondary">My Bookings</a>
            </div>
        </div>
        <div style="position:relative; aspect-ratio:16/10; border-radius:calc(var(--r,20px) - 4px); overflow:hidden; background:linear-gradient(140deg, #20273b 0%, #11151f 60%, #0a0d14 100%); border:1px solid #1c2233; display:flex; align-items:center; justify-content:center;">
            <div style="position:absolute; inset:0; background-image:repeating-linear-gradient(135deg, rgba(255,255,255,0.04) 0px, rgba(255,255,255,0.04) 1px, transparent 1px, transparent 11px); pointer-events:none; z-index:1;"></div>
            <img src="https://images.unsplash.com/photo-1492144534655-ae79c964c9d7?auto=format&fit=crop&w=900&q=80" style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover; opacity:0.6;" alt="Hero car">
            <div style="position:absolute; top:16px; left:16px; display:flex; align-items:center; gap:7px; padding:7px 12px; background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.12); border-radius:999px; backdrop-filter:blur(6px); z-index:2;">
                <span style="width:7px; height:7px; border-radius:50%; background:var(--accent);"></span>
                <span style="font-size:11px; font-weight:600; letter-spacing:0.04em; color:rgba(255,255,255,0.82);">Featured pick</span>
            </div>
        </div>
    </section>

    <section class="dc-grid-4">
        <div class="dc-card">
            <div class="dc-stat-header">
                <span class="dc-mono-subtitle small">Available cars</span>
                <span class="dc-stat-dot blue"></span>
            </div>
            <span class="dc-stat-number"><?php echo h($availableCarCount); ?></span>
            <span class="dc-stat-label">Ready to browse today</span>
        </div>
        <div class="dc-card">
            <div class="dc-stat-header">
                <span class="dc-mono-subtitle small">Active trips</span>
                <span class="dc-stat-dot gray"></span>
            </div>
            <span class="dc-stat-number"><?php echo h($activeTripCount); ?></span>
            <span class="dc-stat-label">Approved or ongoing</span>
        </div>
        <div class="dc-card">
            <div class="dc-stat-header">
                <span class="dc-mono-subtitle small">Pending requests</span>
                <span class="dc-stat-dot yellow"></span>
            </div>
            <span class="dc-stat-number"><?php echo h($pendingCount); ?></span>
            <span class="dc-stat-label">Waiting for approval</span>
        </div>
        <div class="dc-card">
            <div class="dc-stat-header">
                <span class="dc-mono-subtitle small">Payment due</span>
                <span class="dc-stat-dot green"></span>
            </div>
            <span class="dc-stat-number">RM <?php echo h(number_format($paymentAttention['amount_due'], 2)); ?></span>
            <?php if ($paymentAttention['amount_due'] > 0): ?>
                <span class="dc-stat-label" style="color:#c23a52; font-weight:600;">Attention needed (<?php echo h($paymentAttention['count']); ?>)</span>
            <?php else: ?>
                <span class="dc-stat-label green">You're all settled</span>
            <?php endif; ?>
        </div>
    </section>

    <section class="dc-grid-2-sidebar">
        <div class="dc-card padded">
            <div class="dc-h2-title">
                <div>
                    <div class="dc-mono-subtitle small" style="margin-bottom:8px">Your activity</div>
                    <h2 class="dc-h2">Recent bookings</h2>
                </div>
                <a href="my_bookings.php" style="font-size:13px; font-weight:700; color:var(--accent); text-decoration:none; padding-top:2px;">View all</a>
            </div>
            <div class="dc-list-container">
                <?php if (count($recentBookings) === 0): ?>
                    <p style="font-size:14px; color:#9097a8;">You don't have any recent bookings.</p>
                <?php else: ?>
                    <?php foreach ($recentBookings as $rb): ?>
                        <div class="dc-list-item" style="cursor:pointer;" onclick="window.location.href='booking.php?id=<?php echo h($rb['id']); ?>'">
                            <div style="display:flex; flex-direction:column; gap:5px;">
                                <span style="font-size:14.5px; font-weight:700;"><?php echo h($rb['brand'] . ' ' . $rb['model']); ?></span>
                                <span style="font-family:'IBM Plex Mono',monospace; font-size:11.5px; color:#9097a8;"><?php echo h(date('M d, Y', strtotime($rb['pickup_date']))); ?></span>
                            </div>
                            <span class="dc-status <?php echo h(strtolower($rb['booking_status'])); ?>">
                                <?php echo h(ucfirst($rb['booking_status'])); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="dc-card padded">
            <div class="dc-h2-title">
                <div>
                    <div class="dc-mono-subtitle small" style="margin-bottom:8px">Recommended cars</div>
                    <h2 class="dc-h2">Available for your next booking</h2>
                </div>
                <a href="browse_cars.php" style="font-size:13px; font-weight:700; color:var(--accent); text-decoration:none; padding-top:2px;">View all</a>
            </div>
            <div class="dc-grid-3">
                <?php if (count($availableCars) === 0): ?>
                    <p style="font-size:14px; color:#9097a8; grid-column:1/-1;">No cars are available right now. Please check again later.</p>
                <?php else: ?>
                    <?php foreach ($availableCars as $car): ?>
                        <a href="car_detail.php?id=<?php echo h($car['id']); ?>" class="dc-car-card" style="text-decoration:none; color:inherit;">
                            <div class="dc-car-img-wrap">
                                <span class="dc-car-tag"><?php echo h($car['car_type']); ?></span>
                                <img src="<?php echo h(carImageUrl($car['image'], 'https://images.unsplash.com/photo-1549317661-bd32c8ce0db2?auto=format&fit=crop&w=700&q=80')); ?>" alt="<?php echo h($car['brand'] . ' ' . $car['model']); ?>">
                            </div>
                            <div class="dc-car-details">
                                <span class="dc-car-title"><?php echo h($car['brand'] . ' ' . $car['model']); ?></span>
                                <span class="dc-car-specs"><?php echo h($car['transmission']); ?> · <?php echo h($car['fuel_type']); ?> · <?php echo h($car['seats']); ?> seats</span>
                                <div class="dc-car-price-row">
                                    <span class="dc-car-price">
                                        <strong>RM <?php echo h(number_format((float) $car['daily_rate'], 0)); ?></strong>
                                        <span>/ day</span>
                                    </span>
                                    <button class="dc-btn-icon">→</button>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>
<script src="../js/dashboard.js"></script>
