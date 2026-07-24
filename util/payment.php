<?php

const PAYMENT_METHODS = [
    'Cash',
    'Bank Transfer',
    'Online Payment',
    'Credit Card',
    'Debit Card',
    'Online Banking',
    'E-Wallet',
];

// Methods settled through the payment gateway rather than handled at the counter.
const PAYMENT_GATEWAY_METHODS = [
    'Online Payment',
    'Credit Card',
    'Debit Card',
    'Online Banking',
    'E-Wallet',
];

const PAYMENT_STATUS_UNPAID = 'unpaid';
const PAYMENT_STATUS_PAID = 'paid';

function buildPaymentBreakdown(float $rentalSubtotal, float $lateFeeTotal): array
{
    $rentalSubtotal = round(max(0, $rentalSubtotal), 2);
    $lateFeeTotal = round(max(0, $lateFeeTotal), 2);

    return [
        'rental_subtotal' => $rentalSubtotal,
        'late_fee_total' => $lateFeeTotal,
        'payable_total' => round($rentalSubtotal + $lateFeeTotal, 2),
    ];
}

/**
 * What's currently owed on this booking.
 *
 * markBookingPaymentPaid() / markBookingPaymentUnpaid() always write
 * payments.amount as exactly what was outstanding at that moment (e.g. a late
 * return only sets it to the late fee, not the whole rental again), so the
 * stored amount is the source of truth here — it is never recomputed from a
 * fresh rental+fee total, which would resurrect charges already settled.
 */
function calculatePaymentAmountDue(?string $paymentStatus, ?float $paidAmount): float
{
    if ($paymentStatus === PAYMENT_STATUS_PAID) {
        return 0.0;
    }

    return round(max(0, (float) $paidAmount), 2);
}

function loadPaymentByBookingId(mysqli $conn, int $bookingId): ?array
{
    $stmt = $conn->prepare(
        'SELECT id, booking_id, amount, payment_method, payment_status, payment_date, created_at
         FROM payments
         WHERE booking_id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();

    $payment = $stmt->get_result()->fetch_assoc();

    return $payment ?: null;
}

function markBookingPaymentPaid(mysqli $conn, int $bookingId, float $amount, string $paymentMethod): void
{
    if (!in_array($paymentMethod, PAYMENT_METHODS, true)) {
        throw new InvalidArgumentException('Please choose a valid payment method.');
    }

    if (in_array($paymentMethod, PAYMENT_GATEWAY_METHODS, true)) {
        require_once __DIR__ . '/../includes/stripe_stub.php';
        $stripe = new StripeClientStub();
        $charge = $stripe->createCharge($amount, 'usd', 'tok_visa', "Payment for booking $bookingId");
        if ($charge['status'] !== 'succeeded') {
            throw new RuntimeException('Online payment failed.');
        }
    }

    $paidStatus = PAYMENT_STATUS_PAID;

    $stmt = $conn->prepare(
        'INSERT INTO payments (booking_id, amount, payment_method, payment_status, payment_date)
         VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            amount = VALUES(amount),
            payment_method = VALUES(payment_method),
            payment_status = VALUES(payment_status),
            payment_date = VALUES(payment_date)'
    );
    $stmt->bind_param('idss', $bookingId, $amount, $paymentMethod, $paidStatus);
    $stmt->execute();
}

function markBookingPaymentUnpaid(mysqli $conn, int $bookingId, float $amount): void
{
    $unpaidStatus = PAYMENT_STATUS_UNPAID;
    $paymentMethod = 'Online Payment';

    $stmt = $conn->prepare(
        'INSERT INTO payments (booking_id, amount, payment_method, payment_status, payment_date)
         VALUES (?, ?, ?, ?, NULL)
         ON DUPLICATE KEY UPDATE
            amount = VALUES(amount),
            payment_status = VALUES(payment_status),
            payment_date = VALUES(payment_date)'
    );
    $stmt->bind_param('idss', $bookingId, $amount, $paymentMethod, $unpaidStatus);
    $stmt->execute();
}

function loadLateFeeSummary(mysqli $conn, int $bookingId): array
{
    $stmt = $conn->prepare(
        'SELECT
            COALESCE(SUM(late_days), 0) AS total_late_days,
            COALESCE(SUM(late_fee_amount), 0) AS total_late_fee,
            COUNT(*) AS fee_count
         FROM late_fees
         WHERE booking_id = ?'
    );
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();

    $summary = $stmt->get_result()->fetch_assoc() ?: [];

    return [
        'total_late_days' => (int) ($summary['total_late_days'] ?? 0),
        'total_late_fee' => (float) ($summary['total_late_fee'] ?? 0),
        'fee_count' => (int) ($summary['fee_count'] ?? 0),
    ];
}

function calculateLateFeeAmount(int $lateDays, float $dailyRate): float
{
    return round(max(0, $lateDays) * $dailyRate, 2);
}

function customerHasUnpaidLateFees(mysqli $conn, int $customerId): bool
{
    $paidStatus = PAYMENT_STATUS_PAID;

    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS unpaid_late_fees
         FROM bookings b
         INNER JOIN (
            SELECT booking_id, SUM(late_fee_amount) AS total_late_fee
            FROM late_fees
            GROUP BY booking_id
         ) fees ON fees.booking_id = b.id
         LEFT JOIN payments p ON p.booking_id = b.id
         WHERE b.customer_id = ?
           AND fees.total_late_fee > 0
           AND (
                p.id IS NULL
                OR p.payment_status <> ?
                OR p.amount < (b.total_amount + fees.total_late_fee)
           )'
    );
    $stmt->bind_param('is', $customerId, $paidStatus);
    $stmt->execute();

    $result = $stmt->get_result()->fetch_assoc();

    return (int) ($result['unpaid_late_fees'] ?? 0) > 0;
}

function bookingDisplayStatus(string $bookingStatus, ?string $paymentStatus, float $lateFeeTotal): array
{
    if ($lateFeeTotal > 0 && $paymentStatus !== PAYMENT_STATUS_PAID) {
        return ['label' => 'Late fees', 'class' => 'status-late-fees'];
    }

    if ($bookingStatus === 'completed') {
        return ['label' => 'Completed', 'class' => 'status-completed'];
    }

    if (in_array($bookingStatus, ['cancelled', 'rejected', 'approved', 'ongoing'], true)) {
        $className = 'status-' . preg_replace('/[^a-z0-9-]/', '-', strtolower($bookingStatus));

        return ['label' => ucfirst($bookingStatus), 'class' => $className];
    }

    if ($paymentStatus === PAYMENT_STATUS_PAID) {
        return ['label' => 'Paid', 'class' => 'status-paid'];
    }

    $normalizedStatus = strtolower(trim($bookingStatus));
    $className = 'status-' . preg_replace('/[^a-z0-9-]/', '-', $normalizedStatus);

    return ['label' => ucfirst($bookingStatus), 'class' => $className];
}
