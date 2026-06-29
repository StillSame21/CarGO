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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Car Details | CarGo</title>
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
                <a class="back-link" href="browse_cars.php">Back to available cars</a>

                <?php if ($error !== ''): ?>
                    <p class="message error"><?php echo h($error); ?></p>
                <?php else: ?>
                    <section class="vehicle-profile">
                        <header class="vehicle-profile-header">
                            <div>
                                <div class="vehicle-badges" aria-label="Vehicle labels">
                                    <span><?php echo h($car['car_type']); ?></span>
                                    <span><?php echo h(ucfirst($car['status'])); ?></span>
                                </div>
                                <h1><?php echo h($car['brand'] . ' ' . $car['model']); ?></h1>
                                <p>Comfortable, ready-to-rent vehicle prepared for your selected dates.</p>
                            </div>

                            <div class="vehicle-rate">
                                <span>Daily Rate</span>
                                <strong>RM <?php echo h(number_format((float) $car['daily_rate'], 2)); ?></strong>
                                <small>per day</small>
                            </div>
                        </header>

                        <div class="vehicle-profile-main">
                            <div class="vehicle-media">
                                <img
                                    src="<?php echo h(carImageUrl($car['image'], 'https://images.unsplash.com/photo-1549317661-bd32c8ce0db2?auto=format&fit=crop&w=1100&q=80')); ?>"
                                    alt="<?php echo h($car['brand'] . ' ' . $car['model']); ?>"
                                >
                            </div>

                            <section class="vehicle-spec-panel" aria-label="Vehicle details">
                                <div class="section-heading">
                                    <p class="eyebrow">Vehicle Details</p>
                                    <h2>Key specifications</h2>
                                </div>

                                <dl class="vehicle-spec-grid">
                                    <div>
                                        <dt>Plate Number</dt>
                                        <dd><?php echo h($car['plate_number']); ?></dd>
                                    </div>
                                    <div>
                                        <dt>Transmission</dt>
                                        <dd><?php echo h($car['transmission']); ?></dd>
                                    </div>
                                    <div>
                                        <dt>Fuel Type</dt>
                                        <dd><?php echo h($car['fuel_type']); ?></dd>
                                    </div>
                                    <div>
                                        <dt>Seats</dt>
                                        <dd><?php echo h($car['seats']); ?> seats</dd>
                                    </div>
                                    <div>
                                        <dt>Status</dt>
                                        <dd><?php echo h(ucfirst($car['status'])); ?></dd>
                                    </div>
                                </dl>
                            </section>
                        </div>

                        <section class="booking-panel" aria-label="Check car availability">
                            <div class="booking-panel-heading">
                                <div>
                                    <p class="eyebrow">Availability</p>
                                    <h2>Choose your rental dates</h2>
                                </div>
                                <p class="availability-note <?php echo h($availabilityTone); ?>"><?php echo h($availabilityMessage); ?></p>
                            </div>

                            <div class="booking-workflow">
                                <form method="get" action="car_detail.php" id="availability-form" class="availability-date-form">
                                    <input type="hidden" name="id" value="<?php echo h($car['id']); ?>">
                                    <input type="hidden" name="car_id" value="<?php echo h($car['id']); ?>">

                                    <label for="pickup_date">
                                        Pickup Date
                                        <input
                                            type="date"
                                            id="pickup_date"
                                            name="pickup_date"
                                            value="<?php echo h($pickupDate); ?>"
                                            min="<?php echo h($today); ?>"
                                        >
                                    </label>

                                    <label for="return_date">
                                        Return Date
                                        <input
                                            type="date"
                                            id="return_date"
                                            name="return_date"
                                            value="<?php echo h($returnDate); ?>"
                                            min="<?php echo h($pickupDate !== '' ? $pickupDate : $today); ?>"
                                        >
                                    </label>

                                    <button type="submit" class="secondary-button">Check Availability</button>
                                </form>

                                <form method="post" action="car_detail.php" class="availability-date-form">
                                    <?php echo csrfInput(); ?>
                                    <input type="hidden" name="action" value="book">
                                    <input type="hidden" name="car_id" value="<?php echo h($car['id']); ?>">
                                    <input type="hidden" name="pickup_date" value="<?php echo h($pickupDate); ?>">
                                    <input type="hidden" name="return_date" value="<?php echo h($returnDate); ?>">
                                    <button type="submit"<?php echo $availabilityPassed ? '' : ' disabled'; ?>>Book</button>
                                </form>
                            </div>
                        </section>
                    </section>
                <?php endif; ?>
            </div>
        </section>
    </main>
    <script>
    const pickupInput = document.getElementById('pickup_date');
    const returnInput = document.getElementById('return_date');
    const bookButton = document.querySelector('form[method="post"][action="car_detail.php"] button[type="submit"]');

    if (pickupInput && returnInput && bookButton) {
        const originalPickup = pickupInput.value;
        const originalReturn = returnInput.value;

        const monitorDates = () => {
            if (pickupInput.value !== originalPickup || returnInput.value !== originalReturn) {
                bookButton.disabled = true;
                bookButton.innerText = 'Dates Changed (Re-check)';
            } else {
                bookButton.disabled = false;
                bookButton.innerText = 'Book';
            }
        };
        pickupInput.addEventListener('change', monitorDates);
        returnInput.addEventListener('change', monitorDates);
    }
    </script>
</body>
</html>
