<?php
$customerDisplayName = $_SESSION['customer_name'] ?? $_SESSION['user_email'] ?? 'Customer';
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<div class="dashboard-header-inner">
    <a class="dashboard-logo" href="dashboard.php">CarGo</a>

    <nav class="dashboard-nav" aria-label="Customer navigation">
        <a href="browse_cars.php" class="<?php echo in_array($currentPage, ['browse_cars.php', 'car_detail.php'], true) ? 'active' : ''; ?>">Browse Cars</a>
        <a href="my_bookings.php" class="<?php echo in_array($currentPage, ['my_bookings.php', 'booking.php'], true) ? 'active' : ''; ?>">My Bookings</a>
        <a href="#">Payments</a>
        <a href="#">Support</a>
    </nav>

    <div class="dashboard-account">
        <span><?php echo htmlspecialchars($customerDisplayName, ENT_QUOTES, 'UTF-8'); ?></span>
        <a href="../logout.php">Logout</a>
    </div>
</div>
