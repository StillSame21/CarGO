<?php
session_start();
require_once '../db_connect.php';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatBookingDate(?string $date): string
{
    if ($date === null || $date === '') {
        return 'Not set';
    }

    return date('d M Y', strtotime($date));
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$bookings = [];
$error = '';
$customerId = (int) ($_SESSION['customer_id'] ?? 0);

try {
    $conn = getDbConnection();
    $stmt = $conn->prepare(
        'SELECT
            b.id,
            b.pickup_date,
            b.return_date,
            b.total_days,
            b.total_amount,
            b.booking_status,
            car.brand,
            car.model,
            car.plate_number
         FROM bookings b
         INNER JOIN cars car ON car.id = b.car_id
         WHERE b.customer_id = ?
         ORDER BY b.created_at DESC, b.id DESC'
    );
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (mysqli_sql_exception $e) {
    $error = 'Could not load your bookings. Please check the database connection.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings | CarGo</title>
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
                        <p class="eyebrow">Bookings</p>
                        <h1>My bookings</h1>
                    </div>
                </header>

                <?php if ($error !== ''): ?>
                    <p class="message error"><?php echo h($error); ?></p>
                <?php elseif (count($bookings) === 0): ?>
                    <section class="empty-state-panel">
                        <h2>No bookings yet.</h2>
                        <p>Check availability on a car to create your first booking.</p>
                    </section>
                <?php else: ?>
                    <section class="booking-list" aria-label="My bookings">
                        <?php foreach ($bookings as $booking): ?>
                            <?php $statusClass = 'status-' . preg_replace('/[^a-z0-9-]/', '-', strtolower((string) $booking['booking_status'])); ?>
                            <article class="booking-list-card">
                                <div>
                                    <p class="car-type">Booking #<?php echo h($booking['id']); ?></p>
                                    <h2><?php echo h($booking['brand'] . ' ' . $booking['model']); ?></h2>
                                    <p><?php echo h(formatBookingDate($booking['pickup_date']) . ' to ' . formatBookingDate($booking['return_date'])); ?></p>
                                </div>

                                <dl>
                                    <div>
                                        <dt>Plate</dt>
                                        <dd><?php echo h($booking['plate_number']); ?></dd>
                                    </div>
                                    <div>
                                        <dt>Total</dt>
                                        <dd>RM <?php echo h(number_format((float) $booking['total_amount'], 2)); ?></dd>
                                    </div>
                                    <div>
                                        <dt>Status</dt>
                                        <dd><span class="booking-status-pill <?php echo h($statusClass); ?>"><?php echo h(ucfirst($booking['booking_status'])); ?></span></dd>
                                    </div>
                                </dl>

                                <a class="primary-action" href="booking.php?id=<?php echo h($booking['id']); ?>">View Details</a>
                            </article>
                        <?php endforeach; ?>
                    </section>
                <?php endif; ?>
            </div>
        </section>
    </main>
</body>
</html>
