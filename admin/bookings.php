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
<?php
$pageTitle = 'Admin Bookings | CarGo';
include '../includes/layout_top.php';
include 'header.php';
?>
<main class="dc-main">
    <?php if ($booking): ?>
        <?php $displayStatus = bookingDisplayStatus((string) $booking['booking_status'], $booking['payment_status'] ?? null, (float) $booking['total_late_fee']); ?>
        <?php $isPaid = ($booking['payment_status'] ?? '') === PAYMENT_STATUS_PAID; ?>
        <?php $paymentBreakdown = buildPaymentBreakdown((float) $booking['total_amount'], (float) $booking['total_late_fee']); ?>
        <?php $amountDue = calculatePaymentAmountDue($paymentBreakdown['payable_total'], $booking['payment_status'] ?? null, isset($booking['payment_amount']) ? (float) $booking['payment_amount'] : null); ?>

        <div style="margin-bottom: 24px;">
            <a href="<?php echo h(bookingListUrl($bookingListState)); ?>" style="color:var(--accent); font-weight:600; text-decoration:none; font-size:14px; display:inline-flex; align-items:center; gap:6px;">
                <svg width="14" height="14" viewBox="0 0 16 16" aria-hidden="true"><path d="M10.5 13.5 L4.5 8 L10.5 2.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                Back to bookings
            </a>
        </div>

        <?php if ($error !== ''): ?>
            <p class="message error" style="color: #c23a52; background: #fbeaed; padding: 12px; border-radius: 8px; font-weight: 600; margin-bottom:24px;"><?php echo h($error); ?></p>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <p class="message success" style="color: #0b7a5a; background: #e6f6f1; padding: 12px; border-radius: 8px; font-weight: 600; margin-bottom:24px;"><?php echo h($success); ?></p>
        <?php endif; ?>

        <div style="display:grid; grid-template-columns: minmax(400px, 1fr) 300px; gap:24px; align-items:start;">
            <div style="display:flex; flex-direction:column; gap:24px;">
                <div class="dc-card padded" style="display:flex; justify-content:space-between; align-items:center; gap:24px;">
                    <div>
                        <p class="dc-mono-subtitle small" style="margin-bottom:8px">Booking #<?php echo h($booking['id']); ?></p>
                        <h1 class="dc-h1" style="font-size:24px; margin-bottom:8px;"><?php echo h($booking['brand'] . ' ' . $booking['model']); ?></h1>
                        <p style="color:#5b6273; font-size:14px; font-weight:600;"><?php echo h(formatAdminDate($booking['pickup_date']) . ' to ' . formatAdminDate($booking['return_date'])); ?></p>
                        <div style="display:flex; gap:16px; margin-top:16px; font-size:13px; color:#5b6273;">
                            <span><strong><?php echo h($booking['total_days']); ?></strong> Rental days</span>
                            <span><strong>RM <?php echo h(number_format($paymentBreakdown['payable_total'], 2)); ?></strong> Est. total</span>
                            <span><strong><?php echo h($booking['pickup_location']); ?></strong> Pickup</span>
                        </div>
                    </div>
                    <div style="text-align:right;">
                        <?php 
                            $bStatus = $displayStatus['label'];
                            $bColor = $bStatus === 'Confirmed' || $bStatus === 'Completed' || $bStatus === 'Paid' ? '#0b7a5a' : ($bStatus === 'Cancelled' ? '#c23a52' : '#b25e09');
                            $bBg = $bStatus === 'Confirmed' || $bStatus === 'Completed' || $bStatus === 'Paid' ? '#e6f6f1' : ($bStatus === 'Cancelled' ? '#fbeaed' : '#fff3e0');
                        ?>
                        <span class="dc-badge" style="background:<?php echo $bBg; ?>; color:<?php echo $bColor; ?>; margin-bottom:12px; display:inline-block;">
                            <?php echo h($bStatus); ?>
                        </span>
                        <div style="width:120px; height:80px; border-radius:8px; overflow:hidden; background:#f1f3f9;">
                            <img src="<?php echo h(carImageUrl($booking['image'], 'https://images.unsplash.com/photo-1549317661-bd32c8ce0db2?auto=format&fit=crop&w=700&q=80')); ?>" alt="Car" style="width:100%; height:100%; object-fit:cover;">
                        </div>
                    </div>
                </div>

                <div class="dc-card">
                    <div style="padding:24px; border-bottom:1px solid #e4e8f1;">
                        <p class="dc-mono-subtitle small" style="margin-bottom:8px">Trip</p>
                        <h2 class="dc-h2" style="font-size:20px;">Rental timeline</h2>
                    </div>
                    <div style="padding:24px;">
                        <div style="display:flex; flex-direction:column; gap:24px; position:relative; padding-left:16px; border-left:2px solid #e4e8f1; margin-left:8px;">
                            <div style="position:relative;">
                                <div style="position:absolute; left:-25px; top:4px; width:16px; height:16px; background:#fff; border:4px solid var(--accent); border-radius:50%;"></div>
                                <p style="font-size:12px; font-weight:700; color:#8891a5; text-transform:uppercase; margin-bottom:4px;">Pickup</p>
                                <div style="font-size:16px; color:#131722; font-weight:600; margin-bottom:2px;"><?php echo h(formatAdminDate($booking['pickup_date'])); ?></div>
                                <div style="font-size:13px; color:#5b6273;"><?php echo h($booking['pickup_location']); ?></div>
                            </div>
                            <div style="position:relative;">
                                <div style="position:absolute; left:-25px; top:4px; width:16px; height:16px; background:#fff; border:4px solid #8891a5; border-radius:50%;"></div>
                                <p style="font-size:12px; font-weight:700; color:#8891a5; text-transform:uppercase; margin-bottom:4px;">Expected Return</p>
                                <div style="font-size:16px; color:#131722; font-weight:600; margin-bottom:2px;"><?php echo h(formatAdminDate($booking['return_date'])); ?></div>
                                <div style="font-size:13px; color:#5b6273;"><?php echo h($booking['pickup_location']); ?></div>
                            </div>
                            <?php if ($booking['actual_return_date']): ?>
                                <div style="position:relative;">
                                    <div style="position:absolute; left:-25px; top:4px; width:16px; height:16px; background:#fff; border:4px solid <?php echo $booking['total_late_days'] > 0 ? '#c23a52' : '#0b7a5a'; ?>; border-radius:50%;"></div>
                                    <p style="font-size:12px; font-weight:700; color:#8891a5; text-transform:uppercase; margin-bottom:4px;">Actual Return</p>
                                    <div style="font-size:16px; color:#131722; font-weight:600; margin-bottom:2px;"><?php echo h(formatAdminDate($booking['actual_return_date'])); ?></div>
                                    <div style="font-size:13px; color:<?php echo $booking['total_late_days'] > 0 ? '#c23a52' : '#0b7a5a'; ?>; font-weight:600;"><?php echo h($booking['total_late_days'] > 0 ? $booking['total_late_days'] . ' late day(s)' : 'Returned on time'); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:24px;">
                    <div class="dc-card">
                        <div style="padding:24px; border-bottom:1px solid #e4e8f1;">
                            <p class="dc-mono-subtitle small" style="margin-bottom:8px">Customer</p>
                            <h2 class="dc-h2" style="font-size:20px;">Customer information</h2>
                        </div>
                        <div style="padding:24px; display:flex; flex-direction:column; gap:16px;">
                            <div><span style="display:block; font-size:12px; font-weight:700; color:#8891a5; text-transform:uppercase; margin-bottom:4px;">Name</span><span style="font-size:14px; color:#131722;"><?php echo h($booking['customer_name']); ?></span></div>
                            <div><span style="display:block; font-size:12px; font-weight:700; color:#8891a5; text-transform:uppercase; margin-bottom:4px;">Email</span><span style="font-size:14px; color:#131722;"><?php echo h($booking['customer_email']); ?></span></div>
                            <div><span style="display:block; font-size:12px; font-weight:700; color:#8891a5; text-transform:uppercase; margin-bottom:4px;">Phone</span><span style="font-size:14px; color:#131722;"><?php echo h($booking['customer_phone'] ?: 'Not provided'); ?></span></div>
                            <div><span style="display:block; font-size:12px; font-weight:700; color:#8891a5; text-transform:uppercase; margin-bottom:4px;">Address</span><span style="font-size:14px; color:#131722;"><?php echo h($booking['customer_address'] ?: 'Not provided'); ?></span></div>
                        </div>
                    </div>
                    <div class="dc-card">
                        <div style="padding:24px; border-bottom:1px solid #e4e8f1;">
                            <p class="dc-mono-subtitle small" style="margin-bottom:8px">Vehicle</p>
                            <h2 class="dc-h2" style="font-size:20px;">Car information</h2>
                        </div>
                        <div style="padding:24px; display:flex; flex-direction:column; gap:16px;">
                            <div><span style="display:block; font-size:12px; font-weight:700; color:#8891a5; text-transform:uppercase; margin-bottom:4px;">Vehicle</span><span style="font-size:14px; color:#131722;"><?php echo h($booking['brand'] . ' ' . $booking['model']); ?></span></div>
                            <div><span style="display:block; font-size:12px; font-weight:700; color:#8891a5; text-transform:uppercase; margin-bottom:4px;">Plate</span><span style="font-size:14px; color:#131722; font-weight:600;"><?php echo h($booking['plate_number']); ?></span></div>
                            <div><span style="display:block; font-size:12px; font-weight:700; color:#8891a5; text-transform:uppercase; margin-bottom:4px;">Type</span><span style="font-size:14px; color:#131722;"><?php echo h($booking['car_type']); ?></span></div>
                            <div><span style="display:block; font-size:12px; font-weight:700; color:#8891a5; text-transform:uppercase; margin-bottom:4px;">Specs</span><span style="font-size:14px; color:#131722;"><?php echo h($booking['transmission'] . ' / ' . $booking['fuel_type'] . ' / ' . $booking['seats'] . ' seats'); ?></span></div>
                        </div>
                    </div>
                </div>
            </div>

            <div style="display:flex; flex-direction:column; gap:24px; position:sticky; top:24px;">
                <div class="dc-card">
                    <div style="padding:24px; border-bottom:1px solid #e4e8f1;">
                        <p class="dc-mono-subtitle small" style="margin-bottom:8px">Payment</p>
                        <h2 class="dc-h2" style="font-size:20px;">Payment details</h2>
                    </div>
                    <div style="padding:24px; display:flex; flex-direction:column; gap:16px;">
                        <div style="display:flex; justify-content:space-between; font-size:14px;"><span style="color:#5b6273;">Daily Rate</span><span style="color:#131722; font-weight:600;">RM <?php echo h(number_format((float) $booking['daily_rate'], 2)); ?></span></div>
                        <div style="display:flex; justify-content:space-between; font-size:14px;"><span style="color:#5b6273;">Total Days</span><span style="color:#131722; font-weight:600;"><?php echo h($booking['total_days']); ?></span></div>
                        <div style="display:flex; justify-content:space-between; font-size:14px;"><span style="color:#5b6273;">Payment Status</span><span style="color:#131722; font-weight:600;"><?php echo h(ucfirst($booking['payment_status'] ?: 'unpaid')); ?></span></div>
                        <div style="display:flex; justify-content:space-between; font-size:14px;"><span style="color:#5b6273;">Method</span><span style="color:#131722; font-weight:600;"><?php echo h($booking['payment_method'] ?: 'Not paid'); ?></span></div>
                        <div style="display:flex; justify-content:space-between; font-size:14px;"><span style="color:#5b6273;">Payment Date</span><span style="color:#131722; font-weight:600;"><?php echo h(formatAdminDate($booking['payment_date'])); ?></span></div>
                        <?php if ((float) $booking['total_late_fee'] > 0): ?>
                            <div style="display:flex; justify-content:space-between; font-size:14px; color:#c23a52;"><span style="font-weight:600;">Late Days</span><span style="font-weight:600;"><?php echo h($booking['total_late_days']); ?></span></div>
                        <?php endif; ?>
                        
                        <div style="margin-top:8px; padding-top:16px; border-top:1px solid #e4e8f1;">
                            <div style="display:flex; justify-content:space-between; font-size:16px; font-weight:700;"><span style="color:#131722;">Total Amount</span><span style="color:#131722;">RM <?php echo h(number_format($paymentBreakdown['payable_total'], 2)); ?></span></div>
                            <?php if ($amountDue > 0): ?>
                                <div style="display:flex; justify-content:space-between; font-size:14px; margin-top:8px; color:#c23a52;"><span style="font-weight:600;">Amount due</span><span style="font-weight:600;">RM <?php echo h(number_format($amountDue, 2)); ?></span></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="dc-card">
                    <div style="padding:24px; border-bottom:1px solid #e4e8f1;">
                        <p class="dc-mono-subtitle small" style="margin-bottom:8px">Admin</p>
                        <h2 class="dc-h2" style="font-size:20px;">Booking actions</h2>
                    </div>
                    <div style="padding:24px;">
                        <?php if ($isPaid && !in_array($booking['booking_status'], [BOOKING_STATUS_ONGOING, BOOKING_STATUS_COMPLETED, 'cancelled', 'rejected'], true)): ?>
                            <form method="post" action="bookings.php?id=<?php echo h($booking['id']); ?>" style="margin-bottom:16px;">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="action" value="confirm_pickup">
                                <input type="hidden" name="booking_id" value="<?php echo h($booking['id']); ?>">
                                <button type="submit" class="dc-btn-primary" style="width:100%; justify-content:center;">Confirm Pickup</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($booking['booking_status'] === BOOKING_STATUS_ONGOING): ?>
                            <form method="post" action="bookings.php?id=<?php echo h($booking['id']); ?>" style="margin-bottom:16px; padding:16px; background:#f9fafc; border:1px solid #e4e8f1; border-radius:8px;">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="action" value="confirm_return">
                                <input type="hidden" name="booking_id" value="<?php echo h($booking['id']); ?>">
                                <label style="display:block; margin-bottom:12px;">
                                    <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Actual Return Date</span>
                                    <input type="date" name="actual_return_date" value="<?php echo h($today); ?>" required class="dc-input" style="width:100%;">
                                </label>
                                <button type="submit" class="dc-btn-primary" style="width:100%; justify-content:center; background:#131722; color:#fff;">Confirm Return</button>
                            </form>
                        <?php endif; ?>

                        <?php if (!$isPaid): ?>
                            <p style="font-size:13px; color:#5b6273; text-align:center; padding:12px; background:#f1f3f9; border-radius:8px;">Pickup can be confirmed only after payment is paid.</p>
                        <?php elseif ($booking['booking_status'] === BOOKING_STATUS_COMPLETED): ?>
                            <p style="font-size:13px; color:#0b7a5a; text-align:center; padding:12px; background:#e6f6f1; border-radius:8px; font-weight:600;">This booking has been returned and completed.</p>
                        <?php elseif (in_array($booking['booking_status'], ['cancelled', 'rejected'], true)): ?>
                            <p style="font-size:13px; color:#5b6273; text-align:center; padding:12px; background:#f1f3f9; border-radius:8px;">No pickup or return actions are available for this booking.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <header class="dc-h2-title" style="margin-bottom: 24px;">
            <div>
                <div class="dc-mono-subtitle small" style="margin-bottom:8px">Bookings</div>
                <h1 class="dc-h1" style="font-size:32px;">Manage bookings</h1>
            </div>
        </header>

        <?php if ($error !== ''): ?>
            <p class="message error" style="color: #c23a52; background: #fbeaed; padding: 12px; border-radius: 8px; font-weight: 600; margin-bottom:24px;"><?php echo h($error); ?></p>
        <?php endif; ?>

        <div class="dc-card">
            <div style="padding:24px; border-bottom:1px solid #e4e8f1;">
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
            </div>

            <?php if (count($bookings) === 0): ?>
                <div style="padding: 40px 24px; text-align: center; color: #5b6273;">
                    <p>No bookings found.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="dc-table" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="border-bottom: 1px solid #e4e8f1; background: #f9fafc;">
                                <th style="padding: 16px 24px; text-align: left; font-size: 13px; color: #5b6273; font-weight: 600;">Booking</th>
                                <th style="padding: 16px 24px; text-align: left; font-size: 13px; color: #5b6273; font-weight: 600;">Customer</th>
                                <th style="padding: 16px 24px; text-align: left; font-size: 13px; color: #5b6273; font-weight: 600;">Vehicle</th>
                                <th style="padding: 16px 24px; text-align: left; font-size: 13px; color: #5b6273; font-weight: 600;">Dates</th>
                                <th style="padding: 16px 24px; text-align: left; font-size: 13px; color: #5b6273; font-weight: 600;">Amount</th>
                                <th style="padding: 16px 24px; text-align: left; font-size: 13px; color: #5b6273; font-weight: 600;">Status</th>
                                <th style="padding: 16px 24px; text-align: right; font-size: 13px; color: #5b6273; font-weight: 600;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bookings as $row): ?>
                                <?php $rowStatus = bookingDisplayStatus((string) $row['booking_status'], $row['payment_status'] ?? null, (float) $row['total_late_fee']); ?>
                                <?php $rowBreakdown = buildPaymentBreakdown((float) $row['total_amount'], (float) $row['total_late_fee']); ?>
                                <tr style="border-bottom: 1px solid #e4e8f1;">
                                    <td style="padding: 16px 24px;">
                                        <strong style="color: #131722; display:block; margin-bottom:4px; font-size:14px;">#<?php echo h($row['id']); ?></strong>
                                        <div style="color: #5b6273; font-size:13px; font-weight:600;"><?php echo h(ucfirst($row['booking_status'])); ?></div>
                                    </td>
                                    <td style="padding: 16px 24px;">
                                        <strong style="color: #131722; display:block; margin-bottom:4px;"><?php echo h($row['customer_name'] ?: 'Customer removed'); ?></strong>
                                        <div style="color: #5b6273; font-size:13px;"><?php echo h($row['customer_email'] ?: 'No email'); ?></div>
                                    </td>
                                    <td style="padding: 16px 24px;">
                                        <strong style="color: #131722; display:block; margin-bottom:4px;"><?php echo h(trim(($row['brand'] ?? '') . ' ' . ($row['model'] ?? '')) ?: 'Car removed'); ?></strong>
                                        <div style="color: #5b6273; font-size:13px;"><?php echo h($row['plate_number'] ?: 'No plate'); ?></div>
                                    </td>
                                    <td style="padding: 16px 24px; color:#131722; font-size:14px;">
                                        <?php echo h(formatAdminDate($row['pickup_date']) . ' - ' . formatAdminDate($row['return_date'])); ?>
                                    </td>
                                    <td style="padding: 16px 24px; color:#131722; font-size:14px; font-weight:600;">
                                        RM <?php echo h(number_format($rowBreakdown['payable_total'], 2)); ?>
                                    </td>
                                    <td style="padding: 16px 24px;">
                                        <?php 
                                            $rsLabel = $rowStatus['label'];
                                            $rsColor = $rsLabel === 'Confirmed' || $rsLabel === 'Completed' || $rsLabel === 'Paid' ? '#0b7a5a' : ($rsLabel === 'Cancelled' ? '#c23a52' : '#b25e09');
                                            $rsBg = $rsLabel === 'Confirmed' || $rsLabel === 'Completed' || $rsLabel === 'Paid' ? '#e6f6f1' : ($rsLabel === 'Cancelled' ? '#fbeaed' : '#fff3e0');
                                        ?>
                                        <span class="dc-badge" style="background:<?php echo $rsBg; ?>; color:<?php echo $rsColor; ?>;">
                                            <?php echo h($rsLabel); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 16px 24px; text-align: right;">
                                        <a href="<?php echo h(bookingListUrl($bookingListState, ['id' => $row['id']])); ?>" style="color:#3b5fda; font-size:13px; font-weight:600; text-decoration:none;">View Details</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalBookingPages > 1): ?>
                    <div style="padding:24px; border-top:1px solid #e4e8f1; display:flex; justify-content:space-between; align-items:center;">
                        <?php if ($bookingPage > 1): ?>
                            <a href="<?php echo h(bookingListUrl($bookingListState, ['page' => $bookingPage - 1])); ?>" class="dc-btn-secondary" style="background:#fff; text-decoration:none;">Previous</a>
                        <?php else: ?>
                            <div style="width:100px;"></div>
                        <?php endif; ?>

                        <span style="font-size:14px; font-weight:600; color:#5b6273;">Page <?php echo h($bookingPage); ?> of <?php echo h($totalBookingPages); ?></span>

                        <?php if ($bookingPage < $totalBookingPages): ?>
                            <a href="<?php echo h(bookingListUrl($bookingListState, ['page' => $bookingPage + 1])); ?>" class="dc-btn-secondary" style="background:#fff; text-decoration:none;">Next</a>
                        <?php else: ?>
                            <div style="width:100px;"></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>
<?php include '../includes/layout_bottom.php'; ?>
