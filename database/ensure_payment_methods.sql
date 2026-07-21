ALTER TABLE payments MODIFY payment_method ENUM('Cash', 'Bank Transfer', 'Online Payment', 'Credit Card', 'Debit Card', 'Online Banking', 'E-Wallet') NOT NULL;
ALTER TABLE bookings MODIFY booking_status ENUM('pending', 'approved', 'rejected', 'ongoing', 'cancelled', 'completed') NOT NULL DEFAULT 'pending';
