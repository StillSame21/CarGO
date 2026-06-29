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
<?php
$pageTitle = 'Admin Management | CarGo';
include '../includes/layout_top.php';
include 'header.php';
?>
<main class="dc-main">
    <header class="dc-h2-title" style="margin-bottom: 24px;">
        <div>
            <div class="dc-mono-subtitle small" style="margin-bottom:8px">Super Admin</div>
            <h1 class="dc-h1" style="font-size:32px;">Admin Management</h1>
            <p class="dc-p" style="margin-top:8px;">Create and manage CarGO admin accounts.</p>
        </div>
    </header>

    <?php if ($error !== ''): ?>
        <p class="message error" style="color: #c23a52; background: #fbeaed; padding: 12px; border-radius: 8px; font-weight: 600; margin-bottom:24px;"><?php echo h($error); ?></p>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <p class="message success" style="color: #0b7a5a; background: #e6f6f1; padding: 12px; border-radius: 8px; font-weight: 600; margin-bottom:24px;"><?php echo h($success); ?></p>
    <?php endif; ?>

    <div style="display:grid; grid-template-columns: minmax(320px, 400px) 1fr; gap:24px; align-items:start;">
        <div class="dc-card padded" style="position:sticky; top:24px;">
            <?php if ($editAdmin): ?>
                <h2 class="dc-h2" style="font-size:20px; margin-bottom:8px;">Edit Admin</h2>
                <p class="dc-p" style="margin-bottom:24px; font-size:14px;">Update account details without changing the password.</p>
                <form method="post" action="add_admin.php?edit=<?php echo h($editAdmin['id']); ?>">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="action" value="update_admin">
                    <input type="hidden" name="admin_id" value="<?php echo h($editAdmin['id']); ?>">

                    <div style="display:flex; flex-direction:column; gap:16px;">
                        <label style="display:block;">
                            <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Full Name</span>
                            <input type="text" name="name" value="<?php echo h($editAdmin['name']); ?>" maxlength="100" required class="dc-input" style="width:100%;">
                        </label>
                        <label style="display:block;">
                            <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Email</span>
                            <input type="email" name="email" value="<?php echo h($editAdmin['email']); ?>" maxlength="150" required class="dc-input" style="width:100%;">
                        </label>
                        <label style="display:block;">
                            <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Phone</span>
                            <input type="tel" name="phone" value="<?php echo h($editAdmin['phone'] ?? ''); ?>" maxlength="30" class="dc-input" style="width:100%;">
                        </label>
                        <label style="display:block;">
                            <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Role</span>
                            <select name="role" required class="dc-input" style="width:100%;">
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo h($role); ?>"<?php echo selectedIf($editAdmin['role'], $role); ?>><?php echo h($role); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label style="display:block;">
                            <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Status</span>
                            <select name="status" required class="dc-input" style="width:100%;">
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?php echo h($status); ?>"<?php echo selectedIf($editAdmin['status'], $status); ?>><?php echo h(ucfirst($status)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>

                    <div style="margin-top:24px; display:flex; flex-direction:column; gap:12px;">
                        <button type="submit" class="dc-btn-primary" style="width:100%;">Save Changes</button>
                        <a href="add_admin.php" style="color:#5b6273; font-weight:600; font-size:14px; text-decoration:none; text-align:center;">Cancel</a>
                    </div>
                </form>
            <?php elseif ($resetAdmin): ?>
                <h2 class="dc-h2" style="font-size:20px; margin-bottom:8px;">Reset Password</h2>
                <p class="dc-p" style="margin-bottom:24px; font-size:14px;">Set a new password for <?php echo h($resetAdmin['name']); ?>.</p>
                <form method="post" action="add_admin.php?reset=<?php echo h($resetAdmin['id']); ?>">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="admin_id" value="<?php echo h($resetAdmin['id']); ?>">

                    <div style="display:flex; flex-direction:column; gap:16px;">
                        <label style="display:block;">
                            <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">New Password</span>
                            <input type="password" name="password" autocomplete="new-password" required class="dc-input" style="width:100%;">
                        </label>
                        <label style="display:block;">
                            <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Confirm New Password</span>
                            <input type="password" name="confirm_password" autocomplete="new-password" required class="dc-input" style="width:100%;">
                        </label>
                    </div>

                    <div style="margin-top:24px; display:flex; flex-direction:column; gap:12px;">
                        <button type="submit" class="dc-btn-primary" style="width:100%;">Reset Password</button>
                        <a href="add_admin.php" style="color:#5b6273; font-weight:600; font-size:14px; text-decoration:none; text-align:center;">Cancel</a>
                    </div>
                </form>
            <?php else: ?>
                <h2 class="dc-h2" style="font-size:20px; margin-bottom:8px;">Create Admin</h2>
                <p class="dc-p" style="margin-bottom:24px; font-size:14px;">Create access for another CarGO admin.</p>
                <form method="post" action="add_admin.php">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="action" value="create_admin">

                    <div style="display:flex; flex-direction:column; gap:16px;">
                        <label style="display:block;">
                            <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Full Name</span>
                            <input type="text" name="name" value="<?php echo h($formName); ?>" maxlength="100" required class="dc-input" style="width:100%;">
                        </label>
                        <label style="display:block;">
                            <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Email</span>
                            <input type="email" name="email" value="<?php echo h($formEmail); ?>" maxlength="150" required class="dc-input" style="width:100%;">
                        </label>
                        <label style="display:block;">
                            <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Phone</span>
                            <input type="tel" name="phone" value="<?php echo h($formPhone); ?>" maxlength="30" class="dc-input" style="width:100%;">
                        </label>
                        <label style="display:block;">
                            <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Role</span>
                            <select name="role" required class="dc-input" style="width:100%;">
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo h($role); ?>"<?php echo selectedIf($createRole, $role); ?>><?php echo h($role); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label style="display:block;">
                            <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Status</span>
                            <select name="status" required class="dc-input" style="width:100%;">
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?php echo h($status); ?>"<?php echo selectedIf($createStatus, $status); ?>><?php echo h(ucfirst($status)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label style="display:block;">
                            <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Password</span>
                            <input type="password" name="password" autocomplete="new-password" required class="dc-input" style="width:100%;">
                        </label>
                        <label style="display:block;">
                            <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Confirm Password</span>
                            <input type="password" name="confirm_password" autocomplete="new-password" required class="dc-input" style="width:100%;">
                        </label>
                    </div>

                    <div style="margin-top:24px;">
                        <button type="submit" class="dc-btn-primary" style="width:100%;">Create Admin</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <div class="dc-card">
            <div style="padding:24px; border-bottom:1px solid #e4e8f1;">
                <div class="dc-mono-subtitle small" style="margin-bottom:8px">Accounts</div>
                <h2 class="dc-h2" style="font-size:20px;">Admin List</h2>
            </div>
            
            <?php if (count($admins) === 0): ?>
                <div style="padding: 40px 24px; text-align: center; color: #5b6273;">
                    <p>No admin accounts found.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="dc-table" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="border-bottom: 1px solid #e4e8f1; background: #f9fafc;">
                                <th style="padding: 16px 24px; text-align: left; font-size: 13px; color: #5b6273; font-weight: 600;">ID</th>
                                <th style="padding: 16px 24px; text-align: left; font-size: 13px; color: #5b6273; font-weight: 600;">Name & Email</th>
                                <th style="padding: 16px 24px; text-align: left; font-size: 13px; color: #5b6273; font-weight: 600;">Role</th>
                                <th style="padding: 16px 24px; text-align: left; font-size: 13px; color: #5b6273; font-weight: 600;">Status</th>
                                <th style="padding: 16px 24px; text-align: right; font-size: 13px; color: #5b6273; font-weight: 600;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($admins as $admin): ?>
                                <tr style="border-bottom: 1px solid #e4e8f1;">
                                    <td style="padding: 16px 24px; color: #131722; font-size: 14px;">#<?php echo h($admin['id']); ?></td>
                                    <td style="padding: 16px 24px;">
                                        <strong style="color: #131722; display:block; margin-bottom:4px;"><?php echo h($admin['name']); ?></strong>
                                        <div style="color: #5b6273; font-size:13px;"><?php echo h($admin['email']); ?></div>
                                        <?php if($admin['phone']): ?>
                                            <div style="color: #5b6273; font-size:13px; margin-top:2px;">&#128222; <?php echo h($admin['phone']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 16px 24px;">
                                        <span class="dc-badge" style="background:#eef1fb; color:#3b5fda;"><?php echo h($admin['role']); ?></span>
                                    </td>
                                    <td style="padding: 16px 24px;">
                                        <?php 
                                            $statusColor = $admin['status'] === 'active' ? '#0b7a5a' : ($admin['status'] === 'blocked' ? '#c23a52' : '#8891a5');
                                            $statusBg = $admin['status'] === 'active' ? '#e6f6f1' : ($admin['status'] === 'blocked' ? '#fbeaed' : '#f1f3f9');
                                        ?>
                                        <span class="dc-badge" style="background:<?php echo $statusBg; ?>; color:<?php echo $statusColor; ?>;">
                                            <?php echo h(ucfirst($admin['status'])); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 16px 24px; text-align: right;">
                                        <div style="display:inline-flex; gap:8px;">
                                            <a href="add_admin.php?edit=<?php echo h($admin['id']); ?>" style="color:#3b5fda; font-size:13px; font-weight:600; text-decoration:none;">Edit</a>
                                            <a href="add_admin.php?reset=<?php echo h($admin['id']); ?>" style="color:#5b6273; font-size:13px; font-weight:600; text-decoration:none;">Reset</a>

                                            <?php if ($admin['status'] === 'active'): ?>
                                                <form method="post" action="add_admin.php" onsubmit="return confirm('Block this admin account?');" style="display:inline;">
                                                    <?php echo csrfInput(); ?>
                                                    <input type="hidden" name="action" value="set_status">
                                                    <input type="hidden" name="admin_id" value="<?php echo h($admin['id']); ?>">
                                                    <input type="hidden" name="target_status" value="blocked">
                                                    <button type="submit" style="background:none; border:none; color:#c23a52; font-size:13px; font-weight:600; cursor:pointer; padding:0; font-family:inherit;">Block</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="post" action="add_admin.php" style="display:inline;">
                                                    <?php echo csrfInput(); ?>
                                                    <input type="hidden" name="action" value="set_status">
                                                    <input type="hidden" name="admin_id" value="<?php echo h($admin['id']); ?>">
                                                    <input type="hidden" name="target_status" value="active">
                                                    <button type="submit" style="background:none; border:none; color:#0b7a5a; font-size:13px; font-weight:600; cursor:pointer; padding:0; font-family:inherit;"><?php echo $admin['status'] === 'blocked' ? 'Unblock' : 'Activate'; ?></button>
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
        </div>
    </div>
</main>
<?php include '../includes/layout_bottom.php'; ?>
