<?php

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../db_connect.php';

function requireAdminLogin(): void
{
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        header('Location: login.php');
        exit;
    }

    $adminId = (int) ($_SESSION['admin_id'] ?? 0);

    if ($adminId <= 0) {
        destroySecureSession();
        header('Location: login.php');
        exit;
    }

    try {
        $conn = getDbConnection();
        $stmt = $conn->prepare(
            'SELECT id, name, email, role, status
             FROM admins
             WHERE id = ?
             LIMIT 1'
        );
        $stmt->bind_param('i', $adminId);
        $stmt->execute();
        $admin = $stmt->get_result()->fetch_assoc();
    } catch (mysqli_sql_exception $e) {
        destroySecureSession();
        header('Location: login.php');
        exit;
    }

    // Refresh role/status on every protected page so blocked admins lose access immediately.
    if (!$admin || ($admin['status'] ?? '') !== 'active') {
        destroySecureSession();
        header('Location: login.php');
        exit;
    }

    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['admin_name'] = (string) $admin['name'];
    $_SESSION['admin_email'] = (string) $admin['email'];
    $_SESSION['admin_role'] = (string) $admin['role'];
    $_SESSION['admin_status'] = (string) $admin['status'];
}

function currentAdminRole(): string
{
    return (string) ($_SESSION['admin_role'] ?? '');
}

function currentAdminStatus(): string
{
    return (string) ($_SESSION['admin_status'] ?? '');
}

function isSuperAdmin(): bool
{
    return currentAdminRole() === 'super_admin' && currentAdminStatus() === 'active';
}

function requireSuperAdmin(): void
{
    requireAdminLogin();

    if (isSuperAdmin()) {
        return;
    }

    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Access Denied | CarGo Admin</title>
        <link rel="stylesheet" href="../css/style.css">
        <link rel="stylesheet" href="../css/admin.css">
    </head>
    <body>
        <main class="dashboard-page">
            <header class="dashboard-header">
                <?php include __DIR__ . '/../header.php'; ?>
            </header>

            <section class="dashboard-content">
                <div class="dashboard-shell">
                    <section class="empty-admin-panel">
                        <p class="eyebrow">Access Denied</p>
                        <h1>Super admin access required.</h1>
                        <p>You do not have permission to manage admin accounts.</p>
                    </section>
                </div>
            </section>
        </main>
    </body>
    </html>
    <?php
    exit;
}
