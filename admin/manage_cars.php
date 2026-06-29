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
<?php
$pageTitle = 'Manage CarGo Cars';
include '../includes/layout_top.php';
include 'header.php';
?>
<main class="dc-main">
    <header class="dc-h2-title" style="margin-bottom: 24px;">
        <div>
            <div class="dc-mono-subtitle small" style="margin-bottom:8px">Inventory</div>
            <h1 class="dc-h1" style="font-size:32px;">Manage Cars</h1>
            <p class="dc-p" style="margin-top:8px;">Add and update vehicles that customers can rent.</p>
        </div>
    </header>

    <?php if ($error !== ''): ?>
        <p class="message error" style="color: #c23a52; background: #fbeaed; padding: 12px; border-radius: 8px; font-weight: 600; margin-bottom:24px;"><?php echo h($error); ?></p>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <p class="message success" style="color: #0b7a5a; background: #e6f6f1; padding: 12px; border-radius: 8px; font-weight: 600; margin-bottom:24px;"><?php echo h($success); ?></p>
    <?php endif; ?>

    <div style="display:grid; grid-template-columns: minmax(320px, 450px) 1fr; gap:24px; align-items:start;">
        <div class="dc-card padded" style="position:sticky; top:24px;">
            <h2 class="dc-h2" style="font-size:20px; margin-bottom:8px;"><?php echo $isEditMode ? 'Update Car' : 'Add New Car'; ?></h2>
            <p class="dc-p" style="margin-bottom:24px; font-size:14px;"><?php echo $isEditMode ? 'Update this vehicle without creating a duplicate.' : 'Enter details for a new vehicle.'; ?></p>

            <form method="post" action="manage_cars.php" enctype="multipart/form-data">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="form_action" value="<?php echo $isEditMode ? 'update_car' : 'add_car'; ?>">
                <input type="hidden" name="car_id" value="<?php echo h($editCarId); ?>">

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
                    <label style="display:block;">
                        <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Brand</span>
                        <input type="text" name="brand" value="<?php echo h($brand); ?>" maxlength="100" required class="dc-input" style="width:100%;">
                    </label>
                    <label style="display:block;">
                        <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Model</span>
                        <input type="text" name="model" value="<?php echo h($model); ?>" maxlength="100" required class="dc-input" style="width:100%;">
                    </label>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
                    <label style="display:block;">
                        <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Plate Number</span>
                        <input type="text" name="plate_number" value="<?php echo h($plateNumber); ?>" maxlength="30" required class="dc-input" style="width:100%;">
                    </label>
                    <label style="display:block;">
                        <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Car Type</span>
                        <select name="car_type" required class="dc-input" style="width:100%;">
                            <?php foreach ($carTypes as $type): ?>
                                <option value="<?php echo h($type); ?>"<?php echo selectedIf($carType, $type); ?>><?php echo h($type); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
                    <label style="display:block;">
                        <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Transmission</span>
                        <select name="transmission" required class="dc-input" style="width:100%;">
                            <?php foreach ($transmissions as $option): ?>
                                <option value="<?php echo h($option); ?>"<?php echo selectedIf($transmission, $option); ?>><?php echo h($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label style="display:block;">
                        <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Fuel Type</span>
                        <select name="fuel_type" required class="dc-input" style="width:100%;">
                            <?php foreach ($fuelTypes as $option): ?>
                                <option value="<?php echo h($option); ?>"<?php echo selectedIf($fuelType, $option); ?>><?php echo h($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
                    <label style="display:block;">
                        <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Seats</span>
                        <input type="number" name="seats" value="<?php echo h($seats); ?>" min="1" required class="dc-input" style="width:100%;">
                    </label>
                    <label style="display:block;">
                        <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Daily Rate (RM)</span>
                        <input type="number" name="daily_rate" value="<?php echo h($dailyRate); ?>" min="0.01" step="0.01" required class="dc-input" style="width:100%;">
                    </label>
                </div>

                <div style="display:flex; flex-direction:column; gap:16px; margin-bottom:24px;">
                    <label style="display:block;">
                        <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Status</span>
                        <select name="status" required class="dc-input" style="width:100%;">
                            <?php foreach ($statuses as $option): ?>
                                <option value="<?php echo h($option); ?>"<?php echo selectedIf($status, $option); ?>><?php echo h(ucfirst($option)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label style="display:block;">
                        <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Car Image</span>
                        <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo CAR_IMAGE_MAX_BYTES; ?>">
                        <input type="file" name="image" accept="image/*" class="dc-input" style="width:100%; padding:10px;">
                        <?php if ($isEditMode && $image !== ''): ?>
                            <span style="display:block; font-size:12px; color:#5b6273; margin-top:4px;">Leave blank to keep the current image.</span>
                        <?php endif; ?>
                    </label>
                </div>

                <div style="display:flex; flex-direction:column; gap:12px;">
                    <button type="submit" class="dc-btn-primary" style="width:100%; justify-content:center;"><?php echo $isEditMode ? 'Update Car' : 'Add Car'; ?></button>
                    <?php if ($isEditMode): ?>
                        <a href="manage_cars.php" style="color:#5b6273; font-weight:600; font-size:14px; text-decoration:none; text-align:center;">Cancel Edit</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="dc-card">
            <div style="padding:24px; border-bottom:1px solid #e4e8f1;">
                <div class="dc-mono-subtitle small" style="margin-bottom:8px">Fleet</div>
                <h2 class="dc-h2" style="font-size:20px;">Recent Cars</h2>
            </div>
            
            <?php if (count($cars) === 0): ?>
                <div style="padding: 40px 24px; text-align: center; color: #5b6273;">
                    <p>No cars added yet.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="dc-table" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="border-bottom: 1px solid #e4e8f1; background: #f9fafc;">
                                <th style="padding: 16px 24px; text-align: left; font-size: 13px; color: #5b6273; font-weight: 600;">Vehicle</th>
                                <th style="padding: 16px 24px; text-align: left; font-size: 13px; color: #5b6273; font-weight: 600;">Plate</th>
                                <th style="padding: 16px 24px; text-align: left; font-size: 13px; color: #5b6273; font-weight: 600;">Type</th>
                                <th style="padding: 16px 24px; text-align: left; font-size: 13px; color: #5b6273; font-weight: 600;">Rate</th>
                                <th style="padding: 16px 24px; text-align: left; font-size: 13px; color: #5b6273; font-weight: 600;">Status</th>
                                <th style="padding: 16px 24px; text-align: right; font-size: 13px; color: #5b6273; font-weight: 600;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cars as $car): ?>
                                <tr style="border-bottom: 1px solid #e4e8f1;">
                                    <td style="padding: 16px 24px;">
                                        <strong style="color: #131722; display:block; margin-bottom:4px;"><?php echo h($car['brand'] . ' ' . $car['model']); ?></strong>
                                        <div style="color: #5b6273; font-size:13px;"><?php echo h($car['transmission'] . ' / ' . $car['fuel_type'] . ' / ' . $car['seats'] . ' seats'); ?></div>
                                    </td>
                                    <td style="padding: 16px 24px; color:#131722; font-size:14px; font-weight:600;">
                                        <?php echo h($car['plate_number']); ?>
                                    </td>
                                    <td style="padding: 16px 24px; color:#5b6273; font-size:14px;">
                                        <?php echo h($car['car_type']); ?>
                                    </td>
                                    <td style="padding: 16px 24px; color:#131722; font-size:14px; font-weight:600;">
                                        RM <?php echo h(number_format((float) $car['daily_rate'], 2)); ?>
                                    </td>
                                    <td style="padding: 16px 24px;">
                                        <?php 
                                            $cStatus = $car['status'];
                                            $cColor = $cStatus === 'available' ? '#0b7a5a' : ($cStatus === 'unavailable' ? '#c23a52' : '#b25e09');
                                            $cBg = $cStatus === 'available' ? '#e6f6f1' : ($cStatus === 'unavailable' ? '#fbeaed' : '#fff3e0');
                                        ?>
                                        <span class="dc-badge" style="background:<?php echo $cBg; ?>; color:<?php echo $cColor; ?>;">
                                            <?php echo h(ucfirst($cStatus)); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 16px 24px; text-align: right;">
                                        <div style="display:inline-flex; gap:12px;">
                                            <a href="manage_cars.php?edit=<?php echo h($car['id']); ?>" style="color:#3b5fda; font-size:13px; font-weight:600; text-decoration:none;">Edit</a>
                                            <form method="post" action="manage_cars.php" onsubmit="return confirm('Archive this car?');" style="display:inline;">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="form_action" value="archive_car">
                                                <input type="hidden" name="car_id" value="<?php echo h($car['id']); ?>">
                                                <button type="submit" style="background:none; border:none; color:#c23a52; font-size:13px; font-weight:600; cursor:pointer; padding:0; font-family:inherit;">Archive</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>
<?php include '../includes/layout_bottom.php'; ?>
