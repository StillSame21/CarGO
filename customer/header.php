<?php
$customerDisplayName = $_SESSION['customer_name'] ?? $_SESSION['user_email'] ?? 'Customer';
// Extract first two letters for avatar
$avatarLetters = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $customerDisplayName) . 'AA', 0, 2));
$currentPage = basename($_SERVER['PHP_SELF']);
$isProfilePage = $currentPage === 'profile.php';
?>
<header class="dc-header">
    <div class="dc-header-inner">
        <a href="dashboard.php" class="dc-logo">
            <span>C</span>arGo
        </a>

        <nav class="dc-nav" aria-label="Customer navigation">
            <a href="dashboard.php" class="<?php echo $currentPage === 'dashboard.php' ? 'active' : ''; ?>">Dashboard</a>
            <a href="browse_cars.php" class="<?php echo in_array($currentPage, ['browse_cars.php', 'car_detail.php'], true) ? 'active' : ''; ?>">Browse Cars</a>
            <a href="my_bookings.php" class="<?php echo in_array($currentPage, ['my_bookings.php', 'booking.php'], true) ? 'active' : ''; ?>">My Bookings</a>
            <a href="my_payments.php" class="<?php echo $currentPage === 'my_payments.php' ? 'active' : ''; ?>">Payments</a>
        </nav>

        <div class="dc-header-actions">
            <a href="browse_cars.php" class="dc-search" style="text-decoration:none; color:inherit;">
                <svg width="15" height="15" viewBox="0 0 16 16" aria-hidden="true"><circle cx="7" cy="7" r="5" fill="none" stroke="#9097a8" stroke-width="1.6"></circle><line x1="10.8" y1="10.8" x2="14.5" y2="14.5" stroke="#9097a8" stroke-width="1.6" stroke-linecap="round"></line></svg>
                Search cars
            </a>

            <!-- User Menu -->
            <div style="position: relative;" class="dashboard-account">
                <button type="button" class="dc-user-btn" onclick="document.getElementById('customerDropdown').classList.toggle('show')">
                    <span class="dc-avatar"><?php echo htmlspecialchars($avatarLetters); ?></span>
                    <span class="dc-user-info">
                        <span class="dc-user-name"><?php echo htmlspecialchars($customerDisplayName, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="dc-user-role">Member</span>
                    </span>
                    <svg width="11" height="11" viewBox="0 0 12 12" aria-hidden="true" style="margin-left:2px;"><path d="M2.5 4.5 L6 8 L9.5 4.5" fill="none" stroke="#9097a8" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                </button>
                <div id="customerDropdown" style="display:none; position:absolute; top:100%; right:0; background:#fff; border:1px solid #e4e8f1; border-radius:12px; padding:8px; min-width:150px; box-shadow:0 12px 24px rgba(0,0,0,0.1); margin-top:8px;">
                    <a href="profile.php" style="display:block; padding:8px 12px; text-decoration:none; color:#131722; font-size:14px; font-weight:600; border-radius:8px; margin-bottom:4px;" onmouseover="this.style.background='#f1f3f9'" onmouseout="this.style.background='none'">Profile Settings</a>
                    <form method="post" action="../logout.php" style="margin:0;">
                        <?php echo csrfInput(); ?>
                        <button type="submit" style="width:100%; text-align:left; padding:8px 12px; background:none; border:none; cursor:pointer; font-size:14px; font-weight:600; color:#c23a52; border-radius:8px;" onmouseover="this.style.background='#fbeaed'" onmouseout="this.style.background='none'">Logout</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</header>

<nav class="dc-bottombar" aria-label="Mobile navigation">
    <a href="dashboard.php" class="<?php echo $currentPage === 'dashboard.php' ? 'active' : ''; ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 11.5 12 4l8 7.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 10v9h12v-9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span>Dashboard</span>
    </a>
    <a href="browse_cars.php" class="<?php echo in_array($currentPage, ['browse_cars.php', 'car_detail.php'], true) ? 'active' : ''; ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 16v-4l2-5h12l2 5v4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="7.5" cy="16.5" r="1.6" stroke="currentColor" stroke-width="1.8"/><circle cx="16.5" cy="16.5" r="1.6" stroke="currentColor" stroke-width="1.8"/></svg>
        <span>Browse</span>
    </a>
    <a href="my_bookings.php" class="<?php echo in_array($currentPage, ['my_bookings.php', 'booking.php'], true) ? 'active' : ''; ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="4.5" y="4.5" width="15" height="15" rx="2.5" stroke="currentColor" stroke-width="1.8"/><path d="M8 8.5h8M8 12h8M8 15.5h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
        <span>Bookings</span>
    </a>
    <a href="my_payments.php" class="<?php echo $currentPage === 'my_payments.php' ? 'active' : ''; ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3.5" y="6" width="17" height="12" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M3.5 10h17" stroke="currentColor" stroke-width="1.8"/></svg>
        <span>Payments</span>
    </a>
</nav>

<script>
// Simple click outside to close dropdown
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.dc-user-btn');
    var dropdown = document.getElementById('customerDropdown');
    if (!btn && dropdown) {
        dropdown.classList.remove('show');
        dropdown.style.display = 'none';
    } else if (btn && dropdown) {
        dropdown.style.display = dropdown.classList.contains('show') ? 'block' : 'none';
    }
});
</script>
