<?php
require_once __DIR__ . '/includes/auth.php';
require_once '../db_connect.php';
require_once __DIR__ . '/../util/payment.php';
require_once __DIR__ . '/../util/car_display.php';

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
            car.plate_number,
            car.image
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
    <header class="dc-h2-title" style="margin-bottom: 0; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 16px;">
        <div>
            <div class="dc-mono-subtitle small" style="margin-bottom:8px">Bookings</div>
            <h1 class="dc-h1" style="font-size:32px;">My bookings</h1>
        </div>
        <div>
            <a href="my_payments.php" class="dc-btn-secondary" style="padding: 10px 20px; display: inline-flex; align-items: center; gap: 8px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                Payment History
            </a>
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

        <section id="booking-list" style="display: flex; flex-direction: column; gap: 16px; margin-top: 24px;">
            <?php foreach ($bookings as $booking): ?>
                <?php $displayStatus = bookingDisplayStatus((string) $booking['booking_status'], $booking['payment_status'] ?? null, (float) $booking['total_late_fee']); ?>
                <?php $paymentBreakdown = buildPaymentBreakdown((float) $booking['total_amount'], (float) $booking['total_late_fee']); ?>
                <article class="dc-card booking-list-card" style="display: flex; flex-direction: row; align-items: center; justify-content: space-between; padding: 24px; border-radius: 8px; flex-wrap: wrap; gap: 24px;"
                    data-id="<?php echo h($booking['id']); ?>"
                    data-car="<?php echo h(strtolower($booking['brand'] . ' ' . $booking['model'])); ?>"
                    data-status="<?php echo h(strtolower($displayStatus['label'])); ?>"
                    data-date="<?php echo strtotime($booking['pickup_date'] ?? 'now'); ?>"
                >
                    <!-- Left: Date & Status -->
                    <div style="flex: 1; min-width: 200px; display: flex; flex-direction: column; gap: 12px; align-items: flex-start;">
                        <span class="booking-status-pill <?php echo h($displayStatus['class']); ?>"><?php echo h($displayStatus['label']); ?></span>
                        <div style="display: flex; align-items: center; gap: 16px;">
                            <div>
                                <div style="font-size: 12px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Start Date</div>
                                <div style="font-size: 15px; font-weight: 700; color: var(--text-color-strong);"><?php echo h(formatBookingDate($booking['pickup_date'])); ?></div>
                            </div>
                            <div style="color: var(--text-muted); font-size: 14px; font-weight: bold;">→</div>
                            <div>
                                <div style="font-size: 12px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">End Date</div>
                                <div style="font-size: 15px; font-weight: 700; color: var(--text-color-strong);"><?php echo h(formatBookingDate($booking['return_date'])); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Middle: Car Details -->
                    <div style="flex: 1; min-width: 200px;">
                        <div style="font-size: 12px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Booking #<?php echo h($booking['id']); ?></div>
                        <h2 style="font-size: 18px; font-weight: 700; color: var(--text-color-strong); margin: 0 0 4px 0;"><?php echo h($booking['brand'] . ' ' . $booking['model']); ?></h2>
                        <div style="font-size: 14px; color: var(--text-muted); font-weight: 500;">Plate: <?php echo h($booking['plate_number']); ?></div>
                    </div>

                    <!-- Right: Total & Action -->
                    <div style="flex: 1; min-width: 240px; display: flex; justify-content: space-between; align-items: center; padding-left: 24px; border-left: 1px solid var(--border-color);">
                        <div>
                            <div style="font-size: 12px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Total</div>
                            <div style="font-weight: 800; font-size: 18px; color: var(--text-color-strong);">RM <?php echo h(number_format($paymentBreakdown['payable_total'], 2)); ?></div>
                        </div>
                        <a class="dc-btn-primary" href="booking.php?id=<?php echo h($booking['id']); ?>" style="padding: 10px 20px; font-weight: 600;">View Details</a>
                    </div>
                    
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>
<script src="../js/booking-filter.js"></script>
<?php include '../includes/layout_bottom.php'; ?>
