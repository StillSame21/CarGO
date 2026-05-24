<?php

function getDbConnection(): mysqli
{
    $host = 'localhost';
    $username = 'root';
    $password = '';
    $database = 'cargo_rental';

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $conn = new mysqli($host, $username, $password, $database);
    $conn->set_charset('utf8mb4');

    return $conn;
}
