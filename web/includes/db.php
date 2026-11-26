<?php
/**
 * Opens the MySQL connection used by every page as $conn.
 * Credentials come from the project-level .env file (see .env.example).
 */

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$dbHost = env('DB_HOST');
$dbUser = env('DB_USER');
$dbPassword = env('DB_PASSWORD');
$dbName = env('DB_NAME');
$dbPort = (int) env('DB_PORT', '3306');

if (!$dbHost || !$dbUser || $dbPassword === null || !$dbName) {
    error_log('FiscalPoint: database configuration is incomplete (check .env).');
    http_response_code(500);
    exit('Database configuration is incomplete. Copy .env.example to .env and fill in the database settings.');
}

try {
    $conn = new mysqli($dbHost, $dbUser, $dbPassword, $dbName, $dbPort);
    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '" . date('P') . "'");   // match PHP's timezone (see bootstrap.php)
} catch (mysqli_sql_exception $exception) {
    error_log('FiscalPoint: database connection failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Database connection failed.');
}
