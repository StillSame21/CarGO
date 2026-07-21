INSERT IGNORE INTO addons (id, name, price, description) VALUES
(1, 'GPS Navigation', 50.00, 'Turn-by-turn navigation unit fitted before pickup.'),
(2, 'Child Seat', 30.00, 'Safety-approved child seat fitted before pickup.'),
(3, 'Premium Insurance', 100.00, 'Extended damage and liability cover for the rental period.');

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
