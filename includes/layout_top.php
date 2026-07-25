<?php
$pageTitle = $pageTitle ?? 'CarGo';
// CSS path prefix: `../` for pages under admin/ or customer/, `./` for root pages.
$isAdmin = strpos($_SERVER['PHP_SELF'], '/admin/') !== false;
$isCustomer = strpos($_SERVER['PHP_SELF'], '/customer/') !== false;
$cssPrefix = ($isAdmin || $isCustomer) ? '../' : './';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php if ($isAdmin) : ?>
        <script>
            // Apply the saved theme before first paint so the page never
            // flashes the wrong one. No stored choice means follow the OS.
            (function () {
                try {
                    var saved = localStorage.getItem('cargo-theme');
                    if (saved === 'light' || saved === 'dark') {
                        document.documentElement.setAttribute('data-theme', saved);
                    }
                } catch (e) {}
            })();
        </script>
    <?php endif; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo $cssPrefix; ?>css/style.css?v=3">
    <link rel="stylesheet" href="<?php echo $cssPrefix; ?>css/template.css?v=4">
    <?php if ($isAdmin) : ?>
        <link rel="stylesheet" href="<?php echo $cssPrefix; ?>css/admin.css?v=3">
        <link rel="stylesheet" href="<?php echo $cssPrefix; ?>css/theme.css?v=7">
    <?php elseif ($isCustomer) : ?>
        <link rel="stylesheet" href="<?php echo $cssPrefix; ?>css/customer.css?v=9">
    <?php endif; ?>
</head>
<body class="<?php echo $isCustomer ? 'is-customer' : ($isAdmin ? 'is-admin' : ''); ?>">
<div class="dc-layout">
