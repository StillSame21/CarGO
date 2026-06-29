ALTER TABLE bookings MODIFY booking_status ENUM('pending', 'approved', 'rejected', 'cancelled', 'completed') NOT NULL DEFAULT 'pending';
