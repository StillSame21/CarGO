<?php
// database/migrate.php
require_once __DIR__ . '/../db_connect.php';

try {
    $conn = getDbConnection();

    // Create migrations_log table to keep tracking audit
    $conn->query("
        CREATE TABLE IF NOT EXISTS migrations_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Migration scripts defined in order of implementation dependency
    $migrations = [
        'cargo_rental.sql',
        'ensure_car_archived_at.sql',
        'ensure_cancelled_booking_status.sql',
        'ensure_completed_booking_status.sql'
    ];

    foreach ($migrations as $migration) {
        // Query to check if migration has run already
        $stmt = $conn->prepare("SELECT id FROM migrations_log WHERE migration_name = ?");
        $stmt->bind_param("s", $migration);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 0) {
            echo "Executing migration: $migration...\n";
            $sqlPath = __DIR__ . '/' . $migration;
            if (!file_exists($sqlPath)) {
                throw new Exception("Migration file not found: $migration");
            }
            $sqlContent = file_get_contents($sqlPath);

            // Execute using multi_query to support multiple SQL statements
            if ($conn->multi_query($sqlContent)) {
                do {
                    if ($result = $conn->store_result()) {
                        $result->free();
                    }
                } while ($conn->more_results() && $conn->next_result());
            }

            // Log successful execution
            $logStmt = $conn->prepare("INSERT INTO migrations_log (migration_name) VALUES (?)");
            $logStmt->bind_param("s", $migration);
            $logStmt->execute();
            echo "Successfully migrated: $migration\n";
        } else {
            echo "Migration $migration is already up to date. Skipping.\n";
        }
    }
    echo "Database migrations successfully updated!\n";
} catch (Exception $e) {
    echo "DB Migration Error: " . $e->getMessage() . "\n";
    exit(1);
}
