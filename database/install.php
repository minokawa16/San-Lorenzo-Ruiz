<?php
/**
 * Database Install Module - Creates the parish database and imports the initial schema.
 */
// Database Setup Handler
session_start();

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'parish_management_system';

// Create connection without database first
$conn = new mysqli($db_host, $db_user, $db_pass);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Read and execute SQL setup file
$sql_file = dirname(__FILE__) . '/setup.sql';
$sql = file_get_contents($sql_file);

// Split SQL statements and execute them
$statements = array_filter(array_map('trim', preg_split('/;[\r\n]/', $sql)));

foreach ($statements as $statement) {
    if (!empty($statement)) {
        if (!$conn->multi_query($statement . ";")) {
            echo "Error executing query: " . $conn->error;
        }
        
        // Clear results
        while ($conn->more_results() && $conn->next_result()) {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        }
    }
}

$conn->close();
echo "Database setup completed successfully! <br>";
echo "Admin Email: admin@parish.com <br>";
echo "Admin Password: admin123 <br>";
echo "You can now <a href='../index.php'>proceed to login</a>";
?>
