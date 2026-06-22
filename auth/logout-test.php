<?php
/**
 * LOGOUT TEST PAGE
 * Direct logout without any complications
 * Helps verify logout is working
 */

// Completely fresh logout
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Make absolutely sure sessions are stopped
if (session_id()) {
    session_destroy();
}

// Start fresh session
session_start();

// Get current session info before clearing
$was_logged_in = !empty($_SESSION['user_id']);
$user_id = $_SESSION['user_id'] ?? 'N/A';

// Unset all session variables
foreach ($_SESSION as $key => $value) {
    unset($_SESSION[$key]);
}

// Clear session array completely
$_SESSION = null;

// Destroy session
session_destroy();

// Clear session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Set no-cache headers
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Thu, 19 Nov 1981 08:52:00 GMT");

?>
<!DOCTYPE html>
<html>
<head>
    <title>Logout Successful</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 500px; margin: 100px auto; background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-align: center; }
        h1 { color: #10B981; }
        p { color: #666; line-height: 1.6; }
        .button { display: inline-block; margin-top: 20px; padding: 12px 30px; background: #1E3A5F; color: white; text-decoration: none; border-radius: 4px; }
        .button:hover { background: #0D2338; }
        .info { background: #c8e6c9; padding: 15px; border-radius: 4px; margin-top: 20px; }
    </style>
</head>
<body>
<div class="container">
    <h1>✅ Logout Successful</h1>
    
    <p style="font-size: 18px;">You have been successfully logged out of the Parish Management System.</p>
    
    <p>Your session has been destroyed and all authentication data has been cleared.</p>
    
    <div class="info">
        <strong>Session Info:</strong><br>
        Was Logged In: <?php echo $was_logged_in ? 'Yes' : 'No'; ?><br>
        User ID: <?php echo $user_id; ?>
    </div>
    
    <a href="login.php" class="button">Return to Login Page</a>
    
    <p style="margin-top: 30px; font-size: 12px; color: #999;">
        If you're not redirected automatically in 5 seconds, 
        <a href="login.php" style="color: #1E3A5F;">click here</a>
    </p>
</div>

<script>
    // Auto redirect after 3 seconds
    setTimeout(function() {
        window.location.href = 'login.php';
    }, 3000);
</script>
</body>
</html>

<?php
// Force any pending output to be sent
flush();
// Exit to prevent any further code execution
exit();
?>
