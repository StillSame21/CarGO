<?php
require_once __DIR__ . '/../includes/security.php';
require_once '../db_connect.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();
requireAdminLogin();

$conn = getDbConnection();

// Predictive Maintenance logic: vehicles needing maintenance
// Simplification: Let's assume cars that are 'maintenance' status or have more than 5 bookings need maintenance
$query = "SELECT c.id, c.brand, c.model, c.plate_number, c.status, 
          COUNT(b.id) as total_bookings
          FROM cars c
          LEFT JOIN bookings b ON c.id = b.car_id
          GROUP BY c.id
          HAVING c.status = 'maintenance' OR total_bookings > 5";

$result = $conn->query($query);
$carsNeedingMaintenance = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $carsNeedingMaintenance[] = $row;
    }
}

$pageTitle = 'Predictive Maintenance | CarGo Admin';
include '../includes/layout_top.php';
include 'header.php';
?>
<main class="dc-main">
    <header class="dc-h2-title" style="margin-bottom: 24px;">
        <div>
            <div class="dc-mono-subtitle small" style="margin-bottom:8px">Maintenance</div>
            <h1 class="dc-h1" style="font-size:32px;">Predictive Maintenance</h1>
        </div>
    </header>

    <div class="dc-card">
        <div style="padding:24px; border-bottom:1px solid var(--line);">
            <h2 class="dc-h2" style="font-size:20px;">Vehicles Needing Maintenance</h2>
        </div>
        
        <?php if (empty($carsNeedingMaintenance)): ?>
            <div style="padding: 40px 24px; text-align: center; color: var(--ink-2);">
                <p>No vehicles currently flagged for maintenance.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="dc-table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--line); background: var(--surface-2);">
                            <th style="padding: 16px 24px; text-align: left; font-size: 13px; color: var(--ink-2); font-weight: 600;">Car ID</th>
                            <th style="padding: 16px 24px; text-align: left; font-size: 13px; color: var(--ink-2); font-weight: 600;">Brand & Model</th>
                            <th style="padding: 16px 24px; text-align: left; font-size: 13px; color: var(--ink-2); font-weight: 600;">Plate Number</th>
                            <th style="padding: 16px 24px; text-align: left; font-size: 13px; color: var(--ink-2); font-weight: 600;">Status</th>
                            <th style="padding: 16px 24px; text-align: left; font-size: 13px; color: var(--ink-2); font-weight: 600;">Total Bookings (Indicator)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($carsNeedingMaintenance as $car): ?>
                            <tr style="border-bottom: 1px solid var(--line);">
                                <td style="padding: 16px 24px; color:var(--ink); font-weight:600;">
                                    #<?= htmlspecialchars($car['id']) ?>
                                </td>
                                <td style="padding: 16px 24px;">
                                    <strong style="color: var(--ink); display:block; margin-bottom:4px;"><?= htmlspecialchars($car['brand'] . ' ' . $car['model']) ?></strong>
                                </td>
                                <td style="padding: 16px 24px; color:var(--ink-2); font-weight:600;">
                                    <?= htmlspecialchars($car['plate_number']) ?>
                                </td>
                                <td style="padding: 16px 24px;">
                                    <?php 
                                        $statusLabel = htmlspecialchars(ucfirst($car['status']));
                                        $statusColor = $car['status'] === 'available' ? 'var(--go)' : ($car['status'] === 'maintenance' ? 'var(--stop)' : 'var(--wait)');
                                        $statusBg = $car['status'] === 'available' ? 'var(--go-soft)' : ($car['status'] === 'maintenance' ? 'var(--stop-soft)' : 'var(--wait-soft)');
                                    ?>
                                    <span class="dc-badge" style="background:<?= $statusBg ?>; color:<?= $statusColor ?>;">
                                        <?= $statusLabel ?>
                                    </span>
                                </td>
                                <td style="padding: 16px 24px; color:var(--ink); font-weight:600;">
                                    <?= htmlspecialchars($car['total_bookings']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php include '../includes/layout_bottom.php'; ?>
