<?php
require_once __DIR__ . '/includes/auth.php';
require_once '../db_connect.php';
require_once __DIR__ . '/../util/car_display.php';

startSecureSession();
requireCustomerLogin();

$ids = $_GET['ids'] ?? [];
if (!is_array($ids)) {
    $ids = [$ids];
}

$ids = array_filter(array_map('intval', $ids));

$cars = [];
$error = '';

if (count($ids) < 2) {
    $error = 'Please select at least 2 cars to compare.';
} else {
    try {
        $conn = getDbConnection();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        // Same availability rule as browse_cars.php, so a car can never be
        // comparable but unlistable.
        $stmt = $conn->prepare("SELECT * FROM cars WHERE id IN ($placeholders) AND status = 'available' AND archived_at IS NULL");
        
        $types = str_repeat('i', count($ids));
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $cars[] = $row;
        }
        
        if (count($cars) < 2) {
            $error = 'Could not find enough available cars to compare. They might be unavailable.';
        }
    } catch (Exception $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}
?>
<?php
$pageTitle = 'Compare Cars | CarGo';
include '../includes/layout_top.php';
include 'header.php';
?>
<main class="dc-main">
    <div style="margin-bottom: 24px;">
        <a href="browse_cars.php" style="color:var(--accent); font-weight:600; text-decoration:none; font-size:14px; display:inline-flex; align-items:center; gap:6px;">
            <svg width="14" height="14" viewBox="0 0 16 16" aria-hidden="true"><path d="M10.5 13.5 L4.5 8 L10.5 2.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"></path></svg>
            Back to available cars
        </a>
    </div>

    <header class="dc-h2-title" style="margin-bottom: 24px;">
        <div>
            <div class="dc-mono-subtitle small" style="margin-bottom:8px">Compare</div>
            <h1 class="dc-h1" style="font-size:32px;">Side-by-Side Comparison</h1>
        </div>
    </header>

    <?php if ($error !== ''): ?>
        <p class="message error" style="color: #c23a52; background: #fbeaed; padding: 12px; border-radius: 8px; font-weight: 600; margin-bottom:24px;"><?php echo htmlspecialchars($error); ?></p>
    <?php else: ?>
        <div style="overflow-x: auto; padding-bottom: 24px;">
            <table class="compare-table">
                <thead>
                    <tr>
                        <th style="background: #f1f3f9; border-right: 1px solid #e4e8f1;">Feature</th>
                        <?php foreach ($cars as $car): ?>
                            <th style="background: #fff; vertical-align: top;">
                                <div class="compare-car-media">
                                    <img src="<?php echo htmlspecialchars(carImageUrl($car['image'], 'https://images.unsplash.com/photo-1549317661-bd32c8ce0db2?auto=format&fit=crop&w=700&q=80')); ?>" alt="<?php echo htmlspecialchars($car['brand'] . ' ' . $car['model']); ?>">
                                </div>
                                <div style="font-size: 20px; color: #131722; font-weight: 800; margin-bottom: 16px; line-height: 1.2; text-transform:none; letter-spacing:normal;"><?php echo htmlspecialchars($car['brand'] . ' ' . $car['model']); ?></div>
                                <a href="car_detail.php?id=<?php echo $car['id']; ?>" class="dc-btn-primary" style="justify-content:center; padding:10px; text-decoration:none;">View Details</a>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="feature-col">Daily Rate</td>
                        <?php foreach ($cars as $car): ?>
                            <td><strong style="font-size: 18px;">RM <?php echo number_format((float) $car['daily_rate'], 2); ?></strong></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <td class="feature-col">Car Type</td>
                        <?php foreach ($cars as $car): ?>
                            <td><?php echo htmlspecialchars($car['car_type']); ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <td class="feature-col">Transmission</td>
                        <?php foreach ($cars as $car): ?>
                            <td><?php echo htmlspecialchars($car['transmission']); ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <td class="feature-col">Fuel Type</td>
                        <?php foreach ($cars as $car): ?>
                            <td><?php echo htmlspecialchars($car['fuel_type']); ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <td class="feature-col">Seats</td>
                        <?php foreach ($cars as $car): ?>
                            <td><?php echo htmlspecialchars($car['seats']); ?></td>
                        <?php endforeach; ?>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>
<?php include '../includes/layout_bottom.php'; ?>
