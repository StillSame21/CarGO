<?php
require_once __DIR__ . '/../includes/security.php';
require_once '../db_connect.php';
require_once __DIR__ . '/includes/filter_bar.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();

// HTML-escapes a value for safe output.
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Human-readable date+time, or "Not set" when empty.
function formatCustomerDate(?string $date): string
{
    if ($date === null || $date === '') {
        return 'Not set';
    }

    return date('d M Y, h:i A', strtotime($date));
}

// ' selected' attribute string when the two values match, else ''.
function selectedIf($currentValue, $optionValue): string
{
    return $currentValue === $optionValue ? ' selected' : '';
}

// CSS status-pill class for a customer status. Currently unused - see dead code report.
function customerStatusClass(string $status): string
{
    return 'status-' . preg_replace('/[^a-z0-9-]/', '-', strtolower($status));
}

// bind_param() takes args by reference, so build a by-reference array before splatting it.
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

// Rebuild the list URL keeping search, filters and page intact.
function customerPageUrl(array $state, array $overrides = []): string
{
    $params = array_merge($state, $overrides);

    foreach ($params as $key => $value) {
        if ($value === '' || $value === null || ($key === 'page' && (int) $value <= 1)) {
            unset($params[$key]);
        }
    }

    $query = http_build_query($params);

    return 'customers.php' . ($query !== '' ? '?' . $query : '');
}

// Customer row for the edit form, or null when the id doesn't resolve.
function loadCustomer(mysqli $conn, int $customerId): ?array
{
    $stmt = $conn->prepare(
        'SELECT id, name, email, phone, address, status, created_at
         FROM customers
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $customerId);
    $stmt->execute();

    $customer = $stmt->get_result()->fetch_assoc();

    return $customer ?: null;
}

// True if another customer already uses this email.
function customerEmailExists(mysqli $conn, string $email, int $customerId): bool
{
    $stmt = $conn->prepare('SELECT id FROM customers WHERE email = ? AND id <> ? LIMIT 1');
    $stmt->bind_param('si', $email, $customerId);
    $stmt->execute();

    return (bool) $stmt->get_result()->fetch_assoc();
}

// Booking count, latest booking date, and the 5 most recent bookings for this customer.
function loadCustomerBookingSummary(mysqli $conn, int $customerId): array
{
    $summary = [
        'total_bookings' => 0,
        'latest_booking_at' => null,
        'recent_bookings' => [],
    ];

    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total_bookings, MAX(created_at) AS latest_booking_at
         FROM bookings
         WHERE customer_id = ?'
    );
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $counts = $stmt->get_result()->fetch_assoc();

    if ($counts) {
        $summary['total_bookings'] = (int) $counts['total_bookings'];
        $summary['latest_booking_at'] = $counts['latest_booking_at'];
    }

    $stmt = $conn->prepare(
        'SELECT
            b.id,
            b.pickup_date,
            b.return_date,
            b.total_amount,
            b.booking_status,
            car.brand,
            car.model,
            car.plate_number
         FROM bookings b
         INNER JOIN cars car ON car.id = b.car_id
         WHERE b.customer_id = ?
         ORDER BY b.created_at DESC, b.id DESC
         LIMIT 5'
    );
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $summary['recent_bookings'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    return $summary;
}

requireAdminLogin();

$statuses = ['active', 'inactive', 'blocked'];
$error = '';
$successMessages = [
    'updated' => 'Customer updated successfully.',
    'blocked' => 'Customer blocked successfully.',
    'unblocked' => 'Customer unblocked successfully.',
];
$successKey = trim($_GET['success'] ?? '');
$success = $successMessages[$successKey] ?? '';
$search = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status'] ?? 'all');
$statusFilter = in_array($statusFilter, array_merge(['all'], $statuses), true) ? $statusFilter : 'all';
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$page = max(1, $page);
$perPage = 10;
$viewCustomerId = filter_input(INPUT_GET, 'view', FILTER_VALIDATE_INT) ?: 0;
$editCustomerId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;
$listState = [
    'q' => $search,
    'status' => $statusFilter === 'all' ? '' : $statusFilter,
    'page' => $page,
];
$customer = null;
$bookingSummary = null;
$customers = [];
$totalCustomers = 0;
$totalPages = 1;
$editName = '';
$editEmail = '';
$editPhone = '';
$editAddress = '';
$editStatus = 'active';

try {
    $conn = getDbConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireValidCsrfToken();
        blockDemoWrite('customers.php');

        $action = trim($_POST['action'] ?? '');
        $postedCustomerId = filter_input(INPUT_POST, 'customer_id', FILTER_VALIDATE_INT) ?: 0;

        if ($postedCustomerId <= 0) {
            throw new InvalidArgumentException('Please choose a valid customer.');
        }

        if ($action === 'toggle_status') {
            $targetStatus = trim($_POST['target_status'] ?? '');

            if (!in_array($targetStatus, ['active', 'blocked'], true)) {
                throw new InvalidArgumentException('Please choose a valid account status.');
            }

            $stmt = $conn->prepare('UPDATE customers SET status = ? WHERE id = ?');
            $stmt->bind_param('si', $targetStatus, $postedCustomerId);
            $stmt->execute();

            $successType = $targetStatus === 'blocked' ? 'blocked' : 'unblocked';
            header('Location: ' . customerPageUrl($listState, ['success' => $successType]));
            exit;
        }

        if ($action === 'update_customer') {
            $editCustomerId = $postedCustomerId;
            $editName = trim($_POST['name'] ?? '');
            $editEmail = trim($_POST['email'] ?? '');
            $editPhone = trim($_POST['phone'] ?? '');
            $editAddress = trim($_POST['address'] ?? '');
            $editStatus = trim($_POST['status'] ?? '');

            if ($editName === '') {
                throw new InvalidArgumentException('Name is required.');
            }

            if ($editEmail === '' || !filter_var($editEmail, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Please enter a valid email address.');
            }

            if (!in_array($editStatus, $statuses, true)) {
                throw new InvalidArgumentException('Please choose a valid status.');
            }

            if (!loadCustomer($conn, $editCustomerId)) {
                throw new InvalidArgumentException('Customer not found.');
            }

            if (customerEmailExists($conn, $editEmail, $editCustomerId)) {
                throw new InvalidArgumentException('This customer email is already registered.');
            }

            $phoneValue = $editPhone === '' ? null : $editPhone;
            $addressValue = $editAddress === '' ? null : $editAddress;
            $stmt = $conn->prepare(
                'UPDATE customers
                 SET name = ?,
                     email = ?,
                     phone = ?,
                     address = ?,
                     status = ?
                 WHERE id = ?'
            );
            $stmt->bind_param('sssssi', $editName, $editEmail, $phoneValue, $addressValue, $editStatus, $editCustomerId);
            $stmt->execute();

            header('Location: ' . customerPageUrl($listState, ['success' => 'updated']));
            exit;
        }

        throw new InvalidArgumentException('Please choose a valid customer action.');
    }

    if ($viewCustomerId > 0) {
        $customer = loadCustomer($conn, $viewCustomerId);

        if (!$customer) {
            $error = 'Customer not found.';
            $viewCustomerId = 0;
        } else {
            $bookingSummary = loadCustomerBookingSummary($conn, $viewCustomerId);
        }
    }

    if ($editCustomerId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $customer = loadCustomer($conn, $editCustomerId);

        if (!$customer) {
            $error = 'Customer not found.';
            $editCustomerId = 0;
        } else {
            $editName = (string) $customer['name'];
            $editEmail = (string) $customer['email'];
            $editPhone = (string) ($customer['phone'] ?? '');
            $editAddress = (string) ($customer['address'] ?? '');
            $editStatus = (string) $customer['status'];
        }
    }

    if ($viewCustomerId === 0 && $editCustomerId === 0) {
        $where = [];
        $types = '';
        $params = [];

        if ($search !== '') {
            $searchTerm = '%' . $search . '%';
            $where[] = '(name LIKE ? OR email LIKE ? OR phone LIKE ? OR address LIKE ?)';
            $types .= 'ssss';
            array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
        }

        if ($statusFilter !== 'all') {
            $where[] = 'status = ?';
            $types .= 's';
            $params[] = $statusFilter;
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM customers' . $whereSql);

        bindStatementParams($stmt, $types, $params);

        $stmt->execute();
        $countRow = $stmt->get_result()->fetch_assoc();
        $totalCustomers = (int) ($countRow['total'] ?? 0);
        $totalPages = max(1, (int) ceil($totalCustomers / $perPage));
        $page = min($page, $totalPages);
        $listState['page'] = $page;
        $offset = ($page - 1) * $perPage;

        $listTypes = $types . 'ii';
        $listParams = array_merge($params, [$perPage, $offset]);
        $stmt = $conn->prepare(
            'SELECT id, name, email, phone, address, status, created_at
             FROM customers' . $whereSql . '
             ORDER BY created_at DESC, id DESC
             LIMIT ? OFFSET ?'
        );
        bindStatementParams($stmt, $listTypes, $listParams);
        $stmt->execute();
        $customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
} catch (InvalidArgumentException $e) {
    $error = $e->getMessage();
} catch (mysqli_sql_exception $e) {
    $error = 'Could not load or update customers. Please check the database connection.';
}
?>
<?php
$pageTitle = 'Admin Customers | CarGo';
include '../includes/layout_top.php';
include 'header.php';
?>
<main class="dc-main">
    <?php if ($viewCustomerId > 0 && $customer): ?>
        <div style="margin-bottom: 24px;">
            <a href="<?php echo h(customerPageUrl($listState)); ?>" style="color:var(--accent); font-weight:600; text-decoration:none; font-size:14px; display:inline-flex; align-items:center; gap:6px;">
                <svg width="14" height="14" viewBox="0 0 16 16" aria-hidden="true"><path d="M10.5 13.5 L4.5 8 L10.5 2.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                Back to customers
            </a>
        </div>

        <?php if ($error !== ''): ?>
            <p class="message error" style="color: var(--stop); background: var(--stop-soft); padding: 12px; border-radius: 8px; font-weight: 600; margin-bottom:24px;"><?php echo h($error); ?></p>
        <?php endif; ?>

        <div style="display:grid; grid-template-columns: minmax(300px, 1fr) 2fr; gap:24px; align-items:start;">
            <div class="dc-card padded">
                <div style="margin-bottom:24px;">
                    <p class="dc-mono-subtitle small" style="margin-bottom:8px">Customer #<?php echo h($customer['id']); ?></p>
                    <h2 class="dc-h2" style="font-size:24px;"><?php echo h($customer['name']); ?></h2>
                </div>

                <div style="display:flex; flex-direction:column; gap:16px; margin-bottom:24px;">
                    <div><span style="display:block; font-size:12px; font-weight:700; color:var(--ink-3); text-transform:uppercase; margin-bottom:4px;">ID</span><span style="font-size:14px; color:var(--ink); font-weight:600;"><?php echo h($customer['id']); ?></span></div>
                    <div><span style="display:block; font-size:12px; font-weight:700; color:var(--ink-3); text-transform:uppercase; margin-bottom:4px;">Name</span><span style="font-size:14px; color:var(--ink);"><?php echo h($customer['name']); ?></span></div>
                    <div><span style="display:block; font-size:12px; font-weight:700; color:var(--ink-3); text-transform:uppercase; margin-bottom:4px;">Email</span><span style="font-size:14px; color:var(--ink);"><?php echo h($customer['email']); ?></span></div>
                    <div><span style="display:block; font-size:12px; font-weight:700; color:var(--ink-3); text-transform:uppercase; margin-bottom:4px;">Phone</span><span style="font-size:14px; color:var(--ink);"><?php echo h($customer['phone'] ?: 'Not provided'); ?></span></div>
                    <div><span style="display:block; font-size:12px; font-weight:700; color:var(--ink-3); text-transform:uppercase; margin-bottom:4px;">Address</span><span style="font-size:14px; color:var(--ink);"><?php echo h($customer['address'] ?: 'Not provided'); ?></span></div>
                    <div>
                        <span style="display:block; font-size:12px; font-weight:700; color:var(--ink-3); text-transform:uppercase; margin-bottom:4px;">Status</span>
                        <?php 
                            $statusColor = $customer['status'] === 'active' ? 'var(--go)' : ($customer['status'] === 'blocked' ? 'var(--stop)' : 'var(--ink-3)');
                            $statusBg = $customer['status'] === 'active' ? 'var(--go-soft)' : ($customer['status'] === 'blocked' ? 'var(--stop-soft)' : 'var(--surface-2)');
                        ?>
                        <span class="dc-badge" style="background:<?php echo $statusBg; ?>; color:<?php echo $statusColor; ?>;">
                            <?php echo h(ucfirst($customer['status'])); ?>
                        </span>
                    </div>
                    <div><span style="display:block; font-size:12px; font-weight:700; color:var(--ink-3); text-transform:uppercase; margin-bottom:4px;">Created At</span><span style="font-size:14px; color:var(--ink);"><?php echo h(formatCustomerDate($customer['created_at'])); ?></span></div>
                </div>

                <div>
                    <a href="<?php echo h(customerPageUrl($listState, ['edit' => $customer['id'], 'view' => null])); ?>" class="dc-btn-primary" style="width:100%; text-decoration:none; justify-content:center;">Edit Customer</a>
                </div>
            </div>

            <div class="dc-card">
                <div style="padding:24px; border-bottom:1px solid var(--line);">
                    <p class="dc-mono-subtitle small" style="margin-bottom:8px">Bookings</p>
                    <h2 class="dc-h2" style="font-size:20px;">Recent booking summary</h2>
                </div>

                <div style="padding:24px; display:grid; grid-template-columns:1fr 1fr; gap:16px; border-bottom:1px solid var(--line); background:var(--surface-2);">
                    <div style="background:var(--surface); border:1px solid var(--line); border-radius:8px; padding:16px;">
                        <div style="font-size:24px; font-weight:800; color:var(--ink); margin-bottom:4px;"><?php echo h($bookingSummary['total_bookings'] ?? 0); ?></div>
                        <div style="font-size:13px; color:var(--ink-2); font-weight:600;">Total bookings</div>
                    </div>
                    <div style="background:var(--surface); border:1px solid var(--line); border-radius:8px; padding:16px;">
                        <div style="font-size:16px; font-weight:800; color:var(--ink); margin-bottom:4px; padding-top:6px;"><?php echo h(formatCustomerDate($bookingSummary['latest_booking_at'] ?? null)); ?></div>
                        <div style="font-size:13px; color:var(--ink-2); font-weight:600;">Latest booking</div>
                    </div>
                </div>

                <?php if (empty($bookingSummary['recent_bookings'])): ?>
                    <div style="padding:40px 24px; text-align:center; color:var(--ink-2);">No bookings found for this customer.</div>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="dc-table" style="width:100%; border-collapse:collapse;">
                            <thead>
                                <tr style="border-bottom:1px solid var(--line); background:var(--surface);">
                                    <th style="padding:16px 24px; text-align:left; font-size:13px; color:var(--ink-2); font-weight:600;">Booking</th>
                                    <th style="padding:16px 24px; text-align:left; font-size:13px; color:var(--ink-2); font-weight:600;">Vehicle</th>
                                    <th style="padding:16px 24px; text-align:left; font-size:13px; color:var(--ink-2); font-weight:600;">Dates</th>
                                    <th style="padding:16px 24px; text-align:left; font-size:13px; color:var(--ink-2); font-weight:600;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bookingSummary['recent_bookings'] as $booking): ?>
                                    <tr style="border-bottom:1px solid var(--line);">
                                        <td style="padding:16px 24px;">
                                            <strong style="display:block; color:var(--ink); font-size:14px; margin-bottom:4px;">#<?php echo h($booking['id']); ?></strong>
                                            <span style="color:var(--ink-2); font-size:13px; font-weight:600;">RM <?php echo h(number_format((float) $booking['total_amount'], 2)); ?></span>
                                        </td>
                                        <td style="padding:16px 24px;">
                                            <strong style="display:block; color:var(--ink); font-size:14px; margin-bottom:4px;"><?php echo h($booking['brand'] . ' ' . $booking['model']); ?></strong>
                                            <span style="color:var(--ink-2); font-size:13px;"><?php echo h($booking['plate_number']); ?></span>
                                        </td>
                                        <td style="padding:16px 24px; color:var(--ink); font-size:14px;">
                                            <?php echo h(formatCustomerDate($booking['pickup_date']) . ' - ' . formatCustomerDate($booking['return_date'])); ?>
                                        </td>
                                        <td style="padding:16px 24px;">
                                            <?php 
                                                $bStatus = $booking['booking_status'];
                                                $bColor = $bStatus === 'confirmed' || $bStatus === 'completed' ? 'var(--go)' : ($bStatus === 'cancelled' ? 'var(--stop)' : 'var(--wait)');
                                                $bBg = $bStatus === 'confirmed' || $bStatus === 'completed' ? 'var(--go-soft)' : ($bStatus === 'cancelled' ? 'var(--stop-soft)' : 'var(--wait-soft)');
                                            ?>
                                            <span class="dc-badge" style="background:<?php echo $bBg; ?>; color:<?php echo $bColor; ?>;">
                                                <?php echo h(ucfirst($bStatus)); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif ($editCustomerId > 0): ?>
        <div style="margin-bottom: 24px;">
            <a href="<?php echo h(customerPageUrl($listState)); ?>" style="color:var(--accent); font-weight:600; text-decoration:none; font-size:14px; display:inline-flex; align-items:center; gap:6px;">
                <svg width="14" height="14" viewBox="0 0 16 16" aria-hidden="true"><path d="M10.5 13.5 L4.5 8 L10.5 2.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                Back to customers
            </a>
        </div>

        <div class="dc-card padded" style="max-width:600px; margin:0 auto;">
            <h2 class="dc-h2" style="font-size:20px; margin-bottom:8px;">Edit Customer</h2>
            <p class="dc-p" style="margin-bottom:24px; font-size:14px;">Update account details without changing the password.</p>

            <?php if ($error !== ''): ?>
                <p class="message error" style="color: var(--stop); background: var(--stop-soft); padding: 12px; border-radius: 8px; font-weight: 600; margin-bottom:24px;"><?php echo h($error); ?></p>
            <?php endif; ?>

            <form method="post" action="<?php echo h(customerPageUrl($listState, ['edit' => $editCustomerId])); ?>">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="update_customer">
                <input type="hidden" name="customer_id" value="<?php echo h($editCustomerId); ?>">

                <div style="display:flex; flex-direction:column; gap:16px;">
                    <label style="display:block;">
                        <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Name</span>
                        <input type="text" name="name" value="<?php echo h($editName); ?>" maxlength="100" required class="dc-input" style="width:100%;">
                    </label>
                    <label style="display:block;">
                        <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Email</span>
                        <input type="email" name="email" value="<?php echo h($editEmail); ?>" maxlength="150" required class="dc-input" style="width:100%;">
                    </label>
                    <label style="display:block;">
                        <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Phone</span>
                        <input type="tel" name="phone" value="<?php echo h($editPhone); ?>" maxlength="30" class="dc-input" style="width:100%;">
                    </label>
                    <label style="display:block;">
                        <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Address</span>
                        <textarea name="address" rows="3" maxlength="255" class="dc-input" style="width:100%; min-height:80px; resize:vertical;"><?php echo h($editAddress); ?></textarea>
                    </label>
                    <label style="display:block;">
                        <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Status</span>
                        <select name="status" required class="dc-input" style="width:100%;">
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?php echo h($status); ?>"<?php echo selectedIf($editStatus, $status); ?>>
                                    <?php echo h(ucfirst($status)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div style="margin-top:32px; display:flex; gap:12px; align-items:center;">
                    <button type="submit" class="dc-btn-primary" style="padding:12px 24px;">Save Changes</button>
                    <a href="<?php echo h(customerPageUrl($listState)); ?>" style="color:var(--ink-2); font-weight:600; font-size:14px; text-decoration:none;">Cancel</a>
                </div>
            </form>
        </div>
    <?php else: ?>
        <header class="dc-h2-title" style="margin-bottom: 24px;">
            <div>
                <div class="dc-mono-subtitle small" style="margin-bottom:8px">Customers</div>
                <h1 class="dc-h1" style="font-size:32px;">Manage customers</h1>
            </div>
        </header>

        <?php if ($error !== ''): ?>
            <p class="message error" style="color: var(--stop); background: var(--stop-soft); padding: 12px; border-radius: 8px; font-weight: 600; margin-bottom:24px;"><?php echo h($error); ?></p>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <p class="message success" style="color: var(--go); background: var(--go-soft); padding: 12px; border-radius: 8px; font-weight: 600; margin-bottom:24px;"><?php echo h($success); ?></p>
        <?php endif; ?>

        <div class="dc-card">
            <div style="padding:24px; border-bottom:1px solid var(--line);">
                <?php renderAdminFilterBar([
                    'action' => 'customers.php',
                    'search' => [
                        'name' => 'q',
                        'label' => 'Search',
                        'value' => $search,
                        'placeholder' => 'Name, email, phone, or address',
                    ],
                    'inline_fields' => [
                        [
                            'type' => 'select',
                            'name' => 'status',
                            'label' => 'Status',
                            'value' => $statusFilter,
                            'options' => array_merge(['all' => 'All'], array_combine($statuses, array_map('ucfirst', $statuses))),
                        ],
                    ],
                    'submit_label' => 'Apply',
                    'clear_label' => 'Reset',
                    'clear_href' => 'customers.php',
                ]); ?>
            </div>

            <?php if (count($customers) === 0): ?>
                <div style="padding: 40px 24px; text-align: center; color: var(--ink-2);">
                    <p>No customers found.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="dc-table" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--line); background: var(--surface-2);">
                                <th style="padding: 16px 24px; text-align: left; font-size: 13px; color: var(--ink-2); font-weight: 600;">ID</th>
                                <th style="padding: 16px 24px; text-align: left; font-size: 13px; color: var(--ink-2); font-weight: 600;">Name & Email</th>
                                <th style="padding: 16px 24px; text-align: left; font-size: 13px; color: var(--ink-2); font-weight: 600;">Contact</th>
                                <th style="padding: 16px 24px; text-align: left; font-size: 13px; color: var(--ink-2); font-weight: 600;">Status</th>
                                <th style="padding: 16px 24px; text-align: right; font-size: 13px; color: var(--ink-2); font-weight: 600;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customers as $row): ?>
                                <tr style="border-bottom: 1px solid var(--line);">
                                    <td style="padding: 16px 24px; color: var(--ink); font-size: 14px;">#<?php echo h($row['id']); ?></td>
                                    <td style="padding: 16px 24px;">
                                        <strong style="color: var(--ink); display:block; margin-bottom:4px;"><?php echo h($row['name']); ?></strong>
                                        <div style="color: var(--ink-2); font-size:13px;"><?php echo h($row['email']); ?></div>
                                    </td>
                                    <td style="padding: 16px 24px;">
                                        <div style="color: var(--ink-2); font-size:13px; margin-bottom:4px;">&#128222; <?php echo h($row['phone'] ?: 'Not provided'); ?></div>
                                        <div style="color: var(--ink-2); font-size:13px;">&#127968; <span style="display:inline-block; max-width:200px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; vertical-align:bottom;"><?php echo h($row['address'] ?: 'Not provided'); ?></span></div>
                                    </td>
                                    <td style="padding: 16px 24px;">
                                        <?php 
                                            $statusColor = $row['status'] === 'active' ? 'var(--go)' : ($row['status'] === 'blocked' ? 'var(--stop)' : 'var(--ink-3)');
                                            $statusBg = $row['status'] === 'active' ? 'var(--go-soft)' : ($row['status'] === 'blocked' ? 'var(--stop-soft)' : 'var(--surface-2)');
                                        ?>
                                        <span class="dc-badge" style="background:<?php echo $statusBg; ?>; color:<?php echo $statusColor; ?>;">
                                            <?php echo h(ucfirst($row['status'])); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 16px 24px; text-align: right;">
                                        <div style="display:inline-flex; gap:12px;">
                                            <a href="<?php echo h(customerPageUrl($listState, ['view' => $row['id']])); ?>" style="color:var(--ink); font-size:13px; font-weight:600; text-decoration:none;">View</a>
                                            <a href="<?php echo h(customerPageUrl($listState, ['edit' => $row['id']])); ?>" style="color:var(--accent); font-size:13px; font-weight:600; text-decoration:none;">Edit</a>
                                            
                                            <?php if ($row['status'] === 'blocked'): ?>
                                                <form method="post" action="<?php echo h(customerPageUrl($listState)); ?>" style="display:inline;">
                                                    <?php echo csrfInput(); ?>
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="customer_id" value="<?php echo h($row['id']); ?>">
                                                    <input type="hidden" name="target_status" value="active">
                                                    <button type="submit" style="background:none; border:none; color:var(--go); font-size:13px; font-weight:600; cursor:pointer; padding:0; font-family:inherit;">Unblock</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="post" action="<?php echo h(customerPageUrl($listState)); ?>" onsubmit="return confirm('Block this customer?');" style="display:inline;">
                                                    <?php echo csrfInput(); ?>
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="customer_id" value="<?php echo h($row['id']); ?>">
                                                    <input type="hidden" name="target_status" value="blocked">
                                                    <button type="submit" style="background:none; border:none; color:var(--stop); font-size:13px; font-weight:600; cursor:pointer; padding:0; font-family:inherit;">Block</button>
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
                    <div style="padding:24px; border-top:1px solid var(--line); display:flex; justify-content:space-between; align-items:center;">
                        <?php if ($page > 1): ?>
                            <a href="<?php echo h(customerPageUrl($listState, ['page' => $page - 1])); ?>" class="dc-btn-secondary" style="background:var(--surface); text-decoration:none;">Previous</a>
                        <?php else: ?>
                            <div style="width:100px;"></div>
                        <?php endif; ?>

                        <span style="font-size:14px; font-weight:600; color:var(--ink-2);">Page <?php echo h($page); ?> of <?php echo h($totalPages); ?></span>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?php echo h(customerPageUrl($listState, ['page' => $page + 1])); ?>" class="dc-btn-secondary" style="background:var(--surface); text-decoration:none;">Next</a>
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
