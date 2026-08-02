<?php

// Data-loading helpers for admin/manage_cars.php. Declaration-only (PSR-1 §2.3).

/**
 * Rebuild the list URL keeping search, filters and page intact.
 * Same contract as customerPageUrl() in customers.php.
 */
function carPageUrl(array $state, array $overrides = []): string
{
    $params = array_merge($state, $overrides);
    $params = array_filter(
        $params,
        static fn($value) => $value !== null && $value !== '' && $value !== 'all'
    );

    return $params ? 'manage_cars.php?' . http_build_query($params) : 'manage_cars.php';
}
