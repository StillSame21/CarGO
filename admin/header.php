<?php
require_once __DIR__ . '/includes/auth.php';

$adminDisplayName = $_SESSION['admin_name'] ?? $_SESSION['admin_email'] ?? 'Admin';
// Extract first two letters for avatar
$avatarLetters = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $adminDisplayName) . 'AA', 0, 2));
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<?php include __DIR__ . '/../includes/demo_banner.php'; ?>
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
            
            <button type="button" class="theme-toggle" id="themeToggle" aria-label="Switch colour theme" title="Switch colour theme">
                <svg class="icon-moon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                </svg>
                <svg class="icon-sun" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="4"></circle>
                    <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path>
                </svg>
            </button>

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
                <div id="adminDropdown" style="display:none; position:absolute; top:100%; right:0; background:var(--surface); border:1px solid var(--line); border-radius:12px; padding:8px; min-width:150px; box-shadow:var(--shadow-pop); margin-top:8px;">
                    <form method="post" action="../logout.php" style="margin:0;">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="type" value="admin">
                        <button type="submit" style="width:100%; text-align:left; padding:8px 12px; background:none; border:none; cursor:pointer; font-size:14px; font-weight:600; color:var(--stop); border-radius:8px;" onmouseover="this.style.background='var(--stop-soft)'" onmouseout="this.style.background='none'">Logout</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</header>
<script>
// Theme toggle. First click from the OS default flips to the opposite of
// whatever is currently on screen, so the button always does what it shows.
(function () {
    var btn = document.getElementById('themeToggle');
    if (!btn) return;

    btn.addEventListener('click', function () {
        var root = document.documentElement;
        var current = root.getAttribute('data-theme');

        if (!current) {
            var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            current = prefersDark ? 'dark' : 'light';
        }

        var next = current === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);

        try { localStorage.setItem('cargo-theme', next); } catch (e) {}
    });
})();

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
