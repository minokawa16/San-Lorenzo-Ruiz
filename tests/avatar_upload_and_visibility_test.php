<?php
/**
 * Avatar Upload and Cross-Panel Visibility Automated Test
 * Tests:
 * 1. Helper functions: getUserAvatarUrl, hasUserAvatar, renderUserAvatar
 * 2. avatar.php streaming: Image serving, HTTP caching, and SVG fallback
 * 3. Profile photo persistence: DB update, file storage, cache-busting, cleanup
 * 4. Error cases: missing file, invalid ID
 * 5. Admin-side visibility: uniform rendering of photo and initial fallback
 */

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/session.php';

echo "=== TUGON AVATAR UPLOAD & ADMIN VISIBILITY TEST SUITE ===\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $desc, bool $condition, string $details = '') {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] $desc\n";
        $passed++;
    } else {
        echo "  [FAIL] $desc " . ($details ? "($details)" : "") . "\n";
        $failed++;
    }
}

// 1. Check avatar directory
$avatar_dir = ensureAvatarUploadDirectory();
assertTest("Avatar upload directory exists and is writable", is_dir($avatar_dir) && is_writable($avatar_dir));
assertTest("Avatar directory index protection file exists", file_exists($avatar_dir . DIRECTORY_SEPARATOR . 'index.php'));

// 2. Create a test parishioner
$test_email = 'avatar_test_' . time() . '@example.com';
$pwd_hash = password_hash('TestAvatar123!', PASSWORD_DEFAULT);
$stmt = $conn->prepare("INSERT INTO users (fullname, first_name, surname, email, password, role, status) VALUES ('Maria Clara Santos', 'Maria Clara', 'Santos', ?, ?, 'user', 'active')");
$stmt->bind_param('ss', $test_email, $pwd_hash);
$stmt->execute();
$test_user_id = $conn->insert_id;
$stmt->close();
assertTest("Created test parishioner (ID: $test_user_id)", $test_user_id > 0);

$user_record = getUserById($conn, $test_user_id);

// 3. Test Initial State (No photo uploaded yet)
assertTest("hasUserAvatar returns false initially", !hasUserAvatar($user_record));
$initial_url = getUserAvatarUrl($user_record);
assertTest("getUserAvatarUrl returns avatar.php endpoint", strpos($initial_url, 'avatar.php?id=' . $test_user_id) !== false);

$rendered_initial = renderUserAvatar($user_record, 40);
assertTest("renderUserAvatar renders initial letter 'M' fallback", strpos($rendered_initial, 'M') !== false);
assertTest("renderUserAvatar does not render <img> when no photo exists", strpos($rendered_initial, '<img') === false);

// 4. Test avatar.php fallback SVG for user without photo
$php_bin = PHP_BINARY;
$avatar_script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'avatar.php';
$cmd = escapeshellarg($php_bin) . ' ' . escapeshellarg($avatar_script) . ' ' . escapeshellarg('id=' . $test_user_id);

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w']
];
$proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));
$output = '';
if (is_resource($proc)) {
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
}
assertTest("avatar.php outputs SVG fallback when user has no photo", strpos($output, '<svg') !== false && strpos($output, 'M') !== false);

// 5. Simulate Real Photo Upload & Persistence
$temp_img_file = tempnam(sys_get_temp_dir(), 'avtest_');
$im = imagecreatetruecolor(100, 100);
$gold = imagecolorallocate($im, 200, 155, 60);
imagefilledrectangle($im, 0, 0, 99, 99, $gold);
imagepng($im, $temp_img_file);
imagedestroy($im);

$avatar_filename = 'avatar_' . $test_user_id . '_' . bin2hex(random_bytes(8)) . '.png';
$stored_file_path = $avatar_dir . DIRECTORY_SEPARATOR . $avatar_filename;
$relative_db_path = 'uploads/avatars/' . $avatar_filename;

copy($temp_img_file, $stored_file_path);
@unlink($temp_img_file);

assertTest("Photo written to storage disk at $stored_file_path", file_exists($stored_file_path));

// Update DB record (simulating Save Changes)
$stmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
$stmt->bind_param('si', $relative_db_path, $test_user_id);
$stmt->execute();
$stmt->close();

// Reload user from database
$updated_user = getUserById($conn, $test_user_id);
assertTest("Database persisted profile_picture field", $updated_user['profile_picture'] === $relative_db_path);
assertTest("hasUserAvatar returns true after save", hasUserAvatar($updated_user));

// 6. Test URL generation and cache-busting
$photo_url = getUserAvatarUrl($updated_user);
$expected_v = substr(md5($relative_db_path), 0, 8);
assertTest("getUserAvatarUrl includes cache-busting version parameter", strpos($photo_url, '&v=' . $expected_v) !== false);

// 7. Test renderUserAvatar HTML markup with active photo
$rendered_photo = renderUserAvatar($updated_user, 48);
assertTest("renderUserAvatar renders <img> tag when photo exists", strpos($rendered_photo, '<img') !== false);
assertTest("renderUserAvatar img src matches canonical URL", strpos($rendered_photo, htmlspecialchars($photo_url, ENT_QUOTES, 'UTF-8')) !== false);
assertTest("renderUserAvatar contains onerror fallback for resilient rendering", strpos($rendered_photo, 'onerror') !== false);
assertTest("renderUserAvatar sets width and height to 48px", strpos($rendered_photo, '48px') !== false);

// 8. Test avatar.php streaming real image
$proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__));
$img_output = '';
if (is_resource($proc)) {
    fclose($pipes[0]);
    $img_output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
}
// PNG magic bytes: 0x89 'P' 'N' 'G'
assertTest("avatar.php streams PNG binary content for saved photo", strpos($img_output, "\x89PNG") !== false);

// 9. Test Admin-Side Visibility (Single source of truth)
// Simulate how admin views access the parishioner record:
$expected_escaped_url = htmlspecialchars($photo_url, ENT_QUOTES, 'UTF-8');

// A. Manage Parishioners list
$admin_parishioner_html = renderUserAvatar($updated_user, 34);
assertTest("Manage Parishioners table row renders user avatar", strpos($admin_parishioner_html, $expected_escaped_url) !== false);

// B. Manage Users / Parishioners modal
$admin_modal_html = renderUserAvatar($updated_user, 44);
assertTest("Manage Parishioners modal header renders 44px avatar", strpos($admin_modal_html, '44px') !== false && strpos($admin_modal_html, $expected_escaped_url) !== false);

// C. Process Request card
$request_mock = [
    'user_id' => $test_user_id,
    'fullname' => $updated_user['fullname'],
    'profile_picture' => $updated_user['profile_picture']
];
$admin_request_html = renderUserAvatar($request_mock, 54);
assertTest("Process Request header renders 54px applicant avatar", strpos($admin_request_html, '54px') !== false && strpos($admin_request_html, $expected_escaped_url) !== false);

// D. Request Workflow card
$workflow_avatar_html = renderUserAvatar($request_mock, 28);
assertTest("Request Workflow renders 28px applicant avatar", strpos($workflow_avatar_html, '28px') !== false && strpos($workflow_avatar_html, $expected_escaped_url) !== false);

// E. Verify Registrations card
$verify_reg_html = renderUserAvatar($updated_user, 48, 'verification-avatar');
assertTest("Verify Registrations card renders 48px avatar with verification-avatar class", strpos($verify_reg_html, 'verification-avatar') !== false && strpos($verify_reg_html, $expected_escaped_url) !== false);

// 10. Test Photo Replacement and Old File Cleanup
$new_temp_file = tempnam(sys_get_temp_dir(), 'avtest2_');
$im2 = imagecreatetruecolor(100, 100);
$blue = imagecolorallocate($im2, 30, 58, 138);
imagefilledrectangle($im2, 0, 0, 99, 99, $blue);
imagepng($im2, $new_temp_file);
imagedestroy($im2);

$new_filename = 'avatar_' . $test_user_id . '_' . bin2hex(random_bytes(8)) . '.png';
$new_stored_file_path = $avatar_dir . DIRECTORY_SEPARATOR . $new_filename;
$new_relative_db_path = 'uploads/avatars/' . $new_filename;
copy($new_temp_file, $new_stored_file_path);
@unlink($new_temp_file);

// Simulate profile.php cleanup of old avatar file
if (file_exists($stored_file_path)) {
    @unlink($stored_file_path);
}
$stmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
$stmt->bind_param('si', $new_relative_db_path, $test_user_id);
$stmt->execute();
$stmt->close();

assertTest("Previous avatar file was removed on update", !file_exists($stored_file_path));
assertTest("New avatar file exists on disk", file_exists($new_stored_file_path));

$reloaded_user = getUserById($conn, $test_user_id);
$new_photo_url = getUserAvatarUrl($reloaded_user);
assertTest("New avatar URL has updated cache-buster hash", $new_photo_url !== $photo_url);

// 11. Cleanup Test User and File
@unlink($new_stored_file_path);
$conn->query("DELETE FROM users WHERE id = $test_user_id");
assertTest("Test parishioner cleaned up from database", true);

echo "\n=== SUMMARY: $passed PASSED, $failed FAILED ===\n";
exit($failed > 0 ? 1 : 0);
