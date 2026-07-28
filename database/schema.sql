-- database/schema.sql
--
-- Full CarGO schema in one idempotent file. Every CREATE TABLE uses
-- IF NOT EXISTS and already carries its final column/enum/index/FK shape, so
-- running this against an empty database bootstraps it from scratch. The
-- reconcile block at the end brings an older, already-migrated database
-- (created before this file existed) up to that same final shape.
--
-- Run via `php database/migrate.php`, or by hand:
--   mysql -u <user> -p <db> < database/schema.sql
-- Safe to re-run any time.

-- --------------------------------------------------------
-- admins
-- --------------------------------------------------------

-- Admin accounts (role/status gate what an admin can see and do)
CREATE TABLE IF NOT EXISTS `admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `role` enum('super_admin','manager','staff','viewer') NOT NULL DEFAULT 'staff',
  `status` enum('active','inactive','blocked') NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed the two demo admin accounts (super_admin + staff)
INSERT IGNORE INTO `admins` (`id`, `name`, `email`, `password`, `phone`, `role`, `status`, `created_at`, `updated_at`) VALUES
(1, 'admin1', 'admin1@mail.com', '$2y$10$0enzq26RIJ/xRmL8ZGXKg.GzkcKPz6rImE0ILuPKlkkXWpanvdGHi', '0111111112', 'super_admin', 'active', '2026-05-25 00:46:41', '2026-06-07 01:16:35'),
(2, 'admin2', 'admin2@mail.com', '$2y$10$GtYfNJy0PFgANXwpmKiziuRz/6DyLoCzZSvvgnoZvXez.OZ.MqzxS', '0111111113', 'staff', 'active', '2026-05-25 00:53:10', NULL);

-- --------------------------------------------------------
-- customers
-- --------------------------------------------------------

-- Customer accounts
CREATE TABLE IF NOT EXISTS `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive','blocked') NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_customers_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed the one demo customer account
INSERT IGNORE INTO `customers` (`id`, `name`, `email`, `password`, `phone`, `address`, `status`, `created_at`) VALUES
(2, 'ali abdul', 'ali@mail.com', '$2y$10$yVhDibSTmk/0YqN5sYdRzOvk0idh7mA/udCdVKVwgxAazZCwu7tce', '0111111111', 'Kelantan, Malaysia', 'active', '2026-05-25 00:40:44');

-- --------------------------------------------------------
-- cars
-- --------------------------------------------------------

-- Fleet, with the full car_type enum and archived_at (soft-delete) column already in place
CREATE TABLE IF NOT EXISTS `cars` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) DEFAULT NULL,
  `brand` varchar(100) NOT NULL,
  `model` varchar(100) NOT NULL,
  `plate_number` varchar(30) NOT NULL,
  `car_type` enum('Compact','Sedan','SUV','MPV','Luxury','Sports','Hatchback','Truck') NOT NULL,
  `transmission` enum('Automatic','Manual') NOT NULL,
  `fuel_type` enum('Petrol','Diesel','Hybrid','Electric') NOT NULL,
  `seats` int(11) NOT NULL,
  `daily_rate` decimal(10,2) NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `status` enum('available','unavailable','maintenance') NOT NULL DEFAULT 'available',
  `created_at` datetime DEFAULT current_timestamp(),
  `archived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plate_number` (`plate_number`),
  KEY `idx_cars_admin` (`admin_id`),
  KEY `idx_cars_type` (`car_type`),
  KEY `idx_cars_status` (`status`),
  CONSTRAINT `fk_car_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed the two original demo cars (ids 1-2; the rest of the demo fleet is a seed file, not schema)
INSERT IGNORE INTO `cars` (`id`, `admin_id`, `brand`, `model`, `plate_number`, `car_type`, `transmission`, `fuel_type`, `seats`, `daily_rate`, `image`, `status`, `created_at`, `archived_at`) VALUES
(1, 1, 'Proton', 'Saga', '111', 'Sedan', 'Automatic', 'Petrol', 5, 110.00, 'car/11e177bec37f966c-protonsaga.jpg', 'available', '2026-05-26 18:14:33', NULL),
(2, 1, 'Perodua', 'Bezza', '112', 'Sedan', 'Automatic', 'Petrol', 5, 90.00, 'car/d197fe4b6801288a-bezza.jpg', 'available', '2026-05-26 18:15:25', NULL);

-- --------------------------------------------------------
-- bookings
-- --------------------------------------------------------

-- Bookings, with the full booking_status enum (incl. 'ongoing') already in place
CREATE TABLE IF NOT EXISTS `bookings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `car_id` int(11) NOT NULL,
  `handled_by_admin_id` int(11) DEFAULT NULL,
  `pickup_date` date NOT NULL,
  `return_date` date NOT NULL,
  `actual_return_date` date DEFAULT NULL,
  `pickup_location` varchar(255) NOT NULL,
  `total_days` int(11) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `booking_status` enum('pending','approved','rejected','ongoing','completed','cancelled') NOT NULL DEFAULT 'pending',
  `admin_note` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bookings_customer` (`customer_id`),
  KEY `idx_bookings_car` (`car_id`),
  KEY `idx_bookings_admin` (`handled_by_admin_id`),
  KEY `idx_bookings_status` (`booking_status`),
  CONSTRAINT `fk_booking_admin` FOREIGN KEY (`handled_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_booking_car` FOREIGN KEY (`car_id`) REFERENCES `cars` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_booking_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- payments
-- --------------------------------------------------------

-- Payments, with the full payment_method enum already in place
CREATE TABLE IF NOT EXISTS `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `updated_by_admin_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('Cash','Bank Transfer','Online Payment','Credit Card','Debit Card','Online Banking','E-Wallet') NOT NULL,
  `payment_status` enum('unpaid','paid','refunded') NOT NULL DEFAULT 'unpaid',
  `payment_date` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `booking_id` (`booking_id`),
  KEY `idx_payments_status` (`payment_status`),
  KEY `idx_payments_admin` (`updated_by_admin_id`),
  CONSTRAINT `fk_payment_admin` FOREIGN KEY (`updated_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_payment_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- late_fees
-- --------------------------------------------------------

-- Late fees charged on top of a booking's rental total
CREATE TABLE IF NOT EXISTS `late_fees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `created_by_admin_id` int(11) DEFAULT NULL,
  `late_days` int(11) NOT NULL,
  `late_fee_amount` decimal(10,2) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `booking_id` (`booking_id`),
  KEY `fk_late_fee_admin` (`created_by_admin_id`),
  CONSTRAINT `fk_late_fee_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_late_fee_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- addons
-- --------------------------------------------------------

-- Optional checkout add-ons catalogue
CREATE TABLE IF NOT EXISTS `addons` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed the three add-ons checkout offers today
INSERT IGNORE INTO `addons` (`id`, `name`, `price`, `description`) VALUES
(1, 'GPS Navigation', 50.00, 'Turn-by-turn navigation unit fitted before pickup.'),
(2, 'Child Seat', 30.00, 'Safety-approved child seat fitted before pickup.'),
(3, 'Premium Insurance', 100.00, 'Extended damage and liability cover for the rental period.');

-- --------------------------------------------------------
-- booking_addons
-- --------------------------------------------------------

-- Add-ons attached to a booking; unit_price/days are snapshotted at purchase time
CREATE TABLE IF NOT EXISTS `booking_addons` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `addon_id` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `days` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_booking_addons_booking` (`booking_id`),
  CONSTRAINT `fk_booking_addons_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_booking_addons_addon` FOREIGN KEY (`addon_id`) REFERENCES `addons` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- damage_reports
-- --------------------------------------------------------

-- Admin damage-sketch reports (was created ad hoc by admin/damage_sketch.php on every page load)
CREATE TABLE IF NOT EXISTS `damage_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `car_id` int(11) NOT NULL,
  `description` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Reconcile block: brings a database migrated before this file existed up to
-- the same final shape as a fresh install. No-ops on a fresh install, since
-- every CREATE TABLE above already has the final shape these statements aim for.
-- --------------------------------------------------------

-- Add cars.archived_at if an older schema doesn't have it yet (MySQL has no ADD COLUMN IF NOT EXISTS)
SET @dbname = DATABASE();
SET @tablename = 'cars';
SET @columnname = 'archived_at';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  "SELECT 1",
  "ALTER TABLE cars ADD archived_at DATETIME NULL DEFAULT NULL"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Widen cars.car_type to the full enum on a database still on the old 5-value set
ALTER TABLE cars MODIFY car_type ENUM('Compact','Sedan','SUV','MPV','Luxury','Sports','Hatchback','Truck') NOT NULL;

-- Restore 'ongoing' to bookings.booking_status on a database still missing it
ALTER TABLE bookings MODIFY booking_status ENUM('pending','approved','rejected','ongoing','completed','cancelled') NOT NULL DEFAULT 'pending';

-- Widen payments.payment_method to the full enum on a database still on the old 3-value set
ALTER TABLE payments MODIFY payment_method ENUM('Cash','Bank Transfer','Online Payment','Credit Card','Debit Card','Online Banking','E-Wallet') NOT NULL;

-- Backfill 'paid' rows that stored the raw charge instead of the cumulative rental+late-fee settlement total
UPDATE payments p
JOIN bookings b ON b.id = p.booking_id
LEFT JOIN (
    SELECT booking_id, SUM(late_fee_amount) AS fee_total
    FROM late_fees GROUP BY booking_id
) f ON f.booking_id = p.booking_id
SET p.amount = b.total_amount + COALESCE(f.fee_total, 0)
WHERE p.payment_status = 'paid'
  AND p.amount < b.total_amount + COALESCE(f.fee_total, 0);

-- Drop the per-file migration tracking table; schema.sql applies as one idempotent pass instead
DROP TABLE IF EXISTS migrations_log;
