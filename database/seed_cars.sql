-- database/seed_cars.sql
--
-- Seeds 15 demo fleet cars (ids 3-17), on top of the 2 cars schema.sql already
-- seeds (ids 1-2). database/seed_bookings.sql's car_id references depend on
-- these ids existing. Requires the widened car_type enum from schema.sql
-- (already applied by `php database/migrate.php`).
-- Not part of migrate.php's auto-run list -- apply by hand:
--   mysql -u <user> -p <db> < database/seed_cars.sql
-- Safe to re-run: INSERT IGNORE skips ids that already exist.
INSERT IGNORE INTO cars (id, brand, model, plate_number, car_type, transmission, fuel_type, seats, daily_rate, image, status) VALUES
(3,  'Tesla', 'Model S', 'EV 001', 'Luxury', 'Automatic', 'Electric', 5, 500.00, 'car/modelS.jpg', 'available'),
(4,  'BMW', 'M4', 'M 444', 'Sports', 'Automatic', 'Petrol', 4, 800.00, 'car/bmwM4.jpg', 'available'),
(5,  'Mercedes-Benz', 'C-Class', 'MB 100', 'Luxury', 'Automatic', 'Petrol', 5, 450.00, 'car/C-class.jpg', 'available'),
(6,  'Audi', 'RS6', 'RS 66', 'Sports', 'Automatic', 'Petrol', 5, 900.00, 'car/RS6.jpg', 'available'),
(7,  'Honda', 'Civic', 'HC 555', 'Sedan', 'Automatic', 'Petrol', 5, 150.00, 'car/civic.jpg', 'available'),
(8,  'Toyota', 'Corolla', 'TC 999', 'Sedan', 'Automatic', 'Petrol', 5, 140.00, 'car/Corrola.jpg', 'available'),
(9,  'Ford', 'Mustang', 'FM 888', 'Sports', 'Automatic', 'Petrol', 4, 600.00, 'car/mustang.jpg', 'available'),
(10, 'Porsche', '911', 'P 911', 'Sports', 'Automatic', 'Petrol', 2, 1200.00, 'car/911.jpg', 'available'),
(11, 'Land Rover', 'Range Rover', 'LR 101', 'SUV', 'Automatic', 'Petrol', 5, 1000.00, 'car/RangeRover.jpg', 'available'),
(12, 'Jeep', 'Wrangler', 'JP 4X4', 'SUV', 'Automatic', 'Petrol', 5, 400.00, 'car/wrangler.jpg', 'available'),
(13, 'Nissan', 'GT-R', 'GTR 35', 'Sports', 'Automatic', 'Petrol', 4, 950.00, 'car/GTR.jpg', 'available'),
(14, 'Volkswagen', 'Golf', 'VW 007', 'Hatchback', 'Automatic', 'Petrol', 5, 180.00, 'car/VGolf.jpg', 'available'),
(15, 'Hyundai', 'Tucson', 'HT 222', 'SUV', 'Automatic', 'Petrol', 5, 220.00, 'car/tucson.jpg', 'available'),
(16, 'Mazda', 'CX-5', 'MZ 55', 'SUV', 'Automatic', 'Petrol', 5, 250.00, 'car/CX-5.jpg', 'available'),
(17, 'Kia', 'Sportage', 'KS 88', 'SUV', 'Automatic', 'Petrol', 5, 230.00, 'car/sportage.jpg', 'available');
