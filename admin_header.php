<?php
$adminDisplayName = $_SESSION['admin_name'] ?? $_SESSION['admin_email'] ?? 'Admin';
?>
<div class="customer-header-inner admin-header-inner">
    <a class="dashboard-logo" href="admin_dashboard.php">CarGo Admin</a>

    <nav class="customer-nav admin-nav" aria-label="Admin navigation">
        <span>Cars</span>
        <span>Booking</span>
        <span>Customer</span>
        <a href="add_admin.php">Add Admin</a>
    </nav>

    <div class="customer-account">
        <span><?php echo htmlspecialchars($adminDisplayName, ENT_QUOTES, 'UTF-8'); ?></span>
        <a href="logout.php?type=admin">Logout</a>
    </div>
</div>
