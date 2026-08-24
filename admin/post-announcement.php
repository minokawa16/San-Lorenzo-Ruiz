<?php
/**
 * Quick Test - Post Test Announcement
 * Admin can use this to quickly post a test announcement
 * This helps verify the announcement system is working
 */

// Security check - admin only
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireAdmin();
requirePermission('announcements.manage');

$message = '';
$status = 'info';

if ($_POST) {
    $title = $conn->real_escape_string(trim($_POST['title'] ?? 'Test Announcement'));
    $content = $conn->real_escape_string(trim($_POST['content'] ?? 'This is a test announcement to verify the system is working.'));
    $type = $conn->real_escape_string($_POST['type'] ?? 'announcement');
    $admin_id = $_SESSION['user_id'];
    
    $sql = "INSERT INTO announcements (title, content, type, published_by, status) 
            VALUES ('$title', '$content', '$type', '$admin_id', 'active')";
    
    if ($conn->query($sql)) {
        $message = '✅ Announcement posted successfully! Check the user dashboard to see it.';
        $status = 'success';
    } else {
        $message = '❌ Error posting announcement: ' . $conn->error;
        $status = 'error';
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Quick Announcement Posting</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: Arial; margin: 20px; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #1E3A5F; }
        .form-group { margin: 20px 0; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: #333; }
        input[type="text"], textarea, select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-family: Arial; }
        textarea { resize: vertical; min-height: 100px; }
        button { padding: 12px 30px; background: #1E3A5F; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        button:hover { background: #0D2338; }
        .message { padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .message.success { background: #c8e6c9; color: #2e7d32; border-left: 4px solid #4caf50; }
        .message.error { background: #ffcdd2; color: #c62828; border-left: 4px solid #f44336; }
        .message.info { background: #bbdefb; color: #1565c0; border-left: 4px solid #2196f3; }
        .note { background: #fff3cd; padding: 15px; border-radius: 4px; margin-top: 20px; border-left: 4px solid #ffc107; }
        .types-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-top: 10px; }
        .type-option { padding: 10px; background: #f5f5f5; border-radius: 4px; text-align: center; cursor: pointer; transition: all 0.3s; }
        .type-option:hover { background: #e0e0e0; }
        .type-option input { display: none; }
        .type-option input:checked + label { color: #1E3A5F; font-weight: bold; }
    </style>
    <link rel="stylesheet" href="../assets/css/theme.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/theme.css') ? filemtime(__DIR__ . '/../assets/css/theme.css') : time(); ?>">
    <link rel="stylesheet" href="../assets/css/responsive-unified.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/responsive-unified.css'); ?>">
</head>
<body class="premium-admin church-theme">
<div class="premium-admin-shell">
<?php include '../includes/admin-sidebar.php'; ?>
<main class="premium-admin-content" id="main-content" tabindex="-1">
<div class="container">
    <h1>📢 Post Announcement to Users</h1>
    
    <?php if ($message): ?>
    <div class="message <?php echo $status; ?>">
        <?php echo $message; ?>
    </div>
    <?php endif; ?>
    
    <form method="POST">
        <div class="form-group">
            <label for="title">📝 Announcement Title</label>
            <input type="text" id="title" name="title" placeholder="Enter announcement title" required value="Test Announcement - System is Working">
        </div>
        
        <div class="form-group">
            <label for="content">📄 Announcement Content</label>
            <textarea id="content" name="content" placeholder="Enter announcement content" required>Welcome to the Parish Management System! This announcement will be visible on all user dashboards.</textarea>
        </div>
        
        <div class="form-group">
            <label for="type">🏷️ Announcement Type</label>
            <select id="type" name="type" required>
                <option value="announcement">📢 Announcement</option>
                <option value="schedule">📅 Schedule Update</option>
                <option value="event">🎉 Event</option>
                <option value="obituary">🙏 Obituary</option>
            </select>
        </div>
        
        <button type="submit">✅ Post Announcement</button>
    </form>
    
    <div class="note">
        <strong>💡 Tip:</strong> Posted announcements will appear immediately on all user dashboards in the "Latest Announcements" section. Up to 5 most recent announcements are displayed.
    </div>
    
    <div class="note">
        <strong>🔗 Links:</strong>
        <ul>
            <li><a href="manage-announcements.php">Manage All Announcements</a></li>
            <li><a href="../users/index.php">View User Dashboard</a></li>
            <li><a href="dashboard.php">Admin Dashboard</a></li>
        </ul>
    </div>
</div>
</main>
</div>
</body>
</html>
