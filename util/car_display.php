<?php

function carImageUrl(?string $imagePath, string $fallbackUrl): string
{
    $imagePath = trim((string) $imagePath);

    if ($imagePath === '') {
        return $fallbackUrl;
    }

    if (preg_match('/^https?:\/\//', $imagePath)) {
        return $imagePath;
    }

    return '../' . ltrim($imagePath, '/');
}
