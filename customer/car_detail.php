<?php
require_once __DIR__ . '/includes/auth.php';
require_once '../db_connect.php';
require_once __DIR__ . '/../util/booking.php';
require_once __DIR__ . '/../util/car_display.php';
require_once __DIR__ . '/../util/car_archive.php';
require_once __DIR__ . '/../util/payment.php';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

startSecureSession();
requireCustomerLogin();

$car = null;
$error = '';
$carId = filter_input(INPUT_POST, 'car_id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$today = date('Y-m-d');
$pickupDate = trim($_POST['pickup_date'] ?? $_GET['pickup_date'] ?? '');
$returnDate = trim($_POST['return_date'] ?? $_GET['return_date'] ?? '');
$availabilityChecked = $pickupDate !== '' || $returnDate !== '';
$availabilityPassed = false;
$availabilityTone = '';
$availabilityMessage = 'Select your rental dates and check availability before booking.';
$isBookRequest = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'book';
$hasUnpaidLateFees = false;

if (!$carId) {
    $error = 'Please choose a valid car.';
} else {
    try {
        $conn = getDbConnection();
        ensureCarArchiveColumn($conn);

        if ($isBookRequest) {
            requireValidCsrfToken();
        }

        $customerId = (int) ($_SESSION['customer_id'] ?? 0);
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
        } elseif ($hasUnpaidLateFees) {
            unset($_SESSION['checked_car_availability']);
            $availabilityTone = 'error';
            $availabilityMessage = 'Please settle your unpaid late fees in My Bookings before booking another car.';
        } elseif ($availabilityChecked) {
            $dateError = validateBookingDates($pickupDate, $returnDate, $today);

            if ($dateError !== null) {
                unset($_SESSION['checked_car_availability']);
                $availabilityTone = 'error';
                $availabilityMessage = $dateError;
            } elseif (carHasBookingConflict($conn, (int) $car['id'], $pickupDate, $returnDate)) {
                unset($_SESSION['checked_car_availability']);
                $availabilityTone = 'error';
                $availabilityMessage = 'This car is already booked for the selected dates.';
            } else {
                $totalDays = bookingTotalDays($pickupDate, $returnDate);
                $totalAmount = bookingTotalAmount($totalDays, (float) $car['daily_rate']);
                $checkedAvailability = $_SESSION['checked_car_availability'] ?? [];
                $availabilityPassed = !$isBookRequest || (
                    is_array($checkedAvailability) &&
                    (int) ($checkedAvailability['car_id'] ?? 0) === (int) $car['id'] &&
                    ($checkedAvailability['pickup_date'] ?? '') === $pickupDate &&
                    ($checkedAvailability['return_date'] ?? '') === $returnDate
                );

                if ($availabilityPassed) {
                    $_SESSION['checked_car_availability'] = [
                        'car_id' => (int) $car['id'],
                        'pickup_date' => $pickupDate,
                        'return_date' => $returnDate,
                    ];

                    $availabilityTone = 'success';
                    $availabilityMessage = 'This car is available. Estimated total: RM ' . number_format($totalAmount, 2) . ' for ' . $totalDays . ' day' . ($totalDays === 1 ? '.' : 's.');
                } else {
                    $availabilityTone = 'error';
                    $availabilityMessage = 'Please check availability successfully before booking.';
                }
            }
        }

        if ($isBookRequest && $error === '') {
            if ($customerId <= 0) {
                $error = 'Please log in again before booking this car.';
            } elseif ($hasUnpaidLateFees) {
                $availabilityTone = 'error';
                $availabilityMessage = 'Please settle your unpaid late fees in My Bookings before booking another car.';
            } elseif (!$availabilityPassed) {
                $availabilityTone = 'error';
                $availabilityMessage = 'Please check availability successfully before booking.';
            } else {
                $totalDays = bookingTotalDays($pickupDate, $returnDate);
                $totalAmount = bookingTotalAmount($totalDays, (float) $car['daily_rate']);
                $bookingCarId = (int) $car['id'];
                $pickupLocation = BOOKING_DEFAULT_PICKUP_LOCATION;
                $bookingStatus = 'pending';

                $stmt = $conn->prepare(
                    'INSERT INTO bookings
                        (customer_id, car_id, pickup_date, return_date, pickup_location, total_days, total_amount, booking_status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param(
                    'iisssids',
                    $customerId,
                    $bookingCarId,
                    $pickupDate,
                    $returnDate,
                    $pickupLocation,
                    $totalDays,
                    $totalAmount,
                    $bookingStatus
                );
                $stmt->execute();

                header('Location: booking.php?id=' . $stmt->insert_id);
                exit;
            }
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (mysqli_sql_exception $e) {
        $error = $isBookRequest
            ? 'Could not create this booking. Please confirm the database is available and the bookings table matches the expected structure.'
            : 'Could not load car details. Please check the database connection.';
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

    <?php if ($error !== ''): ?>
        <p class="message error" style="color: #c23a52; background: #fbeaed; padding: 12px; border-radius: 8px; font-weight: 600;"><?php echo h($error); ?></p>
    <?php else: ?>
        <section class="dc-grid-2-sidebar">
            <!-- Left Column: Details & Images -->
            <div style="display:flex; flex-direction:column; gap:24px;">
                <div class="dc-card" style="overflow:hidden;">
                    <img
                        src="<?php echo h(carImageUrl($car['image'], 'https://images.unsplash.com/photo-1549317661-bd32c8ce0db2?auto=format&fit=crop&w=1100&q=80')); ?>"
                        alt="<?php echo h($car['brand'] . ' ' . $car['model']); ?>"
                        style="width:100%; aspect-ratio:16/9; object-fit:cover; display:block;"
                    >
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
                    
                    <a href="booking.php?car_id=<?php echo h($car['id']); ?>" class="dc-btn-primary" style="width:100%; justify-content:center; padding:14px;">Book Now</a>
                </div>
            </div>
        </section>
    <?php endif; ?>
</main>
<?php include '../includes/layout_bottom.php'; ?>
