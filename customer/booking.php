<?php
require_once __DIR__ . '/includes/auth.php';
require_once '../db_connect.php';
require_once __DIR__ . '/../util/booking.php';
require_once __DIR__ . '/../util/car_display.php';
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

function loadCustomerBooking(mysqli $conn, int $bookingId, int $customerId): ?array
{
    $stmt = $conn->prepare(
        'SELECT
            b.id,
            b.pickup_date,
            b.return_date,
            b.actual_return_date,
            b.pickup_location,
            b.total_days,
            b.total_amount,
            b.booking_status,
            b.created_at,
            c.name AS customer_name,
            c.email AS customer_email,
            c.phone AS customer_phone,
            c.address AS customer_address,
            car.brand,
            car.model,
            car.plate_number,
            car.car_type,
            car.transmission,
            car.fuel_type,
            car.seats,
            car.daily_rate,
            car.image
         FROM bookings b
         INNER JOIN customers c ON c.id = b.customer_id
         INNER JOIN cars car ON car.id = b.car_id
         WHERE b.id = ? AND b.customer_id = ?
         LIMIT 1'
    );
    $stmt->bind_param('ii', $bookingId, $customerId);
    $stmt->execute();

    $booking = $stmt->get_result()->fetch_assoc();

    return $booking ?: null;
}

startSecureSession();
requireCustomerLogin();

$bookingId = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$customerId = (int) ($_SESSION['customer_id'] ?? 0);
$booking = null;
$payment = null;
$lateFeeSummary = ['total_late_days' => 0, 'total_late_fee' => 0.0, 'fee_count' => 0];
$error = '';
$success = '';
$showPaymentModal = isset($_GET['payment']);

if (!$bookingId || $customerId <= 0) {
    $error = 'Please choose a valid booking.';
} else {
    try {
        $conn = getDbConnection();
        $booking = loadCustomerBooking($conn, $bookingId, $customerId);

        if (!$booking) {
            $error = 'Booking not found.';
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            requireValidCsrfToken();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay' && $booking) {
            $paymentMethod = trim($_POST['payment_method'] ?? '');

            try {
                if (!canPayBooking((string) $booking['booking_status'])) {
                    throw new InvalidArgumentException('This booking cannot be paid.');
                }

                $lateFeeSummary = loadLateFeeSummary($conn, $bookingId);
                $paymentBreakdown = buildPaymentBreakdown((float) $booking['total_amount'], $lateFeeSummary['total_late_fee']);
                markBookingPaymentPaid($conn, $bookingId, $paymentBreakdown['payable_total'], $paymentMethod);
                header('Location: booking.php?id=' . $bookingId . '&payment=1');
                exit;
            } catch (InvalidArgumentException $e) {
                $showPaymentModal = true;
                $error = $e->getMessage();
            }
        }

        $payment = loadPaymentByBookingId($conn, $bookingId);

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel' && $booking) {
            $cancelledStatus = 'cancelled';
            $pendingStatus = BOOKING_CANCELLABLE_STATUSES[0];
            $approvedStatus = BOOKING_CANCELLABLE_STATUSES[1];

            if (($payment['payment_status'] ?? '') === PAYMENT_STATUS_PAID) {
                $error = 'Paid bookings cannot be cancelled from the customer page.';
            } else {
                $stmt = $conn->prepare(
                    'UPDATE bookings
                     SET booking_status = ?
                     WHERE id = ?
                       AND customer_id = ?
                       AND booking_status IN (?, ?)'
                );
                $stmt->bind_param('siiss', $cancelledStatus, $bookingId, $customerId, $pendingStatus, $approvedStatus);
                $stmt->execute();

                if ($stmt->affected_rows > 0) {
                    $success = 'Booking cancelled successfully.';
                } else {
                    $error = 'This booking cannot be cancelled.';
                }
            }
        }

        $booking = loadCustomerBooking($conn, $bookingId, $customerId);
        $payment = loadPaymentByBookingId($conn, $bookingId);
        $lateFeeSummary = loadLateFeeSummary($conn, $bookingId);

        if (!$booking) {
            $error = 'Booking not found.';
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (mysqli_sql_exception $e) {
        $error = 'Could not load or update this booking. Please confirm the database tables match the expected structure.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Details | CarGo</title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../css/customer.css">
</head>
<body>
    <main class="dashboard-page">
        <header class="dashboard-header">
            <?php include 'header.php'; ?>
        </header>

        <section class="dashboard-content">
            <div class="dashboard-shell">
                <a class="back-link" href="browse_cars.php">Back to available cars</a>

                <?php if ($error !== '' && !$booking): ?>
                    <p class="message error"><?php echo h($error); ?></p>
                <?php elseif ($booking): ?>
                    <?php $bookingStatus = (string) $booking['booking_status']; ?>
                    <?php $paymentStatus = $payment['payment_status'] ?? null; ?>
                    <?php $displayStatus = bookingDisplayStatus($bookingStatus, $paymentStatus, $lateFeeSummary['total_late_fee']); ?>
                    <?php $isPaid = $paymentStatus === PAYMENT_STATUS_PAID; ?>
                    <?php $canPay = canPayBooking($bookingStatus); ?>
                    <?php $paymentBreakdown = buildPaymentBreakdown((float) $booking['total_amount'], $lateFeeSummary['total_late_fee']); ?>
                    <?php $amountDue = calculatePaymentAmountDue($paymentBreakdown['payable_total'], $paymentStatus, isset($payment['amount']) ? (float) $payment['amount'] : null); ?>
                    <?php $amountDue = $canPay ? $amountDue : 0.0; ?>

                    <section class="booking-detail-layout">
                        <header class="booking-summary-panel">
                            <div class="booking-summary-copy">
                                <p class="eyebrow">Booking #<?php echo h($booking['id']); ?></p>
                                <h1><?php echo h($booking['brand'] . ' ' . $booking['model']); ?></h1>
                                <p><?php echo h(formatBookingDate($booking['pickup_date']) . ' to ' . formatBookingDate($booking['return_date'])); ?></p>

                                <div class="booking-summary-meta" aria-label="Booking summary">
                                    <span>
                                        <strong><?php echo h($booking['total_days']); ?></strong>
                                        Rental days
                                    </span>
                                    <span>
                                        <strong>RM <?php echo h(number_format($paymentBreakdown['payable_total'], 2)); ?></strong>
                                        Estimated total
                                    </span>
                                    <span>
                                        <strong><?php echo h($booking['pickup_location']); ?></strong>
                                        Pickup location
                                    </span>
                                </div>
                            </div>

                            <div class="booking-summary-side">
                                <span class="booking-status-pill <?php echo h($displayStatus['class']); ?>"><?php echo h($displayStatus['label']); ?></span>
                                <img
                                    src="<?php echo h(carImageUrl($booking['image'], 'https://images.unsplash.com/photo-1549317661-bd32c8ce0db2?auto=format&fit=crop&w=700&q=80')); ?>"
                                    alt="<?php echo h($booking['brand'] . ' ' . $booking['model']); ?>"
                                >
                            </div>
                        </header>

                        <?php if ($error !== ''): ?>
                            <p class="message error"><?php echo h($error); ?></p>
                        <?php endif; ?>

                        <?php if ($success !== ''): ?>
                            <p class="message success"><?php echo h($success); ?></p>
                        <?php endif; ?>

                        <div class="booking-main-flow">
                            <div class="booking-flow-column">
                                <section class="booking-info-panel trip-panel">
                                    <div class="section-heading">
                                        <p class="eyebrow">Trip</p>
                                        <h2>Rental timeline</h2>
                                    </div>

                                    <div class="timeline-list">
                                        <div class="timeline-item">
                                            <span class="timeline-dot"></span>
                                            <div>
                                                <p>Pickup</p>
                                                <strong><?php echo h(formatBookingDate($booking['pickup_date'])); ?></strong>
                                                <small><?php echo h($booking['pickup_location']); ?></small>
                                            </div>
                                        </div>

                                        <div class="timeline-item">
                                            <span class="timeline-dot"></span>
                                            <div>
                                                <p>Return</p>
                                                <strong><?php echo h(formatBookingDate($booking['return_date'])); ?></strong>
                                                <small><?php echo h($booking['pickup_location']); ?></small>
                                            </div>
                                        </div>
                                    </div>
                                </section>

                                <div class="paired-info-grid">
                                    <section class="booking-info-panel">
                                        <div class="section-heading">
                                            <p class="eyebrow">Customer</p>
                                            <h2>Customer information</h2>
                                        </div>

                                        <dl class="detail-list">
                                            <div>
                                                <dt>Name</dt>
                                                <dd><?php echo h($booking['customer_name']); ?></dd>
                                            </div>
                                            <div>
                                                <dt>Email</dt>
                                                <dd><?php echo h($booking['customer_email']); ?></dd>
                                            </div>
                                            <div>
                                                <dt>Phone</dt>
                                                <dd><?php echo h($booking['customer_phone'] ?: 'Not provided'); ?></dd>
                                            </div>
                                            <div>
                                                <dt>Address</dt>
                                                <dd><?php echo h($booking['customer_address'] ?: 'Not provided'); ?></dd>
                                            </div>
                                        </dl>
                                    </section>

                                    <section class="booking-info-panel">
                                        <div class="section-heading">
                                            <p class="eyebrow">Vehicle</p>
                                            <h2>Car information</h2>
                                        </div>

                                        <dl class="detail-list">
                                            <div>
                                                <dt>Vehicle</dt>
                                                <dd><?php echo h($booking['brand'] . ' ' . $booking['model']); ?></dd>
                                            </div>
                                            <div>
                                                <dt>Plate</dt>
                                                <dd><?php echo h($booking['plate_number']); ?></dd>
                                            </div>
                                            <div>
                                                <dt>Type</dt>
                                                <dd><?php echo h($booking['car_type']); ?></dd>
                                            </div>
                                            <div>
                                                <dt>Specs</dt>
                                                <dd><?php echo h($booking['transmission'] . ' / ' . $booking['fuel_type'] . ' / ' . $booking['seats'] . ' seats'); ?></dd>
                                            </div>
                                        </dl>
                                    </section>
                                </div>
                            </div>

                            <aside class="booking-info-panel payment-panel payment-sidebar">
                                <div class="section-heading">
                                    <p class="eyebrow">Payment</p>
                                    <h2>Payment details</h2>
                                </div>

                                <dl class="payment-summary">
                                    <div>
                                        <dt>Daily Rate</dt>
                                        <dd>RM <?php echo h(number_format((float) $booking['daily_rate'], 2)); ?></dd>
                                    </div>
                                    <div>
                                        <dt>Total Days</dt>
                                        <dd><?php echo h($booking['total_days']); ?></dd>
                                    </div>
                                    <div>
                                        <dt>Payment Status</dt>
                                        <dd><?php echo h($isPaid ? 'Paid' : 'Unpaid'); ?></dd>
                                    </div>
                                    <?php if ($isPaid): ?>
                                        <div>
                                            <dt>Method</dt>
                                            <dd><?php echo h($payment['payment_method']); ?></dd>
                                        </div>
                                        <div>
                                            <dt>Payment Date</dt>
                                            <dd><?php echo h(formatBookingDate($payment['payment_date'])); ?></dd>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($lateFeeSummary['total_late_fee'] > 0): ?>
                                        <div>
                                            <dt>Late Days</dt>
                                            <dd><?php echo h($lateFeeSummary['total_late_days']); ?></dd>
                                        </div>
                                    <?php endif; ?>
                                    <div class="total-amount-row payment-total-card">
                                        <dt>Total Amount</dt>
                                        <dd>RM <?php echo h(number_format($paymentBreakdown['payable_total'], 2)); ?></dd>
                                        <?php if ($amountDue > 0): ?>
                                            <p>
                                                <span>Amount due</span>
                                                <strong>RM <?php echo h(number_format($amountDue, 2)); ?></strong>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </dl>

                                <div class="booking-page-actions">
                                    <a class="pay-placeholder-button" href="booking.php?id=<?php echo h($booking['id']); ?>&payment=1">
                                        <?php echo $isPaid || !$canPay ? 'View Payment' : 'Pay Now'; ?>
                                    </a>

                                    <?php if (canCancelBooking($bookingStatus) && !$isPaid): ?>
                                        <form method="post" action="booking.php?id=<?php echo h($booking['id']); ?>">
                                            <?php echo csrfInput(); ?>
                                            <input type="hidden" name="action" value="cancel">
                                            <input type="hidden" name="booking_id" value="<?php echo h($booking['id']); ?>">
                                            <button type="submit" class="danger-button">Cancel Booking</button>
                                        </form>
                                    <?php else: ?>
                                        <button type="button" class="secondary-button" disabled>Cancel Booking</button>
                                    <?php endif; ?>
                                </div>
                            </aside>
                        </div>
                    </section>

                    <?php if ($showPaymentModal): ?>
                        <div class="modal-backdrop" role="presentation">
                            <section class="payment-modal" role="dialog" aria-modal="true" aria-labelledby="payment-modal-title">
                                <header class="payment-modal-header">
                                    <div>
                                        <p class="eyebrow">Payment</p>
                                        <h2 id="payment-modal-title">Booking payment details</h2>
                                    </div>
                                    <a href="booking.php?id=<?php echo h($booking['id']); ?>" aria-label="Close payment details">Close</a>
                                </header>

                                <dl class="payment-itinerary">
                                    <div>
                                        <dt>Rental subtotal</dt>
                                        <dd>RM <?php echo h(number_format($paymentBreakdown['rental_subtotal'], 2)); ?></dd>
                                    </div>
                                    <div>
                                        <dt>Tax <?php echo h((int) round($paymentBreakdown['display_tax_rate'] * 100)); ?>% <span>display only</span></dt>
                                        <dd>RM <?php echo h(number_format($paymentBreakdown['display_tax_amount'], 2)); ?></dd>
                                    </div>
                                    <div>
                                        <dt>Late fees</dt>
                                        <dd>RM <?php echo h(number_format($paymentBreakdown['late_fee_total'], 2)); ?></dd>
                                    </div>
                                    <div class="total-amount-row">
                                        <dt>Total amount</dt>
                                        <dd>RM <?php echo h(number_format($paymentBreakdown['payable_total'], 2)); ?></dd>
                                    </div>
                                </dl>

                                <dl class="payment-modal-summary">
                                    <div>
                                        <dt>Payment Status</dt>
                                        <dd><?php echo h($isPaid ? 'Paid' : 'Unpaid'); ?></dd>
                                    </div>
                                    <div>
                                        <dt>Tax Note</dt>
                                        <dd>Tax is shown for reference and is not charged in this version.</dd>
                                    </div>
                                    <?php if ($isPaid): ?>
                                        <div>
                                            <dt>Method</dt>
                                            <dd><?php echo h($payment['payment_method']); ?></dd>
                                        </div>
                                        <div>
                                            <dt>Payment Date</dt>
                                            <dd><?php echo h(formatBookingDate($payment['payment_date'])); ?></dd>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($lateFeeSummary['total_late_fee'] > 0): ?>
                                        <div>
                                            <dt>Late Days</dt>
                                            <dd><?php echo h($lateFeeSummary['total_late_days']); ?></dd>
                                        </div>
                                    <?php endif; ?>
                                </dl>

                                <?php if (!$isPaid && $canPay): ?>
                                    <form method="post" action="booking.php?id=<?php echo h($booking['id']); ?>&payment=1" class="payment-method-form">
                                        <?php echo csrfInput(); ?>
                                        <input type="hidden" name="action" value="pay">
                                        <input type="hidden" name="booking_id" value="<?php echo h($booking['id']); ?>">

                                        <label for="payment_method">
                                            Payment Method
                                            <select id="payment_method" name="payment_method" required>
                                                <?php foreach (PAYMENT_METHODS as $method): ?>
                                                    <option value="<?php echo h($method); ?>"><?php echo h($method); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>

                                        <button type="submit">Confirm Payment</button>
                                    </form>
                                <?php elseif (!$canPay): ?>
                                    <p class="payment-unavailable-note">Payment is not available for this booking.</p>
                                <?php else: ?>
                                    <p class="payment-complete-note">This booking has already been paid.</p>
                                <?php endif; ?>
                            </section>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>
    </main>
</body>
</html>
