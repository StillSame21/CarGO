<?php

const BOOKING_DEFAULT_PICKUP_LOCATION = 'CarGo Main Branch';
const BOOKING_BLOCKING_STATUSES = ['pending', 'approved', 'ongoing'];
const BOOKING_CANCELLABLE_STATUSES = ['pending', 'approved'];
const BOOKING_NON_PAYABLE_STATUSES = ['cancelled', 'rejected'];
const BOOKING_STATUS_ONGOING = 'ongoing';
const BOOKING_STATUS_COMPLETED = 'completed';

function validateBookingDates(string $pickupDate, string $returnDate, ?string $today = null): ?string
{
    $pickupDate = trim($pickupDate);
    $returnDate = trim($returnDate);
    $today = $today ?? date('Y-m-d');

    if ($pickupDate === '' || $returnDate === '') {
        return 'Please select both pickup and return dates.';
    }

    if (!isValidBookingDate($pickupDate) || !isValidBookingDate($returnDate)) {
        return 'Please choose valid booking dates.';
    }

    if ($pickupDate < $today || $returnDate < $today) {
        return 'Please choose today or a future date.';
    }

    if ($returnDate < $pickupDate) {
        return 'Return date must be the same as or later than pickup date.';
    }

    return null;
}

function isValidBookingDate(string $date): bool
{
    $dateTime = DateTime::createFromFormat('Y-m-d', $date);

    return $dateTime instanceof DateTime && $dateTime->format('Y-m-d') === $date;
}

function bookingTotalDays(string $pickupDate, string $returnDate): int
{
    $pickup = new DateTime($pickupDate);
    $return = new DateTime($returnDate);

    return $pickup->diff($return)->days + 1;
}

function bookingTotalAmount(int $totalDays, float $dailyRate): float
{
    return round($totalDays * $dailyRate, 2);
}

function carHasBookingConflict(mysqli $conn, int $carId, string $pickupDate, string $returnDate): bool
{
    $statuses = BOOKING_BLOCKING_STATUSES;

    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS conflict_count
         FROM bookings
         WHERE car_id = ?
           AND booking_status IN (?, ?, ?)
           AND pickup_date <= ?
           AND return_date >= ?'
    );
    $stmt->bind_param(
        'isssss',
        $carId,
        $statuses[0],
        $statuses[1],
        $statuses[2],
        $returnDate,
        $pickupDate
    );
    $stmt->execute();

    $result = $stmt->get_result()->fetch_assoc();

    return (int) ($result['conflict_count'] ?? 0) > 0;
}

function canCancelBooking(string $bookingStatus): bool
{
    return in_array($bookingStatus, BOOKING_CANCELLABLE_STATUSES, true);
}

function canPayBooking(string $bookingStatus): bool
{
    return !in_array($bookingStatus, BOOKING_NON_PAYABLE_STATUSES, true);
}

function bookingLateDays(string $expectedReturnDate, string $actualReturnDate): int
{
    if (!isValidBookingDate($expectedReturnDate) || !isValidBookingDate($actualReturnDate)) {
        return 0;
    }

    if ($actualReturnDate <= $expectedReturnDate) {
        return 0;
    }

    $expected = new DateTime($expectedReturnDate);
    $actual = new DateTime($actualReturnDate);

    return $expected->diff($actual)->days;
}

function confirmBookingPickup(mysqli $conn, int $bookingId, int $adminId): void
{
    $paidStatus = PAYMENT_STATUS_PAID;
    $ongoingStatus = BOOKING_STATUS_ONGOING;
    $unavailableStatus = 'unavailable';

    $stmt = $conn->prepare(
        'UPDATE bookings b
         INNER JOIN payments p ON p.booking_id = b.id
         INNER JOIN cars c ON c.id = b.car_id
         SET b.booking_status = ?,
             b.handled_by_admin_id = ?,
             c.status = ?
         WHERE b.id = ?
           AND p.payment_status = ?
           AND b.booking_status NOT IN (?, ?, ?, ?)'
    );

    $completedStatus = BOOKING_STATUS_COMPLETED;
    $cancelledStatus = 'cancelled';
    $rejectedStatus = 'rejected';
    $stmt->bind_param(
        'sisisssss',
        $ongoingStatus,
        $adminId,
        $unavailableStatus,
        $bookingId,
        $paidStatus,
        $ongoingStatus,
        $completedStatus,
        $cancelledStatus,
        $rejectedStatus
    );
    $stmt->execute();

    if ($stmt->affected_rows <= 0) {
        throw new RuntimeException('Only paid bookings that have not been picked up can be confirmed.');
    }
}

function confirmBookingReturn(mysqli $conn, array $booking, int $adminId, string $actualReturnDate): array
{
    if (!isValidBookingDate($actualReturnDate)) {
        throw new InvalidArgumentException('Please choose a valid actual return date.');
    }

    if (($booking['booking_status'] ?? '') !== BOOKING_STATUS_ONGOING) {
        throw new RuntimeException('Only ongoing bookings can be returned.');
    }

    $bookingId = (int) $booking['id'];
    $carId = (int) $booking['car_id'];
    $lateDays = bookingLateDays((string) $booking['return_date'], $actualReturnDate);
    $lateFeeAmount = calculateLateFeeAmount($lateDays, (float) $booking['daily_rate']);
    $completedStatus = BOOKING_STATUS_COMPLETED;
    $availableStatus = 'available';

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare(
            'UPDATE bookings
             SET booking_status = ?,
                 handled_by_admin_id = ?,
                 actual_return_date = ?
             WHERE id = ?'
        );
        $stmt->bind_param('sisi', $completedStatus, $adminId, $actualReturnDate, $bookingId);
        $stmt->execute();

        $stmt = $conn->prepare('UPDATE cars SET status = ? WHERE id = ?');
        $stmt->bind_param('si', $availableStatus, $carId);
        $stmt->execute();

        if ($lateDays > 0 && $lateFeeAmount > 0) {
            $stmt = $conn->prepare(
                'INSERT INTO late_fees (booking_id, created_by_admin_id, late_days, late_fee_amount)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->bind_param('iiid', $bookingId, $adminId, $lateDays, $lateFeeAmount);
            $stmt->execute();

            markBookingPaymentUnpaid($conn, $bookingId, (float) $booking['total_amount'] + $lateFeeAmount);
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    return [
        'late_days' => $lateDays,
        'late_fee_amount' => $lateFeeAmount,
    ];
}
