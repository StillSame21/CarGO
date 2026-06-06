<?php
require_once __DIR__ . '/../includes/security.php';
require_once '../db_connect.php';
require_once __DIR__ . '/includes/filter_bar.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatCustomerDate(?string $date): string
{
    if ($date === null || $date === '') {
        return 'Not set';
    }

    return date('d M Y, h:i A', strtotime($date));
}

function selectedIf($currentValue, $optionValue): string
{
    return $currentValue === $optionValue ? ' selected' : '';
}

function customerStatusClass(string $status): string
{
    return 'status-' . preg_replace('/[^a-z0-9-]/', '-', strtolower($status));
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

function customerEmailExists(mysqli $conn, string $email, int $customerId): bool
{
    $stmt = $conn->prepare('SELECT id FROM customers WHERE email = ? AND id <> ? LIMIT 1');
    $stmt->bind_param('si', $email, $customerId);
    $stmt->execute();

    return (bool) $stmt->get_result()->fetch_assoc();
}

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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Customers | CarGo</title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body>
    <main class="dashboard-page">
        <header class="dashboard-header">
            <?php include 'header.php'; ?>
        </header>

        <section class="dashboard-content">
            <div class="dashboard-shell">
                <?php if ($viewCustomerId > 0 && $customer): ?>
                    <a class="back-link" href="<?php echo h(customerPageUrl($listState)); ?>">Back to customers</a>

                    <?php if ($error !== ''): ?>
                        <p class="message error"><?php echo h($error); ?></p>
                    <?php endif; ?>

                    <section class="admin-customer-detail">
                        <section class="admin-detail-panel">
                            <div class="admin-section-heading">
                                <p class="eyebrow">Customer #<?php echo h($customer['id']); ?></p>
                                <h2><?php echo h($customer['name']); ?></h2>
                            </div>

                            <dl class="admin-detail-list">
                                <div><dt>ID</dt><dd><?php echo h($customer['id']); ?></dd></div>
                                <div><dt>Name</dt><dd><?php echo h($customer['name']); ?></dd></div>
                                <div><dt>Email</dt><dd><?php echo h($customer['email']); ?></dd></div>
                                <div><dt>Phone</dt><dd><?php echo h($customer['phone'] ?: 'Not provided'); ?></dd></div>
                                <div><dt>Address</dt><dd><?php echo h($customer['address'] ?: 'Not provided'); ?></dd></div>
                                <div><dt>Status</dt><dd><span class="status-pill <?php echo h(customerStatusClass($customer['status'])); ?>"><?php echo h(ucfirst($customer['status'])); ?></span></dd></div>
                                <div><dt>Created At</dt><dd><?php echo h(formatCustomerDate($customer['created_at'])); ?></dd></div>
                            </dl>

                            <div class="customer-detail-actions">
                                <a class="table-action-link" href="<?php echo h(customerPageUrl($listState, ['edit' => $customer['id'], 'view' => null])); ?>">Edit Customer</a>
                            </div>
                        </section>

                        <section class="admin-detail-panel">
                            <div class="admin-section-heading">
                                <p class="eyebrow">Bookings</p>
                                <h2>Recent booking summary</h2>
                            </div>

                            <div class="customer-summary-grid">
                                <span>
                                    <strong><?php echo h($bookingSummary['total_bookings'] ?? 0); ?></strong>
                                    Total bookings
                                </span>
                                <span>
                                    <strong><?php echo h(formatCustomerDate($bookingSummary['latest_booking_at'] ?? null)); ?></strong>
                                    Latest booking
                                </span>
                            </div>

                            <?php if (empty($bookingSummary['recent_bookings'])): ?>
                                <p class="empty-table-message customer-empty-message">No bookings found for this customer.</p>
                            <?php else: ?>
                                <div class="table-wrap compact-table-wrap">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Booking</th>
                                                <th>Vehicle</th>
                                                <th>Dates</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($bookingSummary['recent_bookings'] as $booking): ?>
                                                <tr>
                                                    <td>
                                                        <strong>#<?php echo h($booking['id']); ?></strong>
                                                        <span>RM <?php echo h(number_format((float) $booking['total_amount'], 2)); ?></span>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo h($booking['brand'] . ' ' . $booking['model']); ?></strong>
                                                        <span><?php echo h($booking['plate_number']); ?></span>
                                                    </td>
                                                    <td><?php echo h(formatCustomerDate($booking['pickup_date']) . ' - ' . formatCustomerDate($booking['return_date'])); ?></td>
                                                    <td><span class="status-pill <?php echo h(customerStatusClass($booking['booking_status'])); ?>"><?php echo h(ucfirst($booking['booking_status'])); ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </section>
                    </section>
                <?php elseif ($editCustomerId > 0): ?>
                    <a class="back-link" href="<?php echo h(customerPageUrl($listState)); ?>">Back to customers</a>

                    <section class="admin-form-shell">
                        <section class="login-card register-card admin-form-card customer-edit-card">
                            <h2>Edit Customer</h2>
                            <p class="subtitle">Update account details without changing the password.</p>

                            <?php if ($error !== ''): ?>
                                <div class="message error"><?php echo h($error); ?></div>
                            <?php endif; ?>

                            <form method="post" action="<?php echo h(customerPageUrl($listState, ['edit' => $editCustomerId])); ?>">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="action" value="update_customer">
                                <input type="hidden" name="customer_id" value="<?php echo h($editCustomerId); ?>">

                                <label for="name">Name</label>
                                <input type="text" id="name" name="name" value="<?php echo h($editName); ?>" maxlength="100" required>

                                <label for="email">Email</label>
                                <input type="email" id="email" name="email" value="<?php echo h($editEmail); ?>" maxlength="150" required>

                                <label for="phone">Phone</label>
                                <input type="tel" id="phone" name="phone" value="<?php echo h($editPhone); ?>" maxlength="30">

                                <label for="address">Address</label>
                                <textarea id="address" name="address" rows="3" maxlength="255"><?php echo h($editAddress); ?></textarea>

                                <label for="status">Status</label>
                                <select id="status" name="status" required>
                                    <?php foreach ($statuses as $status): ?>
                                        <option value="<?php echo h($status); ?>"<?php echo selectedIf($editStatus, $status); ?>>
                                            <?php echo h(ucfirst($status)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <div class="form-actions stacked-actions">
                                    <button type="submit">Save Changes</button>
                                    <a class="cancel-edit-link" href="<?php echo h(customerPageUrl($listState)); ?>">Cancel</a>
                                </div>
                            </form>
                        </section>
                    </section>
                <?php else: ?>
                    <header class="page-heading">
                        <div>
                            <p class="eyebrow">Customers</p>
                            <h1>Manage customers</h1>
                        </div>
                    </header>

                    <?php if ($error !== ''): ?>
                        <p class="message error"><?php echo h($error); ?></p>
                    <?php endif; ?>

                    <?php if ($success !== ''): ?>
                        <p class="message success"><?php echo h($success); ?></p>
                    <?php endif; ?>

                    <section class="cars-list-panel admin-customers-panel">
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

                        <?php if (count($customers) === 0): ?>
                            <p class="empty-table-message">No customers found.</p>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Name</th>
                                            <th>Email</th>
                                            <th>Phone</th>
                                            <th>Address</th>
                                            <th>Status</th>
                                            <th>Created At</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($customers as $row): ?>
                                            <tr>
                                                <td>#<?php echo h($row['id']); ?></td>
                                                <td><strong><?php echo h($row['name']); ?></strong></td>
                                                <td><?php echo h($row['email']); ?></td>
                                                <td><?php echo h($row['phone'] ?: 'Not provided'); ?></td>
                                                <td><?php echo h($row['address'] ?: 'Not provided'); ?></td>
                                                <td><span class="status-pill <?php echo h(customerStatusClass($row['status'])); ?>"><?php echo h(ucfirst($row['status'])); ?></span></td>
                                                <td><?php echo h(formatCustomerDate($row['created_at'])); ?></td>
                                                <td>
                                                    <div class="car-row-actions customer-row-actions">
                                                        <a class="table-action-link" href="<?php echo h(customerPageUrl($listState, ['view' => $row['id']])); ?>">View</a>
                                                        <a class="table-action-link" href="<?php echo h(customerPageUrl($listState, ['edit' => $row['id']])); ?>">Edit</a>
                                                        <?php if ($row['status'] === 'blocked'): ?>
                                                            <form method="post" action="<?php echo h(customerPageUrl($listState)); ?>">
                                                                <?php echo csrfInput(); ?>
                                                                <input type="hidden" name="action" value="toggle_status">
                                                                <input type="hidden" name="customer_id" value="<?php echo h($row['id']); ?>">
                                                                <input type="hidden" name="target_status" value="active">
                                                                <button class="table-action-button" type="submit">Unblock</button>
                                                            </form>
                                                        <?php else: ?>
                                                            <form method="post" action="<?php echo h(customerPageUrl($listState)); ?>" onsubmit="return confirm('Block this customer?');">
                                                                <?php echo csrfInput(); ?>
                                                                <input type="hidden" name="action" value="toggle_status">
                                                                <input type="hidden" name="customer_id" value="<?php echo h($row['id']); ?>">
                                                                <input type="hidden" name="target_status" value="blocked">
                                                                <button class="table-action-button danger" type="submit">Block</button>
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
                                <nav class="pagination" aria-label="Customer pages">
                                    <?php if ($page > 1): ?>
                                        <a href="<?php echo h(customerPageUrl($listState, ['page' => $page - 1])); ?>">Previous</a>
                                    <?php endif; ?>

                                    <span>Page <?php echo h($page); ?> of <?php echo h($totalPages); ?></span>

                                    <?php if ($page < $totalPages): ?>
                                        <a href="<?php echo h(customerPageUrl($listState, ['page' => $page + 1])); ?>">Next</a>
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
