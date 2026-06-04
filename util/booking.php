<?php

const BOOKING_DEFAULT_PICKUP_LOCATION = 'CarGo Main Branch';
const BOOKING_BLOCKING_STATUSES = ['pending', 'approved', 'ongoing'];
const BOOKING_CANCELLABLE_STATUSES = ['pending', 'approved'];

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
