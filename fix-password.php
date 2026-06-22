<?php
// Fix Password Script
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'database/config.php';
include 'includes/helpers.php';

echo "<h2>Admin Password Reset</h2>";
echo "<hr>";

// Generate hash for 'admin123'
$new_password = 'admin123';
$hashed_password = password_hash($new_password, PASSWORD_BCRYPT);

echo "<p>Updating admin password...</p>";
echo "<p>New hash: " . $hashed_password . "</p>";

// Update password
$update_query = "UPDATE users SET password = '" . $conn->real_escape_string($hashed_password) . "' WHERE role='admin'";

if ($conn->query($update_query)) {
    echo "<p style='color:green;'>✅ Password updated successfully!</p>";
    
    // Verify update
    $result = $conn->query("SELECT id, email, role FROM users WHERE role='admin'");
    echo "<p>Updated admin accounts:</p>";
    echo "<ul>";
    while ($row = $result->fetch_assoc()) {
        echo "<li>" . $row['email'] . " (ID: " . $row['id'] . ")</li>";
    }
    echo "</ul>";
    
    echo "<p style='color:blue;'><strong>You can now login with:</strong></p>";
    echo "<p>Email: <strong>admin@parish.com</strong></p>";
    echo "<p>Password: <strong>admin123</strong></p>";
    
    echo "<p><a href='auth/login.php'>Go to Login Page</a></p>";
} else {
    echo "<p style='color:red;'>❌ Failed to update password: " . $conn->error . "</p>";
}

$conn->close();
?>
