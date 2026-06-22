<?php
/**
 * Parish System - Complete Database Setup & Initialization
 * Handles all database creation, tables, and test data
 */

header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html>
<html>
<head>
    <title>Parish System Setup</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 5px; }
        h1 { color: #2196F3; }
        .success { color: green; padding: 10px; background: #c8e6c9; margin: 10px 0; border-radius: 3px; }
        .error { color: red; padding: 10px; background: #ffcdd2; margin: 10px 0; border-radius: 3px; }
        .warning { color: orange; padding: 10px; background: #fff3cd; margin: 10px 0; border-radius: 3px; }
        .info { color: blue; padding: 10px; background: #bbdefb; margin: 10px 0; border-radius: 3px; }
        button { padding: 10px 20px; background: #2196F3; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 16px; }
        button:hover { background: #1976D2; }
    </style>
</head>
<body>
<div class='container'>
<h1>🏪 Parish System - Setup & Initialization</h1>";

// Database credentials
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'parish_management_system';

// Step 1: Connect without database
echo "<h3>Step 1: Connecting to MySQL Server...</h3>";
$temp_conn = new mysqli($db_host, $db_user, $db_pass);

if ($temp_conn->connect_error) {
    echo "<div class='error'>❌ Connection Failed: " . $temp_conn->connect_error . "</div>";
    echo "<div class='warning'>⚠️ Please ensure:<br>• MySQL server is running<br>• XAMPP control panel shows MySQL as running<br>• Check your database credentials in config.php</div>";
    echo "</div></body></html>";
    exit;
}

echo "<div class='success'>✅ Connected to MySQL server</div>";

// Step 2: Create Database
echo "<h3>Step 2: Creating/Checking Database...</h3>";

if ($temp_conn->query("CREATE DATABASE IF NOT EXISTS $db_name") === TRUE) {
    echo "<div class='success'>✅ Database '$db_name' ready</div>";
} else {
    echo "<div class='error'>❌ Error: " . $temp_conn->error . "</div>";
}

// Step 3: Select Database
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    echo "<div class='error'>❌ Cannot connect to database: " . $conn->connect_error . "</div>";
    exit;
}

echo "<div class='success'>✅ Database selected and ready</div>";

// Step 4: Create Tables
echo "<h3>Step 3: Creating Database Tables...</h3>";

// SQL for creating tables
$sql_statements = array(
    // Users table
    "CREATE TABLE IF NOT EXISTS users (
        id INT PRIMARY KEY AUTO_INCREMENT,
        fullname VARCHAR(255) NOT NULL,
        phone_number VARCHAR(20),
        email VARCHAR(100) UNIQUE NOT NULL,
        chapel_district VARCHAR(255),
        password VARCHAR(255) NOT NULL,
        role ENUM('user', 'admin') DEFAULT 'user',
        status ENUM('active', 'inactive') DEFAULT 'active',
        profile_picture VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_email (email),
        INDEX idx_role (role)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    
    // Requests table
    "CREATE TABLE IF NOT EXISTS requests (
        request_id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        request_type VARCHAR(100) NOT NULL,
        description TEXT,
        status ENUM('pending', 'approved', 'rejected', 'processing', 'completed') DEFAULT 'pending',
        date_requested TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        admin_response TEXT,
        reference_number VARCHAR(50) UNIQUE,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user (user_id),
        INDEX idx_status (status),
        INDEX idx_date (date_requested)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    
    // Baptism Records table
    "CREATE TABLE IF NOT EXISTS baptism_records (
        baptism_id INT PRIMARY KEY AUTO_INCREMENT,
        request_id INT,
        registry_no VARCHAR(50),
        fullname VARCHAR(100) NOT NULL,
        birth_date DATE,
        birth_place VARCHAR(150),
        birth_status VARCHAR(80),
        baptism_date DATE,
        parents VARCHAR(200),
        parent_address VARCHAR(200),
        godparents VARCHAR(200),
        parish_address VARCHAR(200),
        priest VARCHAR(100),
        remarks TEXT,
        status ENUM('active', 'archived') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_name (fullname),
        INDEX idx_date (baptism_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    
    // Confirmation Records table
    "CREATE TABLE IF NOT EXISTS confirmation_records (
        confirmation_id INT PRIMARY KEY AUTO_INCREMENT,
        request_id INT,
        registry_no VARCHAR(50),
        fullname VARCHAR(100) NOT NULL,
        birth_date DATE,
        confirmation_date DATE,
        confirmation_name VARCHAR(100),
        age VARCHAR(30),
        origin_parish VARCHAR(150),
        origin_province VARCHAR(150),
        baptismal_place VARCHAR(150),
        parents VARCHAR(200),
        sponsor VARCHAR(100),
        bishop_priest VARCHAR(100),
        stipend_pesos VARCHAR(30),
        stipend_cents VARCHAR(30),
        observations TEXT,
        status ENUM('active', 'archived') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_name (fullname),
        INDEX idx_date (confirmation_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    
    // First Communion Records table
    "CREATE TABLE IF NOT EXISTS first_communion_records (
        communion_id INT PRIMARY KEY AUTO_INCREMENT,
        request_id INT,
        registry_no VARCHAR(50),
        fullname VARCHAR(100) NOT NULL,
        birth_date DATE,
        communion_date DATE,
        domicile VARCHAR(150),
        parents VARCHAR(200),
        sponsor VARCHAR(100),
        priest VARCHAR(100),
        folio VARCHAR(50),
        baptismal_date DATE,
        baptismal_place VARCHAR(150),
        remarks TEXT,
        status ENUM('active', 'archived') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_name (fullname),
        INDEX idx_date (communion_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    
    // Marriage Records table
    "CREATE TABLE IF NOT EXISTS marriage_records (
        marriage_id INT PRIMARY KEY AUTO_INCREMENT,
        request_id INT,
        registry_no VARCHAR(50),
        husband_name VARCHAR(100) NOT NULL,
        husband_status VARCHAR(80),
        husband_age VARCHAR(30),
        husband_birth_origin VARCHAR(150),
        husband_residence VARCHAR(200),
        husband_parents VARCHAR(200),
        wife_name VARCHAR(100) NOT NULL,
        wife_status VARCHAR(80),
        wife_age VARCHAR(30),
        wife_birth_origin VARCHAR(150),
        wife_residence VARCHAR(200),
        wife_parents VARCHAR(200),
        wedding_date DATE,
        sponsors VARCHAR(200),
        witnesses_residence VARCHAR(200),
        officiating_priest VARCHAR(100),
        remarks TEXT,
        status ENUM('active', 'archived') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_husband (husband_name),
        INDEX idx_wife (wife_name),
        INDEX idx_date (wedding_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    
    // Announcements table
    "CREATE TABLE IF NOT EXISTS announcements (
        announcement_id INT PRIMARY KEY AUTO_INCREMENT,
        title VARCHAR(255) NOT NULL,
        content TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        posted_by INT,
        FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$tables_created = 0;
foreach ($sql_statements as $sql) {
    if ($conn->query($sql) === TRUE) {
        $tables_created++;
    } else {
        echo "<div class='error'>❌ Error creating table: " . $conn->error . "</div>";
    }
}

echo "<div class='success'>✅ Created/Verified $tables_created tables</div>";

// Step 5: Create Admin User
echo "<h3>Step 4: Setting Up Admin Account...</h3>";

$admin_email = 'admin@parish.com';
$admin_password = 'admin123';
$admin_hash = password_hash($admin_password, PASSWORD_BCRYPT);

// Check if admin exists
$result = $conn->query("SELECT id FROM users WHERE email = '$admin_email'");

if ($result && $result->num_rows == 0) {
    // Admin doesn't exist, create it
    $stmt = $conn->prepare("INSERT INTO users (fullname, email, password, role, phone_number, status) VALUES (?, ?, ?, ?, ?, ?)");
    $fullname = "System Administrator";
    $role = "admin";
    $phone = "0000000000";
    $status = "active";
    
    $stmt->bind_param("ssssss", $fullname, $admin_email, $admin_hash, $role, $phone, $status);
    
    if ($stmt->execute()) {
        echo "<div class='success'>✅ Admin account created<br>Email: $admin_email<br>Password: $admin_password</div>";
    } else {
        echo "<div class='error'>❌ Error creating admin: " . $stmt->error . "</div>";
    }
    $stmt->close();
} else {
    echo "<div class='info'>ℹ️ Admin account already exists</div>";
}

// Step 6: Summary
echo "<h3>Step 5: Setup Complete!</h3>";
echo "<div class='success'>";
echo "✅ <strong>Parish System is Ready!</strong><br><br>";
echo "Database: $db_name<br>";
echo "Host: $db_host<br>";
echo "Tables: " . $tables_created . " created/verified<br>";
echo "Admin User: Created (if new)<br>";
echo "</div>";

echo "<hr>";
echo "<h3>Next Steps:</h3>";
echo "<a href='auth/login.php'><button>Go to Login Page</button></a>";
echo "<a href='index.php'><button>Visit Homepage</button></a>";
echo "<a href='verify-setup.php'><button>Verify Setup</button></a>";

echo "<hr>";
echo "<div class='info'>";
echo "<strong>Login Credentials:</strong><br>";
echo "Email: $admin_email<br>";
echo "Password: $admin_password<br>";
echo "<br>⚠️ Change the admin password after first login!";
echo "</div>";

$conn->close();
$temp_conn->close();

echo "</div></body></html>";
?>
