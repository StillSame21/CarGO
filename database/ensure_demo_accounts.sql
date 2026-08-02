-- database/ensure_demo_accounts.sql
--
-- Seeds the fixed demo.customer@/demo.admin@cargo.demo accounts demo_login.php signs
-- into (admin role 'manager', never 'super_admin'). Idempotent - safe to re-run by hand.

INSERT INTO `customers` (`name`, `email`, `password`, `phone`, `address`, `status`)
VALUES (
    'Demo Customer',
    'demo.customer@cargo.demo',
    '$2y$10$ELqFjIviolrq.mjurYMYj..fVZ714ZvwKRUHt9wbg95I8Ns5gvRSW',
    NULL,
    'CarGo Demo Branch',
    'active'
)
ON DUPLICATE KEY UPDATE
    `id` = LAST_INSERT_ID(`id`),
    `name` = VALUES(`name`),
    `status` = 'active';

SET @demo_customer_id = LAST_INSERT_ID();

INSERT INTO `admins` (`name`, `email`, `password`, `phone`, `role`, `status`)
VALUES (
    'Demo Admin',
    'demo.admin@cargo.demo',
    '$2y$10$XlsUkT3gOl/KCWTk0vUgdeDxtZAC69WdaBs1F2iO/crcFn20e4OHi',
    NULL,
    'manager',
    'active'
)
ON DUPLICATE KEY UPDATE
    `id` = LAST_INSERT_ID(`id`),
    `name` = VALUES(`name`),
    `role` = 'manager',
    `status` = 'active';

SET @demo_admin_id = LAST_INSERT_ID();

-- --------------------------------------------------------
-- One seeded booking + payment so the demo customer's dashboard isn't empty.
-- --------------------------------------------------------

SET @demo_car_id = (SELECT `id` FROM `cars` WHERE `archived_at` IS NULL ORDER BY `id` ASC LIMIT 1);
SET @demo_daily_rate = (SELECT `daily_rate` FROM `cars` WHERE `id` = @demo_car_id);
SET @demo_total = @demo_daily_rate * 3;

-- Idempotent cleanup (children first, same order as seed_bookings.sql)
DELETE FROM `booking_addons` WHERE `booking_id` IN (
    SELECT `id` FROM (
        SELECT `id` FROM `bookings` WHERE `customer_id` = @demo_customer_id AND `admin_note` = 'demo-seed'
    ) AS `demo_bookings`
);
DELETE FROM `late_fees` WHERE `booking_id` IN (
    SELECT `id` FROM (
        SELECT `id` FROM `bookings` WHERE `customer_id` = @demo_customer_id AND `admin_note` = 'demo-seed'
    ) AS `demo_bookings`
);
DELETE FROM `payments` WHERE `booking_id` IN (
    SELECT `id` FROM (
        SELECT `id` FROM `bookings` WHERE `customer_id` = @demo_customer_id AND `admin_note` = 'demo-seed'
    ) AS `demo_bookings`
);
DELETE FROM `bookings` WHERE `customer_id` = @demo_customer_id AND `admin_note` = 'demo-seed';

INSERT INTO `bookings`
    (`customer_id`, `car_id`, `handled_by_admin_id`, `pickup_date`, `return_date`,
     `actual_return_date`, `pickup_location`, `total_days`, `total_amount`, `booking_status`,
     `admin_note`, `created_at`)
VALUES (
    @demo_customer_id, @demo_car_id, @demo_admin_id,
    '2026-07-20', '2026-07-23', '2026-07-23',
    'CarGo Demo Branch', 3, @demo_total, 'completed',
    'demo-seed', '2026-07-18 09:00:00'
);

SET @demo_booking_id = LAST_INSERT_ID();

INSERT INTO `payments`
    (`booking_id`, `updated_by_admin_id`, `amount`, `payment_method`, `payment_status`, `payment_date`, `created_at`)
VALUES (
    @demo_booking_id, @demo_admin_id, @demo_total, 'Cash', 'paid', '2026-07-20 09:10:00', '2026-07-18 09:00:05'
);
