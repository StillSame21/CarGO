<?php
require_once __DIR__ . '/../includes/security.php';
require_once '../db_connect.php';
require_once __DIR__ . '/../util/car_image.php';
require_once __DIR__ . '/../util/car_archive.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function selectedIf($currentValue, $optionValue): string
{
    return $currentValue === $optionValue ? ' selected' : '';
}

requireAdminLogin();

$carTypes = ['Compact', 'Sedan', 'SUV', 'MPV', 'Luxury'];
$transmissions = ['Automatic', 'Manual'];
$fuelTypes = ['Petrol', 'Diesel', 'Hybrid', 'Electric'];
$statuses = ['available', 'unavailable', 'maintenance'];

$error = '';
$success = trim($_GET['success'] ?? '') === 'updated'
    ? 'Car updated successfully.'
    : (trim($_GET['success'] ?? '') === 'added'
        ? 'Car added successfully.'
        : (trim($_GET['success'] ?? '') === 'archived'
            ? 'Car archived successfully.'
            : ''));
$cars = [];
$editCarId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;
$isEditMode = false;
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
$uploadedImage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireValidCsrfToken();
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $formAction = trim($_POST['form_action'] ?? 'save_car');
    $postedCarId = filter_input(INPUT_POST, 'car_id', FILTER_VALIDATE_INT) ?: 0;

    if ($formAction === 'archive_car') {
        if ($postedCarId <= 0) {
            $error = 'Please choose a valid car to archive.';
        } else {
            try {
                $conn = getDbConnection();
                ensureCarArchiveColumn($conn);

                $archivedStatus = 'unavailable';
                $stmt = $conn->prepare(
                    'UPDATE cars
                     SET archived_at = NOW(), status = ?
                     WHERE id = ? AND archived_at IS NULL'
                );
                $stmt->bind_param('si', $archivedStatus, $postedCarId);
                $stmt->execute();

                if ($stmt->affected_rows > 0) {
                    header('Location: manage_cars.php?success=archived');
                    exit;
                }

                $error = 'Could not archive that car. It may already be archived.';
            } catch (mysqli_sql_exception $e) {
                $error = 'Could not archive car. Please check the database connection and try again.';
            }
        }
    }

    $editCarId = $formAction === 'update_car' ? $postedCarId : 0;
    $isEditMode = $editCarId > 0;
    $brand = trim($_POST['brand'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $plateNumber = strtoupper(trim($_POST['plate_number'] ?? ''));
    $carType = $_POST['car_type'] ?? '';
    $transmission = $_POST['transmission'] ?? '';
    $fuelType = $_POST['fuel_type'] ?? '';
    $seats = trim($_POST['seats'] ?? '');
    $dailyRate = trim($_POST['daily_rate'] ?? '');
    $image = '';
    $status = $_POST['status'] ?? '';

    if ($formAction === 'archive_car') {
        // The archive request has already been handled above.
    } elseif ($formAction !== 'add_car' && $formAction !== 'update_car') {
        $error = 'Please choose a valid car action.';
    } elseif ($isEditMode && $editCarId <= 0) {
        $error = 'Please choose a valid car to update.';
    } elseif (
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
            ensureCarArchiveColumn($conn);
            $adminId = (int) $_SESSION['admin_id'];
            $seatsValue = (int) $seats;
            $dailyRateValue = (float) $dailyRate;
            $existingImage = '';

            if ($isEditMode) {
                $stmt = $conn->prepare('SELECT image FROM cars WHERE id = ? AND archived_at IS NULL LIMIT 1');
                $stmt->bind_param('i', $editCarId);
                $stmt->execute();
                $existingCar = $stmt->get_result()->fetch_assoc();

                if (!$existingCar) {
                    throw new InvalidArgumentException('Please choose a valid car to update.');
                }

                $existingImage = (string) ($existingCar['image'] ?? '');
            }

            $stmt = $conn->prepare('SELECT id FROM cars WHERE plate_number = ? AND id <> ? LIMIT 1');
            $stmt->bind_param('si', $plateNumber, $editCarId);
            $stmt->execute();

            if ($stmt->get_result()->fetch_assoc()) {
                throw new InvalidArgumentException('This plate number already exists.');
            }

            $uploadedImage = processCarImageUpload($_FILES['image'] ?? null);
            $image = $uploadedImage !== '' ? $uploadedImage : $existingImage;

            if ($isEditMode) {
                $stmt = $conn->prepare(
                    'UPDATE cars
                     SET brand = ?,
                         model = ?,
                         plate_number = ?,
                         car_type = ?,
                         transmission = ?,
                         fuel_type = ?,
                         seats = ?,
                         daily_rate = ?,
                         image = ?,
                         status = ?
                     WHERE id = ? AND archived_at IS NULL'
                );
                $stmt->bind_param(
                    'ssssssidssi',
                    $brand,
                    $model,
                    $plateNumber,
                    $carType,
                    $transmission,
                    $fuelType,
                    $seatsValue,
                    $dailyRateValue,
                    $image,
                    $status,
                    $editCarId
                );
                $stmt->execute();

                if ($uploadedImage !== '' && $existingImage !== '' && $uploadedImage !== $existingImage) {
                    deleteCarImageFile($existingImage);
                }

                header('Location: manage_cars.php?success=updated');
                exit;
            }

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

            header('Location: manage_cars.php?success=added');
            exit;
        } catch (InvalidArgumentException $e) {
            deleteCarImageFile($uploadedImage);
            $error = $e->getMessage();
        } catch (mysqli_sql_exception $e) {
            deleteCarImageFile($uploadedImage !== '' ? $uploadedImage : $image);

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
    ensureCarArchiveColumn($conn);

    if ($editCarId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $stmt = $conn->prepare(
            'SELECT id, brand, model, plate_number, car_type, transmission, fuel_type, seats, daily_rate, image, status
             FROM cars
             WHERE id = ? AND archived_at IS NULL
             LIMIT 1'
        );
        $stmt->bind_param('i', $editCarId);
        $stmt->execute();
        $editCar = $stmt->get_result()->fetch_assoc();

        if ($editCar) {
            $isEditMode = true;
            $brand = (string) $editCar['brand'];
            $model = (string) $editCar['model'];
            $plateNumber = (string) $editCar['plate_number'];
            $carType = (string) $editCar['car_type'];
            $transmission = (string) $editCar['transmission'];
            $fuelType = (string) $editCar['fuel_type'];
            $seats = (string) $editCar['seats'];
            $dailyRate = (string) $editCar['daily_rate'];
            $image = (string) ($editCar['image'] ?? '');
            $status = (string) $editCar['status'];
        } elseif ($error === '') {
            $error = 'Could not find that car to edit.';
            $editCarId = 0;
        }
    }

    $result = $conn->query(
        'SELECT id, brand, model, plate_number, car_type, transmission, fuel_type, seats, daily_rate, status
         FROM cars
         WHERE archived_at IS NULL
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
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body>
    <main class="dashboard-page">
        <header class="dashboard-header">
            <?php include 'header.php'; ?>
        </header>

        <section class="dashboard-content dashboard-shell manage-cars-layout">
            <section class="login-card car-form-card">
                <h2><?php echo $isEditMode ? 'Update Car' : 'Manage Cars'; ?></h2>
                <p class="subtitle"><?php echo $isEditMode ? 'Update this vehicle without creating a duplicate.' : 'Add vehicles that customers can rent.'; ?></p>

                <?php if ($error !== ''): ?>
                    <p class="message error"><?php echo h($error); ?></p>
                <?php endif; ?>

                <?php if ($success !== ''): ?>
                    <p class="message success"><?php echo h($success); ?></p>
                <?php endif; ?>

                <form class="form-grid" method="post" action="manage_cars.php" enctype="multipart/form-data">
                    <?php echo csrfInput(); ?>

                    <input type="hidden" name="form_action" value="<?php echo $isEditMode ? 'update_car' : 'add_car'; ?>">
                    <input type="hidden" name="car_id" value="<?php echo h($editCarId); ?>">

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
                        Car Image
                        <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo CAR_IMAGE_MAX_BYTES; ?>">
                        <input type="file" id="image" name="image" accept="image/*">
                        <?php if ($isEditMode && $image !== ''): ?>
                            <span class="field-note">Leave blank to keep the current image.</span>
                        <?php endif; ?>
                    </label>

                    <div class="form-actions">
                        <button type="submit"><?php echo $isEditMode ? 'Update Car' : 'Add Car'; ?></button>
                        <?php if ($isEditMode): ?>
                            <a class="cancel-edit-link" href="manage_cars.php">Cancel Edit</a>
                        <?php endif; ?>
                    </div>
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
                                    <th>Actions</th>
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
                                        <td>
                                            <div class="car-row-actions">
                                                <a class="table-action-link" href="manage_cars.php?edit=<?php echo h($car['id']); ?>">Edit</a>
                                                <form method="post" action="manage_cars.php" onsubmit="return confirm('Archive this car?');">
                                                    <?php echo csrfInput(); ?>
                                                    <input type="hidden" name="form_action" value="archive_car">
                                                    <input type="hidden" name="car_id" value="<?php echo h($car['id']); ?>">
                                                    <button class="table-action-button danger" type="submit">Archive</button>
                                                </form>
                                            </div>
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
