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
$carTypes = CAR_TYPE_FALLBACK;

try {
    $conn = getDbConnection();
    ensureCarArchiveColumn($conn);
    // Same source as the admin form, so a type can never be creatable but unfilterable.
    $carTypes = carTypeValues($conn);
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
$pageTitle = 'Browse Cars | CarGo';
include '../includes/layout_top.php';
include 'header.php';
?>
<main class="dc-main">
    <header class="dc-h2-title" style="margin-bottom: 0;">
        <div>
            <div class="dc-mono-subtitle small" style="margin-bottom:8px">Available Fleet</div>
            <h1 class="dc-h1" style="font-size:32px;">Browse cars ready to rent.</h1>
        </div>
    </header>

    <?php if ($error !== ''): ?>
        <p class="message error" style="color: #c23a52; background: #fbeaed; padding: 12px; border-radius: 8px; font-weight: 600;"><?php echo h($error); ?></p>
    <?php elseif (count($cars) === 0): ?>
        <div class="dc-card padded" style="text-align:center; padding: 60px 20px;">
            <h2 class="dc-h2">No cars available right now.</h2>
            <p class="dc-p" style="margin-top:12px;">Please check again later for newly listed rental cars.</p>
        </div>
    <?php else: ?>
        <div style="display: flex; gap: 32px; align-items: flex-start; margin-top: 24px;">
            
            <!-- Sidebar Filters -->
            <aside class="dc-card" style="width: 260px; flex-shrink: 0; padding: 24px; position: sticky; top: 94px; display: flex; flex-direction: column; gap: 16px; box-sizing: border-box; z-index: 10;">
                <div style="font-size: 16px; font-weight: 700; color: var(--text-color-strong); border-bottom: 1px solid var(--border-color); padding-bottom: 12px; margin-bottom: 4px;">
                    Filter & Sort
                </div>
                
                <div>
                    <label style="display: block; font-size: 11px; font-weight: 700; margin-bottom: 8px; color: #5b6273; text-transform: uppercase; letter-spacing: 0.05em;">Search</label>
                    <input type="text" id="car-search" class="dc-input" placeholder="Brand or model..." style="width: 100%;">
                </div>
                
                <div>
                    <label style="display: block; font-size: 11px; font-weight: 700; margin-bottom: 8px; color: #5b6273; text-transform: uppercase; letter-spacing: 0.05em;">Vehicle Type</label>
                    <select id="filter-type" class="dc-select" style="width: 100%;">
                        <option value="">All Types</option>
                        <?php foreach ($carTypes as $type): ?>
                            <option value="<?php echo h($type); ?>"><?php echo h($type); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label style="display: block; font-size: 11px; font-weight: 700; margin-bottom: 8px; color: #5b6273; text-transform: uppercase; letter-spacing: 0.05em;">Transmission</label>
                    <select id="filter-transmission" class="dc-select" style="width: 100%;">
                        <option value="">All Transmissions</option>
                        <option value="Automatic">Automatic</option>
                        <option value="Manual">Manual</option>
                    </select>
                </div>
                
                <div>
                    <label style="display: block; font-size: 11px; font-weight: 700; margin-bottom: 8px; color: #5b6273; text-transform: uppercase; letter-spacing: 0.05em;">Fuel Type</label>
                    <select id="filter-fuel" class="dc-select" style="width: 100%;">
                        <option value="">All Fuel Types</option>
                        <option value="Petrol">Petrol</option>
                        <option value="Diesel">Diesel</option>
                        <option value="Hybrid">Hybrid</option>
                        <option value="Electric">Electric</option>
                    </select>
                </div>
                
                <div>
                    <label style="display: block; font-size: 11px; font-weight: 700; margin-bottom: 8px; color: #5b6273; text-transform: uppercase; letter-spacing: 0.05em;">Sort By</label>
                    <select id="sort-by" class="dc-select" style="width: 100%;">
                        <option value="default">Default Sorting</option>
                        <option value="price-asc">Price: Low to High</option>
                        <option value="price-desc">Price: High to Low</option>
                        <option value="name-asc">Name: A to Z</option>
                        <option value="name-desc">Name: Z to A</option>
                    </select>
                </div>
                
                <button id="clear-filters" class="dc-btn-secondary" type="button" style="display:none; padding:12px 16px; width: 100%; margin-top: 8px;">Clear All Filters</button>
            </aside>
            
            <!-- Main Content -->
            <div style="flex: 1; min-width: 0;">
                <div style="margin-bottom: 20px; font-size: 14px; font-weight: 600; color: #5b6273;">
                    <span id="results-count">Showing <?php echo count($cars); ?> cars</span>
                </div>

        <form action="compare.php" method="get" id="compare-form">
            <section class="dc-grid-3" id="car-grid">
            <?php foreach ($cars as $car): ?>
                <article class="car-card" style="position: relative;"
                    data-brand="<?php echo h(strtolower($car['brand'])); ?>"
                    data-model="<?php echo h(strtolower($car['model'])); ?>"
                    data-type="<?php echo h(strtolower($car['car_type'])); ?>"
                    data-transmission="<?php echo h(strtolower($car['transmission'])); ?>"
                    data-fuel="<?php echo h(strtolower($car['fuel_type'])); ?>"
                    data-price="<?php echo (float)$car['daily_rate']; ?>"
                >
                    <div style="position: absolute; top: 12px; right: 12px; z-index: 10;">
                        <input type="checkbox" name="ids[]" value="<?php echo h($car['id']); ?>" class="compare-checkbox" style="width: 20px; height: 20px; cursor:pointer;">
                    </div>
                    
                    <a href="car_detail.php?id=<?php echo h($car['id']); ?>" class="dc-car-card" style="text-decoration:none; color:inherit;">
                        <div class="dc-car-img-wrap">
                            <span class="dc-car-tag"><?php echo h($car['car_type']); ?></span>
                            <img src="<?php echo h(carImageUrl($car['image'], 'https://images.unsplash.com/photo-1549317661-bd32c8ce0db2?auto=format&fit=crop&w=700&q=80')); ?>" alt="<?php echo h($car['brand'] . ' ' . $car['model']); ?>">
                        </div>
                        <div class="dc-car-details">
                            <span class="dc-car-title"><?php echo h($car['brand'] . ' ' . $car['model']); ?></span>
                            <span class="dc-car-specs"><?php echo h($car['transmission']); ?> · <?php echo h($car['fuel_type']); ?> · <?php echo h($car['seats']); ?> seats</span>
                            <div class="dc-car-price-row">
                                <span class="dc-car-price">
                                    <strong>RM <?php echo h(number_format((float) $car['daily_rate'], 0)); ?></strong>
                                    <span>/ day</span>
                                </span>
                                <button class="dc-btn-icon" type="button">→</button>
                            </div>
                        </div>
                    </a>
                </article>
            <?php endforeach; ?>
            </section>
        </form>
        <div id="compare-bar" style="display: none; position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%); background: #131722; color:#fff; padding: 12px 24px; border-radius: 999px; box-shadow: 0 14px 30px rgba(19, 23, 34, 0.3); z-index: 1000; align-items: center; gap: 20px;">
            <span id="compare-count" style="font-weight: 600; font-size:14px;">0 cars selected</span>
            <button type="submit" form="compare-form" class="dc-btn-primary" id="compare-btn" style="padding: 10px 20px; box-shadow:none;">Compare</button>
        </div>
        
        </div> <!-- End of main content -->
        </div> <!-- End of layout split -->
    <?php endif; ?>
</main>
<script src="../js/browse-filter.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const checkboxes = document.querySelectorAll('.compare-checkbox');
        const compareBar = document.getElementById('compare-bar');
        const compareCount = document.getElementById('compare-count');
        const compareBtn = document.getElementById('compare-btn');

        checkboxes.forEach(cb => {
            cb.addEventListener('change', () => {
                const selected = document.querySelectorAll('.compare-checkbox:checked');
                if (selected.length > 0) {
                    compareBar.style.display = 'flex';
                    compareCount.textContent = selected.length + ' cars selected';
                    
                    if (selected.length > 4) {
                        compareBtn.disabled = true;
                        compareCount.textContent += ' (Max 4)';
                        compareCount.style.color = '#ff6b6b';
                        compareBtn.style.opacity = '0.5';
                    } else if (selected.length < 2) {
                        compareBtn.disabled = true;
                        compareCount.textContent += ' (Need at least 2)';
                        compareCount.style.color = '#fff';
                        compareBtn.style.opacity = '0.5';
                    } else {
                        compareBtn.disabled = false;
                        compareCount.style.color = '#fff';
                        compareBtn.style.opacity = '1';
                    }
                } else {
                    compareBar.style.display = 'none';
                }
            });
        });
    });
</script>
<?php include '../includes/layout_bottom.php'; ?>
