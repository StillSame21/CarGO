<?php
require_once __DIR__ . '/../includes/security.php';
require_once '../db_connect.php';
require_once __DIR__ . '/../util/payment.php';
require_once __DIR__ . '/../util/booking.php';
require_once __DIR__ . '/../util/car_display.php';
require_once __DIR__ . '/includes/filter_bar.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatAdminDate(?string $date): string
{
    if ($date === null || $date === '') {
        return 'Not set';
    }

    return date('d M Y', strtotime($date));
}

function bindStatementParams(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '') {
        return;
    }

    $refs = [];
    foreach ($params as $key => $value) {
        $refs[$key] = $value;
    }

    $bindValues = [$types];
    foreach ($refs as $key => &$value) {
        $bindValues[] = &$value;
    }

    call_user_func_array([$stmt, 'bind_param'], $bindValues);
}

function isAdminDateFilter(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }

    $parts = explode('-', $value);

    return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
}

function adminBookingStatusValues(mysqli $conn): array
{
    $stmt = $conn->prepare(
        'SELECT COLUMN_TYPE
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1'
    );
    $table = 'bookings';
    $column = 'booking_status';
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $columnType = (string) ($row['COLUMN_TYPE'] ?? '');

    if (!preg_match("/^enum\((.*)\)$/", $columnType, $matches)) {
        return ['pending', 'approved', 'rejected', 'ongoing'];
    }

    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $matches[1], $enumMatches);
    $statuses = array_map(
        static fn($value) => stripcslashes($value),
        $enumMatches[1] ?? []
    );

    return $statuses ?: ['pending', 'approved', 'rejected', 'ongoing'];
}

function bookingListUrl(array $state, array $overrides = []): string
{
    $params = array_merge($state, $overrides);

    foreach ($params as $key => $value) {
        if ($value === '' || $value === null || ($key === 'page' && (int) $value <= 1)) {
            unset($params[$key]);
        }
    }

    $query = http_build_query($params);

    return 'bookings.php' . ($query !== '' ? '?' . $query : '');
}

function buildAdminBookingFilters(array $filters): array
{
    $where = [];
    $types = '';
    $params = [];

    if ($filters['q'] !== '') {
        $searchTerm = '%' . $filters['q'] . '%';
        $where[] = '(
            CAST(b.id AS CHAR) LIKE ?
            OR c.name LIKE ?
            OR c.email LIKE ?
            OR car.brand LIKE ?
            OR car.model LIKE ?
            OR car.plate_number LIKE ?
            OR b.pickup_location LIKE ?
        )';
        $types .= 'sssssss';
        array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
    }

    if ($filters['status'] !== 'all') {
        $where[] = 'b.booking_status = ?';
        $types .= 's';
        $params[] = $filters['status'];
    }

    foreach ([
        'pickup_from' => ['b.pickup_date >= ?', 's'],
        'pickup_to' => ['b.pickup_date <= ?', 's'],
        'return_from' => ['b.return_date >= ?', 's'],
        'return_to' => ['b.return_date <= ?', 's'],
        'min_amount' => ['b.total_amount >= ?', 'd'],
        'max_amount' => ['b.total_amount <= ?', 'd'],
    ] as $key => [$condition, $type]) {
        if ($filters[$key] !== '') {
            $where[] = $condition;
            $types .= $type;
            $params[] = $type === 'd' ? (float) $filters[$key] : $filters[$key];
        }
    }

    return [
        'where_sql' => $where ? ' WHERE ' . implode(' AND ', $where) : '',
        'types' => $types,
        'params' => $params,
    ];
}

function loadAdminBookings(mysqli $conn, array $filters, string $sort, int $limit, int $offset): array
{
    $sortOptions = [
        'newest' => 'b.created_at DESC, b.id DESC',
        'oldest' => 'b.created_at ASC, b.id ASC',
        'pickup_asc' => 'b.pickup_date ASC, b.id ASC',
        'pickup_desc' => 'b.pickup_date DESC, b.id DESC',
        'amount_asc' => 'b.total_amount ASC, b.id ASC',
        'amount_desc' => 'b.total_amount DESC, b.id DESC',
    ];
    $filterSql = buildAdminBookingFilters($filters);
    $orderBy = $sortOptions[$sort] ?? $sortOptions['newest'];
    $types = $filterSql['types'] . 'ii';
    $params = array_merge($filterSql['params'], [$limit, $offset]);
    $stmt = $conn->prepare(
        'SELECT
            b.id,
            b.pickup_date,
            b.return_date,
            b.total_amount,
            b.booking_status,
            c.name AS customer_name,
            c.email AS customer_email,
            car.brand,
            car.model,
            car.plate_number,
            p.payment_status,
            COALESCE(fees.total_late_fee, 0) AS total_late_fee
         FROM bookings b
         LEFT JOIN customers c ON c.id = b.customer_id
         LEFT JOIN cars car ON car.id = b.car_id
         LEFT JOIN payments p ON p.booking_id = b.id
         LEFT JOIN (
            SELECT booking_id, SUM(late_fee_amount) AS total_late_fee
            FROM late_fees
            GROUP BY booking_id
         ) fees ON fees.booking_id = b.id
         ' . $filterSql['where_sql'] . '
         ORDER BY ' . $orderBy . '
         LIMIT ? OFFSET ?'
    );
    bindStatementParams($stmt, $types, $params);
    $stmt->execute();

    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function countAdminBookings(mysqli $conn, array $filters): int
{
    $filterSql = buildAdminBookingFilters($filters);
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM bookings b
         LEFT JOIN customers c ON c.id = b.customer_id
         LEFT JOIN cars car ON car.id = b.car_id
         ' . $filterSql['where_sql']
    );
    bindStatementParams($stmt, $filterSql['types'], $filterSql['params']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return (int) ($row['total'] ?? 0);
}

function loadAdminBooking(mysqli $conn, int $bookingId): ?array
{
    $stmt = $conn->prepare(
        'SELECT
            b.id,
            b.customer_id,
            b.car_id,
            b.handled_by_admin_id,
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
            car.image,
            car.status AS car_status,
            p.amount AS payment_amount,
            p.payment_method,
            p.payment_status,
            p.payment_date,
            COALESCE(fees.total_late_days, 0) AS total_late_days,
            COALESCE(fees.total_late_fee, 0) AS total_late_fee
         FROM bookings b
         INNER JOIN customers c ON c.id = b.customer_id
         INNER JOIN cars car ON car.id = b.car_id
         LEFT JOIN payments p ON p.booking_id = b.id
         LEFT JOIN (
            SELECT
                booking_id,
                SUM(late_days) AS total_late_days,
                SUM(late_fee_amount) AS total_late_fee
            FROM late_fees
            GROUP BY booking_id
         ) fees ON fees.booking_id = b.id
         WHERE b.id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();

    $booking = $stmt->get_result()->fetch_assoc();

    return $booking ?: null;
}

requireAdminLogin();

$bookingId = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$booking = null;
$bookings = [];
$error = '';
$success = '';
$today = date('Y-m-d');
$bookingStatuses = ['pending', 'approved', 'rejected', 'ongoing'];
$bookingFilters = [
    'q' => trim($_GET['q'] ?? ''),
    'status' => trim($_GET['status'] ?? 'all'),
    'pickup_from' => trim($_GET['pickup_from'] ?? ''),
    'pickup_to' => trim($_GET['pickup_to'] ?? ''),
    'return_from' => trim($_GET['return_from'] ?? ''),
    'return_to' => trim($_GET['return_to'] ?? ''),
    'min_amount' => trim($_GET['min_amount'] ?? ''),
    'max_amount' => trim($_GET['max_amount'] ?? ''),
];
$bookingSort = trim($_GET['sort'] ?? 'newest');
$bookingPage = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$bookingPage = max(1, $bookingPage);
$bookingsPerPage = 10;
$totalBookings = 0;
$totalBookingPages = 1;
$bookingListState = [];
$sortOptions = [
    'newest' => 'Newest first',
    'oldest' => 'Oldest first',
    'pickup_asc' => 'Pickup date ascending',
    'pickup_desc' => 'Pickup date descending',
    'amount_asc' => 'Amount low to high',
    'amount_desc' => 'Amount high to low',
];

try {
    $conn = getDbConnection();
    $bookingStatuses = adminBookingStatusValues($conn);
    $bookingFilters['status'] = in_array($bookingFilters['status'], array_merge(['all'], $bookingStatuses), true)
        ? $bookingFilters['status']
        : 'all';

    foreach (['pickup_from', 'pickup_to', 'return_from', 'return_to'] as $dateFilter) {
        if ($bookingFilters[$dateFilter] !== '' && !isAdminDateFilter($bookingFilters[$dateFilter])) {
            $bookingFilters[$dateFilter] = '';
        }
    }

    foreach (['min_amount', 'max_amount'] as $amountFilter) {
        if ($bookingFilters[$amountFilter] !== '' && (!is_numeric($bookingFilters[$amountFilter]) || (float) $bookingFilters[$amountFilter] < 0)) {
            $bookingFilters[$amountFilter] = '';
        }
    }

    $bookingSort = isset($sortOptions[$bookingSort]) ? $bookingSort : 'newest';
    $bookingListState = array_merge($bookingFilters, [
        'status' => $bookingFilters['status'] === 'all' ? '' : $bookingFilters['status'],
        'sort' => $bookingSort === 'newest' ? '' : $bookingSort,
        'page' => $bookingPage,
    ]);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $bookingId && $adminId > 0) {
        requireValidCsrfToken();

        $action = $_POST['action'] ?? '';

        if ($action === 'confirm_pickup') {
            confirmBookingPickup($conn, $bookingId, $adminId);
            $success = 'Pickup confirmed. Booking is now ongoing.';
        } elseif ($action === 'confirm_return') {
            $bookingForReturn = loadAdminBooking($conn, $bookingId);

            if (!$bookingForReturn) {
                throw new RuntimeException('Booking not found.');
            }

            $actualReturnDate = trim($_POST['actual_return_date'] ?? $today);
            $returnResult = confirmBookingReturn($conn, $bookingForReturn, $adminId, $actualReturnDate);

            if ($returnResult['late_days'] > 0) {
                $success = 'Return confirmed with late fees.';
            } else {
                $success = 'Return confirmed. No late fees were added.';
            }
        }
    }

    if ($bookingId) {
        $booking = loadAdminBooking($conn, $bookingId);

        if (!$booking) {
            $error = 'Booking not found.';
        }
    } else {
        $totalBookings = countAdminBookings($conn, $bookingFilters);
        $totalBookingPages = max(1, (int) ceil($totalBookings / $bookingsPerPage));
        $bookingPage = min($bookingPage, $totalBookingPages);
        $bookingListState['page'] = $bookingPage;
        $bookingOffset = ($bookingPage - 1) * $bookingsPerPage;
        $bookings = loadAdminBookings($conn, $bookingFilters, $bookingSort, $bookingsPerPage, $bookingOffset);
    }
} catch (InvalidArgumentException | RuntimeException $e) {
    $error = $e->getMessage();

    if ($bookingId) {
        try {
            $booking = loadAdminBooking($conn, $bookingId);
        } catch (mysqli_sql_exception $ignored) {
            $booking = null;
        }
    }
} catch (mysqli_sql_exception $e) {
    $error = 'Could not load or update bookings. Please confirm the database schema is ready.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Bookings | CarGo</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body>
    <main class="dashboard-page">
        <header class="dashboard-header">
            <?php include 'header.php'; ?>
        </header>

        <section class="dashboard-content">
            <div class="dashboard-shell">
                <?php if ($booking): ?>
                    <?php $displayStatus = bookingDisplayStatus((string) $booking['booking_status'], $booking['payment_status'] ?? null, (float) $booking['total_late_fee']); ?>
                    <?php $isPaid = ($booking['payment_status'] ?? '') === PAYMENT_STATUS_PAID; ?>
                    <?php $paymentBreakdown = buildPaymentBreakdown((float) $booking['total_amount'], (float) $booking['total_late_fee']); ?>
                    <?php $amountDue = calculatePaymentAmountDue($paymentBreakdown['payable_total'], $booking['payment_status'] ?? null, isset($booking['payment_amount']) ? (float) $booking['payment_amount'] : null); ?>

                    <a class="back-link" href="<?php echo h(bookingListUrl($bookingListState)); ?>">Back to bookings</a>

                    <?php if ($error !== ''): ?>
                        <p class="message error"><?php echo h($error); ?></p>
                    <?php endif; ?>

                    <?php if ($success !== ''): ?>
                        <p class="message success"><?php echo h($success); ?></p>
                    <?php endif; ?>

                    <section class="admin-booking-detail">
                        <header class="admin-booking-hero">
                            <div class="admin-booking-hero-copy">
                                <p class="eyebrow">Booking #<?php echo h($booking['id']); ?></p>
                                <h1><?php echo h($booking['brand'] . ' ' . $booking['model']); ?></h1>
                                <p><?php echo h(formatAdminDate($booking['pickup_date']) . ' to ' . formatAdminDate($booking['return_date'])); ?></p>

                                <div class="admin-booking-summary-meta" aria-label="Booking summary">
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

                            <div class="admin-booking-hero-side">
                                <span class="status-pill <?php echo h($displayStatus['class']); ?>"><?php echo h($displayStatus['label']); ?></span>
                                <img
                                    src="<?php echo h(carImageUrl($booking['image'], 'https://images.unsplash.com/photo-1549317661-bd32c8ce0db2?auto=format&fit=crop&w=700&q=80')); ?>"
                                    alt="<?php echo h($booking['brand'] . ' ' . $booking['model']); ?>"
                                >
                            </div>
                        </header>

                        <div class="admin-booking-main-flow">
                            <div class="admin-booking-flow-column">
                                <section class="admin-detail-panel admin-trip-panel">
                                    <div class="admin-section-heading">
                                        <p class="eyebrow">Trip</p>
                                        <h2>Rental timeline</h2>
                                    </div>

                                    <div class="admin-timeline-list">
                                        <div class="admin-timeline-item">
                                            <span class="admin-timeline-dot"></span>
                                            <div>
                                                <p>Pickup</p>
                                                <strong><?php echo h(formatAdminDate($booking['pickup_date'])); ?></strong>
                                                <small><?php echo h($booking['pickup_location']); ?></small>
                                            </div>
                                        </div>

                                        <div class="admin-timeline-item">
                                            <span class="admin-timeline-dot"></span>
                                            <div>
                                                <p>Expected Return</p>
                                                <strong><?php echo h(formatAdminDate($booking['return_date'])); ?></strong>
                                                <small><?php echo h($booking['pickup_location']); ?></small>
                                            </div>
                                        </div>

                                        <?php if ($booking['actual_return_date']): ?>
                                            <div class="admin-timeline-item">
                                                <span class="admin-timeline-dot"></span>
                                                <div>
                                                    <p>Actual Return</p>
                                                    <strong><?php echo h(formatAdminDate($booking['actual_return_date'])); ?></strong>
                                                    <small><?php echo h($booking['total_late_days'] > 0 ? $booking['total_late_days'] . ' late day(s)' : 'Returned on time'); ?></small>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </section>

                                <div class="admin-paired-info-grid">
                                    <section class="admin-detail-panel">
                                        <div class="admin-section-heading">
                                            <p class="eyebrow">Customer</p>
                                            <h2>Customer information</h2>
                                        </div>
                                        <dl class="admin-detail-list">
                                            <div><dt>Name</dt><dd><?php echo h($booking['customer_name']); ?></dd></div>
                                            <div><dt>Email</dt><dd><?php echo h($booking['customer_email']); ?></dd></div>
                                            <div><dt>Phone</dt><dd><?php echo h($booking['customer_phone'] ?: 'Not provided'); ?></dd></div>
                                            <div><dt>Address</dt><dd><?php echo h($booking['customer_address'] ?: 'Not provided'); ?></dd></div>
                                        </dl>
                                    </section>

                                    <section class="admin-detail-panel">
                                        <div class="admin-section-heading">
                                            <p class="eyebrow">Vehicle</p>
                                            <h2>Car information</h2>
                                        </div>
                                        <dl class="admin-detail-list">
                                            <div><dt>Vehicle</dt><dd><?php echo h($booking['brand'] . ' ' . $booking['model']); ?></dd></div>
                                            <div><dt>Plate</dt><dd><?php echo h($booking['plate_number']); ?></dd></div>
                                            <div><dt>Type</dt><dd><?php echo h($booking['car_type']); ?></dd></div>
                                            <div><dt>Specs</dt><dd><?php echo h($booking['transmission'] . ' / ' . $booking['fuel_type'] . ' / ' . $booking['seats'] . ' seats'); ?></dd></div>
                                        </dl>
                                    </section>
                                </div>
                            </div>

                            <aside class="admin-detail-panel admin-payment-sidebar">
                                <div class="admin-section-heading">
                                    <p class="eyebrow">Payment</p>
                                    <h2>Payment details</h2>
                                </div>

                                <dl class="admin-payment-summary">
                                    <div><dt>Daily Rate</dt><dd>RM <?php echo h(number_format((float) $booking['daily_rate'], 2)); ?></dd></div>
                                    <div><dt>Total Days</dt><dd><?php echo h($booking['total_days']); ?></dd></div>
                                    <div><dt>Payment Status</dt><dd><?php echo h(ucfirst($booking['payment_status'] ?: 'unpaid')); ?></dd></div>
                                    <div><dt>Method</dt><dd><?php echo h($booking['payment_method'] ?: 'Not paid'); ?></dd></div>
                                    <div><dt>Payment Date</dt><dd><?php echo h(formatAdminDate($booking['payment_date'])); ?></dd></div>
                                    <?php if ((float) $booking['total_late_fee'] > 0): ?>
                                        <div><dt>Late Days</dt><dd><?php echo h($booking['total_late_days']); ?></dd></div>
                                    <?php endif; ?>
                                    <div class="admin-total-row admin-payment-total-card">
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

                                <div class="admin-sidebar-actions">
                                    <div class="admin-section-heading compact">
                                        <p class="eyebrow">Admin</p>
                                        <h2>Booking actions</h2>
                                    </div>

                                    <?php if ($isPaid && !in_array($booking['booking_status'], [BOOKING_STATUS_ONGOING, BOOKING_STATUS_COMPLETED, 'cancelled', 'rejected'], true)): ?>
                                        <form method="post" action="bookings.php?id=<?php echo h($booking['id']); ?>">
                                            <?php echo csrfInput(); ?>
                                            <input type="hidden" name="action" value="confirm_pickup">
                                            <input type="hidden" name="booking_id" value="<?php echo h($booking['id']); ?>">
                                            <button type="submit">Confirm Pickup</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($booking['booking_status'] === BOOKING_STATUS_ONGOING): ?>
                                        <form method="post" action="bookings.php?id=<?php echo h($booking['id']); ?>" class="return-form">
                                            <?php echo csrfInput(); ?>
                                            <input type="hidden" name="action" value="confirm_return">
                                            <input type="hidden" name="booking_id" value="<?php echo h($booking['id']); ?>">
                                            <label for="actual_return_date">
                                                Actual Return Date
                                                <input type="date" id="actual_return_date" name="actual_return_date" value="<?php echo h($today); ?>" required>
                                            </label>
                                            <button type="submit">Confirm Return</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (!$isPaid): ?>
                                        <p class="admin-action-note">Pickup can be confirmed only after payment is paid.</p>
                                    <?php elseif ($booking['booking_status'] === BOOKING_STATUS_COMPLETED): ?>
                                        <p class="admin-action-note">This booking has been returned and completed.</p>
                                    <?php elseif (in_array($booking['booking_status'], ['cancelled', 'rejected'], true)): ?>
                                        <p class="admin-action-note">No pickup or return actions are available for this booking.</p>
                                    <?php endif; ?>
                                </div>
                            </aside>
                        </div>
                    </section>
                <?php else: ?>
                    <header class="page-heading">
                        <div>
                            <p class="eyebrow">Bookings</p>
                            <h1>Manage bookings</h1>
                        </div>
                    </header>

                    <?php if ($error !== ''): ?>
                        <p class="message error"><?php echo h($error); ?></p>
                    <?php endif; ?>

                    <section class="cars-list-panel admin-bookings-panel">
                        <?php renderAdminFilterBar([
                            'action' => 'bookings.php',
                            'search' => [
                                'name' => 'q',
                                'label' => 'Search bookings',
                                'value' => $bookingFilters['q'],
                                'placeholder' => 'Booking ID, customer, car, plate, or pickup location',
                            ],
                            'modal_id' => 'booking-filter-modal',
                            'modal_title' => 'Filter bookings',
                            'modal_fields' => [
                                [
                                    'type' => 'select',
                                    'name' => 'status',
                                    'label' => 'Status',
                                    'value' => $bookingFilters['status'],
                                    'options' => array_merge(['all' => 'All'], array_combine($bookingStatuses, array_map('ucfirst', $bookingStatuses))),
                                ],
                                ['type' => 'date', 'name' => 'pickup_from', 'label' => 'Pickup From', 'value' => $bookingFilters['pickup_from']],
                                ['type' => 'date', 'name' => 'pickup_to', 'label' => 'Pickup To', 'value' => $bookingFilters['pickup_to']],
                                ['type' => 'date', 'name' => 'return_from', 'label' => 'Return From', 'value' => $bookingFilters['return_from']],
                                ['type' => 'date', 'name' => 'return_to', 'label' => 'Return To', 'value' => $bookingFilters['return_to']],
                                ['type' => 'number', 'name' => 'min_amount', 'label' => 'Min Amount', 'value' => $bookingFilters['min_amount'], 'min' => '0', 'step' => '0.01'],
                                ['type' => 'number', 'name' => 'max_amount', 'label' => 'Max Amount', 'value' => $bookingFilters['max_amount'], 'min' => '0', 'step' => '0.01'],
                                [
                                    'type' => 'select',
                                    'name' => 'sort',
                                    'label' => 'Sort',
                                    'value' => $bookingSort,
                                    'options' => $sortOptions,
                                ],
                            ],
                            'submit_label' => 'Search',
                            'clear_label' => 'Clear',
                            'clear_href' => 'bookings.php',
                        ]); ?>

                        <?php if (count($bookings) === 0): ?>
                            <p class="empty-table-message">No bookings found.</p>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Booking</th>
                                            <th>Customer</th>
                                            <th>Vehicle</th>
                                            <th>Dates</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($bookings as $row): ?>
                                            <?php $rowStatus = bookingDisplayStatus((string) $row['booking_status'], $row['payment_status'] ?? null, (float) $row['total_late_fee']); ?>
                                            <?php $rowBreakdown = buildPaymentBreakdown((float) $row['total_amount'], (float) $row['total_late_fee']); ?>
                                            <tr>
                                                <td>
                                                    <strong>#<?php echo h($row['id']); ?></strong>
                                                    <span><?php echo h(ucfirst($row['booking_status'])); ?></span>
                                                </td>
                                                <td>
                                                    <strong><?php echo h($row['customer_name'] ?: 'Customer removed'); ?></strong>
                                                    <span><?php echo h($row['customer_email'] ?: 'No email'); ?></span>
                                                </td>
                                                <td>
                                                    <strong><?php echo h(trim(($row['brand'] ?? '') . ' ' . ($row['model'] ?? '')) ?: 'Car removed'); ?></strong>
                                                    <span><?php echo h($row['plate_number'] ?: 'No plate'); ?></span>
                                                </td>
                                                <td><?php echo h(formatAdminDate($row['pickup_date']) . ' - ' . formatAdminDate($row['return_date'])); ?></td>
                                                <td>RM <?php echo h(number_format($rowBreakdown['payable_total'], 2)); ?></td>
                                                <td><span class="status-pill <?php echo h($rowStatus['class']); ?>"><?php echo h($rowStatus['label']); ?></span></td>
                                                <td><a class="table-action-link" href="<?php echo h(bookingListUrl($bookingListState, ['id' => $row['id']])); ?>">View Details</a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($totalBookingPages > 1): ?>
                                <nav class="pagination" aria-label="Booking pages">
                                    <?php if ($bookingPage > 1): ?>
                                        <a href="<?php echo h(bookingListUrl($bookingListState, ['page' => $bookingPage - 1])); ?>">Previous</a>
                                    <?php endif; ?>

                                    <span>Page <?php echo h($bookingPage); ?> of <?php echo h($totalBookingPages); ?></span>

                                    <?php if ($bookingPage < $totalBookingPages): ?>
                                        <a href="<?php echo h(bookingListUrl($bookingListState, ['page' => $bookingPage + 1])); ?>">Next</a>
                                    <?php endif; ?>
                                </nav>
                            <?php endif; ?>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            </div>
        </section>
    </main>
</body>
</html>
