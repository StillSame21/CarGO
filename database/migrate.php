<?php

// database/migrate.php
//
// Applies database/schema.sql - the whole schema in one idempotent file.
// Safe to re-run any time (e.g. after pulling changes that touch schema.sql).
require_once __DIR__ . '/../db_connect.php';

try {
    $conn = getDbConnection();

    $sqlPath = __DIR__ . '/schema.sql';
    if (!file_exists($sqlPath)) {
        throw new Exception("Schema file not found: $sqlPath");
    }
    $sqlContent = file_get_contents($sqlPath);

    echo "Applying database/schema.sql...\n";

    if (!$conn->multi_query($sqlContent)) {
        throw new Exception('DB Migration Error: ' . $conn->error);
    }

    do {
        if ($result = $conn->store_result()) {
            $result->free();
        }
        if ($conn->errno) {
            throw new Exception('DB Migration Error: ' . $conn->error);
        }
    } while ($conn->more_results() && $conn->next_result());

    if ($conn->errno) {
        throw new Exception('DB Migration Error: ' . $conn->error);
    }

    echo "Database schema successfully applied!\n";
} catch (Exception $e) {
    echo $e->getMessage() . "\n";
    exit(1);
}
