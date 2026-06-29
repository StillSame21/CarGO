<?php
require_once __DIR__ . '/includes/auth.php';

startSecureSession();
requireCustomerLogin();

$pageTitle = 'CarGo Dashboard';
include '../includes/layout_top.php';
include 'header.php';
?>
<main class="dc-main">
    <?php include 'dashboard_content.php'; ?>
</main>
<?php include '../includes/layout_bottom.php'; ?>
