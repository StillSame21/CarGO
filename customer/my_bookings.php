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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings | CarGo</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/customer.css">
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
                        <p class="eyebrow">Bookings</p>
                        <h1>My bookings</h1>
                    </div>
                </header>

                <?php if ($error !== ''): ?>
                    <p class="message error"><?php echo h($error); ?></p>
                <?php elseif (count($bookings) === 0): ?>
                    <section class="empty-state-panel">
                        <h2>No bookings yet.</h2>
                        <p>Check availability on a car to create your first booking.</p>
                    </section>
                <?php else: ?>
                    <section class="booking-toolbar browse-toolbar">
                        <div class="search-box">
                            <input type="text" id="booking-search" class="search-input" placeholder="Search car or booking ID...">
                        </div>
                        <div class="filter-controls">
                            <select id="filter-status" class="filter-select">
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
                            <select id="sort-by" class="sort-select">
                                <option value="newest">Newest First</option>
                                <option value="oldest">Oldest First</option>
                            </select>
                        </div>
                        <div class="toolbar-footer">
                            <span id="results-count" class="results-count">Showing <?php echo count($bookings); ?> bookings</span>
                        </div>
                    </section>

                    <section class="booking-list" id="booking-list" aria-label="My bookings">
                        <?php foreach ($bookings as $booking): ?>
                            <?php $displayStatus = bookingDisplayStatus((string) $booking['booking_status'], $booking['payment_status'] ?? null, (float) $booking['total_late_fee']); ?>
                            <?php $paymentBreakdown = buildPaymentBreakdown((float) $booking['total_amount'], (float) $booking['total_late_fee']); ?>
                            <article class="booking-list-card"
                                data-id="<?php echo h($booking['id']); ?>"
                                data-car="<?php echo h(strtolower($booking['brand'] . ' ' . $booking['model'])); ?>"
                                data-status="<?php echo h(strtolower($displayStatus['label'])); ?>"
                                data-date="<?php echo strtotime($booking['pickup_date'] ?? 'now'); ?>"
                            >
                                <div>
                                    <p class="car-type">Booking #<?php echo h($booking['id']); ?></p>
                                    <h2><?php echo h($booking['brand'] . ' ' . $booking['model']); ?></h2>
                                    <p><?php echo h(formatBookingDate($booking['pickup_date']) . ' to ' . formatBookingDate($booking['return_date'])); ?></p>
                                </div>

                                <dl>
                                    <div>
                                        <dt>Plate</dt>
                                        <dd><?php echo h($booking['plate_number']); ?></dd>
                                    </div>
                                    <div>
                                        <dt>Total</dt>
                                        <dd>RM <?php echo h(number_format($paymentBreakdown['payable_total'], 2)); ?></dd>
                                    </div>
                                    <div>
                                        <dt>Status</dt>
                                        <dd><span class="booking-status-pill <?php echo h($displayStatus['class']); ?>"><?php echo h($displayStatus['label']); ?></span></dd>
                                    </div>
                                </dl>

                                <a class="primary-action" href="booking.php?id=<?php echo h($booking['id']); ?>">View Details</a>
                            </article>
                        <?php endforeach; ?>
                    </section>
                <?php endif; ?>
            </div>
        </section>
    </main>
    <script src="../js/booking-filter.js"></script>
</body>
</html>
