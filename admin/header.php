<?php
$adminDisplayName = $_SESSION['admin_name'] ?? $_SESSION['admin_email'] ?? 'Admin';
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<div class="dashboard-header-inner">
    <a class="dashboard-logo" href="dashboard.php">CarGo Admin</a>

    <nav class="dashboard-nav" aria-label="Admin navigation">
        <a href="manage_cars.php" class="<?php echo $currentPage === 'manage_cars.php' ? 'active' : ''; ?>">Cars</a>
        <span>Booking</span>
        <span>Customer</span>
        <a href="add_admin.php" class="<?php echo $currentPage === 'add_admin.php' ? 'active' : ''; ?>">Add Admin</a>
    </nav>

    <div class="dashboard-account">
        <span><?php echo htmlspecialchars($adminDisplayName, ENT_QUOTES, 'UTF-8'); ?></span>
        <a href="../logout.php?type=admin">Logout</a>
    </div>
</div>
