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
    <link rel="stylesheet" href="../style.css">
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
                    <section class="car-grid" aria-label="Available cars">
                        <?php foreach ($cars as $car): ?>
                            <article class="car-card">
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
</body>
</html>
