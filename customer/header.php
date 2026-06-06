<?php
$customerDisplayName = $_SESSION['customer_name'] ?? $_SESSION['user_email'] ?? 'Customer';
$currentPage = basename($_SERVER['PHP_SELF']);
$isProfilePage = $currentPage === 'profile.php';
?>
<div class="dashboard-header-inner">
    <a class="dashboard-logo" href="dashboard.php">CarGo</a>

    <nav class="dashboard-nav" aria-label="Customer navigation">
        <a href="browse_cars.php" class="<?php echo in_array($currentPage, ['browse_cars.php', 'car_detail.php'], true) ? 'active' : ''; ?>">Browse Cars</a>
        <a href="my_bookings.php" class="<?php echo in_array($currentPage, ['my_bookings.php', 'booking.php'], true) ? 'active' : ''; ?>">My Bookings</a>
        <a href="#">Support</a>
    </nav>

    <div class="dashboard-account customer-account-menu" data-customer-menu>
        <button
            type="button"
            class="customer-account-button<?php echo $isProfilePage ? ' active' : ''; ?>"
            aria-haspopup="true"
            aria-expanded="false"
            data-customer-menu-button
        >
            <span><?php echo htmlspecialchars($customerDisplayName, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="customer-account-caret" aria-hidden="true">&#9662;</span>
        </button>

        <div class="customer-account-dropdown" data-customer-menu-dropdown>
            <a href="profile.php" class="<?php echo $isProfilePage ? 'active' : ''; ?>">Profile Settings</a>
            <form method="post" action="../logout.php">
                <?php echo csrfInput(); ?>
                <button type="submit">Logout</button>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var menu = document.querySelector('[data-customer-menu]');

    if (!menu) {
        return;
    }

    var button = menu.querySelector('[data-customer-menu-button]');
    var dropdown = menu.querySelector('[data-customer-menu-dropdown]');

    function closeMenu() {
        menu.classList.remove('is-open');
        button.setAttribute('aria-expanded', 'false');
    }

    button.addEventListener('click', function () {
        var isOpen = menu.classList.toggle('is-open');
        button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    document.addEventListener('click', function (event) {
        if (!menu.contains(event.target)) {
            closeMenu();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeMenu();
            button.focus();
        }
    });

    dropdown.addEventListener('click', function () {
        closeMenu();
    });
});
</script>
