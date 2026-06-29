<?php
require_once __DIR__ . '/includes/auth.php';
require_once '../db_connect.php';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function loadCustomerProfile(mysqli $conn, int $customerId): ?array
{
    $stmt = $conn->prepare(
        'SELECT id, name, email, phone, address, password
         FROM customers
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $customerId);
    $stmt->execute();

    $customer = $stmt->get_result()->fetch_assoc();

    return $customer ?: null;
}

function customerProfileEmailExists(mysqli $conn, string $email, int $customerId): bool
{
    $stmt = $conn->prepare('SELECT id FROM customers WHERE email = ? AND id <> ? LIMIT 1');
    $stmt->bind_param('si', $email, $customerId);
    $stmt->execute();

    return $stmt->get_result()->num_rows > 0;
}

startSecureSession();
requireCustomerLogin();

$customerId = (int) ($_SESSION['customer_id'] ?? 0);
$customer = null;
$error = '';
$success = '';
$name = '';
$email = '';
$phone = '';
$address = '';

try {
    $conn = getDbConnection();

    if ($customerId <= 0) {
        throw new InvalidArgumentException('Please log in again to update your profile.');
    }

    $customer = loadCustomerProfile($conn, $customerId);

    if (!$customer) {
        throw new InvalidArgumentException('Customer profile not found.');
    }

    $name = (string) $customer['name'];
    $email = (string) $customer['email'];
    $phone = (string) ($customer['phone'] ?? '');
    $address = (string) ($customer['address'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireValidCsrfToken();

        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $shouldUpdatePassword = $currentPassword !== '' || $newPassword !== '' || $confirmPassword !== '';

        if ($name === '') {
            throw new InvalidArgumentException('Name is required.');
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Please enter a valid email address.');
        }

        if (strlen($name) > 100) {
            throw new InvalidArgumentException('Name must be 100 characters or fewer.');
        }

        if (strlen($email) > 150) {
            throw new InvalidArgumentException('Email must be 150 characters or fewer.');
        }

        if (strlen($phone) > 30) {
            throw new InvalidArgumentException('Phone must be 30 characters or fewer.');
        }

        if (strlen($address) > 255) {
            throw new InvalidArgumentException('Address must be 255 characters or fewer.');
        }

        if (customerProfileEmailExists($conn, $email, $customerId)) {
            throw new InvalidArgumentException('This email is already registered.');
        }

        $phoneValue = $phone === '' ? null : $phone;
        $addressValue = $address === '' ? null : $address;

        if ($shouldUpdatePassword) {
            if ($currentPassword === '') {
                throw new InvalidArgumentException('Enter your current password to change your password.');
            }

            if (!password_verify($currentPassword, (string) $customer['password'])) {
                throw new InvalidArgumentException('Current password is incorrect.');
            }

            if (strlen($newPassword) < 6) {
                throw new InvalidArgumentException('New password must be at least 6 characters.');
            }

            if ($newPassword !== $confirmPassword) {
                throw new InvalidArgumentException('New passwords do not match.');
            }

            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare(
                'UPDATE customers
                 SET name = ?,
                     email = ?,
                     phone = ?,
                     address = ?,
                     password = ?
                 WHERE id = ?'
            );
            $stmt->bind_param('sssssi', $name, $email, $phoneValue, $addressValue, $hashedPassword, $customerId);
        } else {
            $stmt = $conn->prepare(
                'UPDATE customers
                 SET name = ?,
                     email = ?,
                     phone = ?,
                     address = ?
                 WHERE id = ?'
            );
            $stmt->bind_param('ssssi', $name, $email, $phoneValue, $addressValue, $customerId);
        }

        $stmt->execute();
        $_SESSION['customer_name'] = $name;
        $_SESSION['user_email'] = $email;
        $success = 'Profile updated successfully.';
        $customer = loadCustomerProfile($conn, $customerId);
    }
} catch (InvalidArgumentException $e) {
    $error = $e->getMessage();
} catch (mysqli_sql_exception $e) {
    $error = 'Could not update your profile. Please check the database connection.';
}
?>
<?php
$pageTitle = 'Profile Settings | CarGo';
include '../includes/layout_top.php';
include 'header.php';
?>
<main class="dc-main">
    <header class="dc-h2-title" style="margin-bottom: 24px; display:flex; justify-content:space-between; align-items:center;">
        <div>
            <div class="dc-mono-subtitle small" style="margin-bottom:8px">Account</div>
            <h1 class="dc-h1" style="font-size:32px;">Profile settings</h1>
        </div>
        <a class="dc-btn-secondary" href="dashboard.php" style="background:#fff;">Back to Dashboard</a>
    </header>

    <div class="dc-card padded" style="max-width: 600px; margin: 0 auto;">
        <h2 class="dc-h2" style="font-size:20px; margin-bottom:8px;">Personal information</h2>
        <p class="dc-p" style="margin-bottom:24px; font-size:14px;">Update your account details and password.</p>

        <?php if ($error !== ''): ?>
            <p class="message error" style="color: #c23a52; background: #fbeaed; padding: 12px; border-radius: 8px; font-weight: 600; margin-bottom:24px;"><?php echo h($error); ?></p>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <p class="message success" style="color: #0b7a5a; background: #e6f6f1; padding: 12px; border-radius: 8px; font-weight: 600; margin-bottom:24px;"><?php echo h($success); ?></p>
        <?php endif; ?>

        <form method="post" action="profile.php">
            <?php echo csrfInput(); ?>

            <div style="display:flex; flex-direction:column; gap:16px;">
                <label style="display:block;">
                    <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Full Name</span>
                    <input type="text" id="name" name="name" value="<?php echo h($name); ?>" maxlength="100" autocomplete="name" required class="dc-input" style="width:100%;">
                </label>

                <label style="display:block;">
                    <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Email</span>
                    <input type="email" id="email" name="email" value="<?php echo h($email); ?>" maxlength="150" autocomplete="email" required class="dc-input" style="width:100%;">
                </label>

                <label style="display:block;">
                    <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Phone</span>
                    <input type="tel" id="phone" name="phone" value="<?php echo h($phone); ?>" maxlength="30" autocomplete="tel" class="dc-input" style="width:100%;">
                </label>

                <label style="display:block;">
                    <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Address</span>
                    <textarea id="address" name="address" rows="3" maxlength="255" class="dc-input" style="width:100%; min-height:80px; resize:vertical;"><?php echo h($address); ?></textarea>
                </label>
            </div>

            <div style="margin-top:32px; padding-top:24px; border-top:1px solid #e4e8f1;">
                <h3 class="dc-h2" style="font-size:18px; margin-bottom:8px;">Password</h3>
                <p class="dc-p" style="margin-bottom:20px; font-size:13px;">Leave these fields blank if you do not want to change your password.</p>

                <div style="display:flex; flex-direction:column; gap:16px;">
                    <label style="display:block;">
                        <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Current Password</span>
                        <input type="password" id="current_password" name="current_password" autocomplete="current-password" class="dc-input" style="width:100%;">
                    </label>

                    <label style="display:block;">
                        <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">New Password</span>
                        <input type="password" id="new_password" name="new_password" autocomplete="new-password" class="dc-input" style="width:100%;">
                    </label>

                    <label style="display:block;">
                        <span style="display:block; margin-bottom:6px; font-size:13px; font-weight:600;">Confirm New Password</span>
                        <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" class="dc-input" style="width:100%;">
                    </label>
                </div>
            </div>

            <div style="margin-top:32px; display:flex; gap:12px; align-items:center;">
                <button type="submit" class="dc-btn-primary" style="padding:12px 24px;">Save Changes</button>
                <a href="dashboard.php" style="color:#5b6273; font-weight:600; font-size:14px; text-decoration:none;">Cancel</a>
            </div>
        </form>
    </div>
</main>
<?php include '../includes/layout_bottom.php'; ?>
