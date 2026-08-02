<?php
require_once __DIR__ . '/includes/auth.php';
require_once '../db_connect.php';
require_once __DIR__ . '/../util/car_display.php';
require_once __DIR__ . '/../util/car_archive.php';
require_once __DIR__ . '/../util/payment.php';
require_once __DIR__ . '/../util/booking.php';
require_once __DIR__ . '/../util/addon.php';
require_once __DIR__ . '/../util/html.php';

startSecureSession();
requireCustomerLogin();

$car = null;
$error = '';
$carId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$hasUnpaidLateFees = false;
$checkoutAddons = [];
$checkoutError = '';
$checkoutPickupDate = '';
$checkoutReturnDate = '';
$checkoutSelectedAddonIds = [];
$openBookingPanel = isset($_GET['book']);
$customerId = (int) ($_SESSION['customer_id'] ?? 0);

if (!$carId) {
    $error = 'Please choose a valid car.';
} else {
    try {
        $conn = getDbConnection();

        $hasUnpaidLateFees = $customerId > 0 && customerHasUnpaidLateFees($conn, $customerId);

        $stmt = $conn->prepare(
            'SELECT id, brand, model, plate_number, car_type, transmission, fuel_type, seats, daily_rate, image, status
             FROM cars
             WHERE id = ? AND status = \'available\' AND archived_at IS NULL
             LIMIT 1'
        );
        $stmt->bind_param('i', $carId);
        $stmt->execute();
        $result = $stmt->get_result();
        $car = $result->fetch_assoc();

        if (!$car) {
            $error = 'This car is not available for booking.';
        } else {
            $checkoutAddons = loadAvailableAddons($conn);

            if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkout') {
                $openBookingPanel = true;

                try {
                    requireValidCsrfToken();
                    $checkoutPickupDate = trim($_POST['pickup_date'] ?? '');
                    $checkoutReturnDate = trim($_POST['return_date'] ?? '');
                    $paymentMethod = $_POST['payment_method'] ?? '';
                    $checkoutSelectedAddonIds = (array) ($_POST['addons'] ?? []);

                    // The date inputs only carry a client-side min attribute, so re-check everything here.
                    $dateError = validateBookingDates($checkoutPickupDate, $checkoutReturnDate);

                    if ($dateError !== null) {
                        throw new InvalidArgumentException($dateError);
                    }

                    if (customerHasUnpaidLateFees($conn, $customerId)) {
                        throw new InvalidArgumentException('Please settle your unpaid late fees in My Bookings before booking another car.');
                    }

                    if (carHasBookingConflict($conn, (int) $car['id'], $checkoutPickupDate, $checkoutReturnDate)) {
                        throw new InvalidArgumentException('This car is already booked for the selected dates.');
                    }

                    // Price add-ons from the database, never from the submitted form values.
                    $selectedAddons = filterSelectedAddons($checkoutSelectedAddonIds, $checkoutAddons);

                    $newBookingId = createBookingWithPayment(
                        $conn,
                        $customerId,
                        $car,
                        $checkoutPickupDate,
                        $checkoutReturnDate,
                        $selectedAddons,
                        $paymentMethod
                    );

                    header('Location: booking.php?id=' . $newBookingId);
                    exit;
                } catch (InvalidArgumentException $e) {
                    $checkoutError = $e->getMessage();
                } catch (mysqli_sql_exception $e) {
                    $checkoutError = 'Could not complete this booking. Please try again.';
                } catch (RuntimeException $e) {
                    $checkoutError = $e->getMessage();
                }
            }
        }
    } catch (mysqli_sql_exception $e) {
        $error = 'Could not load car details. Please check the database connection.';
    }
}
?>
<?php
$pageTitle = 'Car Details | CarGo';
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

    <?php if ($error !== '') : ?>
        <p class="message error" style="color: #c23a52; background: #fbeaed; padding: 12px; border-radius: 8px; font-weight: 600;"><?php echo h($error); ?></p>
    <?php else : ?>
        <section class="dc-grid-2-sidebar">
            <!-- Left Column: Details & Images -->
            <div style="display:flex; flex-direction:column; gap:24px;">
                <div class="dc-card" style="overflow:hidden;">
                    <?php echo carImageTag(
                        $car['image'],
                        $car['brand'] . ' ' . $car['model'],
                        '(max-width: 1000px) 100vw, 800px',
                        [
                            'loading' => 'eager',
                            'fetchpriority' => 'high',
                            'style' => 'width:100%; aspect-ratio:16/9; object-fit:cover; display:block;',
                        ]
                    ); ?>
                </div>
                
                <div class="dc-card padded">
                    <div class="dc-h2-title">
                        <div>
                            <div class="dc-mono-subtitle small" style="margin-bottom:8px">Vehicle Details</div>
                            <h2 class="dc-h2">Key specifications</h2>
                        </div>
                    </div>
                    
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap:20px; margin-top:20px;">
                        <div>
                            <div style="color:#9097a8; font-size:13px; margin-bottom:6px;">Plate Number</div>
                            <div style="font-weight:600; font-size:15px;"><?php echo h($car['plate_number']); ?></div>
                        </div>
                        <div>
                            <div style="color:#9097a8; font-size:13px; margin-bottom:6px;">Transmission</div>
                            <div style="font-weight:600; font-size:15px;"><?php echo h($car['transmission']); ?></div>
                        </div>
                        <div>
                            <div style="color:#9097a8; font-size:13px; margin-bottom:6px;">Fuel Type</div>
                            <div style="font-weight:600; font-size:15px;"><?php echo h($car['fuel_type']); ?></div>
                        </div>
                        <div>
                            <div style="color:#9097a8; font-size:13px; margin-bottom:6px;">Seats</div>
                            <div style="font-weight:600; font-size:15px;"><?php echo h($car['seats']); ?> seats</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Info & Booking -->
            <div style="display:flex; flex-direction:column; gap:24px;">
                <div class="dc-card padded">
                    <div style="display:flex; gap:8px; margin-bottom:16px;">
                        <span class="dc-car-tag" style="position:static;"><?php echo h($car['car_type']); ?></span>
                        <span class="dc-status available" style="padding:4px 8px; font-size:11px;"><?php echo h(ucfirst($car['status'])); ?></span>
                    </div>
                    
                    <h1 class="dc-h1" style="font-size:28px; margin-bottom:8px;"><?php echo h($car['brand'] . ' ' . $car['model']); ?></h1>
                    <p class="dc-p" style="font-size:14px; margin-bottom:24px;">Comfortable, ready-to-rent vehicle prepared for your next journey.</p>
                    
                    <div style="padding:16px; background:#f9fafc; border-radius:12px; display:flex; justify-content:space-between; align-items:center; margin-bottom:24px;">
                        <span style="color:#5b6273; font-size:14px; font-weight:600;">Daily Rate</span>
                        <div style="text-align:right;">
                            <strong style="font-size:20px; font-weight:800; color:#131722;">RM <?php echo h(number_format((float) $car['daily_rate'], 2)); ?></strong>
                            <span style="color:#9097a8; font-size:13px;">/ day</span>
                        </div>
                    </div>
                    
                    <?php if ($hasUnpaidLateFees) : ?>
                        <p class="message error" style="color:#c23a52; background:#fbeaed; padding:12px 16px; border-radius:8px; font-weight:600; font-size:14px; margin-bottom:16px;">
                            Please settle your unpaid late fees in <a href="my_bookings.php" style="color:#c23a52; text-decoration:underline;">My Bookings</a> before booking another car.
                        </p>
                        <button type="button" class="dc-btn-primary" style="width:100%; justify-content:center; padding:14px; opacity:0.5; cursor:not-allowed;" disabled>Book Now</button>
                    <?php else : ?>
                        <button type="button" id="book-now-toggle" class="dc-btn-primary" style="width:100%; justify-content:center; padding:14px;" aria-expanded="<?php echo $openBookingPanel ? 'true' : 'false'; ?>" aria-controls="booking-panel">
                            Book Now
                        </button>
                    <?php endif; ?>
                </div>

                <?php if (!$hasUnpaidLateFees) : ?>
                <div class="dc-card padded booking-panel<?php echo $openBookingPanel ? ' is-open' : ''; ?>" id="booking-panel">
                    <div class="dc-h2-title">
                        <div>
                            <div class="dc-mono-subtitle small" style="margin-bottom:8px">Checkout</div>
                            <h2 class="dc-h2">Complete your booking</h2>
                        </div>
                    </div>

                    <?php if ($checkoutError !== '') : ?>
                        <p class="message error" style="color: #c23a52; background: #fbeaed; padding: 12px 16px; border-radius: 8px; font-weight: 600; margin-bottom:24px;"><?php echo h($checkoutError); ?></p>
                    <?php endif; ?>

                    <div style="display:flex; gap:10px; margin-bottom:30px;">
                        <div class="checkout-step" id="step1-indicator" style="flex:1; padding:12px; background:#f1f3f9; text-align:center; font-weight:600; border-radius:8px; color:#5b6273;">1. Select Dates</div>
                        <div class="checkout-step" id="step2-indicator" style="flex:1; padding:12px; background:#f9fafc; text-align:center; font-weight:600; border-radius:8px; color:#9097a8;">2. Add-ons</div>
                        <div class="checkout-step" id="step3-indicator" style="flex:1; padding:12px; background:#f9fafc; text-align:center; font-weight:600; border-radius:8px; color:#9097a8;">3. Payment</div>
                    </div>

                    <form method="post" action="car_detail.php?id=<?php echo h($car['id']); ?>" id="checkout-form">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="action" value="checkout">
                        <input type="hidden" name="car_id" value="<?php echo h($car['id']); ?>">

                        <!-- Step 1 -->
                        <div class="checkout-section" id="step1">
                            <h2 class="dc-h2" style="font-size:20px; margin-bottom:20px;">Select your rental dates</h2>
                            <div class="checkout-date-grid">
                                <label style="display:block;">
                                    <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Pickup Date</span>
                                    <input type="date" name="pickup_date" id="chk_pickup" class="dc-input" required min="<?php echo date('Y-m-d'); ?>" value="<?php echo h($checkoutPickupDate); ?>" style="width:100%;">
                                </label>
                                <label style="display:block;">
                                    <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Return Date</span>
                                    <input type="date" name="return_date" id="chk_return" class="dc-input" required min="<?php echo date('Y-m-d'); ?>" value="<?php echo h($checkoutReturnDate); ?>" style="width:100%;">
                                </label>
                            </div>
                            <button type="button" class="dc-btn-primary next-btn" data-next="step2" style="margin-top:24px;">Next: Add-ons</button>
                        </div>

                        <!-- Step 2 -->
                        <div class="checkout-section" id="step2" style="display:none;">
                            <h2 class="dc-h2" style="font-size:20px; margin-bottom:20px;">Enhance your trip</h2>
                            <div style="display:flex; flex-direction:column; gap:12px;">
                                <?php if (count($checkoutAddons) === 0) : ?>
                                    <p style="font-size:14px; color:#9097a8;">No extras are available right now.</p>
                                <?php else : ?>
                                    <?php foreach ($checkoutAddons as $addon) : ?>
                                        <label style="display:flex; align-items:center; gap:12px; padding:16px; border:1px solid #e4e8f1; border-radius:8px; cursor:pointer;">
                                            <input
                                                type="checkbox"
                                                name="addons[]"
                                                value="<?php echo h($addon['id']); ?>"
                                                data-price="<?php echo (float) $addon['price']; ?>"
                                                <?php echo in_array((string) $addon['id'], array_map('strval', $checkoutSelectedAddonIds), true) ? 'checked' : ''; ?>
                                                style="width:18px; height:18px;"
                                            >
                                            <span style="font-weight:600;">
                                                <?php echo h($addon['name']); ?>
                                                <span style="color:#5b6273; font-weight:400;">(+RM <?php echo h(number_format((float) $addon['price'], 0)); ?>/day)</span>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <div style="display:flex; gap:12px; margin-top:24px;">
                                <button type="button" class="dc-btn-secondary prev-btn" data-prev="step1">Back</button>
                                <button type="button" class="dc-btn-primary next-btn" data-next="step3">Next: Payment</button>
                            </div>
                        </div>

                        <!-- Step 3 -->
                        <div class="checkout-section" id="step3" style="display:none;">
                            <h2 class="dc-h2" style="font-size:20px; margin-bottom:20px;">Payment Details</h2>
                            <div style="margin-bottom:24px; padding:20px; background:#f9fafc; border-radius:12px; border:1px solid #e4e8f1;">
                                <p style="margin-bottom:8px;">Car: <strong><?php echo h($car['brand'] . ' ' . $car['model']); ?></strong></p>
                                <p style="margin-bottom:16px;">Rate: RM <?php echo number_format((float) $car['daily_rate'], 2); ?> / day</p>
                                <div style="padding-top:16px; border-top:1px solid #e4e8f1; display:flex; justify-content:space-between; align-items:center;">
                                    <span style="font-weight:600; font-size:16px;">Total</span>
                                    <strong style="font-size:24px; color:#131722;" id="checkout-total">RM 0.00</strong>
                                </div>
                            </div>

                            <label style="display:block; margin-bottom:24px;">
                                <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Payment Method</span>
                                <select name="payment_method" class="dc-select" required style="width:100%;">
                                    <?php foreach (PAYMENT_METHODS as $method) : ?>
                                        <option value="<?php echo h($method); ?>"><?php echo h($method); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <div style="display:flex; gap:12px;">
                                <button type="button" class="dc-btn-secondary prev-btn" data-prev="step2">Back</button>
                                <button type="submit" class="dc-btn-primary">Confirm & Pay</button>
                            </div>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const panel = document.getElementById('booking-panel');
                const toggleBtn = document.getElementById('book-now-toggle');

                if (toggleBtn && panel) {
                    toggleBtn.addEventListener('click', () => {
                        const isOpen = panel.classList.toggle('is-open');
                        toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                        if (isOpen) {
                            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    });

                    <?php if ($openBookingPanel) : ?>
                    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    <?php endif; ?>
                }

                const dailyRate = <?php echo (float) $car['daily_rate']; ?>;
                const pickup = document.getElementById('chk_pickup');
                const returnDate = document.getElementById('chk_return');
                const totalEl = document.getElementById('checkout-total');
                const addons = document.querySelectorAll('input[name="addons[]"]');

                function calcTotal() {
                    if (!pickup.value || !returnDate.value) return;
                    const d1 = new Date(pickup.value);
                    const d2 = new Date(returnDate.value);
                    // Inclusive day count, matching the server's bookingTotalDays()
                    // (diff->days + 1) — pickup and return day both count as rental days.
                    let days = Math.round((d2 - d1) / (1000 * 60 * 60 * 24)) + 1;
                    if (days < 1) days = 1;

                    let total = days * dailyRate;
                    addons.forEach(a => {
                        if (a.checked) {
                            total += parseFloat(a.dataset.price || 0) * days;
                        }
                    });
                    totalEl.innerText = 'RM ' + total.toFixed(2);
                }

                pickup.addEventListener('change', calcTotal);
                returnDate.addEventListener('change', calcTotal);
                addons.forEach(a => a.addEventListener('change', calcTotal));

                function updateSteps(activeStepId) {
                    document.querySelectorAll('.checkout-step').forEach(s => {
                        s.style.background = '#f9fafc';
                        s.style.color = '#9097a8';
                    });
                    const active = document.getElementById(activeStepId + '-indicator');
                    if (active) {
                        active.style.background = '#f1f3f9';
                        active.style.color = '#5b6273';
                    }
                }

                document.querySelectorAll('.next-btn').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        if (e.target.dataset.next === 'step2' && (!pickup.value || !returnDate.value)) {
                            alert('Please select dates');
                            return;
                        }
                        document.querySelectorAll('.checkout-section').forEach(s => s.style.display = 'none');
                        document.getElementById(e.target.dataset.next).style.display = 'block';
                        updateSteps(e.target.dataset.next);
                        calcTotal();
                    });
                });
                document.querySelectorAll('.prev-btn').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        document.querySelectorAll('.checkout-section').forEach(s => s.style.display = 'none');
                        document.getElementById(e.target.dataset.prev).style.display = 'block';
                        updateSteps(e.target.dataset.prev);
                    });
                });

                // Guard against a double-click firing two submits (two bookings, two
                // charges) while the first request is still in flight.
                const checkoutForm = document.getElementById('checkout-form');
                if (checkoutForm) {
                    checkoutForm.addEventListener('submit', () => {
                        const submitBtn = checkoutForm.querySelector('button[type="submit"]');
                        if (submitBtn) {
                            submitBtn.disabled = true;
                            submitBtn.textContent = 'Processing…';
                        }
                    });
                }

                calcTotal();
            });
        </script>
    <?php endif; ?>
</main>
<?php include '../includes/layout_bottom.php'; ?>
