ALTER TABLE cars MODIFY COLUMN car_type ENUM('Compact','Sedan','SUV','MPV','Luxury','Sports','Hatchback','Truck') NOT NULL;

INSERT INTO cars (brand, model, plate_number, car_type, transmission, fuel_type, seats, daily_rate, image, status) VALUES
('Tesla', 'Model S', 'EV 001', 'Luxury', 'Automatic', 'Electric', 5, 500.00, 'car/modelS.jpg', 'available'),
('BMW', 'M4', 'M 444', 'Sports', 'Automatic', 'Petrol', 4, 800.00, 'car/bmwM4.jpg', 'available'),
('Mercedes-Benz', 'C-Class', 'MB 100', 'Luxury', 'Automatic', 'Petrol', 5, 450.00, 'car/C-class.jpg', 'available'),
('Audi', 'RS6', 'RS 66', 'Sports', 'Automatic', 'Petrol', 5, 900.00, 'car/RS6.jpg', 'available'),
('Honda', 'Civic', 'HC 555', 'Sedan', 'Automatic', 'Petrol', 5, 150.00, 'car/civic.jpg', 'available'),
('Toyota', 'Corolla', 'TC 999', 'Sedan', 'Automatic', 'Petrol', 5, 140.00, 'car/Corrola.jpg', 'available'),
('Ford', 'Mustang', 'FM 888', 'Sports', 'Automatic', 'Petrol', 4, 600.00, 'car/mustang.jpg', 'available'),
('Porsche', '911', 'P 911', 'Sports', 'Automatic', 'Petrol', 2, 1200.00, 'car/911.jpg', 'available'),
('Land Rover', 'Range Rover', 'LR 101', 'SUV', 'Automatic', 'Petrol', 5, 1000.00, 'car/RangeRover.jpg', 'available'),
('Jeep', 'Wrangler', 'JP 4X4', 'SUV', 'Automatic', 'Petrol', 5, 400.00, 'car/wrangler.jpg', 'available'),
('Nissan', 'GT-R', 'GTR 35', 'Sports', 'Automatic', 'Petrol', 4, 950.00, 'car/GTR.jpg', 'available'),
('Volkswagen', 'Golf', 'VW 007', 'Hatchback', 'Automatic', 'Petrol', 5, 180.00, 'car/VGolf.jpg', 'available'),
('Hyundai', 'Tucson', 'HT 222', 'SUV', 'Automatic', 'Petrol', 5, 220.00, 'car/tucson.jpg', 'available'),
('Mazda', 'CX-5', 'MZ 55', 'SUV', 'Automatic', 'Petrol', 5, 250.00, 'car/CX-5.jpg', 'available'),
('Kia', 'Sportage', 'KS 88', 'SUV', 'Automatic', 'Petrol', 5, 230.00, 'car/sportage.jpg', 'available');
