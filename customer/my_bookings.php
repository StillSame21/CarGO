<?php
require_once __DIR__ . '/includes/auth.php';
require_once '../db_connect.php';
require_once __DIR__ . '/../util/payment.php';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatBookingDate(?string $date): string
{
    if ($date === null || $date === '') {
        return 'Not set';
    }

    return date('d M Y', strtotime($date));
}

startSecureSession();
requireCustomerLogin();

$bookings = [];
$error = '';
$customerId = (int) ($_SESSION['customer_id'] ?? 0);

try {
    $conn = getDbConnection();
    $stmt = $conn->prepare(
        'SELECT
            b.id,
            b.pickup_date,
            b.return_date,
            b.total_days,
            b.total_amount,
            b.booking_status,
            p.payment_status,
            COALESCE(fees.total_late_fee, 0) AS total_late_fee,
            car.brand,
            car.model,
            car.plate_number
         FROM bookings b
         INNER JOIN cars car ON car.id = b.car_id
         LEFT JOIN payments p ON p.booking_id = b.id
         LEFT JOIN (
            SELECT booking_id, SUM(late_fee_amount) AS total_late_fee
            FROM late_fees
            GROUP BY booking_id
         ) fees ON fees.booking_id = b.id
         WHERE b.customer_id = ?
         ORDER BY b.created_at DESC, b.id DESC'
    );
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (mysqli_sql_exception $e) {
    $error = 'Could not load your bookings. Please check the database connection.';
}
?>
<?php
$pageTitle = 'My Bookings | CarGo';
include '../includes/layout_top.php';
include 'header.php';
?>
<main class="dc-main">
    <header class="dc-h2-title" style="margin-bottom: 0;">
        <div>
            <div class="dc-mono-subtitle small" style="margin-bottom:8px">Bookings</div>
            <h1 class="dc-h1" style="font-size:32px;">My bookings</h1>
        </div>
    </header>

    <?php if ($error !== ''): ?>
        <p class="message error" style="color: #c23a52; background: #fbeaed; padding: 12px; border-radius: 8px; font-weight: 600;"><?php echo h($error); ?></p>
    <?php elseif (count($bookings) === 0): ?>
        <div class="dc-card padded" style="text-align:center; padding: 60px 20px;">
            <h2 class="dc-h2">No bookings yet.</h2>
            <p class="dc-p" style="margin-top:12px;">Check availability on a car to create your first booking.</p>
        </div>
    <?php else: ?>
        <div class="dc-card" style="padding: 16px;">
            <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                <input type="text" id="booking-search" class="dc-input" placeholder="Search car or booking ID..." style="flex:1; min-width:200px;">
                <select id="filter-status" class="dc-select" style="width:auto;">
                    <option value="">All Statuses</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="ongoing">Ongoing</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="rejected">Rejected</option>
                    <option value="late fees">Late Fees</option>
                    <option value="paid">Paid</option>
                </select>
                <select id="sort-by" class="dc-select" style="width:auto;">
                    <option value="newest">Newest First</option>
                    <option value="oldest">Oldest First</option>
                </select>
            </div>
            <div style="margin-top:12px; font-size:13px; font-weight:600; color:#5b6273;">
                <span id="results-count">Showing <?php echo count($bookings); ?> bookings</span>
            </div>
        </div>

        <section class="dc-grid-3" id="booking-list">
            <?php foreach ($bookings as $booking): ?>
                <?php $displayStatus = bookingDisplayStatus((string) $booking['booking_status'], $booking['payment_status'] ?? null, (float) $booking['total_late_fee']); ?>
                <?php $paymentBreakdown = buildPaymentBreakdown((float) $booking['total_amount'], (float) $booking['total_late_fee']); ?>
                <article class="dc-card padded booking-list-card"
                    data-id="<?php echo h($booking['id']); ?>"
                    data-car="<?php echo h(strtolower($booking['brand'] . ' ' . $booking['model'])); ?>"
                    data-status="<?php echo h(strtolower($displayStatus['label'])); ?>"
                    data-date="<?php echo strtotime($booking['pickup_date'] ?? 'now'); ?>"
                >
                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                        <div>
                            <span class="dc-mono-subtitle small">Booking #<?php echo h($booking['id']); ?></span>
                            <h2 class="dc-h2" style="font-size:18px; margin-top:4px;"><?php echo h($booking['brand'] . ' ' . $booking['model']); ?></h2>
                            <p class="dc-p" style="font-size:13px; margin-top:4px;"><?php echo h(formatBookingDate($booking['pickup_date']) . ' to ' . formatBookingDate($booking['return_date'])); ?></p>
                        </div>
                        <span class="dc-status <?php echo h($displayStatus['class']); ?>"><?php echo h($displayStatus['label']); ?></span>
                    </div>

                    <div style="margin-top:16px; padding-top:16px; border-top:1px solid var(--border-color); display:flex; justify-content:space-between; font-size:13px;">
                        <div>
                            <div style="color:#9097a8; margin-bottom:4px;">Plate</div>
                            <div style="font-weight:600;"><?php echo h($booking['plate_number']); ?></div>
                        </div>
                        <div style="text-align:right;">
                            <div style="color:#9097a8; margin-bottom:4px;">Total</div>
                            <div style="font-weight:600;">RM <?php echo h(number_format($paymentBreakdown['payable_total'], 2)); ?></div>
                        </div>
                    </div>

                    <a class="dc-btn-primary" href="booking.php?id=<?php echo h($booking['id']); ?>" style="display:block; text-align:center; margin-top:16px; padding:10px;">View Details</a>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>
<script src="../js/booking-filter.js"></script>
<?php include '../includes/layout_bottom.php'; ?>
