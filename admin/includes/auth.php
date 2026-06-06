<?php

function requireAdminLogin(): void
{
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        header('Location: login.php');
        exit;
    }
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
        <link rel="stylesheet" href="../style.css">
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
