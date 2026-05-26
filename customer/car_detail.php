<?php
session_start();
require_once '../db_connect.php';
require_once __DIR__ . '/../util/car_display.php';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$car = null;
$error = '';
$carId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$today = date('Y-m-d');
$pickupDate = trim($_GET['pickup_date'] ?? '');
$returnDate = trim($_GET['return_date'] ?? '');
$availabilityMessage = 'Select your rental dates to prepare this booking.';

if (!$carId) {
    $error = 'Please choose a valid car.';
} else {
    try {
        $conn = getDbConnection();
        $stmt = $conn->prepare(
            'SELECT id, brand, model, plate_number, car_type, transmission, fuel_type, seats, daily_rate, image, status
             FROM cars
             WHERE id = ? AND status = \'available\'
             LIMIT 1'
        );
        $stmt->bind_param('i', $carId);
        $stmt->execute();
        $result = $stmt->get_result();
        $car = $result->fetch_assoc();

        if (!$car) {
            $error = 'This car is not available for booking.';
        } elseif ($pickupDate !== '' || $returnDate !== '') {
            if ($pickupDate === '' || $returnDate === '') {
                $availabilityMessage = 'Please select both pickup and return dates.';
            } elseif ($pickupDate < $today || $returnDate < $today) {
                $availabilityMessage = 'Please choose today or a future date.';
            } elseif ($returnDate < $pickupDate) {
                $availabilityMessage = 'Return date must be the same as or later than pickup date.';
            } else {
                $availabilityMessage = 'This car is currently listed as available for the selected dates.';
            }
        }
    } catch (mysqli_sql_exception $e) {
        $error = 'Could not load car details. Please check the database connection.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Car Details | CarGo</title>
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
                                <p class="availability-note"><?php echo h($availabilityMessage); ?></p>
                            </div>

                            <form method="get" action="car_detail.php" class="availability-form">
                                <input type="hidden" name="id" value="<?php echo h($car['id']); ?>">

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

                                <div class="booking-actions">
                                    <button type="submit" class="secondary-button">Check Availability</button>
                                    <button type="button">Book</button>
                                </div>
                            </form>
                        </section>
                    </section>
                <?php endif; ?>
            </div>
        </section>
    </main>
</body>
</html>
