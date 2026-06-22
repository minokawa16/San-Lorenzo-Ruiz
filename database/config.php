<?php
/**
 * Database Configuration - Defines connection constants and opens the shared MySQL connection.
 */
// Database Configuration
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'parish_management_system');

// Disable mysqli warnings so we can handle errors gracefully
mysqli_report(MYSQLI_REPORT_OFF);

// Quick port check to give a clear message if MySQL is not running
$port_open = false;
$fp = @fsockopen(DB_HOST, DB_PORT, $errno, $errstr, 1);
if ($fp) { fclose($fp); $port_open = true; }
if (!$port_open) {
    $msg = "Cannot connect to MySQL at " . DB_HOST . ":" . DB_PORT . ".";
    $msg .= " Start MySQL from XAMPP Control Panel and try again.";
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
            die("Connection failed: " . $temp_conn->connect_error . " Please check your database credentials in database/config.php and ensure MySQL is running (start XAMPP MySQL).");
        }

        $temp_conn->close();
        die("Database not found. Please run the installation script at /database/install.php");
    }

    // Connection actively refused -> usually MySQL not running or firewall/port issue
    if (stripos($err, 'refused') !== false || stripos($err, 'actively refused') !== false || stripos($err, 'No connection could be made') !== false) {
        die("Connection failed: " . $err . " Please ensure MySQL is running (open XAMPP Control Panel and start MySQL), and that DB_HOST/DB_PORT in database/config.php are correct.");
    }

    die("Connection failed: " . $err);
}

// Set charset to UTF-8
$conn->set_charset("utf8");

?>
