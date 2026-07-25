<?php

require_once __DIR__ . '/car_image.php';

// Placeholder for cars with no photo, relative to the project root.
const CAR_IMAGE_PLACEHOLDER = 'asset/car-placeholder.svg';

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

function carImageRelativeUrl(string $imagePath): string
{
    return '../' . ltrim($imagePath, '/');
}

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
        $sources .= '<source type="image/webp" srcset="' . carImageEscape(implode(', ', $webpSrcset)) . '" sizes="' . carImageEscape($sizes) . '">';
    }
    if ($jpegSrcset !== []) {
        $sources .= '<source type="image/jpeg" srcset="' . carImageEscape(implode(', ', $jpegSrcset)) . '" sizes="' . carImageEscape($sizes) . '">';
    }

    return '<picture>' . $sources . $imgTag . '</picture>';
}

function carImageImgTag(string $src, string $alt, string $loadingAttr, ?string $fetchPriority, ?int $width, ?int $height, ?string $class, ?string $style = null): string
{
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

function carImageDerivativeExists(string $relativePath): bool
{
    $diskPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

    return is_file($diskPath);
}

function carImageEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
