<?php
/**
 * Database Configuration - Defines connection constants and opens the shared MySQL connection.
 */
// Database Configuration
// Local XAMPP defaults are used unless production credentials are provided.
// For InfinityFree, set these values in config/db.local.php or environment variables:
// DB_HOST=sql123.infinityfree.com
// DB_USER=if0_12345678
// DB_PASSWORD=your_password
// DB_NAME=if0_12345678_tugondb
$local_db_config = __DIR__ . '/../config/db.local.php';
if (is_file($local_db_config)) {
    require_once $local_db_config;
}

$databaseProduction = strtolower(trim((string) (getenv('APP_ENV') ?: 'local'))) === 'production';
$databaseEnvironment = [
    'host' => getenv('DB_HOST') ?: getenv('MYSQLHOST'),
    'user' => getenv('DB_USER') ?: getenv('MYSQLUSER'),
    'password' => getenv('DB_PASSWORD') ?: getenv('MYSQLPASSWORD'),
    'name' => getenv('DB_NAME') ?: getenv('MYSQLDATABASE'),
];
if ($databaseProduction) {
    foreach ($databaseEnvironment as $label => $value) {
        if ($value === false || trim((string) $value) === '') {
            throw new RuntimeException('Production database ' . $label . ' must be configured through the environment.');
        }
    }
}

if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('DB_HOST') ?: (getenv('MYSQLHOST') ?: '127.0.0.1'));
}
if (!defined('DB_PORT')) {
    define('DB_PORT', intval(getenv('DB_PORT') ?: (getenv('MYSQLPORT') ?: 3306)));
}
if (!defined('DB_USER')) {
    define('DB_USER', getenv('DB_USER') ?: (getenv('MYSQLUSER') ?: 'root'));
}
if (!defined('DB_PASSWORD')) {
    define('DB_PASSWORD', getenv('DB_PASSWORD') ?: (getenv('MYSQLPASSWORD') ?: ''));
}
if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('DB_NAME') ?: (getenv('MYSQLDATABASE') ?: 'parish_management_system'));
}

// Disable mysqli warnings so we can handle errors gracefully
mysqli_report(MYSQLI_REPORT_OFF);

// Quick port check to give a clear message if MySQL is not running
$port_open = false;
$fp = @fsockopen(DB_HOST, DB_PORT, $errno, $errstr, 1);
if ($fp) { fclose($fp); $port_open = true; }
if (!$port_open) {
    $msg = "Cannot connect to MySQL at " . DB_HOST . ":" . DB_PORT . ".";
    $msg .= " If you are local, start MySQL from XAMPP Control Panel. If you are on hosting, check your database host, username, password, and database name.";
    die("<div style='font-family:Arial,Helvetica,sans-serif;margin:20px;padding:12px;border:1px solid #e0e0e0;background:#fff7f7;color:#900;'>Database connection error: " . htmlspecialchars($msg) . "</div>");
}

// Create connection using consistent constant names (include port)
$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, DB_PORT);

// Check connection
if ($conn->connect_error) {
    $err = $conn->connect_error;
    // If connection fails due to database not existing, try to offer install hint
    if (strpos($err, 'Unknown database') !== false) {
        // Connect without specifying database
        $temp_conn = @new mysqli(DB_HOST, DB_USER, DB_PASSWORD, '', DB_PORT);
        if ($temp_conn->connect_error) {
            die("Connection failed: " . $temp_conn->connect_error . " Please check your database credentials in config/db.local.php or database/config.php.");
        }

        $temp_conn->close();
        die("Database not found. Please verify DB_NAME in config/db.local.php and import your database tables.");
    }

    // Connection actively refused -> usually MySQL not running or firewall/port issue
    if (stripos($err, 'refused') !== false || stripos($err, 'actively refused') !== false || stripos($err, 'No connection could be made') !== false) {
        die("Connection failed: " . $err . " If local, start MySQL in XAMPP. If hosted, verify DB_HOST and DB_PORT in config/db.local.php.");
    }

    die("Connection failed: " . $err);
}

// Use full Unicode so names, multilingual content, and symbols are preserved.
if (!$conn->set_charset('utf8mb4')) {
    error_log('Unable to configure the database connection for utf8mb4.');
    http_response_code(500);
    exit('Database character-set configuration error.');
}

// TUGON's authoritative civil timezone for parish schedules.
date_default_timezone_set('Asia/Manila');
$conn->query("SET time_zone = '+08:00'");

?>
