<?php

// phpcs:disable PSR1.Files.SideEffects -- require_once is intentional here; car_image.php is
// excluded from composer's autoload files to avoid redeclaring its functions.
require_once __DIR__ . '/car_image.php';

// Placeholder for cars with no photo, relative to the project root.
const CAR_IMAGE_PLACEHOLDER = 'asset/car-placeholder.svg';

// Browser-facing URL for a car image: passes through external URLs, resolves local paths, falls back when empty.
function carImageUrl(?string $imagePath, string $fallbackUrl): string
{
    $imagePath = trim((string) $imagePath);

    if ($imagePath === '') {
        return $fallbackUrl;
    }

    if (preg_match('/^https?:\/\//', $imagePath)) {
        return $imagePath;
    }

    return carImageRelativeUrl($imagePath);
}

// Project-root-relative image path rewritten for a page served one directory deep.
function carImageRelativeUrl(string $imagePath): string
{
    return '../' . ltrim($imagePath, '/');
}

// URL of the generic placeholder shown when a car has no photo.
function carImagePlaceholderUrl(): string
{
    return carImageRelativeUrl(CAR_IMAGE_PLACEHOLDER);
}

/**
 * Render a car photo as a <picture> with WebP/JPEG srcset candidates, falling back to a plain
 * <img> when no on-disk derivatives exist yet. $sizes is the CSS `sizes` attribute.
 */
function carImageTag(?string $imagePath, string $alt, string $sizes, array $opts = []): string
{
    $imagePath = trim((string) $imagePath);
    $loadingAttr = $opts['loading'] ?? 'lazy';
    $fetchPriority = $opts['fetchpriority'] ?? null;
    $width = isset($opts['width']) ? (int) $opts['width'] : null;
    $height = isset($opts['height']) ? (int) $opts['height'] : null;
    $class = $opts['class'] ?? null;
    $style = $opts['style'] ?? null;

    $src = carImageUrl($imagePath, carImagePlaceholderUrl());
    $isLocalCarImage = $imagePath !== '' && !preg_match('/^https?:\/\//', $imagePath);

    $webpSrcset = [];
    $jpegSrcset = [];

    if ($isLocalCarImage) {
        try {
            foreach (CAR_IMAGE_DERIVATIVE_WIDTHS as $derivativeWidth) {
                $jpegPath = carImageDerivativePath($imagePath, $derivativeWidth, 'jpg');
                if (carImageDerivativeExists($jpegPath)) {
                    $jpegSrcset[] = carImageRelativeUrl($jpegPath) . ' ' . $derivativeWidth . 'w';
                }

                $webpPath = carImageDerivativePath($imagePath, $derivativeWidth, 'webp');
                if (carImageDerivativeExists($webpPath)) {
                    $webpSrcset[] = carImageRelativeUrl($webpPath) . ' ' . $derivativeWidth . 'w';
                }
            }

            $fullWebpPath = carImageDerivativePath($imagePath, null, 'webp');
            if (carImageDerivativeExists($fullWebpPath)) {
                $webpSrcset[] = carImageRelativeUrl($fullWebpPath) . ' ' . CAR_IMAGE_MAX_DIMENSION . 'w';
            }
        } catch (InvalidArgumentException $e) {
            // Not a well-formed 'car/...' path - treat as no derivatives available.
            $webpSrcset = [];
            $jpegSrcset = [];
        }
    }

    $imgTag = carImageImgTag($src, $alt, $loadingAttr, $fetchPriority, $width, $height, $class, $style);

    if ($webpSrcset === [] && $jpegSrcset === []) {
        return $imgTag;
    }

    $sources = '';
    if ($webpSrcset !== []) {
        $sources .= '<source type="image/webp" srcset="' . carImageEscape(implode(', ', $webpSrcset))
            . '" sizes="' . carImageEscape($sizes) . '">';
    }
    if ($jpegSrcset !== []) {
        $sources .= '<source type="image/jpeg" srcset="' . carImageEscape(implode(', ', $jpegSrcset))
            . '" sizes="' . carImageEscape($sizes) . '">';
    }

    return '<picture>' . $sources . $imgTag . '</picture>';
}

// Builds the plain <img> fallback used when no derivative srcset is available.
function carImageImgTag(
    string $src,
    string $alt,
    string $loadingAttr,
    ?string $fetchPriority,
    ?int $width,
    ?int $height,
    ?string $class,
    ?string $style = null
): string {
    $html = '<img src="' . carImageEscape($src) . '" alt="' . carImageEscape($alt) . '"';

    if ($class !== null) {
        $html .= ' class="' . carImageEscape($class) . '"';
    }
    if ($style !== null) {
        $html .= ' style="' . carImageEscape($style) . '"';
    }
    if ($width !== null) {
        $html .= ' width="' . $width . '"';
    }
    if ($height !== null) {
        $html .= ' height="' . $height . '"';
    }

    $html .= ' loading="' . carImageEscape($loadingAttr) . '" decoding="async"';

    if ($fetchPriority !== null) {
        $html .= ' fetchpriority="' . carImageEscape($fetchPriority) . '"';
    }

    return $html . '>';
}

// True if a derivative file actually exists on disk for this relative path.
function carImageDerivativeExists(string $relativePath): bool
{
    $diskPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

    return is_file($diskPath);
}

// HTML-attribute-safe escape shared by every tag built in this file.
function carImageEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
