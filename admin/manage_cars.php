<?php
require_once __DIR__ . '/../includes/security.php';
require_once '../db_connect.php';
require_once __DIR__ . '/../util/car_image.php';
require_once __DIR__ . '/../util/car_archive.php';
require_once __DIR__ . '/../util/car_display.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/filter_bar.php';

startSecureSession();

// HTML-escapes a value for safe output.
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ' selected' attribute string when the two values match, else ''.
function selectedIf($currentValue, $optionValue): string
{
    return $currentValue === $optionValue ? ' selected' : '';
}

/**
 * Rebuild the list URL keeping search, filters and page intact.
 * Same contract as customerPageUrl() in customers.php.
 */
function carPageUrl(array $state, array $overrides = []): string
{
    $params = array_merge($state, $overrides);
    $params = array_filter(
        $params,
        static fn($value) => $value !== null && $value !== '' && $value !== 'all'
    );

    return $params ? 'manage_cars.php?' . http_build_query($params) : 'manage_cars.php';
}

requireAdminLogin();

$transmissions = ['Automatic', 'Manual'];
$fuelTypes = ['Petrol', 'Diesel', 'Hybrid', 'Electric'];
$statuses = ['available', 'unavailable', 'maintenance'];
$carTypes = CAR_TYPE_FALLBACK;

$search = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status_filter'] ?? 'all');
$typeFilter = trim($_GET['type'] ?? 'all');
$view = ($_GET['view'] ?? 'active') === 'archived' ? 'archived' : 'active';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$totalCars = 0;
$totalPages = 1;

$listState = [
    'q' => $search,
    'status_filter' => $statusFilter,
    'type' => $typeFilter,
    'view' => $view === 'archived' ? 'archived' : null,
    'page' => $page > 1 ? $page : null,
];

// Types come from the cars.car_type enum, so the form and the validation below
// can never drift from the schema and silently rewrite a car's type.
try {
    $carTypes = carTypeValues(getDbConnection());
} catch (mysqli_sql_exception $e) {
    $carTypes = CAR_TYPE_FALLBACK;
}

$error = '';
$successMessages = [
    'added' => 'Car added successfully.',
    'updated' => 'Car updated successfully.',
    'archived' => 'Car archived successfully.',
    'restored' => 'Car restored to the active fleet.',
];
$success = $successMessages[trim($_GET['success'] ?? '')] ?? '';
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
    blockDemoWrite('manage_cars.php');

    $formAction = trim($_POST['form_action'] ?? 'save_car');
    $postedCarId = filter_input(INPUT_POST, 'car_id', FILTER_VALIDATE_INT) ?: 0;

    if ($formAction === 'archive_car') {
        if ($postedCarId <= 0) {
            $error = 'Please choose a valid car to archive.';
        } else {
            try {
                $conn = getDbConnection();

                $archivedStatus = 'unavailable';
                $stmt = $conn->prepare(
                    'UPDATE cars
                     SET archived_at = NOW(), status = ?
                     WHERE id = ? AND archived_at IS NULL'
                );
                $stmt->bind_param('si', $archivedStatus, $postedCarId);
                $stmt->execute();

                if ($stmt->affected_rows > 0) {
                    header('Location: ' . carPageUrl($listState, ['success' => 'archived']));
                    exit;
                }

                $error = 'Could not archive that car. It may already be archived.';
            } catch (mysqli_sql_exception $e) {
                $error = 'Could not archive car. Please check the database connection and try again.';
            }
        }
    }

    if ($formAction === 'restore_car') {
        if ($postedCarId <= 0) {
            $error = 'Please choose a valid car to restore.';
        } else {
            try {
                $conn = getDbConnection();

                // Status stays as the admin last set it; only the archive flag lifts.
                $stmt = $conn->prepare(
                    'UPDATE cars
                     SET archived_at = NULL
                     WHERE id = ? AND archived_at IS NOT NULL'
                );
                $stmt->bind_param('i', $postedCarId);
                $stmt->execute();

                if ($stmt->affected_rows > 0) {
                    header('Location: ' . carPageUrl($listState, ['success' => 'restored']));
                    exit;
                }

                $error = 'Could not restore that car. It may already be active.';
            } catch (mysqli_sql_exception $e) {
                $error = 'Could not restore car. Please check the database connection and try again.';
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

    if ($formAction === 'archive_car' || $formAction === 'restore_car') {
        // Both were handled above.
    } elseif ($formAction !== 'add_car' && $formAction !== 'update_car') {
        $error = 'Please choose a valid car action.';
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

    $where = [$view === 'archived' ? 'archived_at IS NOT NULL' : 'archived_at IS NULL'];
    $params = [];
    $types = '';

    if ($search !== '') {
        $where[] = '(brand LIKE ? OR model LIKE ? OR plate_number LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'sss';
    }

    if (in_array($statusFilter, $statuses, true)) {
        $where[] = 'status = ?';
        $params[] = $statusFilter;
        $types .= 's';
    }

    if (in_array($typeFilter, $carTypes, true)) {
        $where[] = 'car_type = ?';
        $params[] = $typeFilter;
        $types .= 's';
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM cars $whereSql");
    if ($types !== '') {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $totalCars = (int) (($countStmt->get_result()->fetch_assoc())['total'] ?? 0);

    $totalPages = max(1, (int) ceil($totalCars / $perPage));
    $page = min($page, $totalPages);
    $listState['page'] = $page > 1 ? $page : null;
    $offset = ($page - 1) * $perPage;

    $listStmt = $conn->prepare(
        "SELECT id, brand, model, plate_number, car_type, transmission, fuel_type, seats, daily_rate, image, status, archived_at
         FROM cars
         $whereSql
         ORDER BY created_at DESC, id DESC
         LIMIT ? OFFSET ?"
    );
    $listParams = array_merge($params, [$perPage, $offset]);
    $listStmt->bind_param($types . 'ii', ...$listParams);
    $listStmt->execute();
    $cars = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
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
<?php
// The panel opens on ?edit= or ?add=, and stays open when a submit failed
// so the admin never loses what they typed.
$panelOpen = $isEditMode || isset($_GET['add']) || ($error !== '' && $_SERVER['REQUEST_METHOD'] === 'POST');
?>
<main class="dc-main">
    <header class="adm-head">
        <div>
            <div class="dc-mono-subtitle small" style="margin-bottom:8px">Inventory</div>
            <h1 class="dc-h1" style="font-size:32px;">Manage Cars</h1>
            <p class="dc-p" style="margin-top:8px;">
                <?php echo h($totalCars); ?> <?php echo $view === 'archived' ? 'archived' : 'active'; ?>
                <?php echo $totalCars === 1 ? 'car' : 'cars'; ?>.
            </p>
        </div>
        <a class="dc-btn-primary adm-add-btn" href="<?php echo h(carPageUrl($listState, ['add' => 1, 'edit' => null])); ?>">
            <span aria-hidden="true">+</span> Add car
        </a>
    </header>

    <?php if ($error !== ''): ?>
        <p class="message error" style="color: var(--stop); background: var(--stop-soft); padding: 12px; border-radius: 8px; font-weight: 600; margin-bottom:24px;"><?php echo h($error); ?></p>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <p class="message success" style="color: var(--go); background: var(--go-soft); padding: 12px; border-radius: 8px; font-weight: 600; margin-bottom:24px;"><?php echo h($success); ?></p>
    <?php endif; ?>

    <div class="dc-card adm-list-card">
        <div class="adm-filter-wrap">
            <?php renderAdminFilterBar([
                'action' => 'manage_cars.php',
                'search' => [
                    'name' => 'q',
                    'label' => 'Search fleet',
                    'value' => $search,
                    'placeholder' => 'Brand, model, or plate number',
                ],
                'inline_fields' => [
                    [
                        'type' => 'select',
                        'name' => 'view',
                        'label' => 'View',
                        'value' => $view,
                        'options' => ['active' => 'Active', 'archived' => 'Archived'],
                    ],
                    [
                        'type' => 'select',
                        'name' => 'status_filter',
                        'label' => 'Status',
                        'value' => $statusFilter,
                        'options' => array_merge(['all' => 'All'], array_combine($statuses, array_map('ucfirst', $statuses))),
                    ],
                    [
                        'type' => 'select',
                        'name' => 'type',
                        'label' => 'Type',
                        'value' => $typeFilter,
                        'options' => array_merge(['all' => 'All'], array_combine($carTypes, $carTypes)),
                    ],
                ],
                'submit_label' => 'Apply',
                'clear_label' => 'Reset',
                'clear_href' => 'manage_cars.php',
            ]); ?>
        </div>

        <?php if (count($cars) === 0): ?>
            <div class="adm-empty">
                <p class="dc-p" style="margin-bottom:4px; font-weight:650; color:var(--ink);">
                    <?php echo $view === 'archived' ? 'No archived cars.' : 'No cars match these filters.'; ?>
                </p>
                <p class="dc-p" style="font-size:14px;">
                    <?php if ($search !== '' || $statusFilter !== 'all' || $typeFilter !== 'all'): ?>
                        <a class="adm-link" href="manage_cars.php">Clear the filters</a> to see the whole fleet.
                    <?php elseif ($view !== 'archived'): ?>
                        Use <strong>Add car</strong> to put the first vehicle on the road.
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <div class="adm-tbl-scroll">
                <table class="adm-tbl">
                    <thead>
                        <tr>
                            <th>Vehicle</th>
                            <th>Plate</th>
                            <th>Type</th>
                            <th style="text-align:right;">Rate</th>
                            <th>Status</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cars as $car): ?>
                            <tr>
                                <td>
                                    <div class="mc-vehicle">
                                        <?php echo carImageTag(
                                            $car['image'],
                                            '',
                                            '64px',
                                            ['loading' => 'lazy', 'class' => 'mc-thumb']
                                        ); ?>
                                        <div style="min-width:0;">
                                            <strong class="mc-vehicle-name"><?php echo h($car['brand'] . ' ' . $car['model']); ?></strong>
                                            <div class="mc-vehicle-spec"><?php echo h($car['transmission'] . ' · ' . $car['fuel_type'] . ' · ' . $car['seats'] . ' seats'); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="mc-plate"><?php echo h($car['plate_number']); ?></td>
                                <td class="adm-muted"><?php echo h($car['car_type']); ?></td>
                                <td class="mc-rate">RM <?php echo h(number_format((float) $car['daily_rate'], 2)); ?></td>
                                <td>
                                    <span class="dc-badge status-<?php echo h(strtolower($car['status'])); ?>"><?php echo h(ucfirst($car['status'])); ?></span>
                                </td>
                                <td>
                                    <div class="adm-actions">
                                        <?php if ($view === 'archived'): ?>
                                            <form method="post" action="<?php echo h(carPageUrl($listState)); ?>" style="display:inline;">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="form_action" value="restore_car">
                                                <input type="hidden" name="car_id" value="<?php echo h($car['id']); ?>">
                                                <button type="submit" class="adm-action">Restore</button>
                                            </form>
                                        <?php else: ?>
                                            <a class="adm-action" href="<?php echo h(carPageUrl($listState, ['edit' => $car['id'], 'add' => null])); ?>">Edit</a>
                                            <form method="post" action="<?php echo h(carPageUrl($listState)); ?>" onsubmit="return confirm('Archive this car? It moves to the Archived view and can be restored.');" style="display:inline;">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="form_action" value="archive_car">
                                                <input type="hidden" name="car_id" value="<?php echo h($car['id']); ?>">
                                                <button type="submit" class="adm-action is-danger">Archive</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="adm-pager">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo h(carPageUrl($listState, ['page' => $page - 1])); ?>" class="dc-btn-secondary" style="background:var(--surface); text-decoration:none;">Previous</a>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>
                    <span style="font-size:14px; font-weight:600; color:var(--ink-2);">Page <?php echo h($page); ?> of <?php echo h($totalPages); ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a href="<?php echo h(carPageUrl($listState, ['page' => $page + 1])); ?>" class="dc-btn-secondary" style="background:var(--surface); text-decoration:none;">Next</a>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Add / edit panel -->
    <div class="adm-panel-backdrop<?php echo $panelOpen ? ' is-open' : ''; ?>" id="carPanel">
        <a class="adm-panel-dismiss" href="<?php echo h(carPageUrl($listState)); ?>" aria-label="Close the car form"></a>
        <section class="adm-panel" role="dialog" aria-modal="true" aria-labelledby="carPanelTitle">
            <header class="adm-panel-head">
                <div>
                    <div class="dc-mono-subtitle small" style="margin-bottom:6px"><?php echo $isEditMode ? 'Edit' : 'New vehicle'; ?></div>
                    <h2 class="dc-h2" style="font-size:20px;" id="carPanelTitle"><?php echo $isEditMode ? 'Update car' : 'Add car'; ?></h2>
                </div>
                <a class="adm-panel-close" href="<?php echo h(carPageUrl($listState)); ?>" aria-label="Close">&times;</a>
            </header>

            <div class="adm-panel-body">
            <form method="post" action="<?php echo h(carPageUrl($listState, $isEditMode ? ['edit' => $editCarId] : ['add' => 1])); ?>" enctype="multipart/form-data">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="form_action" value="<?php echo $isEditMode ? 'update_car' : 'add_car'; ?>">
                <input type="hidden" name="car_id" value="<?php echo h($editCarId); ?>">

                <div class="adm-field-row">
                    <label class="adm-field">
                        <span class="adm-field-label">Brand</span>
                        <input type="text" name="brand" value="<?php echo h($brand); ?>" maxlength="100" required class="dc-input" style="width:100%;">
                    </label>
                    <label class="adm-field">
                        <span class="adm-field-label">Model</span>
                        <input type="text" name="model" value="<?php echo h($model); ?>" maxlength="100" required class="dc-input" style="width:100%;">
                    </label>
                </div>

                <div class="adm-field-row">
                    <label class="adm-field">
                        <span class="adm-field-label">Plate Number</span>
                        <input type="text" name="plate_number" value="<?php echo h($plateNumber); ?>" maxlength="30" required class="dc-input" style="width:100%;">
                    </label>
                    <label class="adm-field">
                        <span class="adm-field-label">Car Type</span>
                        <select name="car_type" required class="dc-input" style="width:100%;">
                            <?php foreach (carTypeOptions($carTypes, $carType) as $type): ?>
                                <option value="<?php echo h($type); ?>"<?php echo selectedIf($carType, $type); ?>><?php echo h($type); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div class="adm-field-row">
                    <label class="adm-field">
                        <span class="adm-field-label">Transmission</span>
                        <select name="transmission" required class="dc-input" style="width:100%;">
                            <?php foreach ($transmissions as $option): ?>
                                <option value="<?php echo h($option); ?>"<?php echo selectedIf($transmission, $option); ?>><?php echo h($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="adm-field">
                        <span class="adm-field-label">Fuel Type</span>
                        <select name="fuel_type" required class="dc-input" style="width:100%;">
                            <?php foreach ($fuelTypes as $option): ?>
                                <option value="<?php echo h($option); ?>"<?php echo selectedIf($fuelType, $option); ?>><?php echo h($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div class="adm-field-row">
                    <label class="adm-field">
                        <span class="adm-field-label">Seats</span>
                        <input type="number" name="seats" value="<?php echo h($seats); ?>" min="1" required class="dc-input" style="width:100%;">
                    </label>
                    <label class="adm-field">
                        <span class="adm-field-label">Daily Rate (RM)</span>
                        <input type="number" name="daily_rate" value="<?php echo h($dailyRate); ?>" min="0.01" step="0.01" required class="dc-input" style="width:100%;">
                    </label>
                </div>

                <div class="adm-field-stack">
                    <label class="adm-field">
                        <span class="adm-field-label">Status</span>
                        <select name="status" required class="dc-input" style="width:100%;">
                            <?php foreach ($statuses as $option): ?>
                                <option value="<?php echo h($option); ?>"<?php echo selectedIf($status, $option); ?>><?php echo h(ucfirst($option)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="adm-field">
                        <span class="adm-field-label">Car Image</span>
                        <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo CAR_IMAGE_MAX_BYTES; ?>">
                        <input type="file" name="image" accept="image/*" class="dc-input" style="width:100%; padding:10px;">
                        <?php if ($isEditMode && $image !== ''): ?>
                            <span style="display:block; font-size:12px; color:var(--ink-2); margin-top:4px;">Leave blank to keep the current image.</span>
                        <?php endif; ?>
                    </label>
                </div>

                <div class="adm-panel-actions">
                    <button type="submit" class="dc-btn-primary" style="width:100%; justify-content:center;"><?php echo $isEditMode ? 'Update car' : 'Add car'; ?></button>
                    <a class="adm-panel-cancel" href="<?php echo h(carPageUrl($listState)); ?>">Cancel</a>
                </div>
            </form>
            </div>
        </section>
    </div>
</main>
<?php include '../includes/layout_bottom.php'; ?>
