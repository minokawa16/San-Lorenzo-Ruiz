<?php
require __DIR__ . '/config.php';
header('Content-Type: text/plain');
if (isset($conn) && !$conn->connect_error) {
    echo "Database connection successful. Host: " . DB_HOST . " Port: " . DB_PORT . "\n";
    echo "Database: " . DB_NAME . "\n";
} else {
    echo "Database connection failed: " . ($conn->connect_error ?? 'unknown') . "\n";
    echo "Check XAMPP -> Start MySQL, verify credentials in database/config.php, and ensure no firewall blocks port " . DB_PORT . "\n";
}
?>