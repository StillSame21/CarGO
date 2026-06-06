<?php
require_once __DIR__ . '/../includes/security.php';
require_once '../db_connect.php';
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

function loadAdmins(mysqli $conn): array
{
    $result = $conn->query(
        'SELECT id, name, email, phone, role, status, created_at, updated_at
         FROM admins
         ORDER BY created_at DESC, id DESC'
    );

    return $result->fetch_all(MYSQLI_ASSOC);
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

            header('Location: add_admin.php?success=created');
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

            header('Location: add_admin.php?success=updated');
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
            header('Location: add_admin.php?success=' . $successType);
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

            header('Location: add_admin.php?success=password_reset');
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

    $admins = loadAdmins($conn);
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

if (isset($conn) && count($admins) === 0) {
    try {
        $admins = loadAdmins($conn);
    } catch (mysqli_sql_exception $ignored) {
        $admins = [];
    }
}

$createRole = in_array($formRole, $roles, true) ? $formRole : 'staff';
$createStatus = in_array($formStatus, $statuses, true) ? $formStatus : 'active';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Management | CarGo</title>
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
                <header class="page-heading">
                    <div>
                        <p class="eyebrow">Super Admin</p>
                        <h1>Admin Management</h1>
                        <p class="dashboard-heading-subtitle">Create and manage CarGO admin accounts.</p>
                    </div>
                </header>

                <?php if ($error !== ''): ?>
                    <p class="message error"><?php echo h($error); ?></p>
                <?php endif; ?>

                <?php if ($success !== ''): ?>
                    <p class="message success"><?php echo h($success); ?></p>
                <?php endif; ?>

                <section class="admin-management-layout">
                    <section class="login-card register-card admin-management-form">
                        <?php if ($editAdmin): ?>
                            <h2>Edit Admin</h2>
                            <p class="subtitle">Update account details without changing the password.</p>
                            <form method="post" action="add_admin.php?edit=<?php echo h($editAdmin['id']); ?>">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="action" value="update_admin">
                                <input type="hidden" name="admin_id" value="<?php echo h($editAdmin['id']); ?>">

                                <label for="edit_name">Full Name</label>
                                <input type="text" id="edit_name" name="name" value="<?php echo h($editAdmin['name']); ?>" maxlength="100" required>

                                <label for="edit_email">Email</label>
                                <input type="email" id="edit_email" name="email" value="<?php echo h($editAdmin['email']); ?>" maxlength="150" required>

                                <label for="edit_phone">Phone</label>
                                <input type="tel" id="edit_phone" name="phone" value="<?php echo h($editAdmin['phone'] ?? ''); ?>" maxlength="30">

                                <label for="edit_role">Role</label>
                                <select id="edit_role" name="role" required>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?php echo h($role); ?>"<?php echo selectedIf($editAdmin['role'], $role); ?>><?php echo h($role); ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <label for="edit_status">Status</label>
                                <select id="edit_status" name="status" required>
                                    <?php foreach ($statuses as $status): ?>
                                        <option value="<?php echo h($status); ?>"<?php echo selectedIf($editAdmin['status'], $status); ?>><?php echo h(ucfirst($status)); ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <div class="form-actions stacked-actions">
                                    <button type="submit">Save Changes</button>
                                    <a class="cancel-edit-link" href="add_admin.php">Cancel</a>
                                </div>
                            </form>
                        <?php elseif ($resetAdmin): ?>
                            <h2>Reset Password</h2>
                            <p class="subtitle">Set a new password for <?php echo h($resetAdmin['name']); ?>.</p>
                            <form method="post" action="add_admin.php?reset=<?php echo h($resetAdmin['id']); ?>">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="admin_id" value="<?php echo h($resetAdmin['id']); ?>">

                                <label for="reset_password">New Password</label>
                                <input type="password" id="reset_password" name="password" autocomplete="new-password" required>

                                <label for="reset_confirm_password">Confirm New Password</label>
                                <input type="password" id="reset_confirm_password" name="confirm_password" autocomplete="new-password" required>

                                <div class="form-actions stacked-actions">
                                    <button type="submit">Reset Password</button>
                                    <a class="cancel-edit-link" href="add_admin.php">Cancel</a>
                                </div>
                            </form>
                        <?php else: ?>
                            <h2>Create Admin</h2>
                            <p class="subtitle">Create access for another CarGO admin.</p>
                            <form method="post" action="add_admin.php">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="action" value="create_admin">

                                <label for="name">Full Name</label>
                                <input type="text" id="name" name="name" value="<?php echo h($formName); ?>" maxlength="100" required>

                                <label for="email">Email</label>
                                <input type="email" id="email" name="email" value="<?php echo h($formEmail); ?>" maxlength="150" required>

                                <label for="phone">Phone</label>
                                <input type="tel" id="phone" name="phone" value="<?php echo h($formPhone); ?>" maxlength="30">

                                <label for="role">Role</label>
                                <select id="role" name="role" required>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?php echo h($role); ?>"<?php echo selectedIf($createRole, $role); ?>><?php echo h($role); ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <label for="status">Status</label>
                                <select id="status" name="status" required>
                                    <?php foreach ($statuses as $status): ?>
                                        <option value="<?php echo h($status); ?>"<?php echo selectedIf($createStatus, $status); ?>><?php echo h(ucfirst($status)); ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <label for="password">Password</label>
                                <input type="password" id="password" name="password" autocomplete="new-password" required>

                                <label for="confirm_password">Confirm Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>

                                <button type="submit">Create Admin</button>
                            </form>
                        <?php endif; ?>
                    </section>

                    <section class="cars-list-panel admin-management-panel">
                        <header class="panel-heading">
                            <p class="eyebrow">Accounts</p>
                            <h2>Admin List</h2>
                        </header>

                        <?php if (count($admins) === 0): ?>
                            <p class="empty-table-message">No admin accounts found.</p>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Name</th>
                                            <th>Email</th>
                                            <th>Phone</th>
                                            <th>Role</th>
                                            <th>Status</th>
                                            <th>Created At</th>
                                            <th>Updated At</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($admins as $admin): ?>
                                            <tr>
                                                <td>#<?php echo h($admin['id']); ?></td>
                                                <td><strong><?php echo h($admin['name']); ?></strong></td>
                                                <td><?php echo h($admin['email']); ?></td>
                                                <td><?php echo h($admin['phone'] ?: 'Not provided'); ?></td>
                                                <td><span class="role-pill role-<?php echo h($admin['role']); ?>"><?php echo h($admin['role']); ?></span></td>
                                                <td><span class="status-pill <?php echo h(adminStatusClass($admin['status'])); ?>"><?php echo h(ucfirst($admin['status'])); ?></span></td>
                                                <td><?php echo h(formatAdminManagementDate($admin['created_at'])); ?></td>
                                                <td><?php echo h(formatAdminManagementDate($admin['updated_at'])); ?></td>
                                                <td>
                                                    <div class="car-row-actions customer-row-actions">
                                                        <a class="table-action-link" href="add_admin.php?edit=<?php echo h($admin['id']); ?>">Edit</a>
                                                        <a class="table-action-link" href="add_admin.php?reset=<?php echo h($admin['id']); ?>">Reset Password</a>

                                                        <?php if ($admin['status'] === 'active'): ?>
                                                            <form method="post" action="add_admin.php" onsubmit="return confirm('Block this admin account?');">
                                                                <?php echo csrfInput(); ?>
                                                                <input type="hidden" name="action" value="set_status">
                                                                <input type="hidden" name="admin_id" value="<?php echo h($admin['id']); ?>">
                                                                <input type="hidden" name="target_status" value="blocked">
                                                                <button class="table-action-button danger" type="submit">Block</button>
                                                            </form>
                                                        <?php else: ?>
                                                            <form method="post" action="add_admin.php">
                                                                <?php echo csrfInput(); ?>
                                                                <input type="hidden" name="action" value="set_status">
                                                                <input type="hidden" name="admin_id" value="<?php echo h($admin['id']); ?>">
                                                                <input type="hidden" name="target_status" value="active">
                                                                <button class="table-action-button" type="submit"><?php echo $admin['status'] === 'blocked' ? 'Unblock' : 'Activate'; ?></button>
                                                            </form>
                                                        <?php endif; ?>
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
            </div>
        </section>
    </main>
</body>
</html>
