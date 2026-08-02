<?php
require_once __DIR__ . '/../includes/security.php';
require_once '../db_connect.php';

startSecureSession();

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
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
                'SELECT id, name, email, password, status FROM customers WHERE email = ? LIMIT 1'
            );
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $customer = $result->fetch_assoc();

            if (!$customer || !password_verify($password, $customer['password'])) {
                $error = 'Invalid email or password.';
            } elseif ($customer['status'] !== 'active') {
                $error = 'Your account is not active. Please contact support.';
            } else {
                session_regenerate_id(true);
                regenerateCsrfToken();

                $_SESSION['logged_in'] = true;
                $_SESSION['customer_id'] = $customer['id'];
                $_SESSION['customer_name'] = $customer['name'];
                $_SESSION['user_email'] = $customer['email'];

                header('Location: dashboard.php');
                exit;
            }
        } catch (mysqli_sql_exception $e) {
            $error = 'Login failed. Please check the database connection and try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CarGo Customer Login</title>
    <link rel="stylesheet" href="../css/template.css">
    <link rel="stylesheet" href="../css/auth.css">
</head>
<body class="auth-page auth-customer">
    <div class="login-wrapper">
        <div class="login-brand">
            <div class="login-brand-logo">CarGo</div>
            <div class="login-brand-copy">
                <h1>Drive your dreams today.</h1>
                <p>Sign in to manage your bookings, browse our premium fleet, and explore the open road.</p>
            </div>
        </div>
        <div class="login-form-wrap">
            <h2>Welcome back</h2>
            <p>Login to continue to your dashboard.</p>

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
                <input type="hidden" name="type" value="customer">
                <button type="submit" class="dc-btn-secondary">Try Customer Demo</button>
            </form>
            <p class="demo-launch-copy">Browse cars and make a sandbox booking on a shared demo account &mdash; no signup needed.</p>

            <div class="auth-links">
                <p>New customer? <a href="register.php">Create an account</a></p>
                <p>Admin user? <a href="../admin/login.php">Go to admin login</a></p>
            </div>
        </div>
    </div>
</body>
</html>
