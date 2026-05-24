<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CarGo Dashboard</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <main class="dashboard-page">
        <header class="dashboard-header">
            <?php // Future header include goes here ?>
        </header>

        <section class="dashboard-content">
            <?php // Future content include goes here ?>
        </section>
    </main>
</body>
</html>
