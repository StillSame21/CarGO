<?php

/**
 * Seed for this visitor's car recommendations, generated once per session so a refresh
 * shows the same picks; a new login draws a fresh set.
 */
function recommendationSeed(): int
{
    if (!isset($_SESSION['recommendation_seed'])) {
        $_SESSION['recommendation_seed'] = random_int(1, 2147483647);
    }

    return (int) $_SESSION['recommendation_seed'];
}

/**
 * A stable random sample of rentable cars, ordered via RAND($seed) so the same seed
 * always yields the same order without scanning the whole fleet in PHP.
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
