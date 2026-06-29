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
    $pageTitle = 'Access Denied | CarGo Admin';
    include __DIR__ . '/../../includes/layout_top.php';
    include __DIR__ . '/../header.php';
    ?>
    <main class="dc-main">
        <div class="dc-card" style="max-width: 600px; margin: 40px auto; text-align: center; padding: 40px 24px;">
            <div style="width: 64px; height: 64px; background: #fbeaed; color: #c23a52; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 24px;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
            </div>
            <p class="dc-mono-subtitle small" style="margin-bottom:8px">Access Denied</p>
            <h1 class="dc-h1" style="font-size:24px; margin-bottom: 16px;">Super admin access required.</h1>
            <p style="color: #5b6273; margin-bottom: 32px;">You do not have permission to manage admin accounts.</p>
            <a href="dashboard.php" class="dc-btn-primary" style="display: inline-flex; justify-content: center;">Return to Dashboard</a>
        </div>
    </main>
    <?php include __DIR__ . '/../../includes/layout_bottom.php'; ?>
    <?php
    exit;
}
