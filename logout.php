<?php
require_once __DIR__ . '/includes/security.php';

startSecureSession();

$redirect = ($_POST['type'] ?? '') === 'admin' ? 'admin/login.php' : 'customer/login.php';

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

destroySecureSession();

header('Location: ' . $redirect);
exit;
