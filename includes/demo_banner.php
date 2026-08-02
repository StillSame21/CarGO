<?php
// Shared demo-mode banner, included from customer/header.php and admin/header.php.
// Both sit one directory deep, so "../logout.php" below resolves the same way.

if (!isDemoSession()) {
    return;
}

$demoNotice = $_SESSION['demo_notice'] ?? '';
unset($_SESSION['demo_notice']);
?>
<div class="demo-banner">
    <div class="demo-banner-inner">
        <span class="demo-banner-text">
            <strong>Demo mode.</strong> You're exploring a shared demo account &mdash; changes to cars, customers,
            and admin settings are disabled.
            <?php if ($demoNotice !== '') : ?>
                <span class="demo-banner-notice">
                    <?php echo htmlspecialchars($demoNotice, ENT_QUOTES, 'UTF-8'); ?>
                </span>
            <?php endif; ?>
        </span>
        <form method="post" action="../logout.php" class="demo-banner-leave">
            <?php echo csrfInput(); ?>
            <?php if (isset($_SESSION['admin_logged_in'])) : ?>
                <input type="hidden" name="type" value="admin">
            <?php endif; ?>
            <button type="submit">Leave demo</button>
        </form>
    </div>
</div>
