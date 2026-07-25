<?php

// One-time backfill: resize/recompress car images already in car/ and generate their derivatives.
// Run: php scripts/optimize_existing_car_images.php (or via browser with ?confirm=1). Safe to re-run.

require_once __DIR__ . '/../util/car_image.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli && (!isset($_GET['confirm']) || $_GET['confirm'] !== '1')) {
    http_response_code(403);
    echo "Refusing to run without ?confirm=1 (or run from CLI).\n";
    exit;
}

if (!extension_loaded('gd')) {
    echo "GD extension not available on this host — cannot resize images.\n";
    exit(1);
}

// A CLI backfill runs once on a controlled machine, so raise the web-upload decode ceiling
// to let very high-resolution legacy photos actually get optimized.
if ($isCli) {
    putenv('CARGO_DECODE_CEILING_MB=1024');
}

const SKIP_IF_UNDER_BYTES = 250 * 1024; // 250 KB
const SKIP_IF_DIMENSION_AT_MOST = CAR_IMAGE_MAX_DIMENSION;

$carDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'car';
if (!is_dir($carDir)) {
    echo "No car/ directory found at $carDir\n";
    exit(1);
}

$mimeByExtension = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
];

// Decode $filePath (now at its final name) and write its derivatives. Best-effort - a decode
// failure just means no derivatives for this file, not a script abort.
function ensureCarImageDerivatives(string $filePath, string $mimeType): void
{
    $image = loadCarImageResource($filePath, $mimeType);
    if ($image === false) {
        return;
    }

    try {
        generateCarImageDerivatives('car/' . basename($filePath), $image, $mimeType);
    } catch (\Throwable $e) {
        // Best-effort - leave whatever derivatives did get written.
    } finally {
        imagedestroy($image);
    }
}

// saveResizedCarImage() writes to a temp filename for atomic rename(), but also generates
// derivatives named off that temp name - junk carImageTag() will never look for. Delete it.
function cleanupTempDerivativeJunk(string $tempDestination): void
{
    $tempImagePath = 'car/' . basename($tempDestination);

    foreach (carImageAllDerivativeCandidates($tempImagePath) as $relativePath) {
        $diskPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (is_file($diskPath)) {
            unlink($diskPath);
        }
    }
}

$files = glob($carDir . DIRECTORY_SEPARATOR . '*.*');
$totalBefore = 0;
$totalAfter = 0;
$nl = $isCli ? "\n" : "<br>\n";

foreach ($files as $filePath) {
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if (!isset($mimeByExtension[$extension])) {
        continue;
    }

    $mimeType = $mimeByExtension[$extension];
    $beforeSize = filesize($filePath);
    $dimensions = @getimagesize($filePath);
    $longestSide = $dimensions !== false ? max($dimensions[0], $dimensions[1]) : 0;

    $totalBefore += $beforeSize;

    if ($beforeSize < SKIP_IF_UNDER_BYTES && $longestSide <= SKIP_IF_DIMENSION_AT_MOST) {
        echo "SKIP  " . basename($filePath) . " ({$beforeSize} bytes, already small)" . $nl;
        $totalAfter += $beforeSize;
        ensureCarImageDerivatives($filePath, $mimeType);
        continue;
    }

    $tempDestination = $filePath . '.optimizing';
    $saved = saveResizedCarImage($filePath, $mimeType, $tempDestination);
    cleanupTempDerivativeJunk($tempDestination);

    if (!$saved || !is_file($tempDestination)) {
        echo "SKIP  " . basename($filePath) . " (too large to safely resize on this host, or GD couldn't decode it - left untouched)" . $nl;
        $totalAfter += $beforeSize;
        if (is_file($tempDestination)) {
            unlink($tempDestination);
        }
        ensureCarImageDerivatives($filePath, $mimeType);
        continue;
    }

    $afterSize = filesize($tempDestination);

    if ($afterSize >= $beforeSize) {
        // Re-encode didn't help (already efficient) — keep the original.
        unlink($tempDestination);
        echo "KEEP  " . basename($filePath) . " ({$beforeSize} bytes, re-encode was not smaller)" . $nl;
        $totalAfter += $beforeSize;
        ensureCarImageDerivatives($filePath, $mimeType);
        continue;
    }

    rename($tempDestination, $filePath);
    $totalAfter += $afterSize;
    $savedPct = round((1 - $afterSize / $beforeSize) * 100);
    echo "OK    " . basename($filePath) . ": {$beforeSize} -> {$afterSize} bytes (-{$savedPct}%)" . $nl;
    ensureCarImageDerivatives($filePath, $mimeType);
}

$totalSavedPct = $totalBefore > 0 ? round((1 - $totalAfter / $totalBefore) * 100) : 0;
echo $nl . "Total: {$totalBefore} -> {$totalAfter} bytes (-{$totalSavedPct}%)" . $nl;
