<?php
// db_connect.php

// Enable Composer autoloader for dynamic environment handling
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    if (file_exists(__DIR__ . '/.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
        $dotenv->safeLoad();
    }
}

function getDbConnection(): mysqli
{
    static $conn = null;
    if ($conn !== null) {
        return $conn;
    }

    $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
    $username = $_ENV['DB_USER'] ?? 'root';
    $password = $_ENV['DB_PASS'] ?? '';
    $database = $_ENV['DB_NAME'] ?? 'cargo_rental';
    $port = (int)($_ENV['DB_PORT'] ?? 3306);

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $conn = new mysqli($host, $username, $password, $database, $port);
    $conn->set_charset('utf8mb4');

    return $conn;
}
