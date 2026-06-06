<?php
require_once __DIR__ . '/includes/auth.php';

$adminDisplayName = $_SESSION['admin_name'] ?? $_SESSION['admin_email'] ?? 'Admin';
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<div class="dashboard-header-inner">
    <a class="dashboard-logo" href="dashboard.php">CarGo Admin</a>

    <nav class="dashboard-nav" aria-label="Admin navigation">
        <a href="manage_cars.php" class="<?php echo $currentPage === 'manage_cars.php' ? 'active' : ''; ?>">Cars</a>
        <a href="bookings.php" class="<?php echo $currentPage === 'bookings.php' ? 'active' : ''; ?>">Booking</a>
        <a href="customers.php" class="<?php echo $currentPage === 'customers.php' ? 'active' : ''; ?>">Customer</a>
        <?php if (isSuperAdmin()): ?>
            <a href="add_admin.php" class="<?php echo $currentPage === 'add_admin.php' ? 'active' : ''; ?>">Admin Management</a>
        <?php endif; ?>
    </nav>

    <div class="dashboard-account">
        <span><?php echo htmlspecialchars($adminDisplayName, ENT_QUOTES, 'UTF-8'); ?></span>
        <form method="post" action="../logout.php" class="header-logout-form">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="type" value="admin">
            <button type="submit">Logout</button>
        </form>
    </div>
</div>
