# CarGO Car Rental Management System

CarGO is a PHP and MySQL car rental management system for customers and administrators. Customers can browse available cars, create and manage bookings, pay bookings, and update their profile. Admin users can manage cars, bookings, customers, dashboard reporting, and admin accounts.

## Preview

> Add screenshots here.

| Customer Dashboard | Admin Dashboard |
| --- | --- |
| _Screenshot placeholder_ | _Screenshot placeholder_ |

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
- MySQL / MariaDB
- HTML, CSS, vanilla JavaScript
- XAMPP / LAMPP local server

## Project Structure

```text
CarGO/
├── admin/              # Admin pages and admin auth helpers
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

1. Place the project in your web server directory, for example:

   ```text
   /opt/lampp/htdocs/CarGO
   ```

2. Create or import the `cargo_rental` MySQL database.

3. Check database credentials in `db_connect.php`:

   ```php
   $host = 'localhost';
   $username = 'root';
   $password = '';
   $database = 'cargo_rental';
   ```

4. Run the database helper scripts if needed:

   ```text
   database/ensure_car_archived_at.sql
   database/ensure_cancelled_booking_status.sql
   database/ensure_completed_booking_status.sql
   ```

5. Start Apache and MySQL, then open:

   ```text
   http://localhost/CarGO/
   ```

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

## Testing Checklist

- Register and log in as a customer.
- Browse cars, check availability, create a booking, pay, and cancel where allowed.
- Log in as an admin and manage cars, bookings, and customers.
- Confirm blocked customers/admins cannot continue using protected pages.
- Try invalid CSRF tokens and confirm requests are rejected.
- Run PHP lint on changed files:

  ```bash
  php -l path/to/file.php
  ```

## Notes

- This project is designed for a local academic/demo environment.
- Keep uploaded car images in `car/`.
- Add screenshots or database diagrams in the Preview section when preparing the final report.
