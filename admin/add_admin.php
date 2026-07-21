<?php
require_once __DIR__ . '/../includes/security.php';
require_once '../db_connect.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/filter_bar.php';

startSecureSession();

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Rebuild the list URL keeping search, filters and page intact.
 * Same contract as carPageUrl() in manage_cars.php.
 */
function adminPageUrl(array $state, array $overrides = []): string
{
    $params = array_merge($state, $overrides);
    $params = array_filter(
        $params,
        static fn($value) => $value !== null && $value !== '' && $value !== 'all'
    );

    return $params ? 'add_admin.php?' . http_build_query($params) : 'add_admin.php';
}

/** "super_admin" reads as "Super Admin" for a person; the value stays raw. */
function adminRoleLabel(string $role): string
{
    return ucwords(str_replace('_', ' ', $role));
}

function adminRoleClass(string $role): string
{
    return 'role-' . preg_replace('/[^a-z0-9_-]/', '-', strtolower($role));
}

function selectedIf($currentValue, $optionValue): string
{
    return $currentValue === $optionValue ? ' selected' : '';
}

function adminStatusClass(string $status): string
{
    return 'status-' . preg_replace('/[^a-z0-9-]/', '-', strtolower($status));
}

function formatAdminManagementDate(?string $date): string
{
    if ($date === null || $date === '') {
        return 'Not set';
    }

    return date('d M Y, h:i A', strtotime($date));
}

/**
 * Filtered, paginated admin list plus the matching total.
 *
 * @return array{rows: array<int, array>, total: int}
 */
function loadAdmins(mysqli $conn, string $search, string $roleFilter, string $statusFilter, int $perPage, int $offset): array
{
    $where = ['1=1'];
    $params = [];
    $types = '';

    if ($search !== '') {
        $where[] = '(name LIKE ? OR email LIKE ? OR phone LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'sss';
    }

    if ($roleFilter !== '' && $roleFilter !== 'all') {
        $where[] = 'role = ?';
        $params[] = $roleFilter;
        $types .= 's';
    }

    if ($statusFilter !== '' && $statusFilter !== 'all') {
        $where[] = 'status = ?';
        $params[] = $statusFilter;
        $types .= 's';
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM admins $whereSql");
    if ($types !== '') {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $total = (int) (($countStmt->get_result()->fetch_assoc())['total'] ?? 0);

    $listStmt = $conn->prepare(
        "SELECT id, name, email, phone, role, status, created_at, updated_at
         FROM admins
         $whereSql
         ORDER BY created_at DESC, id DESC
         LIMIT ? OFFSET ?"
    );
    $listParams = array_merge($params, [$perPage, $offset]);
    $listStmt->bind_param($types . 'ii', ...$listParams);
    $listStmt->execute();

    return [
        'rows' => $listStmt->get_result()->fetch_all(MYSQLI_ASSOC),
        'total' => $total,
    ];
}

function loadAdminAccount(mysqli $conn, int $adminId): ?array
{
    $stmt = $conn->prepare(
        'SELECT id, name, email, phone, role, status, created_at, updated_at
         FROM admins
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();

    return $admin ?: null;
}

function adminEmailExists(mysqli $conn, string $email, int $adminId = 0): bool
{
    $stmt = $conn->prepare('SELECT id FROM admins WHERE email = ? AND id <> ? LIMIT 1');
    $stmt->bind_param('si', $email, $adminId);
    $stmt->execute();

    return (bool) $stmt->get_result()->fetch_assoc();
}

function activeSuperAdminCount(mysqli $conn): int
{
    $role = 'super_admin';
    $status = 'active';
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM admins WHERE role = ? AND status = ?');
    $stmt->bind_param('ss', $role, $status);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return (int) ($row['total'] ?? 0);
}

function wouldRemoveLastActiveSuperAdmin(mysqli $conn, array $admin, string $newRole, string $newStatus): bool
{
    if (($admin['role'] ?? '') !== 'super_admin' || ($admin['status'] ?? '') !== 'active') {
        return false;
    }

    if ($newRole === 'super_admin' && $newStatus === 'active') {
        return false;
    }

    return activeSuperAdminCount($conn) <= 1;
}

requireSuperAdmin();

$roles = ['super_admin', 'manager', 'staff', 'viewer'];
$statuses = ['active', 'inactive', 'blocked'];
$currentAdminId = (int) ($_SESSION['admin_id'] ?? 0);
$error = '';
$successMessages = [
    'created' => 'Admin account created successfully.',
    'updated' => 'Admin account updated successfully.',
    'blocked' => 'Admin account blocked successfully.',
    'activated' => 'Admin account activated successfully.',
    'password_reset' => 'Admin password reset successfully.',
];
$success = $successMessages[trim($_GET['success'] ?? '')] ?? '';
$admins = [];
$editAdminId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;
$resetAdminId = filter_input(INPUT_GET, 'reset', FILTER_VALIDATE_INT) ?: 0;
$editAdmin = null;
$resetAdmin = null;

$search = trim($_GET['q'] ?? '');
$roleFilter = trim($_GET['role_filter'] ?? 'all');
$statusFilter = trim($_GET['status_filter'] ?? 'all');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$totalAdmins = 0;
$totalPages = 1;

$listState = [
    'q' => $search,
    'role_filter' => $roleFilter,
    'status_filter' => $statusFilter,
    'page' => $page > 1 ? $page : null,
];

$formName = '';
$formEmail = '';
$formPhone = '';
$formRole = 'staff';
$formStatus = 'active';

try {
    $conn = getDbConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireValidCsrfToken();

        $action = trim($_POST['action'] ?? '');

        if ($action === 'create_admin') {
            $formName = trim($_POST['name'] ?? '');
            $formEmail = trim($_POST['email'] ?? '');
            $formPhone = trim($_POST['phone'] ?? '');
            $formRole = trim($_POST['role'] ?? '');
            $formStatus = trim($_POST['status'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if ($formName === '' || $formEmail === '' || $password === '' || $confirmPassword === '') {
                throw new InvalidArgumentException('Please complete all required admin details.');
            }

            if (!filter_var($formEmail, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Please enter a valid email address.');
            }

            if (!in_array($formRole, $roles, true)) {
                throw new InvalidArgumentException('Please choose a valid role.');
            }

            if (!in_array($formStatus, $statuses, true)) {
                throw new InvalidArgumentException('Please choose a valid status.');
            }

            if (strlen($password) < 6) {
                throw new InvalidArgumentException('Password must be at least 6 characters.');
            }

            if ($password !== $confirmPassword) {
                throw new InvalidArgumentException('Passwords do not match.');
            }

            if (adminEmailExists($conn, $formEmail)) {
                throw new InvalidArgumentException('This admin email is already registered.');
            }

            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $phoneValue = $formPhone === '' ? null : $formPhone;
            $stmt = $conn->prepare(
                'INSERT INTO admins (name, email, password, phone, role, status)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('ssssss', $formName, $formEmail, $hashedPassword, $phoneValue, $formRole, $formStatus);
            $stmt->execute();

            header('Location: ' . adminPageUrl($listState, ['success' => 'created']));
            exit;
        }

        if ($action === 'update_admin') {
            $postedAdminId = filter_input(INPUT_POST, 'admin_id', FILTER_VALIDATE_INT) ?: 0;
            $formName = trim($_POST['name'] ?? '');
            $formEmail = trim($_POST['email'] ?? '');
            $formPhone = trim($_POST['phone'] ?? '');
            $formRole = trim($_POST['role'] ?? '');
            $formStatus = trim($_POST['status'] ?? '');
            $admin = $postedAdminId > 0 ? loadAdminAccount($conn, $postedAdminId) : null;

            if (!$admin) {
                throw new InvalidArgumentException('Admin account not found.');
            }

            if ($formName === '' || $formEmail === '') {
                throw new InvalidArgumentException('Name and email are required.');
            }

            if (!filter_var($formEmail, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Please enter a valid email address.');
            }

            if (!in_array($formRole, $roles, true) || !in_array($formStatus, $statuses, true)) {
                throw new InvalidArgumentException('Please choose a valid role and status.');
            }

            if ($postedAdminId === $currentAdminId && $formStatus !== 'active') {
                throw new InvalidArgumentException('You cannot deactivate or block your own account.');
            }

            if (wouldRemoveLastActiveSuperAdmin($conn, $admin, $formRole, $formStatus)) {
                throw new InvalidArgumentException('At least one active super admin must remain.');
            }

            if (adminEmailExists($conn, $formEmail, $postedAdminId)) {
                throw new InvalidArgumentException('This admin email is already registered.');
            }

            $phoneValue = $formPhone === '' ? null : $formPhone;
            $stmt = $conn->prepare(
                'UPDATE admins
                 SET name = ?, email = ?, phone = ?, role = ?, status = ?
                 WHERE id = ?'
            );
            $stmt->bind_param('sssssi', $formName, $formEmail, $phoneValue, $formRole, $formStatus, $postedAdminId);
            $stmt->execute();

            if ($postedAdminId === $currentAdminId) {
                $_SESSION['admin_name'] = $formName;
                $_SESSION['admin_email'] = $formEmail;
                $_SESSION['admin_role'] = $formRole;
                $_SESSION['admin_status'] = $formStatus;
            }

            header('Location: ' . adminPageUrl($listState, ['success' => 'updated']));
            exit;
        }

        if ($action === 'set_status') {
            $postedAdminId = filter_input(INPUT_POST, 'admin_id', FILTER_VALIDATE_INT) ?: 0;
            $targetStatus = trim($_POST['target_status'] ?? '');
            $admin = $postedAdminId > 0 ? loadAdminAccount($conn, $postedAdminId) : null;

            if (!$admin) {
                throw new InvalidArgumentException('Admin account not found.');
            }

            if (!in_array($targetStatus, ['active', 'blocked'], true)) {
                throw new InvalidArgumentException('Please choose a valid status action.');
            }

            if ($postedAdminId === $currentAdminId && $targetStatus !== 'active') {
                throw new InvalidArgumentException('You cannot block your own account.');
            }

            if (wouldRemoveLastActiveSuperAdmin($conn, $admin, (string) $admin['role'], $targetStatus)) {
                throw new InvalidArgumentException('At least one active super admin must remain.');
            }

            $stmt = $conn->prepare('UPDATE admins SET status = ? WHERE id = ?');
            $stmt->bind_param('si', $targetStatus, $postedAdminId);
            $stmt->execute();

            $successType = $targetStatus === 'blocked' ? 'blocked' : 'activated';
            header('Location: ' . adminPageUrl($listState, ['success' => $successType]));
            exit;
        }

        if ($action === 'reset_password') {
            $postedAdminId = filter_input(INPUT_POST, 'admin_id', FILTER_VALIDATE_INT) ?: 0;
            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if ($postedAdminId <= 0 || !loadAdminAccount($conn, $postedAdminId)) {
                throw new InvalidArgumentException('Admin account not found.');
            }

            if ($password === '' || $confirmPassword === '') {
                throw new InvalidArgumentException('Please enter and confirm the new password.');
            }

            if (strlen($password) < 6) {
                throw new InvalidArgumentException('Password must be at least 6 characters.');
            }

            if ($password !== $confirmPassword) {
                throw new InvalidArgumentException('Passwords do not match.');
            }

            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('UPDATE admins SET password = ? WHERE id = ?');
            $stmt->bind_param('si', $hashedPassword, $postedAdminId);
            $stmt->execute();

            header('Location: ' . adminPageUrl($listState, ['success' => 'password_reset']));
            exit;
        }

        throw new InvalidArgumentException('Please choose a valid admin action.');
    }

    if ($editAdminId > 0) {
        $editAdmin = loadAdminAccount($conn, $editAdminId);

        if (!$editAdmin) {
            $error = 'Admin account not found.';
            $editAdminId = 0;
        }
    }

    if ($resetAdminId > 0) {
        $resetAdmin = loadAdminAccount($conn, $resetAdminId);

        if (!$resetAdmin) {
            $error = 'Admin account not found.';
            $resetAdminId = 0;
        }
    }

    $countProbe = loadAdmins($conn, $search, $roleFilter, $statusFilter, 1, 0);
    $totalAdmins = $countProbe['total'];
    $totalPages = max(1, (int) ceil($totalAdmins / $perPage));
    $page = min($page, $totalPages);
    $listState['page'] = $page > 1 ? $page : null;

    $adminList = loadAdmins($conn, $search, $roleFilter, $statusFilter, $perPage, ($page - 1) * $perPage);
    $admins = $adminList['rows'];
} catch (InvalidArgumentException $e) {
    $error = $e->getMessage();

    if ($editAdminId > 0) {
        try {
            $editAdmin = loadAdminAccount($conn, $editAdminId);
        } catch (mysqli_sql_exception $ignored) {
            $editAdmin = null;
        }
    }
} catch (mysqli_sql_exception $e) {
    if ((int) $e->getCode() === 1062) {
        $error = 'This admin email is already registered.';
    } else {
        $error = 'Could not load or update admin accounts. Please check the database connection.';
    }
}

// A failed POST throws before the list loads, so fetch it again for the re-render.
if (isset($conn) && count($admins) === 0 && $error !== '') {
    try {
        $retry = loadAdmins($conn, $search, $roleFilter, $statusFilter, $perPage, 0);
        $admins = $retry['rows'];
        $totalAdmins = $retry['total'];
        $totalPages = max(1, (int) ceil($totalAdmins / $perPage));
    } catch (mysqli_sql_exception $ignored) {
        $admins = [];
    }
}

$createRole = in_array($formRole, $roles, true) ? $formRole : 'staff';
$createStatus = in_array($formStatus, $statuses, true) ? $formStatus : 'active';
?>
<?php
$pageTitle = 'Admin Management | CarGo';
include '../includes/layout_top.php';
include 'header.php';
?>
<?php
$panelMode = $editAdmin ? 'edit' : ($resetAdmin ? 'reset' : (isset($_GET['add']) ? 'add' : ''));
// Keep the panel open when a submit failed so nothing typed is lost.
if ($panelMode === '' && $error !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $panelMode = 'add';
}
$panelOpen = $panelMode !== '';
?>
<main class="dc-main">
    <header class="adm-head">
        <div>
            <div class="dc-mono-subtitle small" style="margin-bottom:8px">Super Admin</div>
            <h1 class="dc-h1" style="font-size:32px;">Admin Management</h1>
            <p class="dc-p" style="margin-top:8px;">
                <?php echo h($totalAdmins); ?> admin <?php echo $totalAdmins === 1 ? 'account' : 'accounts'; ?>.
            </p>
        </div>
        <a class="dc-btn-primary adm-add-btn" href="<?php echo h(adminPageUrl($listState, ['add' => 1, 'edit' => null, 'reset' => null])); ?>">
            <span aria-hidden="true">+</span> Add admin
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
                'action' => 'add_admin.php',
                'search' => [
                    'name' => 'q',
                    'label' => 'Search admins',
                    'value' => $search,
                    'placeholder' => 'Name, email, or phone',
                ],
                'inline_fields' => [
                    [
                        'type' => 'select',
                        'name' => 'role_filter',
                        'label' => 'Role',
                        'value' => $roleFilter,
                        'options' => array_merge(['all' => 'All'], array_combine($roles, array_map('adminRoleLabel', $roles))),
                    ],
                    [
                        'type' => 'select',
                        'name' => 'status_filter',
                        'label' => 'Status',
                        'value' => $statusFilter,
                        'options' => array_merge(['all' => 'All'], array_combine($statuses, array_map('ucfirst', $statuses))),
                    ],
                ],
                'submit_label' => 'Apply',
                'clear_label' => 'Reset',
                'clear_href' => 'add_admin.php',
            ]); ?>
        </div>

        <?php if (count($admins) === 0): ?>
            <div class="adm-empty">
                <p class="dc-p" style="margin-bottom:4px; font-weight:650; color:var(--ink);">No admin accounts match these filters.</p>
                <p class="dc-p" style="font-size:14px;">
                    <a class="adm-link" href="add_admin.php">Clear the filters</a> to see every account.
                </p>
            </div>
        <?php else: ?>
            <div class="adm-tbl-scroll">
                <table class="adm-tbl">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name &amp; contact</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($admins as $admin): ?>
                            <?php $isSelf = (int) $admin['id'] === $currentAdminId; ?>
                            <tr>
                                <td class="adm-id">#<?php echo h($admin['id']); ?></td>
                                <td>
                                    <strong style="display:block; color:var(--ink);">
                                        <?php echo h($admin['name']); ?>
                                        <?php if ($isSelf): ?><span class="adm-you">You</span><?php endif; ?>
                                    </strong>
                                    <div class="adm-muted" style="font-size:12.5px;"><?php echo h($admin['email']); ?></div>
                                    <?php if ($admin['phone']): ?>
                                        <div class="adm-muted" style="font-size:12.5px; margin-top:2px;">&#128222; <?php echo h($admin['phone']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="role-pill <?php echo h(adminRoleClass((string) $admin['role'])); ?>"><?php echo h(adminRoleLabel((string) $admin['role'])); ?></span>
                                </td>
                                <td>
                                    <span class="dc-badge <?php echo h(adminStatusClass((string) $admin['status'])); ?>"><?php echo h(ucfirst($admin['status'])); ?></span>
                                </td>
                                <td>
                                    <div class="adm-actions">
                                        <a class="adm-action" href="<?php echo h(adminPageUrl($listState, ['edit' => $admin['id'], 'reset' => null, 'add' => null])); ?>">Edit</a>
                                        <a class="adm-action" href="<?php echo h(adminPageUrl($listState, ['reset' => $admin['id'], 'edit' => null, 'add' => null])); ?>">Reset</a>

                                        <?php if ($admin['status'] === 'active'): ?>
                                            <form method="post" action="<?php echo h(adminPageUrl($listState)); ?>" onsubmit="return confirm('Block this admin account?');" style="display:inline;">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="action" value="set_status">
                                                <input type="hidden" name="admin_id" value="<?php echo h($admin['id']); ?>">
                                                <input type="hidden" name="target_status" value="blocked">
                                                <button type="submit" class="adm-action is-danger"<?php echo $isSelf ? ' disabled title="You cannot block your own account."' : ''; ?>>Block</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" action="<?php echo h(adminPageUrl($listState)); ?>" style="display:inline;">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="action" value="set_status">
                                                <input type="hidden" name="admin_id" value="<?php echo h($admin['id']); ?>">
                                                <input type="hidden" name="target_status" value="active">
                                                <button type="submit" class="adm-action is-go"><?php echo $admin['status'] === 'blocked' ? 'Unblock' : 'Activate'; ?></button>
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
                        <a href="<?php echo h(adminPageUrl($listState, ['page' => $page - 1])); ?>" class="dc-btn-secondary" style="background:var(--surface); text-decoration:none;">Previous</a>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>
                    <span style="font-size:14px; font-weight:600; color:var(--ink-2);">Page <?php echo h($page); ?> of <?php echo h($totalPages); ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a href="<?php echo h(adminPageUrl($listState, ['page' => $page + 1])); ?>" class="dc-btn-secondary" style="background:var(--surface); text-decoration:none;">Next</a>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Create / edit / reset panel -->
    <div class="adm-panel-backdrop<?php echo $panelOpen ? ' is-open' : ''; ?>">
        <a class="adm-panel-dismiss" href="<?php echo h(adminPageUrl($listState)); ?>" aria-label="Close the admin form"></a>
        <section class="adm-panel" role="dialog" aria-modal="true" aria-labelledby="adminPanelTitle">
            <header class="adm-panel-head">
                <div>
                    <div class="dc-mono-subtitle small" style="margin-bottom:6px">
                        <?php echo $panelMode === 'edit' ? 'Edit' : ($panelMode === 'reset' ? 'Security' : 'New account'); ?>
                    </div>
                    <h2 class="dc-h2" style="font-size:20px;" id="adminPanelTitle">
                        <?php echo $panelMode === 'edit' ? 'Update admin' : ($panelMode === 'reset' ? 'Reset password' : 'Add admin'); ?>
                    </h2>
                </div>
                <a class="adm-panel-close" href="<?php echo h(adminPageUrl($listState)); ?>" aria-label="Close">&times;</a>
            </header>

            <div class="adm-panel-body">
            <?php if ($editAdmin): ?>
                <p class="dc-p" style="margin-bottom:20px; font-size:14px;">Update account details without changing the password.</p>
                <form method="post" action="<?php echo h(adminPageUrl($listState, ['edit' => $editAdmin['id']])); ?>">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="action" value="update_admin">
                    <input type="hidden" name="admin_id" value="<?php echo h($editAdmin['id']); ?>">

                    <div class="adm-field-stack">
                        <label class="adm-field">
                            <span class="adm-field-label">Full Name</span>
                            <input type="text" name="name" value="<?php echo h($editAdmin['name']); ?>" maxlength="100" required class="dc-input" style="width:100%;">
                        </label>
                        <label class="adm-field">
                            <span class="adm-field-label">Email</span>
                            <input type="email" name="email" value="<?php echo h($editAdmin['email']); ?>" maxlength="150" required class="dc-input" style="width:100%;">
                        </label>
                        <label class="adm-field">
                            <span class="adm-field-label">Phone</span>
                            <input type="tel" name="phone" value="<?php echo h($editAdmin['phone'] ?? ''); ?>" maxlength="30" class="dc-input" style="width:100%;">
                        </label>
                        <label class="adm-field">
                            <span class="adm-field-label">Role</span>
                            <select name="role" required class="dc-input" style="width:100%;">
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo h($role); ?>"<?php echo selectedIf($editAdmin['role'], $role); ?>><?php echo h(adminRoleLabel($role)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="adm-field">
                            <span class="adm-field-label">Status</span>
                            <select name="status" required class="dc-input" style="width:100%;">
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?php echo h($status); ?>"<?php echo selectedIf($editAdmin['status'], $status); ?>><?php echo h(ucfirst($status)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>

                    <div class="adm-panel-actions" style="margin-top:24px;">
                        <button type="submit" class="dc-btn-primary" style="width:100%; justify-content:center;">Save changes</button>
                        <a class="adm-panel-cancel" href="<?php echo h(adminPageUrl($listState)); ?>">Cancel</a>
                    </div>
                </form>
            <?php elseif ($resetAdmin): ?>
                <h2 class="dc-h2" style="font-size:20px; margin-bottom:8px;">Reset Password</h2>
                <p class="dc-p" style="margin-bottom:24px; font-size:14px;">Set a new password for <?php echo h($resetAdmin['name']); ?>.</p>
                <form method="post" action="add_admin.php?reset=<?php echo h($resetAdmin['id']); ?>">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="admin_id" value="<?php echo h($resetAdmin['id']); ?>">

                    <div class="adm-field-stack">
                        <label class="adm-field">
                            <span class="adm-field-label">New Password</span>
                            <input type="password" name="password" autocomplete="new-password" required class="dc-input" style="width:100%;">
                        </label>
                        <label class="adm-field">
                            <span class="adm-field-label">Confirm New Password</span>
                            <input type="password" name="confirm_password" autocomplete="new-password" required class="dc-input" style="width:100%;">
                        </label>
                    </div>

                    <div class="adm-panel-actions" style="margin-top:24px;">
                        <button type="submit" class="dc-btn-primary" style="width:100%; justify-content:center;">Reset password</button>
                        <a class="adm-panel-cancel" href="<?php echo h(adminPageUrl($listState)); ?>">Cancel</a>
                    </div>
                </form>
            <?php else: ?>
                <h2 class="dc-h2" style="font-size:20px; margin-bottom:8px;">Create Admin</h2>
                <p class="dc-p" style="margin-bottom:24px; font-size:14px;">Create access for another CarGO admin.</p>
                <form method="post" action="add_admin.php">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="action" value="create_admin">

                    <div class="adm-field-stack">
                        <label class="adm-field">
                            <span class="adm-field-label">Full Name</span>
                            <input type="text" name="name" value="<?php echo h($formName); ?>" maxlength="100" required class="dc-input" style="width:100%;">
                        </label>
                        <label class="adm-field">
                            <span class="adm-field-label">Email</span>
                            <input type="email" name="email" value="<?php echo h($formEmail); ?>" maxlength="150" required class="dc-input" style="width:100%;">
                        </label>
                        <label class="adm-field">
                            <span class="adm-field-label">Phone</span>
                            <input type="tel" name="phone" value="<?php echo h($formPhone); ?>" maxlength="30" class="dc-input" style="width:100%;">
                        </label>
                        <label class="adm-field">
                            <span class="adm-field-label">Role</span>
                            <select name="role" required class="dc-input" style="width:100%;">
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo h($role); ?>"<?php echo selectedIf($createRole, $role); ?>><?php echo h(adminRoleLabel($role)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="adm-field">
                            <span class="adm-field-label">Status</span>
                            <select name="status" required class="dc-input" style="width:100%;">
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?php echo h($status); ?>"<?php echo selectedIf($createStatus, $status); ?>><?php echo h(ucfirst($status)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="adm-field">
                            <span class="adm-field-label">Password</span>
                            <input type="password" name="password" autocomplete="new-password" required class="dc-input" style="width:100%;">
                        </label>
                        <label class="adm-field">
                            <span class="adm-field-label">Confirm Password</span>
                            <input type="password" name="confirm_password" autocomplete="new-password" required class="dc-input" style="width:100%;">
                        </label>
                    </div>

                    <div class="adm-panel-actions" style="margin-top:24px;">
                        <button type="submit" class="dc-btn-primary" style="width:100%; justify-content:center;">Create admin</button>
                        <a class="adm-panel-cancel" href="<?php echo h(adminPageUrl($listState)); ?>">Cancel</a>
                    </div>
                </form>
            <?php endif; ?>
            </div>
        </section>
    </div>
</main>
<?php include '../includes/layout_bottom.php'; ?>
