ALTER TABLE cars MODIFY COLUMN car_type ENUM('Compact','Sedan','SUV','MPV','Luxury','Sports','Hatchback','Truck') NOT NULL;

INSERT INTO cars (brand, model, plate_number, car_type, transmission, fuel_type, seats, daily_rate, image, status) VALUES
('Tesla', 'Model S', 'EV 001', 'Luxury', 'Automatic', 'Electric', 5, 500.00, 'tesla-model-s.jpg', 'available'),
('BMW', 'M4', 'M 444', 'Sports', 'Automatic', 'Petrol', 4, 800.00, 'bmw-m4.jpg', 'available'),
('Mercedes-Benz', 'C-Class', 'MB 100', 'Luxury', 'Automatic', 'Petrol', 5, 450.00, 'mercedes-c-class.jpg', 'available'),
('Audi', 'RS6', 'RS 66', 'Sports', 'Automatic', 'Petrol', 5, 900.00, 'audi-rs6.jpg', 'available'),
('Honda', 'Civic', 'HC 555', 'Sedan', 'Automatic', 'Petrol', 5, 150.00, 'honda-civic.jpg', 'available'),
('Toyota', 'Corolla', 'TC 999', 'Sedan', 'Automatic', 'Petrol', 5, 140.00, 'toyota-corolla.jpg', 'available'),
('Ford', 'Mustang', 'FM 888', 'Sports', 'Automatic', 'Petrol', 4, 600.00, 'ford-mustang.jpg', 'available'),
('Porsche', '911', 'P 911', 'Sports', 'Automatic', 'Petrol', 2, 1200.00, 'porsche-911.jpg', 'available'),
('Land Rover', 'Range Rover', 'LR 101', 'SUV', 'Automatic', 'Petrol', 5, 1000.00, 'range-rover.jpg', 'available'),
('Jeep', 'Wrangler', 'JP 4X4', 'SUV', 'Automatic', 'Petrol', 5, 400.00, 'jeep-wrangler.jpg', 'available'),
('Nissan', 'GT-R', 'GTR 35', 'Sports', 'Automatic', 'Petrol', 4, 950.00, 'nissan-gtr.jpg', 'available'),
('Volkswagen', 'Golf', 'VW 007', 'Hatchback', 'Automatic', 'Petrol', 5, 180.00, 'vw-golf.jpg', 'available'),
('Hyundai', 'Tucson', 'HT 222', 'SUV', 'Automatic', 'Petrol', 5, 220.00, 'hyundai-tucson.jpg', 'available'),
('Mazda', 'CX-5', 'MZ 55', 'SUV', 'Automatic', 'Petrol', 5, 250.00, 'mazda-cx5.jpg', 'available'),
('Kia', 'Sportage', 'KS 88', 'SUV', 'Automatic', 'Petrol', 5, 230.00, 'kia-sportage.jpg', 'available');
