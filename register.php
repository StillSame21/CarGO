<?php
session_start();
require_once 'db_connect.php';

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';
$name = '';
$email = '';
$phone = '';
$address = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($name === '' || $email === '' || $password === '' || $confirmPassword === '') {
        $error = 'Please complete all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $conn = getDbConnection();
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $conn->prepare(
                'INSERT INTO customers (name, email, password, phone, address) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('sssss', $name, $email, $hashedPassword, $phone, $address);
            $stmt->execute();

            $success = 'Registration successful. You can now login.';
            $name = '';
            $email = '';
            $phone = '';
            $address = '';
        } catch (mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1062) {
                $error = 'This email is already registered.';
            } else {
                $error = 'Registration failed. Please check the database connection and try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CarGo Customer Registration</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <main class="login-page">
        <section class="brand-panel">
            <div class="logo">CarGo</div>
            <h1>Create your customer account.</h1>
            <p>Register with CarGo to browse cars, manage bookings, and prepare your rental details in one place.</p>
        </section>

        <section class="login-card register-card">
            <h2>Customer Register</h2>
            <p class="subtitle">Enter your details to create an account.</p>

            <?php if ($error !== ''): ?>
                <div class="message error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="message success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="post" action="register.php">
                <label for="name">Full Name</label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>"
                    autocomplete="name"
                    maxlength="100"
                    required
                >

                <label for="email">Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>"
                    autocomplete="email"
                    maxlength="150"
                    required
                >

                <label for="phone">Phone</label>
                <input
                    type="tel"
                    id="phone"
                    name="phone"
                    value="<?php echo htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'); ?>"
                    autocomplete="tel"
                    maxlength="30"
                >

                <label for="address">Address</label>
                <textarea
                    id="address"
                    name="address"
                    rows="3"
                    maxlength="255"
                ><?php echo htmlspecialchars($address, ENT_QUOTES, 'UTF-8'); ?></textarea>

                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    autocomplete="new-password"
                    required
                >

                <label for="confirm_password">Confirm Password</label>
                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    autocomplete="new-password"
                    required
                >

                <button type="submit">Create Account</button>
            </form>

            <p class="auth-link">Already have an account? <a href="login.php">Login</a></p>
        </section>
    </main>
</body>
</html>
