-- Backfills payments rows that were marked 'paid' while markBookingPaymentPaid() still stored
-- the raw charge amount instead of the cumulative settlement total (rental + late fees). Only
-- 'paid' rows that under-record are touched; 'unpaid' rows correctly keep outstanding-balance
-- semantics, and already-correct rows are left untouched since the app has no discount feature
-- that could legitimately put a paid row below its settled total.
UPDATE payments p
JOIN bookings b ON b.id = p.booking_id
LEFT JOIN (
    SELECT booking_id, SUM(late_fee_amount) AS fee_total
    FROM late_fees GROUP BY booking_id
) f ON f.booking_id = p.booking_id
SET p.amount = b.total_amount + COALESCE(f.fee_total, 0)
WHERE p.payment_status = 'paid'
  AND p.amount < b.total_amount + COALESCE(f.fee_total, 0);
