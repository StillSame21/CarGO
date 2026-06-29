<?php
require_once __DIR__ . '/../includes/security.php';
require_once '../db_connect.php';
require_once __DIR__ . '/includes/auth.php';

startSecureSession();
requireAdminLogin();

$conn = getDbConnection();

// Ensure damage_reports table exists
$conn->query("CREATE TABLE IF NOT EXISTS damage_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    car_id INT NOT NULL,
    description TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$message = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $car_id = filter_input(INPUT_POST, 'car_id', FILTER_VALIDATE_INT);
    $description = trim($_POST['description'] ?? '');
    
    if ($car_id && $description) {
        $stmt = $conn->prepare("INSERT INTO damage_reports (car_id, description) VALUES (?, ?)");
        $stmt->bind_param("is", $car_id, $description);
        if ($stmt->execute()) {
            $message = "Damage report saved successfully.";
        } else {
            $error = "Failed to save damage report.";
        }
        $stmt->close();
    } else {
        $error = "Please provide all required fields.";
    }
}

$carsResult = $conn->query("SELECT id, brand, model, plate_number FROM cars");
$cars = [];
if ($carsResult) {
    while ($row = $carsResult->fetch_assoc()) {
        $cars[] = $row;
    }
}

$pageTitle = 'Visual Damage Reporting | CarGo Admin';
include '../includes/layout_top.php';
include 'header.php';
?>
<main class="dc-main">
    <header class="dc-h2-title" style="margin-bottom: 24px;">
        <div>
            <div class="dc-mono-subtitle small" style="margin-bottom:8px">Damage Reports</div>
            <h1 class="dc-h1" style="font-size:32px;">Visual Damage Reporting</h1>
        </div>
    </header>

    <?php if ($message): ?>
        <p class="message success" style="color: #0b7a5a; background: #e6f6f1; padding: 12px; border-radius: 8px; font-weight: 600; margin-bottom:24px;"><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>
    <?php if ($error): ?>
        <p class="message error" style="color: #c23a52; background: #fbeaed; padding: 12px; border-radius: 8px; font-weight: 600; margin-bottom:24px;"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <div class="dc-card" style="max-width: 600px;">
        <div style="padding:24px; border-bottom:1px solid #e4e8f1;">
            <h2 class="dc-h2" style="font-size:20px;">Report New Damage</h2>
        </div>
        
        <form method="POST" style="padding:24px; display:flex; flex-direction:column; gap:20px;">
            <?php echo csrfInput(); ?>
            <div class="form-group">
                <label for="car_id" style="display:block; margin-bottom:8px; font-size:13px; font-weight:600; color:#131722;">Select Car</label>
                <select name="car_id" id="car_id" required class="dc-input" style="width:100%;">
                    <option value="">-- Select a Car --</option>
                    <?php foreach ($cars as $car): ?>
                        <option value="<?= $car['id'] ?>"><?= htmlspecialchars($car['brand'] . ' ' . $car['model'] . ' (' . $car['plate_number'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="description" style="display:block; margin-bottom:8px; font-size:13px; font-weight:600; color:#131722;">Damage Description / "Sketch"</label>
                <textarea name="description" id="description" rows="5" required class="dc-input" style="width:100%; min-height:100px; resize:vertical;" placeholder="Describe the damage..."></textarea>
            </div>
            
            <button type="submit" class="dc-btn-primary" style="width:100%; justify-content:center;">Save Damage Report</button>
        </form>
    </div>
</main>
<?php include '../includes/layout_bottom.php'; ?>
