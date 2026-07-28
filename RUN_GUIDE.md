# How to Run CarGO Locally (Docker)

This guide explains how to run the CarGO application on your own local computer using Docker. This is the safest and easiest way to develop and test the app without needing to install XAMPP, PHP, or MySQL manually.

## Prerequisites

Before you start, make sure you have the following installed on your computer:
- **Docker Desktop** (or Docker Engine if you are on Linux)
- **Docker Compose** (comes included with Docker Desktop)

## Steps to Run

1. **Open your Terminal** (or Command Prompt / PowerShell) and navigate to the root directory of this project (`CarGO`).

2. **Start the Application:**
   Run the following command to build the app container and start the MySQL database:
   ```bash
   docker-compose up -d --build
   ```
   *Note: The `-d` flag runs it in the background so you can keep using your terminal.*

3. **Wait for the Database:**
   Wait about 10-15 seconds for the MySQL container to fully start up and initialize.

4. **Run Database Migrations:**
   Now, you need to create the database tables. Run this command to execute the migration script inside the app container:
   ```bash
   docker-compose exec app php database/migrate.php
   ```

5. **Optional: Load Demo Data:**
   To browse a full demo fleet and booking history instead of the 2 starter cars, run:
   ```bash
   docker-compose exec app sh -c 'mysql -h db -u cargo_user -pcargo_secure_password cargo_rental < database/seed_cars.sql'
   docker-compose exec app sh -c 'mysql -h db -u cargo_user -pcargo_secure_password cargo_rental < database/seed_bookings.sql'
   ```

6. **Open the App:**
   Open your favorite web browser (Chrome, Firefox, Safari) and go to:
   **[http://localhost:8888](http://localhost:8888)**

   You should now see the CarGO application running!

## Stopping the App

When you are done and want to turn off the server, simply run:
```bash
docker-compose down
```
This will stop and remove the containers, but your database data will be saved safely.
