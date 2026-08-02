<?php

// phpcs:disable PSR1.Files.SideEffects -- CLI/browser one-shot script, not a declarations-only file.

// One-time migration: move existing car/*-400.* / *-800.* derivative siblings into car/400/,
// car/800/ subfolders. Run: php scripts/reorganize_car_image_folders.php. Idempotent.

require_once __DIR__ . '/../util/car_image.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli && (!isset($_GET['confirm']) || $_GET['confirm'] !== '1')) {
    http_response_code(403);
    echo "Refusing to run without ?confirm=1 (or run from CLI).\n";
    exit;
}

$carDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'car';
if (!is_dir($carDir)) {
    echo "No car/ directory found at $carDir\n";
    exit(1);
}

$nl = $isCli ? "\n" : "<br>\n";
$moved = 0;
$skipped = 0;

$widthGlob = implode(',', CAR_IMAGE_DERIVATIVE_WIDTHS);
$candidates = glob($carDir . DIRECTORY_SEPARATOR . "*-{{$widthGlob}}.{jpg,webp}", GLOB_BRACE);

foreach ($candidates as $filePath) {
    $filename = basename($filePath);

    if (!preg_match('/^(.+)-(' . implode('|', CAR_IMAGE_DERIVATIVE_WIDTHS) . ')\.(jpg|webp)$/', $filename, $m)) {
        continue;
    }

    [, $base, $width, $extension] = $m;

    // Only relocate genuine <original>-<width>.<ext> derivatives - guards against an
    // uploaded slug that legitimately ends in "-400" being mistaken for one.
    $originalExists = false;
    foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $originalExt) {
        if (is_file($carDir . DIRECTORY_SEPARATOR . $base . '.' . $originalExt)) {
            $originalExists = true;
            break;
        }
    }

    if (!$originalExists) {
        echo "SKIP  $filename (no matching original 'car/{$base}.*' - not a derivative)" . $nl;
        $skipped++;
        continue;
    }

    $destDir = $carDir . DIRECTORY_SEPARATOR . $width;
    if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
        echo "SKIP  $filename (could not create $destDir)" . $nl;
        $skipped++;
        continue;
    }

    $destPath = $destDir . DIRECTORY_SEPARATOR . $base . '.' . $extension;
    if (is_file($destPath)) {
        echo "SKIP  $filename (already exists at car/{$width}/{$base}.{$extension})" . $nl;
        $skipped++;
        continue;
    }

    if (rename($filePath, $destPath)) {
        echo "MOVE  $filename -> car/{$width}/{$base}.{$extension}" . $nl;
        $moved++;
    } else {
        echo "FAIL  $filename -> car/{$width}/{$base}.{$extension}" . $nl;
        $skipped++;
    }
}

echo $nl . "Total: moved {$moved}, skipped {$skipped}" . $nl;
