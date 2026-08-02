<?php

// this file only declares requireCustomerLogin().
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../db_connect.php';

// Gate for every customer page: re-checks account status against the DB and exits to login.php on failure.
function requireCustomerLogin(): void
{
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header('Location: login.php');
        exit;
    }

    $customerId = (int) ($_SESSION['customer_id'] ?? 0);

    if ($customerId <= 0) {
        destroySecureSession();
        header('Location: login.php');
        exit;
    }

    try {
        $conn = getDbConnection();
        $stmt = $conn->prepare(
            'SELECT id, name, email, status
             FROM customers
             WHERE id = ?
             LIMIT 1'
        );
        $stmt->bind_param('i', $customerId);
        $stmt->execute();
        $customer = $stmt->get_result()->fetch_assoc();
    } catch (mysqli_sql_exception $e) {
        destroySecureSession();
        header('Location: login.php');
        exit;
    }

    // Refresh account state on each protected page so blocked accounts cannot keep browsing.
    if (!$customer || ($customer['status'] ?? '') !== 'active') {
        destroySecureSession();
        header('Location: login.php');
        exit;
    }

    $_SESSION['customer_id'] = (int) $customer['id'];
    $_SESSION['customer_name'] = (string) $customer['name'];
    $_SESSION['user_email'] = (string) $customer['email'];
}
