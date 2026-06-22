<?php
/**
 * Parish System Setup Verification
 * Checks database connection and required tables
 */

// Include database config
include 'database/config.php';

echo "<h2>Parish System - Setup Verification</h2>";
echo "<hr>";

// 1. Check Database Connection
echo "<h3>1. Database Connection</h3>";
if ($conn && !$conn->connect_error) {
    echo "✅ <strong>Connected to database successfully</strong><br>";
    echo "Database: " . DB_NAME . "<br>";
    echo "Host: " . DB_SERVER . "<br>";
} else {
    echo "❌ <strong>Database connection failed</strong><br>";
    echo "Error: " . $conn->connect_error . "<br>";
    exit;
}

echo "<hr>";

// 2. Check Required Tables
echo "<h3>2. Required Database Tables</h3>";

$required_tables = [
    'users' => 'User accounts',
    'requests' => 'Request tracking',
    'baptism_records' => 'Baptism records',
    'confirmation_records' => 'Confirmation records',
    'first_communion_records' => 'First communion records',
    'marriage_records' => 'Marriage records',
    'announcements' => 'Parish announcements'
];

$missing_tables = [];

foreach ($required_tables as $table_name => $description) {
    $result = $conn->query("SHOW TABLES LIKE '$table_name'");
    if ($result && $result->num_rows > 0) {
        echo "✅ " . ucfirst($table_name) . " - $description<br>";
    } else {
        echo "❌ " . ucfirst($table_name) . " - $description (MISSING)<br>";
        $missing_tables[] = $table_name;
    }
}

echo "<hr>";

// 3. Check for Admin User
echo "<h3>3. Admin User Account</h3>";

if (in_array('users', $missing_tables)) {
    echo "⚠️ Users table is missing. Please run database setup first.<br>";
} else {
    $result = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'admin'");
    if ($result) {
        $count = $result->fetch_assoc()['count'];
        if ($count > 0) {
            echo "✅ Admin user exists<br>";
        } else {
            echo "⚠️ No admin user found. Run setup to create one.<br>";
        }
    }
}

echo "<hr>";

// 4. Summary and Next Steps
echo "<h3>4. Status Summary</h3>";

if (empty($missing_tables)) {
    echo "✅ <strong>All systems ready!</strong><br>";
    echo "<br>You can now:<br>";
    echo "• <a href='auth/login.php'>Go to Login Page</a><br>";
    echo "• <a href='index.php'>Visit Homepage</a><br>";
} else {
    echo "❌ <strong>System needs setup</strong><br>";
    echo "Missing tables: " . implode(", ", $missing_tables) . "<br>";
    echo "<br><a href='database/install.php'><button style='padding:10px 20px; background:#4CAF50; color:white; border:none; cursor:pointer;'>Run Database Setup</button></a><br>";
}

echo "<hr>";
echo "<p style='font-size:12px; color:#666;'>Last checked: " . date('Y-m-d H:i:s') . "</p>";

$conn->close();
?>
