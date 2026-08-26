<?php
require_once __DIR__ . '/../database/config.php';

echo "=== DATABASE SCHEMA AUDIT FOR PASSWORDS & AUTH ===\n\n";

// 1. Check all tables and their columns with 'pass' or 'hash' or 'token' in name
$query = "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT 
          FROM INFORMATION_SCHEMA.COLUMNS 
          WHERE TABLE_SCHEMA = DATABASE() 
          AND (COLUMN_NAME LIKE '%pass%' OR COLUMN_NAME LIKE '%hash%' OR COLUMN_NAME LIKE '%token%' OR COLUMN_NAME LIKE '%auth%')
          ORDER BY TABLE_NAME, COLUMN_NAME";

$result = $conn->query($query);
if ($result) {
    echo sprintf("%-30s | %-25s | %-15s | %-8s | %-25s\n", "TABLE", "COLUMN", "DATA TYPE", "MAX LEN", "COLUMN TYPE");
    echo str_repeat("-", 110) . "\n";
    while ($row = $result->fetch_assoc()) {
        echo sprintf("%-30s | %-25s | %-15s | %-8s | %-25s\n", 
            $row['TABLE_NAME'], 
            $row['COLUMN_NAME'], 
            $row['DATA_TYPE'], 
            $row['CHARACTER_MAXIMUM_LENGTH'] ?? 'N/A', 
            $row['COLUMN_TYPE']
        );
    }
} else {
    echo "Error querying columns: " . $conn->error . "\n";
}

echo "\n\n=== CHECKING ALL TABLES IN DATABASE ===\n";
$tablesRes = $conn->query("SHOW TABLES");
if ($tablesRes) {
    while ($t = $tablesRes->fetch_row()) {
        echo "- " . $t[0] . "\n";
    }
}

echo "\n\n=== CHECKING FOR MYSQL TRIGGERS OR EVENTS ===\n";
$triggers = $conn->query("SHOW TRIGGERS");
if ($triggers && $triggers->num_rows > 0) {
    while ($trig = $triggers->fetch_assoc()) {
        echo "Trigger: " . $trig['Trigger'] . " on table " . $trig['Table'] . "\n";
    }
} else {
    echo "No triggers found.\n";
}

$events = $conn->query("SHOW EVENTS");
if ($events && $events->num_rows > 0) {
    while ($ev = $events->fetch_assoc()) {
        echo "Event: " . $ev['Name'] . "\n";
    }
} else {
    echo "No events / MySQL cron jobs found.\n";
}

echo "\n\n=== CHECKING STORED HASH LENGTHS IN USERS TABLE ===\n";
$usersRes = $conn->query("SELECT id, email, phone_number, role, status, LENGTH(password) as pass_len, LEFT(password, 7) as hash_prefix, failed_login_attempts, account_locked_until, must_change_password FROM users LIMIT 15");
if ($usersRes) {
    echo sprintf("%-4s | %-25s | %-15s | %-10s | %-8s | %-8s | %-10s | %-6s | %-20s\n", "ID", "EMAIL", "PHONE", "ROLE", "STATUS", "HASH LEN", "PREFIX", "FAILS", "LOCKED UNTIL");
    echo str_repeat("-", 125) . "\n";
    while ($u = $usersRes->fetch_assoc()) {
        echo sprintf("%-4s | %-25s | %-15s | %-10s | %-8s | %-8s | %-10s | %-6s | %-20s\n",
            $u['id'],
            substr($u['email'] ?? '', 0, 25),
            $u['phone_number'] ?? '',
            $u['role'] ?? '',
            $u['status'] ?? '',
            $u['pass_len'] ?? 'NULL',
            $u['hash_prefix'] ?? '',
            $u['failed_login_attempts'] ?? '0',
            $u['account_locked_until'] ?? 'NULL'
        );
    }
}
