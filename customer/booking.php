<?php
require_once __DIR__ . '/includes/auth.php';
require_once '../db_connect.php';
require_once __DIR__ . '/../util/booking.php';
require_once __DIR__ . '/../util/car_display.php';
require_once __DIR__ . '/../util/payment.php';
require_once __DIR__ . '/../util/addon.php';

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

$carId = filter_input(INPUT_POST, 'car_id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_GET, 'car_id', FILTER_VALIDATE_INT);

// The standalone checkout page moved to car_detail.php (inline booking panel) so
// the customer never has to leave the car they're looking at. Old links/bookmarks
// to booking.php?car_id=X still work — they land back on the car page with the
// panel auto-opened.
if ($carId && !$bookingId) {
    header('Location: car_detail.php?id=' . $carId . '&book=1');
    exit;
}

$booking = null;
$payment = null;
$lateFeeSummary = ['total_late_days' => 0, 'total_late_fee' => 0.0, 'fee_count' => 0];
$bookingAddons = [];
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

                // The UI hides "Pay Now" once a booking is paid, but that's client-only —
                // without this, a replayed/duplicated POST (double-click, back+resubmit)
                // would charge an already-paid booking again.
                $existingPayment = loadPaymentByBookingId($conn, $bookingId);
                if (($existingPayment['payment_status'] ?? null) === PAYMENT_STATUS_PAID) {
                    throw new InvalidArgumentException('This booking has already been paid.');
                }

                // markBookingPaymentPaid()/markBookingPaymentUnpaid() always write
                // payments.amount as exactly what's currently outstanding (e.g. only the
                // late fee after a late return, not the rental total again), so charge
                // that stored amount rather than recomputing a fresh rental+fee total —
                // recomputing would re-bill the rental portion that was already paid.
                $amountToCharge = isset($existingPayment['amount'])
                    ? (float) $existingPayment['amount']
                    : (float) $booking['total_amount'];

                markBookingPaymentPaid($conn, $bookingId, $amountToCharge, $paymentMethod);
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
        $bookingAddons = loadBookingAddons($conn, $bookingId);

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
<?php
$pageTitle = 'Booking Details | CarGo';
include '../includes/layout_top.php';
include 'header.php';
?>
<main class="dc-main">
    <div style="margin-bottom: 24px;">
        <a href="browse_cars.php" style="color:var(--accent); font-weight:600; text-decoration:none; font-size:14px; display:inline-flex; align-items:center; gap:6px;">
            <svg width="14" height="14" viewBox="0 0 16 16" aria-hidden="true"><path d="M10.5 13.5 L4.5 8 L10.5 2.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"></path></svg>
            Back to available cars
        </a>
    </div>

    <?php if ($error !== '' && !$booking): ?>
        <p class="message error" style="color: #c23a52; background: #fbeaed; padding: 12px; border-radius: 8px; font-weight: 600;"><?php echo h($error); ?></p>
    <?php elseif ($booking): ?>
        <?php $bookingStatus = (string) $booking['booking_status']; ?>
        <?php $paymentStatus = $payment['payment_status'] ?? null; ?>
        <?php $displayStatus = bookingDisplayStatus($bookingStatus, $paymentStatus, $lateFeeSummary['total_late_fee']); ?>
        <?php $isPaid = $paymentStatus === PAYMENT_STATUS_PAID; ?>
        <?php $canPay = canPayBooking($bookingStatus); ?>
        <?php $paymentBreakdown = buildPaymentBreakdown((float) $booking['total_amount'], $lateFeeSummary['total_late_fee']); ?>
        <?php $amountDue = calculatePaymentAmountDue($paymentStatus, isset($payment['amount']) ? (float) $payment['amount'] : null); ?>
        <?php $amountDue = $canPay ? $amountDue : 0.0; ?>

        <?php if ($error !== ''): ?>
            <p class="message error" style="color: #c23a52; background: #fbeaed; padding: 12px; border-radius: 8px; font-weight: 600; margin-bottom:24px;"><?php echo h($error); ?></p>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <p class="message success" style="color: #0b7a5a; background: #e6f6f1; padding: 12px; border-radius: 8px; font-weight: 600; margin-bottom:24px;"><?php echo h($success); ?></p>
        <?php endif; ?>

        <section class="booking-detail-grid">
            <div class="booking-detail-main">
                <!-- Car Header -->
                <div class="dc-card booking-summary-card">
                    <div class="booking-summary-body">
                        <div class="dc-mono-subtitle small" style="margin-bottom:8px">Booking #<?php echo h($booking['id']); ?></div>
                        <h1 class="dc-h1" style="font-size:28px; margin-bottom:8px;"><?php echo h($booking['brand'] . ' ' . $booking['model']); ?></h1>
                        <p class="dc-p" style="margin-bottom:24px;"><?php echo h(formatBookingDate($booking['pickup_date']) . ' to ' . formatBookingDate($booking['return_date'])); ?></p>
                        
                        <div style="display:flex; gap:24px;">
                            <div>
                                <span style="display:block; font-size:20px; font-weight:800; color:#131722;"><?php echo h($booking['total_days']); ?></span>
                                <span style="font-size:12px; color:#5b6273;">Rental days</span>
                            </div>
                            <div>
                                <span style="display:block; font-size:20px; font-weight:800; color:#131722;">RM <?php echo h(number_format($paymentBreakdown['payable_total'], 2)); ?></span>
                                <span style="font-size:12px; color:#5b6273;">Estimated total</span>
                            </div>
                        </div>
                    </div>
                    <div class="booking-summary-media">
                        <img src="<?php echo h(carImageUrl($booking['image'], 'https://images.unsplash.com/photo-1549317661-bd32c8ce0db2?auto=format&fit=crop&w=700&q=80')); ?>" alt="Car image">
                        <div style="position:absolute; top:16px; right:16px;">
                            <span class="dc-status <?php echo h($displayStatus['class']); ?>"><?php echo h($displayStatus['label']); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Info Grid -->
                <div class="booking-info-grid">
                    <div class="dc-card padded">
                        <div class="dc-h2-title">
                            <div>
                                <div class="dc-mono-subtitle small" style="margin-bottom:8px">Trip</div>
                                <h2 class="dc-h2">Rental timeline</h2>
                            </div>
                        </div>
                        <div style="display:flex; flex-direction:column; gap:20px; position:relative; padding-left:16px; border-left:2px solid #e4e8f1; margin-top:16px; margin-left:8px;">
                            <div style="position:relative;">
                                <div style="position:absolute; left:-23px; top:4px; width:12px; height:12px; border-radius:50%; background:var(--accent); border:2px solid #fff;"></div>
                                <div style="font-size:13px; color:#5b6273; margin-bottom:4px;">Pickup</div>
                                <div style="font-weight:600; font-size:15px;"><?php echo h(formatBookingDate($booking['pickup_date'])); ?></div>
                                <div style="font-size:13px; color:#9097a8; margin-top:2px;"><?php echo h($booking['pickup_location']); ?></div>
                            </div>
                            <div style="position:relative;">
                                <div style="position:absolute; left:-23px; top:4px; width:12px; height:12px; border-radius:50%; background:#131722; border:2px solid #fff;"></div>
                                <div style="font-size:13px; color:#5b6273; margin-bottom:4px;">Return</div>
                                <div style="font-weight:600; font-size:15px;"><?php echo h(formatBookingDate($booking['return_date'])); ?></div>
                                <div style="font-size:13px; color:#9097a8; margin-top:2px;"><?php echo h($booking['pickup_location']); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="dc-card padded">
                        <div class="dc-h2-title">
                            <div>
                                <div class="dc-mono-subtitle small" style="margin-bottom:8px">Customer</div>
                                <h2 class="dc-h2">Information</h2>
                            </div>
                        </div>
                        <div style="display:flex; flex-direction:column; gap:16px; margin-top:16px;">
                            <div>
                                <div style="color:#9097a8; font-size:13px; margin-bottom:4px;">Name</div>
                                <div style="font-weight:600;"><?php echo h($booking['customer_name']); ?></div>
                            </div>
                            <div>
                                <div style="color:#9097a8; font-size:13px; margin-bottom:4px;">Email</div>
                                <div style="font-weight:600;"><?php echo h($booking['customer_email']); ?></div>
                            </div>
                            <div>
                                <div style="color:#9097a8; font-size:13px; margin-bottom:4px;">Phone</div>
                                <div style="font-weight:600;"><?php echo h($booking['customer_phone'] ?: 'Not provided'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Sidebar -->
            <div class="booking-detail-aside">
                <div class="dc-card padded">
                    <div class="dc-h2-title">
                        <div>
                            <div class="dc-mono-subtitle small" style="margin-bottom:8px">Payment</div>
                            <h2 class="dc-h2">Details</h2>
                        </div>
                    </div>
                    
                    <div style="display:flex; flex-direction:column; gap:12px; margin-top:16px; margin-bottom:24px;">
                        <div style="display:flex; justify-content:space-between;">
                            <span style="color:#5b6273;">Daily Rate</span>
                            <span style="font-weight:600;">RM <?php echo h(number_format((float) $booking['daily_rate'], 2)); ?></span>
                        </div>
                        <div style="display:flex; justify-content:space-between;">
                            <span style="color:#5b6273;">Total Days</span>
                            <span style="font-weight:600;"><?php echo h($booking['total_days']); ?></span>
                        </div>
                        <div style="display:flex; justify-content:space-between;">
                            <span style="color:#5b6273;">Rental Subtotal</span>
                            <span style="font-weight:600;">RM <?php echo h(number_format((float) $booking['daily_rate'] * (int) $booking['total_days'], 2)); ?></span>
                        </div>
                        <?php foreach ($bookingAddons as $bookingAddon): ?>
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px;">
                                <span style="color:#5b6273; min-width:0;">
                                    <?php echo h($bookingAddon['name']); ?>
                                    <span style="display:block; color:#9097a8; font-size:12px; margin-top:2px;"><?php echo h($bookingAddon['days']); ?> × RM <?php echo h(number_format((float) $bookingAddon['unit_price'], 2)); ?></span>
                                </span>
                                <span style="font-weight:600; white-space:nowrap;">RM <?php echo h(number_format((float) $bookingAddon['unit_price'] * (int) $bookingAddon['days'], 2)); ?></span>
                            </div>
                        <?php endforeach; ?>
                        <div style="display:flex; justify-content:space-between; gap:16px;">
                            <span style="color:#5b6273;">Payment Status</span>
                            <span style="font-weight:600;"><?php echo h($isPaid ? 'Paid' : 'Unpaid'); ?></span>
                        </div>
                        <?php if ($isPaid): ?>
                            <div style="display:flex; justify-content:space-between;">
                                <span style="color:#5b6273;">Method</span>
                                <span style="font-weight:600;"><?php echo h($payment['payment_method']); ?></span>
                            </div>
                            <div style="display:flex; justify-content:space-between;">
                                <span style="color:#5b6273;">Payment Date</span>
                                <span style="font-weight:600;"><?php echo h(formatBookingDate($payment['payment_date'])); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($lateFeeSummary['total_late_fee'] > 0): ?>
                            <div style="display:flex; justify-content:space-between;">
                                <span style="color:#5b6273;">Late Days</span>
                                <span style="font-weight:600;"><?php echo h($lateFeeSummary['total_late_days']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div style="padding:16px; background:#f9fafc; border-radius:12px; margin-bottom:24px;">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span style="font-weight:600;">Total Amount</span>
                            <strong style="font-size:20px; color:#131722;">RM <?php echo h(number_format($paymentBreakdown['payable_total'], 2)); ?></strong>
                        </div>
                        <?php if ($amountDue > 0): ?>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px; padding-top:8px; border-top:1px solid #e4e8f1;">
                                <span style="font-weight:600; color:#c23a52;">Amount Due</span>
                                <strong style="font-size:18px; color:#c23a52;">RM <?php echo h(number_format($amountDue, 2)); ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div style="display:flex; flex-direction:column; gap:12px;">
                        <a href="booking.php?id=<?php echo h($booking['id']); ?>&payment=1" class="dc-btn-primary" style="justify-content:center; padding:12px;">
                            <?php echo $isPaid || !$canPay ? 'View Payment' : 'Pay Now'; ?>
                        </a>

                        <?php if (canCancelBooking($bookingStatus) && !$isPaid): ?>
                            <form method="post" action="booking.php?id=<?php echo h($booking['id']); ?>" onsubmit="return confirm('Are you sure you want to cancel this booking? This action cannot be undone.');">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="booking_id" value="<?php echo h($booking['id']); ?>">
                                <button type="submit" class="dc-btn-secondary" style="width:100%; justify-content:center; color:#c23a52; border-color:#fbeaed; background:#fff;">Cancel Booking</button>
                            </form>
                        <?php else: ?>
                            <button type="button" class="dc-btn-secondary" style="width:100%; justify-content:center; opacity:0.5; cursor:not-allowed;" disabled>Cancel Booking</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>


        <?php if ($showPaymentModal): ?>
            <div style="position:fixed; inset:0; background:rgba(10,13,20,0.6); backdrop-filter:blur(4px); z-index:100; display:flex; align-items:center; justify-content:center; padding:20px;">
                <div class="dc-card padded" style="width:100%; max-width:480px; position:relative; box-shadow:0 24px 48px rgba(0,0,0,0.2);">
                    <a href="booking.php?id=<?php echo h($booking['id']); ?>" style="position:absolute; top:20px; right:20px; color:#9097a8; text-decoration:none;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </a>
                    
                    <div class="dc-h2-title" style="margin-bottom:24px;">
                        <div>
                            <div class="dc-mono-subtitle small" style="margin-bottom:8px">Payment</div>
                            <h2 class="dc-h2">Booking payment details</h2>
                        </div>
                    </div>
                    
                    <div style="display:flex; flex-direction:column; gap:12px; margin-bottom:24px;">
                        <?php
                        // rental_subtotal covers car + add-ons, so split the car portion out
                        // to keep the line items adding up to the total below.
                        $addonsSubtotal = 0.0;
                        foreach ($bookingAddons as $bookingAddon) {
                            $addonsSubtotal += (float) $bookingAddon['unit_price'] * (int) $bookingAddon['days'];
                        }
                        $carSubtotal = $paymentBreakdown['rental_subtotal'] - $addonsSubtotal;
                        ?>
                        <div style="display:flex; justify-content:space-between;">
                            <span style="color:#5b6273;">Rental subtotal</span>
                            <span style="font-weight:600;">RM <?php echo h(number_format($carSubtotal, 2)); ?></span>
                        </div>
                        <?php foreach ($bookingAddons as $bookingAddon): ?>
                            <div style="display:flex; justify-content:space-between;">
                                <span style="color:#5b6273;"><?php echo h($bookingAddon['name']); ?></span>
                                <span style="font-weight:600;">RM <?php echo h(number_format((float) $bookingAddon['unit_price'] * (int) $bookingAddon['days'], 2)); ?></span>
                            </div>
                        <?php endforeach; ?>
                        <div style="display:flex; justify-content:space-between;">
                            <span style="color:#5b6273;">Late fees</span>
                            <span style="font-weight:600;">RM <?php echo h(number_format($paymentBreakdown['late_fee_total'], 2)); ?></span>
                        </div>
                        <div style="display:flex; justify-content:space-between; padding-top:12px; border-top:1px solid #e4e8f1; margin-top:4px;">
                            <span style="font-weight:700;">Total amount</span>
                            <strong style="font-size:18px;">RM <?php echo h(number_format($paymentBreakdown['payable_total'], 2)); ?></strong>
                        </div>
                    </div>
                    
                    <?php if (!$isPaid && $canPay): ?>
                        <form method="post" action="booking.php?id=<?php echo h($booking['id']); ?>&payment=1" style="background:#f9fafc; padding:20px; border-radius:12px; border:1px solid #e4e8f1;">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="action" value="pay">
                            <input type="hidden" name="booking_id" value="<?php echo h($booking['id']); ?>">

                            <label style="display:block; margin-bottom:16px;">
                                <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Payment Method</span>
                                <select name="payment_method" class="dc-select" required style="width:100%; background:#fff;">
                                    <?php foreach (PAYMENT_METHODS as $method): ?>
                                        <option value="<?php echo h($method); ?>"><?php echo h($method); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <button type="submit" class="dc-btn-primary" style="width:100%; justify-content:center; padding:12px;">Confirm Payment</button>
                        </form>
                    <?php elseif (!$canPay): ?>
                        <div style="padding:16px; background:#f9fafc; border-radius:8px; text-align:center; color:#5b6273; font-size:14px;">
                            Payment is not available for this booking.
                        </div>
                    <?php else: ?>
                        <div style="padding:16px; background:#e6f6f1; border-radius:8px; text-align:center; color:#0b7a5a; font-size:14px; font-weight:600;">
                            This booking has already been paid.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</main>
<?php include '../includes/layout_bottom.php'; ?>
