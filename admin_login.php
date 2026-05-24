<?php
session_start();
require_once 'db_connect.php';

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: admin_dashboard.php');
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
                'SELECT id, name, email, password FROM admins WHERE email = ? LIMIT 1'
            );
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $admin = $result->fetch_assoc();

            if (!$admin || !password_verify($password, $admin['password'])) {
                $error = 'Invalid admin email or password.';
            } else {
                session_regenerate_id(true);

                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_name'] = $admin['name'];
                $_SESSION['admin_email'] = $admin['email'];

                header('Location: admin_dashboard.php');
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
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <main class="login-page">
        <section class="brand-panel">
            <div class="logo">CarGo</div>
            <h1>Admin control for CarGo operations.</h1>
            <p>Sign in to manage cars, bookings, customers, and admin access from a dedicated dashboard.</p>
        </section>

        <section class="login-card">
            <h2>Admin Login</h2>
            <p class="subtitle">Login with your admin account.</p>

            <?php if ($error !== ''): ?>
                <div class="message error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="post" action="admin_login.php">
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

            <p class="auth-link">Customer login? <a href="login.php">Go to customer login</a></p>
        </section>
    </main>
</body>
</html>
