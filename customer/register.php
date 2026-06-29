
<?php
require_once __DIR__ . '/../includes/security.php';
require_once '../db_connect.php';

startSecureSession();

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

    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Security token expired. Please refresh and try again.';
    } elseif ($name === '' || $email === '' || $password === '' || $confirmPassword === '') {
        $error = 'Please complete all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($name) > 100 || strlen($email) > 150 || strlen($phone) > 30 || strlen($address) > 255) {
        $error = 'Please keep your profile details within the allowed length.';
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
    <link rel="stylesheet" href="../css/template.css">
    <style>
        body { background: #f1f3f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 24px; }
        .login-wrapper { display: flex; width: 100%; max-width: 1000px; background: #fff; border-radius: 24px; overflow: hidden; box-shadow: 0 12px 40px rgba(19,23,34,0.06); }
        .login-brand { flex: 1; padding: 48px; background: #131722; color: #fff; display: flex; flex-direction: column; justify-content: center; position: relative; }
        .login-brand::after { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: url('https://images.unsplash.com/photo-1449965408869-eaa3f722e40d?auto=format&fit=crop&w=1000&q=80') center/cover; opacity: 0.2; }
        .login-brand > * { position: relative; z-index: 1; }
        .login-brand h1 { font-size: 36px; margin-bottom: 16px; font-weight: 700; line-height: 1.2; letter-spacing: -0.02em; }
        .login-brand p { font-size: 16px; color: rgba(255,255,255,0.7); line-height: 1.5; }
        .login-form-wrap { flex: 1; padding: 64px 48px; max-height: 90vh; overflow-y: auto; }
        .login-form-wrap h2 { font-size: 28px; margin-bottom: 8px; font-weight: 700; letter-spacing: -0.01em; color: #131722; }
        .login-form-wrap > p { color: #5b6273; margin-bottom: 32px; font-size: 15px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-size: 13px; font-weight: 600; color: #131722; }
        .dc-input { width: 100%; padding: 12px 16px; border: 1px solid #e4e8f1; border-radius: 8px; font-family: inherit; font-size: 14px; background: #fff; color: #131722; transition: all 0.2s ease; }
        .dc-input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 4px rgba(59,95,218,0.1); }
        .dc-btn-primary { width: 100%; justify-content: center; padding: 14px 24px; font-size: 15px; border-radius: 8px; background: var(--accent); color: #fff; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s ease; margin-top: 8px; }
        .dc-btn-primary:hover { background: var(--accent-hover); transform: translateY(-1px); }
        .message.error { color: #c23a52; background: #fbeaed; padding: 12px 16px; border-radius: 8px; font-weight: 600; font-size: 14px; margin-bottom: 24px; }
        .message.success { color: #0b7a5a; background: #e6f6f1; padding: 12px 16px; border-radius: 8px; font-weight: 600; font-size: 14px; margin-bottom: 24px; }
        .auth-links { margin-top: 32px; font-size: 14px; color: #5b6273; text-align: center; }
        .auth-links a { color: var(--accent); font-weight: 600; text-decoration: none; }
        .auth-links a:hover { text-decoration: underline; }
        @media (max-width: 768px) { .login-wrapper { flex-direction: column; } .login-brand { padding: 40px 24px; } .login-form-wrap { padding: 40px 24px; max-height: none; } }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-brand">
            <div style="font-size:24px; font-weight:800; margin-bottom:auto; color:var(--accent);">CarGo</div>
            <div style="margin-top:40px;">
                <h1>Create your customer account.</h1>
                <p>Register with CarGo to browse cars, manage bookings, and prepare your rental details in one place.</p>
            </div>
        </div>
        <div class="login-form-wrap">
            <h2>Customer Register</h2>
            <p>Enter your details to create an account.</p>

            <?php if ($error !== ''): ?>
                <div class="message error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="message success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="post" action="register.php">
                <?php echo csrfInput(); ?>

                <div class="form-group">
                    <label for="name">Full Name</label>
                    <input type="text" id="name" name="name" class="dc-input" value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="name" maxlength="100" required>
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" class="dc-input" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="email" maxlength="150" required>
                </div>

                <div class="form-group">
                    <label for="phone">Phone</label>
                    <input type="tel" id="phone" name="phone" class="dc-input" value="<?php echo htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="tel" maxlength="30">
                </div>

                <div class="form-group">
                    <label for="address">Address</label>
                    <textarea id="address" name="address" class="dc-input" rows="3" maxlength="255"><?php echo htmlspecialchars($address, ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" class="dc-input" autocomplete="new-password" required>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="dc-input" autocomplete="new-password" required>
                </div>

                <button type="submit" class="dc-btn-primary">Create Account</button>
            </form>

            <div class="auth-links">
                <p>Already have an account? <a href="login.php">Login</a></p>
            </div>
        </div>
    </div>
</body>
</html>
