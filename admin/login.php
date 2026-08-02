<?php
require_once __DIR__ . '/../includes/security.php';
require_once '../db_connect.php';

startSecureSession();

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: dashboard.php');
    exit;
}

$error = (string) ($_SESSION['demo_error'] ?? '');
unset($_SESSION['demo_error']);
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Security token expired. Please refresh and try again.';
    } elseif ($email === '' || $password === '') {
        $error = 'Please enter both email and password.';
    } elseif (strlen($email) > 150) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $conn = getDbConnection();
            $stmt = $conn->prepare(
                'SELECT id, name, email, password, role, status FROM admins WHERE email = ? LIMIT 1'
            );
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $admin = $result->fetch_assoc();

            if (!$admin || !password_verify($password, $admin['password'])) {
                $error = 'Invalid admin email or password.';
            } elseif ($admin['status'] !== 'active') {
                $error = 'Your admin account is not active. Please contact a super admin.';
            } else {
                session_regenerate_id(true);
                regenerateCsrfToken();

                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_name'] = $admin['name'];
                $_SESSION['admin_email'] = $admin['email'];
                $_SESSION['admin_role'] = $admin['role'];
                $_SESSION['admin_status'] = $admin['status'];

                header('Location: dashboard.php');
                exit;
            }
        } catch (mysqli_sql_exception $e) {
            $error = 'Admin login failed. Please check the database connection and try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CarGo Admin Login</title>
    <link rel="stylesheet" href="../css/template.css">
    <link rel="stylesheet" href="../css/auth.css">
</head>
<body class="auth-page auth-admin">
    <div class="login-wrapper">
        <div class="login-brand">
            <div class="login-brand-logo">CarGo Admin</div>
            <div class="login-brand-copy">
                <h1>Admin control for CarGo operations.</h1>
                <p>Sign in to manage cars, bookings, customers, and admin access from a dedicated dashboard.</p>
            </div>
        </div>
        <div class="login-form-wrap">
            <h2>Admin Login</h2>
            <p>Login with your admin account.</p>

            <?php if ($error !== ''): ?>
                <div class="message error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="post" action="login.php">
                <?php echo csrfInput(); ?>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" class="dc-input" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="username" required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" class="dc-input" autocomplete="current-password" required>
                </div>

                <button type="submit" class="dc-btn-primary">Login</button>
            </form>

            <div class="auth-divider">or</div>

            <form method="post" action="../demo_login.php" class="demo-launch">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="type" value="admin">
                <button type="submit" class="dc-btn-secondary">Try Admin Demo</button>
            </form>
            <p class="demo-launch-copy">Explore the admin dashboard on a shared demo account &mdash; car, customer, and admin edits are disabled.</p>

            <div class="auth-links">
                <p>Customer login? <a href="../customer/login.php">Go to customer login</a></p>
            </div>
        </div>
    </div>
</body>
</html>
