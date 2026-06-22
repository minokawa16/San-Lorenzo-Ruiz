<?php
session_start(); require_once __DIR__ . '/../database/config.php';
if (!isset($_SESSION['user_id'])) { header('Location: /auth/login.php'); exit; }
$user_id = intval($_SESSION['user_id']);
$q = $conn->query("SELECT * FROM Certificate_Requests WHERE user_id = " . $user_id . " ORDER BY submitted_at DESC");
?>
<!doctype html><html><head><meta charset="utf-8"><title>Download Center</title></head><body>
<h2>Download Center</h2>
<table border="1"><tr><th>ID</th><th>Type</th><th>Release</th><th>Status</th><th>Actions</th></tr>
<?php while($r = $q->fetch_assoc()): ?>
  <tr>
    <td><?=$r['request_id']?></td>
    <td><?=htmlspecialchars($r['certificate_type'])?></td>
    <td><?=$r['release_method']?></td>
    <td><?=$r['request_status']?></td>
    <td>
      <a href="/certs/request_view_user.php?id=<?=$r['request_id']?>">View</a>
    </td>
  </tr>
<?php endwhile; ?></table>
</body></html>
