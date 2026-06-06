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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Settings | CarGo</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/customer.css">
</head>
<body>
    <main class="dashboard-page">
        <header class="dashboard-header">
            <?php include 'header.php'; ?>
        </header>

        <section class="dashboard-content">
            <div class="dashboard-shell">
                <header class="page-heading profile-heading">
                    <div>
                        <p class="eyebrow">Account</p>
                        <h1>Profile settings</h1>
                    </div>
                    <a class="secondary-action" href="dashboard.php">Back to Dashboard</a>
                </header>

                <section class="profile-settings-layout">
                    <section class="login-card register-card profile-settings-card">
                        <h2>Personal information</h2>
                        <p class="subtitle">Update your account details and password.</p>

                        <?php if ($error !== ''): ?>
                            <div class="message error"><?php echo h($error); ?></div>
                        <?php endif; ?>

                        <?php if ($success !== ''): ?>
                            <div class="message success"><?php echo h($success); ?></div>
                        <?php endif; ?>

                        <form method="post" action="profile.php" class="profile-settings-form">
                            <?php echo csrfInput(); ?>

                            <div class="profile-form-grid">
                                <label for="name">
                                    Full Name
                                    <input type="text" id="name" name="name" value="<?php echo h($name); ?>" maxlength="100" autocomplete="name" required>
                                </label>

                                <label for="email">
                                    Email
                                    <input type="email" id="email" name="email" value="<?php echo h($email); ?>" maxlength="150" autocomplete="email" required>
                                </label>

                                <label for="phone">
                                    Phone
                                    <input type="tel" id="phone" name="phone" value="<?php echo h($phone); ?>" maxlength="30" autocomplete="tel">
                                </label>

                                <label for="address" class="profile-full-width">
                                    Address
                                    <textarea id="address" name="address" rows="3" maxlength="255"><?php echo h($address); ?></textarea>
                                </label>
                            </div>

                            <div class="profile-password-section">
                                <h3>Password</h3>
                                <p>Leave these fields blank if you do not want to change your password.</p>

                                <div class="profile-form-grid">
                                    <label for="current_password">
                                        Current Password
                                        <input type="password" id="current_password" name="current_password" autocomplete="current-password">
                                    </label>

                                    <label for="new_password">
                                        New Password
                                        <input type="password" id="new_password" name="new_password" autocomplete="new-password">
                                    </label>

                                    <label for="confirm_password">
                                        Confirm New Password
                                        <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password">
                                    </label>
                                </div>
                            </div>

                            <div class="profile-form-actions">
                                <button type="submit">Save Changes</button>
                                <a class="cancel-edit-link" href="dashboard.php">Cancel</a>
                            </div>
                        </form>
                    </section>
                </section>
            </div>
        </section>
    </main>
</body>
</html>
