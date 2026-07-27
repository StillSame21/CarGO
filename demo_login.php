<?php

require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/db_connect.php';

startSecureSession();

$type = ($_POST['type'] ?? '') === 'admin' ? 'admin' : 'customer';
$redirect = $type === 'admin' ? 'admin/login.php' : 'customer/login.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Location: ' . $redirect);
    exit;
}

try {
    requireValidCsrfToken();
} catch (InvalidArgumentException $e) {
    http_response_code(403);
    header('Location: ' . $redirect);
    exit;
}

try {
    $conn = getDbConnection();

    if ($type === 'admin') {
        $result = $conn->query(
            "SELECT id, name, email, role, status FROM admins
             WHERE role = 'super_admin' AND status = 'active'
             ORDER BY id ASC LIMIT 1"
        );
        $admin = $result->fetch_assoc();

        if (!$admin) {
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

        header('Location: admin/dashboard.php');
        exit;
    }

    $result = $conn->query(
        "SELECT id, name, email, status FROM customers
         WHERE status = 'active'
         ORDER BY id ASC LIMIT 1"
    );
    $customer = $result->fetch_assoc();

    if (!$customer) {
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

    header('Location: customer/dashboard.php');
    exit;
} catch (mysqli_sql_exception $e) {
    header('Location: ' . $redirect);
    exit;
}
