-- database/seed_bookings.sql
--
-- Seeds 20 demo bookings (ids 3-22) covering every status/fee/add-on/payment shape.
-- Not auto-run by migrate.php -- apply by hand: mysql -u <user> -p <db> < this file.

-- --------------------------------------------------------
-- Idempotent cleanup (children first: late_fees/payments only cascade on UPDATE, not DELETE)
-- --------------------------------------------------------
DELETE FROM booking_addons WHERE booking_id BETWEEN 3 AND 22;
DELETE FROM late_fees      WHERE booking_id BETWEEN 3 AND 22;
DELETE FROM payments       WHERE booking_id BETWEEN 3 AND 22;
DELETE FROM bookings       WHERE id         BETWEEN 3 AND 22;

-- --------------------------------------------------------
-- bookings
-- --------------------------------------------------------
INSERT INTO `bookings`
  (`id`, `customer_id`, `car_id`, `handled_by_admin_id`, `pickup_date`, `return_date`,
   `actual_return_date`, `pickup_location`, `total_days`, `total_amount`, `booking_status`,
   `admin_note`, `created_at`) VALUES
(3,  2, 7,  1,    '2026-05-04', '2026-05-07', '2026-05-07', 'CarGo Main Branch', 4,  600.00,  'completed', NULL, '2026-05-02 09:10:00'),
(4,  2, 16, 2,    '2026-05-20', '2026-05-24', '2026-05-26', 'CarGo Main Branch', 5,  1250.00, 'completed', NULL, '2026-05-18 11:20:00'),
(5,  2, 3,  1,    '2026-06-22', '2026-06-25', '2026-06-25', 'CarGo Main Branch', 4,  2600.00, 'completed', NULL, '2026-06-20 08:00:00'),
(6,  2, 12, NULL, '2026-08-05', '2026-08-09', NULL,         'CarGo Main Branch', 5,  2000.00, 'approved',  NULL, '2026-07-25 14:05:00'),
(7,  3, 1,  2,    '2026-05-11', '2026-05-15', '2026-05-15', 'CarGo Main Branch', 5,  550.00,  'completed', NULL, '2026-05-09 10:00:00'),
(8,  3, 9,  1,    '2026-06-01', '2026-06-04', '2026-06-05', 'CarGo Main Branch', 4,  2400.00, 'completed', NULL, '2026-05-29 09:30:00'),
(9,  3, 14, 2,    '2026-06-15', '2026-06-21', '2026-06-21', 'CarGo Main Branch', 7,  1470.00, 'completed', NULL, '2026-06-13 12:00:00'),
(10, 3, 10, 1,    '2026-07-18', '2026-07-24', NULL,         'CarGo Main Branch', 7,  8400.00, 'ongoing',   NULL, '2026-07-16 09:00:00'),
(11, 4, 8,  2,    '2026-05-06', '2026-05-10', '2026-05-10', 'CarGo Main Branch', 5,  850.00,  'completed', NULL, '2026-05-04 10:15:00'),
(12, 4, 15, 1,    '2026-05-25', '2026-05-29', '2026-05-30', 'CarGo Main Branch', 5,  1100.00, 'completed', NULL, '2026-05-23 13:45:00'),
(13, 4, 5,  2,    '2026-06-10', '2026-06-14', '2026-06-16', 'CarGo Main Branch', 5,  2750.00, 'completed', NULL, '2026-06-08 09:00:00'),
(14, 4, 2,  NULL, '2026-08-12', '2026-08-15', NULL,         'CarGo Main Branch', 4,  360.00,  'cancelled', NULL, '2026-07-24 16:30:00'),
(15, 5, 17, 1,    '2026-05-14', '2026-05-18', '2026-05-18', 'CarGo Main Branch', 5,  1150.00, 'completed', NULL, '2026-05-12 08:50:00'),
(16, 5, 11, 2,    '2026-06-05', '2026-06-09', '2026-06-16', 'CarGo Main Branch', 5,  5250.00, 'completed', NULL, '2026-06-03 10:40:00'),
(17, 5, 18, 1,    '2026-07-15', '2026-07-18', NULL,         'CarGo Main Branch', 4,  720.00,  'rejected',  'Missing valid driving license.', '2026-07-13 11:00:00'),
(18, 5, 6,  NULL, '2026-08-01', '2026-08-03', NULL,         'CarGo Main Branch', 3,  3000.00, 'approved',  NULL, '2026-07-26 15:00:00'),
(19, 6, 13, 2,    '2026-05-18', '2026-05-21', '2026-05-22', 'CarGo Main Branch', 4,  4000.00, 'completed', NULL, '2026-05-16 09:20:00'),
(20, 6, 4,  1,    '2026-06-12', '2026-06-15', '2026-06-15', 'CarGo Main Branch', 4,  3520.00, 'completed', NULL, '2026-06-10 10:10:00'),
(21, 6, 8,  2,    '2026-07-06', '2026-07-10', '2026-07-12', 'CarGo Main Branch', 5,  700.00,  'completed', NULL, '2026-07-04 09:00:00'),
(22, 6, 7,  1,    '2026-07-24', '2026-07-31', NULL,         'CarGo Main Branch', 8,  1200.00, 'ongoing',   NULL, '2026-07-22 08:30:00');

-- --------------------------------------------------------
-- booking_addons (unit_price = addon price snapshot; days = booking's total_days)
-- --------------------------------------------------------
INSERT INTO `booking_addons` (`booking_id`, `addon_id`, `unit_price`, `days`) VALUES
(5,  1, 50.00,  4),  -- GPS Navigation on booking 5
(5,  3, 100.00, 4),  -- Premium Insurance on booking 5
(9,  2, 30.00,  7),  -- Child Seat on booking 9
(11, 2, 30.00,  5),  -- Child Seat on booking 11
(13, 3, 100.00, 5),  -- Premium Insurance on booking 13
(16, 1, 50.00,  5),  -- GPS Navigation on booking 16
(18, 3, 100.00, 3),  -- Premium Insurance on booking 18
(19, 1, 50.00,  4),  -- GPS Navigation on booking 19
(20, 1, 50.00,  4),  -- GPS Navigation on booking 20
(20, 2, 30.00,  4);  -- Child Seat on booking 20

-- --------------------------------------------------------
-- late_fees (late_days = DATEDIFF(actual_return_date, return_date); amount = late_days * daily_rate)
-- --------------------------------------------------------
INSERT INTO `late_fees` (`booking_id`, `created_by_admin_id`, `late_days`, `late_fee_amount`, `created_at`) VALUES
(4,  2, 2, 500.00,  '2026-05-26 17:00:00'),  -- car 16 @ 250/day
(8,  1, 1, 600.00,  '2026-06-05 16:30:00'),  -- car 9 @ 600/day
(12, 1, 1, 220.00,  '2026-05-30 17:15:00'),  -- car 15 @ 220/day
(13, 2, 2, 900.00,  '2026-06-16 16:45:00'),  -- car 5 @ 450/day
(16, 2, 7, 7000.00, '2026-06-16 18:00:00'),  -- car 11 @ 1000/day, severe overrun
(19, 2, 1, 950.00,  '2026-05-22 17:30:00'),  -- car 13 @ 950/day
(21, 2, 2, 280.00,  '2026-07-12 17:00:00');  -- car 8 @ 140/day

-- --------------------------------------------------------
-- payments (unsettled late fee -> amount = fee only, unpaid; settled -> total + fee, paid)
-- --------------------------------------------------------
INSERT INTO `payments` (`booking_id`, `updated_by_admin_id`, `amount`, `payment_method`, `payment_status`, `payment_date`, `created_at`) VALUES
(3,  1,    600.00,  'Cash',           'paid',   '2026-05-04 09:15:00', '2026-05-02 09:10:05'),
(4,  NULL, 500.00,  'Online Payment', 'unpaid', NULL,                  '2026-05-26 17:00:05'),
(5,  1,    2600.00, 'Bank Transfer',  'paid',   '2026-06-22 08:05:00', '2026-06-20 08:00:05'),
(6,  NULL, 2000.00, 'Credit Card',    'paid',   '2026-07-25 14:06:00', '2026-07-25 14:05:05'),
(7,  2,    550.00,  'E-Wallet',       'paid',   '2026-05-11 10:05:00', '2026-05-09 10:00:05'),
(8,  1,    3000.00, 'Online Banking', 'paid',   '2026-06-05 16:35:00', '2026-05-29 09:30:05'),
(9,  2,    1470.00, 'Cash',           'paid',   '2026-06-15 12:05:00', '2026-06-13 12:00:05'),
(10, NULL, 8400.00, 'Bank Transfer',  'paid',   '2026-07-18 09:05:00', '2026-07-16 09:00:05'),
(11, 2,    850.00,  'Credit Card',    'paid',   '2026-05-06 10:20:00', '2026-05-04 10:15:05'),
(12, NULL, 220.00,  'Online Payment', 'unpaid', NULL,                  '2026-05-30 17:15:05'),
(13, 2,    3650.00, 'E-Wallet',       'paid',   '2026-06-16 16:50:00', '2026-06-08 09:00:05'),
(15, 1,    1150.00, 'Cash',           'paid',   '2026-05-14 08:55:00', '2026-05-12 08:50:05'),
(16, NULL, 7000.00, 'Online Payment', 'unpaid', NULL,                  '2026-06-16 18:00:05'),
(18, NULL, 3000.00, 'Bank Transfer',  'paid',   '2026-07-26 15:01:00', '2026-07-26 15:00:05'),
(19, 2,    4950.00, 'Online Banking', 'paid',   '2026-05-22 17:35:00', '2026-05-16 09:20:05'),
(20, 1,    3520.00, 'Cash',           'paid',   '2026-06-12 10:15:00', '2026-06-10 10:10:05'),
(21, NULL, 280.00,  'Online Payment', 'unpaid', NULL,                  '2026-07-12 17:00:05'),
(22, 1,    1200.00, 'Credit Card',    'paid',   '2026-07-24 08:35:00', '2026-07-22 08:30:05');

-- --------------------------------------------------------
-- Cars currently out on the two ongoing bookings must reflect that in cars.status,
-- mirroring what confirmBookingPickup() does at checkout time.
-- --------------------------------------------------------
UPDATE cars SET status = 'unavailable' WHERE id IN (10, 7);
