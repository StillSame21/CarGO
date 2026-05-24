<?php
session_start();

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
    } elseif ($email === 'admin@cargo.com' && $password === 'password123') {
        $_SESSION['logged_in'] = true;
        $_SESSION['user_email'] = $email;

        header('Location: dashboard.php');
        exit;
    } else {
        $error = 'Invalid email or password.';
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

            <p class="demo-note">Demo: admin@cargo.com / password123</p>
        </section>
    </main>
</body>
</html>
