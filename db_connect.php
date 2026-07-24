<?php

// db_connect.php

if (!function_exists('getDbConnection')) {
    function getDbConnection(): mysqli
    {
        static $conn = null;
        if ($conn !== null) {
            return $conn;
        }

        $cfg = [];
        $cfgPath = __DIR__ . '/config.local.php';
        if (is_file($cfgPath)) {
            $cfg = require $cfgPath;
        }

        $host = $cfg['DB_HOST'] ?? $_ENV['DB_HOST'] ?? '127.0.0.1';
        $username = $cfg['DB_USER'] ?? $_ENV['DB_USER'] ?? 'root';
        $password = $cfg['DB_PASS'] ?? $_ENV['DB_PASS'] ?? '';
        $database = $cfg['DB_NAME'] ?? $_ENV['DB_NAME'] ?? 'cargo_rental';
        $port = (int)($cfg['DB_PORT'] ?? $_ENV['DB_PORT'] ?? 3306);

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $conn = new mysqli($host, $username, $password, $database, $port);
        $conn->set_charset('utf8mb4');

        return $conn;
    }
}
