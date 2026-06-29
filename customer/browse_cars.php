<?php
require_once __DIR__ . '/includes/auth.php';
require_once '../db_connect.php';
require_once __DIR__ . '/../util/car_display.php';
require_once __DIR__ . '/../util/car_archive.php';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

startSecureSession();
requireCustomerLogin();

$cars = [];
$error = '';

try {
    $conn = getDbConnection();
    ensureCarArchiveColumn($conn);
    $result = $conn->query(
        'SELECT id, brand, model, plate_number, car_type, transmission, fuel_type, seats, daily_rate, image
         FROM cars
         WHERE status = \'available\' AND archived_at IS NULL
         ORDER BY brand ASC, model ASC'
    );
    $cars = $result->fetch_all(MYSQLI_ASSOC);
} catch (mysqli_sql_exception $e) {
    $error = 'Could not load available cars. Please check the database connection.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Cars | CarGo</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/customer.css">
</head>
<body>
    <main class="dashboard-page">
        <header class="dashboard-header">
            <?php include 'header.php'; ?>
        </header>

        <section class="dashboard-content">
            <div class="dashboard-shell">
                <header class="page-heading">
                    <div>
                        <p class="eyebrow">Available Fleet</p>
                        <h1>Browse cars ready to rent.</h1>
                    </div>
                </header>

                <?php if ($error !== ''): ?>
                    <p class="message error"><?php echo h($error); ?></p>
                <?php elseif (count($cars) === 0): ?>
                    <section class="empty-state-panel">
                        <h2>No cars available right now.</h2>
                        <p>Please check again later for newly listed rental cars.</p>
                    </section>
                <?php else: ?>
                    <section class="browse-toolbar">
                        <div class="search-box">
                            <input type="text" id="car-search" class="search-input" placeholder="Search brand or model...">
                        </div>
                        <div class="filter-controls">
                            <select id="filter-type" class="filter-select">
                                <option value="">All Types</option>
                                <option value="Sedan">Sedan</option>
                                <option value="SUV">SUV</option>
                                <option value="Hatchback">Hatchback</option>
                                <option value="MPV">MPV</option>
                                <option value="Truck">Truck</option>
                                <option value="Luxury">Luxury</option>
                            </select>
                            <select id="filter-transmission" class="filter-select">
                                <option value="">All Transmissions</option>
                                <option value="Automatic">Automatic</option>
                                <option value="Manual">Manual</option>
                            </select>
                            <select id="filter-fuel" class="filter-select">
                                <option value="">All Fuel Types</option>
                                <option value="Petrol">Petrol</option>
                                <option value="Diesel">Diesel</option>
                                <option value="Hybrid">Hybrid</option>
                                <option value="Electric">Electric</option>
                            </select>
                            <select id="sort-by" class="sort-select">
                                <option value="default">Sort by...</option>
                                <option value="price-asc">Price: Low to High</option>
                                <option value="price-desc">Price: High to Low</option>
                                <option value="name-asc">Name: A to Z</option>
                                <option value="name-desc">Name: Z to A</option>
                            </select>
                        </div>
                        <div class="toolbar-footer">
                            <span id="results-count" class="results-count">Showing <?php echo count($cars); ?> cars</span>
                            <button id="clear-filters" class="clear-btn" type="button" style="display:none;">Clear Filters</button>
                        </div>
                    </section>

                    <section class="car-grid" id="car-grid" aria-label="Available cars">
                        <?php foreach ($cars as $car): ?>
                            <article class="car-card"
                                data-brand="<?php echo h(strtolower($car['brand'])); ?>"
                                data-model="<?php echo h(strtolower($car['model'])); ?>"
                                data-type="<?php echo h(strtolower($car['car_type'])); ?>"
                                data-transmission="<?php echo h(strtolower($car['transmission'])); ?>"
                                data-fuel="<?php echo h(strtolower($car['fuel_type'])); ?>"
                                data-price="<?php echo (float)$car['daily_rate']; ?>"
                            >
                                <a href="car_detail.php?id=<?php echo h($car['id']); ?>" aria-label="View <?php echo h($car['brand'] . ' ' . $car['model']); ?> details">
                                    <img
                                        src="<?php echo h(carImageUrl($car['image'], 'https://images.unsplash.com/photo-1549317661-bd32c8ce0db2?auto=format&fit=crop&w=700&q=80')); ?>"
                                        alt="<?php echo h($car['brand'] . ' ' . $car['model']); ?>"
                                    >
                                    <div class="car-card-body">
                                        <div>
                                            <p class="car-type"><?php echo h($car['car_type']); ?></p>
                                            <h2><?php echo h($car['brand'] . ' ' . $car['model']); ?></h2>
                                        </div>
                                        <p class="car-summary">
                                            <?php echo h($car['transmission']); ?> / <?php echo h($car['fuel_type']); ?> / <?php echo h($car['seats']); ?> seats
                                        </p>
                                        <div class="car-card-footer">
                                            <span>RM <?php echo h(number_format((float) $car['daily_rate'], 2)); ?>/day</span>
                                            <span><?php echo h($car['plate_number']); ?></span>
                                        </div>
                                    </div>
                                </a>
                            </article>
                        <?php endforeach; ?>
                    </section>
                <?php endif; ?>
            </div>
        </section>
    </main>
    <script src="../js/browse-filter.js"></script>
</body>
</html>
