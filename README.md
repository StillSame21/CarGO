# CarGO Car Rental Management System

Website Preview: [cargo.site.je](https://cargo.site.je)

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

- PHP 8.1+ (procedural, mysqli with prepared statements)
- MySQL / MariaDB
- GD (image resizing, WebP/JPEG derivative generation)
- HTML, CSS, vanilla JavaScript
- Docker + Docker Compose (local dev)
- Composer (`vlucas/phpdotenv`; dev: PHPUnit, PHPStan, PHP_CodeSniffer)
- Hosted on InfinityFree (shared hosting)

## Project Structure

```text
CarGO/
├── admin/              # Admin pages and admin auth helpers
├── asset/              # README screenshots, car image placeholder
├── car/                # Uploaded car images and generated WebP/JPEG derivatives
├── css/                # Admin and customer page styles
├── customer/           # Customer pages and customer auth helpers
├── database/           # schema.sql (full schema, idempotent), migrate.php runner, seed files
├── includes/           # Shared security, session, and layout helpers
├── js/                 # Browse/booking filter UI scripts
├── scripts/            # One-time CLI maintenance scripts (image backfill)
├── tests/              # PHPUnit test suite
├── util/               # Shared booking, payment, car image, and archive helpers
├── composer.json        # Dependencies and eager-autoloaded helper files
├── db_connect.php       # Database connection (env vars or config.local.php)
├── docker-compose.yml   # Local app + MariaDB stack
├── Dockerfile            # PHP/Apache image with GD, WebP, and caching config
├── index.php             # Entry redirect/page
├── logout.php            # POST-only logout endpoint
└── phpunit.xml            # Test runner config
```

## Setup

### Local development: Docker

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
5. Optional: load demo cars and bookings:
   ```bash
   docker-compose exec app sh -c 'mysql -h db -u cargo_user -pcargo_secure_password cargo_rental < database/seed_cars.sql'
   docker-compose exec app sh -c 'mysql -h db -u cargo_user -pcargo_secure_password cargo_rental < database/seed_bookings.sql'
   ```
6. Open your browser and navigate to `http://localhost:8888`.

For more details, see [RUN_GUIDE.md](RUN_GUIDE.md).

### Hosting: InfinityFree

The live site runs on [InfinityFree](https://infinityfree.net) shared hosting.

1. Upload the project files to the account's `htdocs/` via FTP.
2. Create the MySQL database from the InfinityFree control panel and run `database/schema.sql`
   against it (via `database/migrate.php` or by importing the file directly through phpMyAdmin).
3. Set DB credentials via `config.local.php` (see `db_connect.php`) rather than committing them.
4. GD's WebP support and `.htaccess`'s `mod_expires`/`mod_headers`/`mod_deflate` rules depend on
   what the host has enabled; `util/car_image.php` falls back to a plain copy/no derivatives when
   GD or WebP aren't available, so the upload path still works either way.
5. Shared hosting has a low memory ceiling — `CAR_IMAGE_DECODE_CEILING_BYTES` in
   `util/car_image.php` is tuned for that; only raise it via `CARGO_DECODE_CEILING_MB` on
   hosts you know can handle it.

## Demo Accounts

Both login pages (`customer/login.php`, `admin/login.php`) offer a "Try Demo" button that
signs into a dedicated, public demo account via `demo_login.php` — no real customer or admin
credentials are ever used or exposed:

- **Demo customer** (`demo.customer@cargo.demo`): can browse cars and run the booking flow
  (create, pay, cancel) end to end; profile edits are blocked.
- **Demo admin** (`demo.admin@cargo.demo`): has the `manager` role, not `super_admin`, so
  Admin Management is hidden and out of reach; car, customer, and booking writes are blocked.

Both accounts are seeded by `database/ensure_demo_accounts.sql` (run automatically by
`database/migrate.php`) and have no password anyone can log in with directly — the demo
buttons are the only way in. A demo session is marked with `$_SESSION['is_demo']`; write
attempts are rejected by `blockDemoWrite()` in `includes/security.php`, and a persistent
banner (`includes/demo_banner.php`) makes the demo state visible with a "Leave demo" link.

## Security Notes

- All SQL uses prepared statements (`mysqli` bound params); see `util/` and `admin/`/`customer/` pages.
- CSRF tokens are required on every state-changing POST (`includes/security.php`:
  `csrfToken()`, `requireValidCsrfToken()`); logout is POST-only.
- Sessions use strict mode, `HttpOnly`, `SameSite=Lax`, and `Secure` when served over HTTPS
  (`startSecureSession()`); the CSRF token and session ID both regenerate on login.
- Customer and admin accounts are re-checked against their current DB status
  (`requireCustomerLogin()`, `requireAdminLogin()`) on every protected page, so a blocked or
  deleted account loses access immediately rather than on next login.
- Passwords are stored with `password_hash()` / `password_verify()`; only super admins can
  create/edit other admin accounts (`requireSuperAdmin()`).
- Uploaded car images are validated by MIME type, size, and decoded resolution before being
  written to disk (`processCarImageUpload()`), and derivative/original file paths are checked
  against directory traversal before any filesystem write or delete.

## Notes

- Run `php test.php` (or `vendor/bin/phpunit`, `vendor/bin/phpstan analyze --level=4`,
  `vendor/bin/phpcs --standard=PSR12`) before committing changes to `util/` or `includes/`.
- `car/` holds the uploaded originals (plus a full-size WebP sibling); their generated JPEG+WebP
  derivatives live in `car/400/` and `car/800/` width subfolders. `scripts/optimize_existing_car_images.php`
  is a one-time CLI backfill for images that predate the derivative pipeline — safe to re-run.
  `scripts/reorganize_car_image_folders.php` was a one-time migration that moved pre-existing
  `car/<name>-400.*`/`car/<name>-800.*` files into those subfolders.
- `database/schema.sql` is the whole schema in one idempotent file; `database/migrate.php` just
  applies it, so re-run it any time after pulling changes that touch `schema.sql`.
  `database/seed_cars.sql` and `database/seed_bookings.sql` are demo data, applied by hand.
- Add screenshots or database diagrams to the Preview section when preparing the final report.
