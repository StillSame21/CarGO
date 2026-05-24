<?php
$customerDisplayName = $_SESSION['customer_name'] ?? $_SESSION['user_email'] ?? 'Customer';
?>
<div class="customer-header-inner">
    <a class="dashboard-logo" href="dashboard.php">CarGo</a>

    <nav class="customer-nav" aria-label="Customer navigation">
        <a href="dashboard.php" class="active">Browse Cars</a>
        <a href="#">My Bookings</a>
        <a href="#">Payments</a>
        <a href="#">Support</a>
    </nav>

    <div class="customer-account">
        <span><?php echo htmlspecialchars($customerDisplayName, ENT_QUOTES, 'UTF-8'); ?></span>
        <a href="logout.php">Logout</a>
    </div>
</div>
