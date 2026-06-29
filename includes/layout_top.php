<?php
$pageTitle = $pageTitle ?? 'CarGo';
$basePath = '/';
// Find the level of nesting to properly link CSS if absolute path doesn't work.
// Since we don't know the exact domain setup, let's just use absolute path assuming DocumentRoot is CarGO,
// or we can use a relative path based on a variable.
// But mostly CarGo is running locally where it might be in a subfolder like /CarGO/.
// Let's use a function to get the base URL or just rely on a config if it exists.
// A simpler way is to use `../` for pages in `admin/` and `customer/`, and `./` for root pages.
$cssPrefix = (strpos($_SERVER['PHP_SELF'], '/admin/') !== false || strpos($_SERVER['PHP_SELF'], '/customer/') !== false) ? '../' : './';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo $cssPrefix; ?>css/style.css">
    <link rel="stylesheet" href="<?php echo $cssPrefix; ?>css/template.css">
    <?php if (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) : ?>
        <link rel="stylesheet" href="<?php echo $cssPrefix; ?>css/admin.css">
    <?php elseif (strpos($_SERVER['PHP_SELF'], '/customer/') !== false) : ?>
        <link rel="stylesheet" href="<?php echo $cssPrefix; ?>css/customer.css">
    <?php endif; ?>
</head>
<body>
<div class="dc-layout">
