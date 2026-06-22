<?php
// Debug Login Script - Test Password Verification
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include database config
include 'database/config.php';
include 'includes/helpers.php';

echo "<h1>Parish System - Login Debug</h1>";
echo "<hr>";

// Test 1: Database Connection
echo "<h3>Test 1: Database Connection</h3>";
if ($conn->connect_error) {
    echo "<p style='color:red;'>❌ Connection failed: " . $conn->connect_error . "</p>";
} else {
    echo "<p style='color:green;'>✅ Database connected successfully</p>";
    echo "<p>Host: " . DB_HOST . "</p>";
    echo "<p>Database: " . DB_NAME . "</p>";
}

// Test 2: Check admin users exist
echo "<h3>Test 2: Check Admin Users</h3>";
$result = $conn->query("SELECT id, fullname, email, role, status FROM users WHERE role='admin'");
if ($result && $result->num_rows > 0) {
    echo "<p style='color:green;'>✅ Admin users found:</p>";
    echo "<table border='1' cellpadding='10'>";
    echo "<tr><th>ID</th><th>Fullname</th><th>Email</th><th>Role</th><th>Status</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr><td>{$row['id']}</td><td>{$row['fullname']}</td><td>{$row['email']}</td><td>{$row['role']}</td><td>{$row['status']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:red;'>❌ No admin users found</p>";
}

// Test 3: Password Verification
echo "<h3>Test 3: Password Verification</h3>";
$test_password = 'admin123';
$test_email = 'admin@parish.com';

$result = $conn->query("SELECT * FROM users WHERE email = '$test_email'");
if ($result && $result->num_rows > 0) {
    $user = $result->fetch_assoc();
    echo "<p><strong>Testing password for: " . $test_email . "</strong></p>";
    echo "<p>Password from DB (first 20 chars): " . substr($user['password'], 0, 20) . "...</p>";
    
    // Test password verification
    if (verifyPassword($test_password, $user['password'])) {
        echo "<p style='color:green;'>✅ Password verification PASSED</p>";
        echo "<p>The password '{$test_password}' matches the hash in database</p>";
    } else {
        echo "<p style='color:red;'>❌ Password verification FAILED</p>";
        echo "<p>The password '{$test_password}' does NOT match the hash in database</p>";
        
        // Generate a new hash to show user
        echo "<h4>Testing Hash Generation:</h4>";
        $new_hash = password_hash($test_password, PASSWORD_BCRYPT);
        echo "<p>Hash for '{$test_password}': " . $new_hash . "</p>";
        
        // Test if the new hash verifies
        if (password_verify($test_password, $new_hash)) {
            echo "<p style='color:green;'>✅ New hash would work correctly</p>";
        }
    }
} else {
    echo "<p style='color:red;'>❌ User not found: " . $test_email . "</p>";
}

// Test 4: Check what password the current hash corresponds to
echo "<h3>Test 4: Check Current Hash in Database</h3>";
$result = $conn->query("SELECT email, password FROM users WHERE role='admin' LIMIT 1");
if ($result && $result->num_rows > 0) {
    $user = $result->fetch_assoc();
    echo "<p>Testing various common passwords against the stored hash:</p>";
    
    $common_passwords = ['password', 'admin', 'admin123', '123456', 'password123', 'test', 'test123'];
    
    $found = false;
    foreach ($common_passwords as $pwd) {
        if (password_verify($pwd, $user['password'])) {
            echo "<p style='color:green;'>✅ MATCH FOUND: The password is '<strong>" . $pwd . "</strong>'</p>";
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        echo "<p style='color:orange;'>⚠️ Password hash does not match common passwords tested</p>";
        echo "<p>Current hash: " . $user['password'] . "</p>";
    }
}

// Test 5: Session Test
echo "<h3>Test 5: Session Start</h3>";
if (session_status() === PHP_SESSION_ACTIVE) {
    echo "<p style='color:green;'>✅ Session is active</p>";
} else {
    echo "<p style='color:red;'>❌ Session is not active</p>";
}

// Test 6: Helpers Functions
echo "<h3>Test 6: Helper Functions</h3>";
echo "<p>Testing helper functions:</p>";
echo "<p>sanitize('test@email.com'): " . sanitize('test@email.com') . "</p>";
echo "<p>isValidEmail('test@email.com'): " . (isValidEmail('test@email.com') ? 'true' : 'false') . "</p>";
echo "<p>hashPassword('test123'): " . hashPassword('test123') . "</p>";

echo "<hr>";
echo "<h3>Summary & Recommendations:</h3>";
echo "<p>If password verification failed in Test 3, you need to update the admin password.</p>";
echo "<p>Click the button below to update the admin password to 'admin123'</p>";

// Reset password form
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reset_password'])) {
    $new_password = password_hash('admin123', PASSWORD_BCRYPT);
    $update = $conn->query("UPDATE users SET password = '$new_password' WHERE role='admin'");
    
    if ($update) {
        echo "<p style='color:green;'>✅ Admin password reset to 'admin123'</p>";
        echo "<p>You can now login with:</p>";
        echo "<p><strong>Email:</strong> admin@parish.com<br><strong>Password:</strong> admin123</p>";
    } else {
        echo "<p style='color:red;'>❌ Failed to reset password</p>";
    }
}
?>

<form method="post">
    <button type="submit" name="reset_password" style="background-color: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;">
        Reset Admin Password to 'admin123'
    </button>
</form>

