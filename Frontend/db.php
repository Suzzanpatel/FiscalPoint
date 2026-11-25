<?php
require_once __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$dbHost = $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? null;
$dbUser = $_ENV['DB_USER'] ?? $_SERVER['DB_USER'] ?? null;
$dbPassword = $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? null;
$dbName = $_ENV['DB_NAME'] ?? $_SERVER['DB_NAME'] ?? null;

if (!$dbHost || !$dbUser || $dbPassword === null || !$dbName) {
    error_log('Database configuration is incomplete.');
    http_response_code(500);
    exit('Database configuration is incomplete.');
}

try {
    $conn = new mysqli($dbHost, $dbUser, $dbPassword, $dbName);
} catch (mysqli_sql_exception $exception) {
    error_log('Database connection failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Database connection failed.');
}

if ($conn->connect_error) {
    error_log('Database connection failed: ' . $conn->connect_error);
    http_response_code(500);
    exit('Database connection failed.');
}

$conn->set_charset('utf8mb4');
