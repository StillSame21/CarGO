<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin_login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CarGo Admin Dashboard</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <main class="dashboard-page">
        <header class="dashboard-header">
            <?php include 'admin_header.php'; ?>
        </header>

        <section class="dashboard-content">
            <div class="dashboard-shell">
                <section class="empty-admin-panel">
                    <p class="eyebrow">Admin Dashboard</p>
                    <h1>CarGo management area</h1>
                    <p>Cars, booking, and customer management sections are ready for your next database features.</p>
                </section>
            </div>
        </section>
    </main>
</body>
</html>
