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

// Rental + late fees, each clamped to zero, plus the combined payable total.
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
 * What's currently owed. The stored payments.amount is the source of truth (never recomputed
 * from a fresh rental+fee total), since markBookingPayment*() always write exactly what's owed.
 */
function calculatePaymentAmountDue(?string $paymentStatus, ?float $paidAmount): float
{
    if ($paymentStatus === PAYMENT_STATUS_PAID) {
        return 0.0;
    }

    return round(max(0, (float) $paidAmount), 2);
}

// This booking's payment row, or null if none exists yet.
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

/**
 * The cumulative figure a fully-settled booking must record: rental plus every late fee.
 * Settlement checks and revenue reporting both compare payments.amount against this.
 */
function bookingSettlementTotal(mysqli $conn, int $bookingId): float
{
    $stmt = $conn->prepare('SELECT total_amount FROM bookings WHERE id = ?');
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();

    if (!$booking) {
        throw new InvalidArgumentException('Booking not found.');
    }

    $lateFees = loadLateFeeSummary($conn, $bookingId);

    return buildPaymentBreakdown((float) $booking['total_amount'], $lateFees['total_late_fee'])['payable_total'];
}

/**
 * Charges $chargeAmount (whatever is currently outstanding) but always stores the cumulative
 * settlement total in payments.amount, per the contract on bookingSettlementTotal() above.
 */
function markBookingPaymentPaid(mysqli $conn, int $bookingId, float $chargeAmount, string $paymentMethod): void
{
    if (!in_array($paymentMethod, PAYMENT_METHODS, true)) {
        throw new InvalidArgumentException('Please choose a valid payment method.');
    }

    if (in_array($paymentMethod, PAYMENT_GATEWAY_METHODS, true)) {
        require_once __DIR__ . '/../includes/stripe_stub.php';
        $stripe = new StripeClientStub();
        $charge = $stripe->createCharge($chargeAmount, 'usd', 'tok_visa', "Payment for booking $bookingId");
        if ($charge['status'] !== 'succeeded') {
            throw new RuntimeException('Online payment failed.');
        }
    }

    $paidStatus = PAYMENT_STATUS_PAID;
    $settledAmount = bookingSettlementTotal($conn, $bookingId);

    $stmt = $conn->prepare(
        'INSERT INTO payments (booking_id, amount, payment_method, payment_status, payment_date)
         VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            amount = VALUES(amount),
            payment_method = VALUES(payment_method),
            payment_status = VALUES(payment_status),
            payment_date = VALUES(payment_date)'
    );
    $stmt->bind_param('idss', $bookingId, $settledAmount, $paymentMethod, $paidStatus);
    $stmt->execute();
}

// Records a newly-owed amount (a late fee) against a booking as unpaid.
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

// Totals across every late fee charged on this booking.
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

// Late fee for a given overrun: days late times the car's daily rate.
function calculateLateFeeAmount(int $lateDays, float $dailyRate): float
{
    return round(max(0, $lateDays) * $dailyRate, 2);
}

// True if any booking has late fees not yet fully covered by its payment.
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

// Precedence chain: unpaid late fees outrank every other booking/payment status shown to a user.
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
