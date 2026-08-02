<?php
require_once __DIR__ . '/includes/auth.php';
require_once '../db_connect.php';
require_once __DIR__ . '/../util/payment.php';
require_once __DIR__ . '/../util/html.php';

startSecureSession();
requireCustomerLogin();

$payments = [];
$error = '';
$customerId = (int) ($_SESSION['customer_id'] ?? 0);

try {
    $conn = getDbConnection();
    $stmt = $conn->prepare(
        'SELECT 
            p.id as payment_id,
            p.amount,
            p.payment_method,
            p.payment_status,
            p.payment_date,
            b.id as booking_id,
            c.brand,
            c.model
         FROM payments p
         INNER JOIN bookings b ON p.booking_id = b.id
         INNER JOIN cars c ON b.car_id = c.id
         WHERE b.customer_id = ?
         ORDER BY p.created_at DESC, p.id DESC'
    );
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (mysqli_sql_exception $e) {
    $error = 'Could not load your payment history.';
}
?>
<?php
$pageTitle = 'Payment History | CarGo';
include '../includes/layout_top.php';
include 'header.php';
?>
<main class="dc-main">
    <header class="dc-h2-title" style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 16px;">
        <div>
            <div class="dc-mono-subtitle small" style="margin-bottom:8px">Finance</div>
            <h1 class="dc-h1" style="font-size:32px;">Payment History</h1>
        </div>
        <div>
            <a href="my_bookings.php" class="dc-btn-secondary" style="padding: 10px 20px;">Back to Bookings</a>
        </div>
    </header>

    <?php if ($error !== '') : ?>
        <p class="message error" style="color: #c23a52; background: #fbeaed; padding: 12px; border-radius: 8px; font-weight: 600;"><?php echo h($error); ?></p>
    <?php elseif (count($payments) === 0) : ?>
        <div class="dc-card padded" style="text-align:center; padding: 60px 20px;">
            <h2 class="dc-h2">No payments found.</h2>
            <p class="dc-p" style="margin-top:12px;">You haven't made any payments yet.</p>
        </div>
    <?php else : ?>
        <div class="dc-card" style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; text-align: left; min-width: 800px;">
                <thead>
                    <tr style="background: #f9fafc; border-bottom: 1px solid #e4e8f1;">
                        <th style="padding: 16px 24px; font-weight: 600; color: #9097a8; font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em;">Date</th>
                        <th style="padding: 16px 24px; font-weight: 600; color: #9097a8; font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em;">Booking</th>
                        <th style="padding: 16px 24px; font-weight: 600; color: #9097a8; font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em;">Method</th>
                        <th style="padding: 16px 24px; font-weight: 600; color: #9097a8; font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em;">Status</th>
                        <th style="padding: 16px 24px; font-weight: 600; color: #9097a8; font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em; text-align: right;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $payment) : ?>
                        <tr style="border-bottom: 1px solid #f1f3f9;">
                            <td style="padding: 16px 24px; color: #5b6273; font-size: 15px;">
                                <?php echo h(formatPaymentDate($payment['payment_date'])); ?>
                            </td>
                            <td style="padding: 16px 24px;">
                                <div style="font-weight: 600; color: #131722;"><?php echo h($payment['brand'] . ' ' . $payment['model']); ?></div>
                                <div style="font-size: 13px; color: #9097a8; margin-top: 2px;">
                                    <a href="booking.php?id=<?php echo h($payment['booking_id']); ?>" style="color: var(--primary-color);">Booking #<?php echo h($payment['booking_id']); ?></a>
                                </div>
                            </td>
                            <td style="padding: 16px 24px; color: #5b6273;">
                                <?php echo h(strtoupper($payment['payment_method'] ?? 'N/A')); ?>
                            </td>
                            <td style="padding: 16px 24px;">
                                <?php if ($payment['payment_status'] === 'paid') : ?>
                                    <span style="background: #e6f6f1; color: #0b7a5a; padding: 4px 10px; border-radius: 100px; font-size: 12px; font-weight: 600;">Paid</span>
                                <?php else : ?>
                                    <span style="background: #fbeaed; color: #c23a52; padding: 4px 10px; border-radius: 100px; font-size: 12px; font-weight: 600;">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 16px 24px; text-align: right; font-weight: 600; color: #131722; font-size: 16px;">
                                RM <?php echo h(number_format((float) $payment['amount'], 2)); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>
<?php include '../includes/layout_bottom.php'; ?>
