<?php
session_start();
require_once 'db_connect.php';

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter both email and password.';
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
    <title>CarGo Login</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <main class="login-page">
        <section class="brand-panel">
            <div class="logo">CarGo</div>
            <h1>Manage your car rental operations.</h1>
            <p>Sign in to view your dashboard and keep your fleet, bookings, and customers moving smoothly.</p>
        </section>

        <section class="login-card">
            <h2>Welcome back</h2>
            <p class="subtitle">Login to continue to your dashboard.</p>

            <?php if ($error !== ''): ?>
                <div class="message error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="post" action="login.php">
                <label for="email">Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>"
                    autocomplete="username"
                    required
                >

                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    autocomplete="current-password"
                    required
                >

                <button type="submit">Login</button>
            </form>

            <p class="auth-link">New customer? <a href="register.php">Create an account</a></p>
            <p class="auth-link">Admin user? <a href="admin_login.php">Go to admin login</a></p>
        </section>
    </main>
</body>
</html>
