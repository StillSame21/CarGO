<?php
require_once __DIR__ . '/includes/auth.php';

$adminDisplayName = $_SESSION['admin_name'] ?? $_SESSION['admin_email'] ?? 'Admin';
// Extract first two letters for avatar
$avatarLetters = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $adminDisplayName) . 'AA', 0, 2));
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<header class="dc-header">
    <div class="dc-header-inner">
        <a href="dashboard.php" class="dc-logo">
            <span>C</span>arGo Admin
        </a>

        <nav class="dc-nav" aria-label="Admin navigation">
            <a href="dashboard.php" class="<?php echo $currentPage === 'dashboard.php' ? 'active' : ''; ?>">Dashboard</a>
            <a href="manage_cars.php" class="<?php echo $currentPage === 'manage_cars.php' ? 'active' : ''; ?>">Cars</a>
            <a href="bookings.php" class="<?php echo $currentPage === 'bookings.php' ? 'active' : ''; ?>">Bookings</a>
            <a href="customers.php" class="<?php echo $currentPage === 'customers.php' ? 'active' : ''; ?>">Customers</a>
            <?php if (isSuperAdmin()): ?>
                <a href="add_admin.php" class="<?php echo $currentPage === 'add_admin.php' ? 'active' : ''; ?>">Admin Management</a>
            <?php endif; ?>
        </nav>

        <div class="dc-header-actions">
            <!-- Search bar placeholder -->
            <div class="dc-search">
                <svg width="15" height="15" viewBox="0 0 16 16" aria-hidden="true"><circle cx="7" cy="7" r="5" fill="none" stroke="#9097a8" stroke-width="1.6"></circle><line x1="10.8" y1="10.8" x2="14.5" y2="14.5" stroke="#9097a8" stroke-width="1.6" stroke-linecap="round"></line></svg>
                Search system
            </div>
            
            <!-- User Menu -->
            <div style="position: relative;" class="dashboard-account">
                <button type="button" class="dc-user-btn" onclick="document.getElementById('adminDropdown').classList.toggle('show')">
                    <span class="dc-avatar"><?php echo htmlspecialchars($avatarLetters); ?></span>
                    <span class="dc-user-info">
                        <span class="dc-user-name"><?php echo htmlspecialchars($adminDisplayName, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="dc-user-role"><?php echo isSuperAdmin() ? 'Super Admin' : 'Admin'; ?></span>
                    </span>
                    <svg width="11" height="11" viewBox="0 0 12 12" aria-hidden="true" style="margin-left:2px;"><path d="M2.5 4.5 L6 8 L9.5 4.5" fill="none" stroke="#9097a8" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                </button>
                <div id="adminDropdown" style="display:none; position:absolute; top:100%; right:0; background:#fff; border:1px solid #e4e8f1; border-radius:12px; padding:8px; min-width:150px; box-shadow:0 12px 24px rgba(0,0,0,0.1); margin-top:8px;">
                    <form method="post" action="../logout.php" style="margin:0;">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="type" value="admin">
                        <button type="submit" style="width:100%; text-align:left; padding:8px 12px; background:none; border:none; cursor:pointer; font-size:14px; font-weight:600; color:#c23a52; border-radius:8px;" onmouseover="this.style.background='#fbeaed'" onmouseout="this.style.background='none'">Logout</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</header>
<script>
// Simple click outside to close dropdown
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.dc-user-btn');
    var dropdown = document.getElementById('adminDropdown');
    if (!btn && dropdown) {
        dropdown.classList.remove('show');
        dropdown.style.display = 'none';
    } else if (btn && dropdown) {
        dropdown.style.display = dropdown.classList.contains('show') ? 'block' : 'none';
    }
});
</script>
