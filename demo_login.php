<?php

require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/db_connect.php';

// Fixed, reserved accounts seeded by database/ensure_demo_accounts.sql --
// never "first row in the table", which would leak a real account.
const DEMO_CUSTOMER_EMAIL = 'demo.customer@cargo.demo';
const DEMO_ADMIN_EMAIL = 'demo.admin@cargo.demo';

startSecureSession();

$type = ($_POST['type'] ?? '') === 'admin' ? 'admin' : 'customer';
$redirect = $type === 'admin' ? 'admin/login.php' : 'customer/login.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirect);
    exit;
}

try {
    requireValidCsrfToken();
} catch (InvalidArgumentException $e) {
    $_SESSION['demo_error'] = 'Security token expired. Please refresh and try again.';
    header('Location: ' . $redirect);
    exit;
}

try {
    $conn = getDbConnection();

    if ($type === 'admin') {
        $stmt = $conn->prepare(
            "SELECT id, name, email, role, status FROM admins
             WHERE email = ? AND status = 'active' LIMIT 1"
        );
        $email = DEMO_ADMIN_EMAIL;
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();

        if (!$admin) {
            $_SESSION['demo_error'] = 'Demo admin account is unavailable right now. Please try again later.';
            header('Location: ' . $redirect);
            exit;
        }

        $_SESSION = [];
        session_regenerate_id(true);
        regenerateCsrfToken();

        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['admin_name'] = (string) $admin['name'];
        $_SESSION['admin_email'] = (string) $admin['email'];
        $_SESSION['admin_role'] = (string) $admin['role'];
        $_SESSION['admin_status'] = (string) $admin['status'];
        $_SESSION['is_demo'] = true;

        header('Location: admin/dashboard.php');
        exit;
    }

    $stmt = $conn->prepare(
        "SELECT id, name, email, status FROM customers
         WHERE email = ? AND status = 'active' LIMIT 1"
    );
    $email = DEMO_CUSTOMER_EMAIL;
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();

    if (!$customer) {
        $_SESSION['demo_error'] = 'Demo customer account is unavailable right now. Please try again later.';
        header('Location: ' . $redirect);
        exit;
    }

    $_SESSION = [];
    session_regenerate_id(true);
    regenerateCsrfToken();

    $_SESSION['logged_in'] = true;
    $_SESSION['customer_id'] = (int) $customer['id'];
    $_SESSION['customer_name'] = (string) $customer['name'];
    $_SESSION['user_email'] = (string) $customer['email'];
    $_SESSION['is_demo'] = true;

    header('Location: customer/dashboard.php');
    exit;
} catch (mysqli_sql_exception $e) {
    $_SESSION['demo_error'] = 'Demo login failed. Please check the database connection and try again.';
    header('Location: ' . $redirect);
    exit;
}
