<?php
session_start();
require_once '../db_connect.php';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function selectedIf($currentValue, $optionValue): string
{
    return $currentValue === $optionValue ? ' selected' : '';
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$carTypes = ['Compact', 'Sedan', 'SUV', 'MPV', 'Luxury'];
$transmissions = ['Automatic', 'Manual'];
$fuelTypes = ['Petrol', 'Diesel', 'Hybrid', 'Electric'];
$statuses = ['available', 'unavailable', 'maintenance'];

$error = '';
$success = '';
$cars = [];
$brand = '';
$model = '';
$plateNumber = '';
$carType = 'Sedan';
$transmission = 'Automatic';
$fuelType = 'Petrol';
$seats = '';
$dailyRate = '';
$image = '';
$status = 'available';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $brand = trim($_POST['brand'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $plateNumber = strtoupper(trim($_POST['plate_number'] ?? ''));
    $carType = $_POST['car_type'] ?? '';
    $transmission = $_POST['transmission'] ?? '';
    $fuelType = $_POST['fuel_type'] ?? '';
    $seats = trim($_POST['seats'] ?? '');
    $dailyRate = trim($_POST['daily_rate'] ?? '');
    $image = trim($_POST['image'] ?? '');
    $status = $_POST['status'] ?? '';

    if (
        $brand === '' ||
        $model === '' ||
        $plateNumber === '' ||
        $seats === '' ||
        $dailyRate === ''
    ) {
        $error = 'Please complete all required car details.';
    } elseif (!in_array($carType, $carTypes, true)) {
        $error = 'Please choose a valid car type.';
    } elseif (!in_array($transmission, $transmissions, true)) {
        $error = 'Please choose a valid transmission.';
    } elseif (!in_array($fuelType, $fuelTypes, true)) {
        $error = 'Please choose a valid fuel type.';
    } elseif (!in_array($status, $statuses, true)) {
        $error = 'Please choose a valid status.';
    } elseif (!filter_var($seats, FILTER_VALIDATE_INT) || (int) $seats <= 0) {
        $error = 'Seats must be greater than zero.';
    } elseif (!is_numeric($dailyRate) || (float) $dailyRate <= 0) {
        $error = 'Daily rate must be greater than zero.';
    } else {
        try {
            $conn = getDbConnection();
            $adminId = (int) $_SESSION['admin_id'];
            $seatsValue = (int) $seats;
            $dailyRateValue = (float) $dailyRate;

            $stmt = $conn->prepare(
                'INSERT INTO cars
                    (admin_id, brand, model, plate_number, car_type, transmission, fuel_type, seats, daily_rate, image, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param(
                'issssssidss',
                $adminId,
                $brand,
                $model,
                $plateNumber,
                $carType,
                $transmission,
                $fuelType,
                $seatsValue,
                $dailyRateValue,
                $image,
                $status
            );
            $stmt->execute();

            $success = 'Car added successfully.';
            $brand = '';
            $model = '';
            $plateNumber = '';
            $carType = 'Sedan';
            $transmission = 'Automatic';
            $fuelType = 'Petrol';
            $seats = '';
            $dailyRate = '';
            $image = '';
            $status = 'available';
        } catch (mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1062) {
                $error = 'This plate number already exists.';
            } else {
                $error = 'Could not save car. Please check the database connection and try again.';
            }
        }
    }
}

try {
    $conn = getDbConnection();
    $result = $conn->query(
        'SELECT brand, model, plate_number, car_type, transmission, fuel_type, seats, daily_rate, status
         FROM cars
         ORDER BY created_at DESC
         LIMIT 10'
    );
    $cars = $result->fetch_all(MYSQLI_ASSOC);
} catch (mysqli_sql_exception $e) {
    if ($error === '') {
        $error = 'Could not load cars. Please check the database connection.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage CarGo Cars</title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body>
    <main class="dashboard-page">
        <header class="dashboard-header">
            <?php include 'header.php'; ?>
        </header>

        <section class="dashboard-content dashboard-shell manage-cars-layout">
            <section class="login-card car-form-card">
                <h2>Manage Cars</h2>
                <p class="subtitle">Add vehicles that customers can rent.</p>

                <?php if ($error !== ''): ?>
                    <p class="message error"><?php echo h($error); ?></p>
                <?php endif; ?>

                <?php if ($success !== ''): ?>
                    <p class="message success"><?php echo h($success); ?></p>
                <?php endif; ?>

                <form class="form-grid" method="post" action="manage_cars.php">
                    <label for="brand">
                        Brand
                        <input type="text" id="brand" name="brand" value="<?php echo h($brand); ?>" maxlength="100" required>
                    </label>

                    <label for="model">
                        Model
                        <input type="text" id="model" name="model" value="<?php echo h($model); ?>" maxlength="100" required>
                    </label>

                    <label for="plate_number">
                        Plate Number
                        <input type="text" id="plate_number" name="plate_number" value="<?php echo h($plateNumber); ?>" maxlength="30" required>
                    </label>

                    <label for="car_type">
                        Car Type
                        <select id="car_type" name="car_type" required>
                            <?php foreach ($carTypes as $type): ?>
                                <option value="<?php echo h($type); ?>"<?php echo selectedIf($carType, $type); ?>>
                                    <?php echo h($type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label for="transmission">
                        Transmission
                        <select id="transmission" name="transmission" required>
                            <?php foreach ($transmissions as $option): ?>
                                <option value="<?php echo h($option); ?>"<?php echo selectedIf($transmission, $option); ?>>
                                    <?php echo h($option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label for="fuel_type">
                        Fuel Type
                        <select id="fuel_type" name="fuel_type" required>
                            <?php foreach ($fuelTypes as $option): ?>
                                <option value="<?php echo h($option); ?>"<?php echo selectedIf($fuelType, $option); ?>>
                                    <?php echo h($option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label for="seats">
                        Seats
                        <input type="number" id="seats" name="seats" value="<?php echo h($seats); ?>" min="1" required>
                    </label>

                    <label for="daily_rate">
                        Daily Rate
                        <input type="number" id="daily_rate" name="daily_rate" value="<?php echo h($dailyRate); ?>" min="0.01" step="0.01" required>
                    </label>

                    <label for="status">
                        Status
                        <select id="status" name="status" required>
                            <?php foreach ($statuses as $option): ?>
                                <option value="<?php echo h($option); ?>"<?php echo selectedIf($status, $option); ?>>
                                    <?php echo h(ucfirst($option)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="form-grid-full" for="image">
                        Image Path
                        <input type="text" id="image" name="image" value="<?php echo h($image); ?>" maxlength="255" placeholder="images/car.jpg">
                    </label>

                    <button type="submit">Add Car</button>
                </form>
            </section>

            <section class="cars-list-panel">
                <header class="panel-heading">
                    <p class="eyebrow">Inventory</p>
                    <h2>Recent Cars</h2>
                </header>

                <?php if (count($cars) === 0): ?>
                    <p class="empty-table-message">No cars added yet.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Vehicle</th>
                                    <th>Plate</th>
                                    <th>Type</th>
                                    <th>Rate</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cars as $car): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo h($car['brand'] . ' ' . $car['model']); ?></strong>
                                            <span><?php echo h($car['transmission'] . ' / ' . $car['fuel_type'] . ' / ' . $car['seats'] . ' seats'); ?></span>
                                        </td>
                                        <td><?php echo h($car['plate_number']); ?></td>
                                        <td><?php echo h($car['car_type']); ?></td>
                                        <td>RM <?php echo h(number_format((float) $car['daily_rate'], 2)); ?></td>
                                        <td>
                                            <span class="status-pill"><?php echo h(ucfirst($car['status'])); ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </section>
    </main>
</body>
</html>
