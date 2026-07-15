# CarGO Car Rental Management System

CarGO is a PHP and MySQL car rental management system for customers and administrators. Customers can browse available cars, create and manage bookings, pay bookings, and update their profile. Admin users can manage cars, bookings, customers, dashboard reporting, and admin accounts.

## Preview

| Customer Dashboard | Customer Booking | Customer Booking Record |
| --- | --- | --- |
| ![Customer Dashboard](asset/customer_dashboard.png) | ![Customer Booking](asset/customer_booking.png) | ![Customer Booking Record](asset/customer_booking_record.png) |

| Admin Dashboard | Admin Cars | Admin Booking |
| --- | --- | --- |
| ![Admin Dashboard](asset/admin_dashboard.png) | ![Admin Cars](asset/admin_cars.png) | ![Admin Booking](asset/admin_booking.png) |

| Admin Customer | Admin Management |
| --- | --- |
| ![Admin Customer](asset/admin_customer.png) | ![Admin Management](asset/admin_management.png) |

## Features

- Customer registration, login, profile update, and password change.
- Customer car browsing, availability checks, booking creation, payment, cancellation, and booking history.
- Admin dashboard with fleet, booking, customer, and revenue overview.
- Admin car management with add, edit, image upload, and soft archive.
- Admin booking management with search, filters, pickup confirmation, return confirmation, and late fee handling.
- Customer management with search, filtering, edit, block, and unblock.
- Super admin account management for admin users, roles, status, and password reset.
- Security helpers for prepared statements, CSRF protection, safer sessions, POST-only logout, and active-account checks.

## Tech Stack

- PHP
- MySQL
- HTML, CSS
- XAMPP local server

## Project Structure

```text
CarGO/
├── admin/              # Admin pages and admin auth helpers
├── asset/              # README screenshots
├── customer/           # Customer pages and customer auth helpers
├── css/                # Admin and customer page styles
├── database/           # Small database update scripts
├── includes/           # Shared security helpers
├── util/               # Shared booking, payment, car image, and archive helpers
├── car/                # Uploaded car images
├── db_connect.php      # Database connection
├── index.php           # Entry redirect/page
└── logout.php          # POST-only logout endpoint
```

## Setup

### Option 1: Using Docker (Recommended)

1. Ensure **Docker** and **Docker Compose** are installed.
2. Run the following command in the project root to start the containers:
   ```bash
   docker-compose up -d --build
   ```
3. Wait about 10-15 seconds for the MySQL database to initialize.
4. Run the database migrations:
   ```bash
   docker-compose exec app php database/migrate.php
   ```
5. Open your browser and navigate to `http://localhost:8888`.

For more details, see [RUN_GUIDE.md](RUN_GUIDE.md).

### Option 2: Using XAMPP / Local Server

1. Place the project in your web server directory, for example:
   `/opt/lampp/htdocs/CarGO`
2. Create or import the `cargo_rental` MySQL database.
3. Check database credentials in `db_connect.php`:
   ```php
   $host = 'localhost';
   $username = 'root';
   $password = '';
   $database = 'cargo_rental';
   ```
4. Run the database helper scripts if needed:
   `database/ensure_car_archived_at.sql`
   `database/ensure_cancelled_booking_status.sql`
   `database/ensure_completed_booking_status.sql`
5. Start Apache and MySQL, then open:
   `http://localhost/CarGO/`

## Main Pages

- Customer login: `customer/login.php`
- Customer dashboard: `customer/dashboard.php`
- Browse cars: `customer/browse_cars.php`
- My bookings: `customer/my_bookings.php`
- Admin login: `admin/login.php`
- Admin dashboard: `admin/dashboard.php`
- Manage cars: `admin/manage_cars.php`
- Manage bookings: `admin/bookings.php`
- Manage customers: `admin/customers.php`
- Admin management: `admin/add_admin.php`

## Security Notes

- SQL operations that use user input should use prepared statements.
- CSRF tokens are required for state-changing POST requests.
- Logout is POST-only.
- Customer and admin sessions are checked against current database account status.
- Passwords are stored with `password_hash()` and verified with `password_verify()`.

## Notes

- This project is designed for a local academic/demo environment.
- Keep uploaded car images in `car/`.
- Add screenshots or database diagrams in the Preview section when preparing the final report.
