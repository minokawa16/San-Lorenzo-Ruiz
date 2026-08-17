<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$host = getenv('DB_HOST') ?: (getenv('MYSQLHOST') ?: '127.0.0.1');
$port = (int) (getenv('DB_PORT') ?: (getenv('MYSQLPORT') ?: 3306));
$user = getenv('DB_USER') ?: (getenv('MYSQLUSER') ?: 'root');
$password = getenv('DB_PASSWORD') ?: (getenv('MYSQLPASSWORD') ?: '');
$database = getenv('DB_NAME') ?: (getenv('MYSQLDATABASE') ?: 'parish_management_system');

mysqli_report(MYSQLI_REPORT_OFF);
$connection = @new mysqli($host, $user, $password, $database, $port);
if ($connection->connect_errno) {
    http_response_code(503);
    echo json_encode(['status' => 'unavailable', 'database' => false]);
    exit;
}

$connection->close();
echo json_encode(['status' => 'ok', 'database' => true]);
