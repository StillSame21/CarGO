-- Backfills 'paid' rows that stored a raw charge amount instead of the cumulative
-- settlement total (rental + late fees); 'unpaid' and already-correct rows are untouched.
UPDATE payments p
JOIN bookings b ON b.id = p.booking_id
LEFT JOIN (
    SELECT booking_id, SUM(late_fee_amount) AS fee_total
    FROM late_fees GROUP BY booking_id
) f ON f.booking_id = p.booking_id
SET p.amount = b.total_amount + COALESCE(f.fee_total, 0)
WHERE p.payment_status = 'paid'
  AND p.amount < b.total_amount + COALESCE(f.fee_total, 0);
