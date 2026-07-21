<?php

/**
 * Seed for this visitor's car recommendations.
 *
 * Generated once per session so the dashboard shows the same picks on a
 * refresh — re-rolling every request makes the page feel broken — while a new
 * login draws a fresh set. random_int() is the CSPRNG and leaves the global
 * mt_rand sequence alone; the upper bound keeps the value inside signed 32-bit
 * range for the 'i' bind in loadRecommendedCars().
 */
function recommendationSeed(): int
{
    if (!isset($_SESSION['recommendation_seed'])) {
        $_SESSION['recommendation_seed'] = random_int(1, 2147483647);
    }

    return (int) $_SESSION['recommendation_seed'];
}

/**
 * A stable random sample of rentable cars.
 *
 * Ordering in SQL keeps the work proportional to the handful of rows shown
 * rather than to the whole fleet. MariaDB seeds its generator from RAND(N), so
 * the same seed always yields the same order.
 *
 * @return array<int, array<string, mixed>>
 */
function loadRecommendedCars(mysqli $conn, int $seed, int $limit = 3): array
{
    $stmt = $conn->prepare(
        'SELECT id, brand, model, plate_number, car_type, transmission, fuel_type, seats, daily_rate, image
         FROM cars
         WHERE status = \'available\' AND archived_at IS NULL
         ORDER BY RAND(?)
         LIMIT ?'
    );
    $stmt->bind_param('ii', $seed, $limit);
    $stmt->execute();

    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
