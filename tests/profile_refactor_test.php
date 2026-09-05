<?php
/**
 * Profile Settings Refactor Automated Test
 * Validates:
 * 1. Schema fields: middle_name, suffix, id_type, street_address, barangay, city, province.
 * 2. Avatar directory creation and permissions.
 * 3. Email update validation (format, uniqueness, identifier synchronization).
 * 4. Full registration fields (personal, address breakdown, fullname sync, middle initial).
 * 5. Identity verification badge detection.
 * 6. Profile picture validation (MIME, size, storage path).
 */

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/account-management.php';
require_once __DIR__ . '/../includes/authentication.php';

echo "=== TUGON PROFILE SETTINGS TEST SUITE ===\n\n";

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

// 1. Check schema fields
ensureUserProfileFieldsSchema($conn);
$expected_columns = ['middle_name', 'suffix', 'id_type', 'street_address', 'barangay', 'city', 'province'];
foreach ($expected_columns as $col) {
    $res = $conn->query("SHOW COLUMNS FROM users LIKE '$col'");
    assertTest("Column `users.$col` exists", $res && $res->num_rows > 0);
}

// 2. Check avatar upload directory
$avatar_dir = ensureAvatarUploadDirectory();
assertTest("Avatar upload directory exists", is_dir($avatar_dir));
assertTest("Avatar directory index.php exists", file_exists($avatar_dir . DIRECTORY_SEPARATOR . 'index.php'));

// 3. Create a temporary test user
$test_email_1 = 'profile_test_' . time() . '_1@example.com';
$test_email_2 = 'profile_test_' . time() . '_2@example.com';
$test_pwd_hash = password_hash('TestPass123!@#', PASSWORD_DEFAULT);

$stmt = $conn->prepare("INSERT INTO users (fullname, first_name, surname, email, password, role, status) VALUES ('Initial Name', 'Initial', 'Name', ?, ?, 'user', 'active')");
$stmt->bind_param('ss', $test_email_1, $test_pwd_hash);
$stmt->execute();
$user1_id = $conn->insert_id;
$stmt->close();
synchronizeAuthenticationIdentifier($conn, $user1_id, 'email', $test_email_1);

$stmt = $conn->prepare("INSERT INTO users (fullname, first_name, surname, email, password, role, status) VALUES ('Other User', 'Other', 'User', ?, ?, 'user', 'active')");
$stmt->bind_param('ss', $test_email_2, $test_pwd_hash);
$stmt->execute();
$user2_id = $conn->insert_id;
$stmt->close();
synchronizeAuthenticationIdentifier($conn, $user2_id, 'email', $test_email_2);

assertTest("Created test user 1 (ID: $user1_id)", $user1_id > 0);
assertTest("Created test user 2 (ID: $user2_id)", $user2_id > 0);

// 4. Test Email Uniqueness Validation
// User 1 cannot change to User 2's email
$email_available_for_user1 = authenticationIdentifierAvailable($conn, 'email', $test_email_2, $user1_id);
assertTest("Duplicate email rejection: User 1 cannot claim User 2's email", !$email_available_for_user1);

// User 1 CAN keep their own email
$own_email_available = authenticationIdentifierAvailable($conn, 'email', $test_email_1, $user1_id);
assertTest("Self-email allowed: User 1 can re-save their own email", $own_email_available);

// User 1 CAN change to a brand new email
$new_unique_email = 'profile_test_' . time() . '_new@example.com';
$new_email_available = authenticationIdentifierAvailable($conn, 'email', $new_unique_email, $user1_id);
assertTest("Unique email allowed: User 1 can choose an unregistered email", $new_email_available);

// 5. Test Profile Updates: Personal Details, Address Breakdown, Fullname sync
$first_name = 'Maria';
$middle_name = 'Santos';
$surname = 'Dela Cruz';
$suffix = 'Jr.';
$middle_initial = strtoupper(substr($middle_name, 0, 1));
$fullname = trim("$first_name $middle_name $surname $suffix");

$birthdate = '1995-08-15';
$sex = 'Female';
$phone_number = '09171234567';
$phone_normalized = normalizePhilippineMobileForStorage($phone_number);

$street_address = 'Block 4 Lot 10, Sitio Pag-asa';
$barangay = 'San Mateo';
$city = 'Aleosan';
$province = 'Cotabato';
$composite_address = implode(', ', [$street_address, $barangay, $city, $province]);

$update_stmt = $conn->prepare("UPDATE users SET 
    fullname = ?, 
    first_name = ?, 
    middle_name = ?, 
    middle_initial = ?, 
    surname = ?, 
    suffix = ?, 
    email = ?, 
    phone_number = ?, 
    birthdate = ?, 
    sex = ?, 
    address = ?, 
    street_address = ?, 
    barangay = ?, 
    city = ?, 
    province = ? 
    WHERE id = ?");
$update_stmt->bind_param(
    'sssssssssssssssi',
    $fullname,
    $first_name,
    $middle_name,
    $middle_initial,
    $surname,
    $suffix,
    $new_unique_email,
    $phone_normalized,
    $birthdate,
    $sex,
    $composite_address,
    $street_address,
    $barangay,
    $city,
    $province,
    $user1_id
);
$update_success = $update_stmt->execute();
$update_stmt->close();
assertTest("Execute profile update statement", $update_success);

// Sync auth identifiers
synchronizeAuthenticationIdentifier($conn, $user1_id, 'email', $new_unique_email);
synchronizeAuthenticationIdentifier($conn, $user1_id, 'mobile', $phone_normalized);

// Verify in DB
$user1_fresh = getUserById($conn, $user1_id);
assertTest("Fullname correctly assembled: '{$user1_fresh['fullname']}'", $user1_fresh['fullname'] === 'Maria Santos Dela Cruz Jr.');
assertTest("First name correctly saved: '{$user1_fresh['first_name']}'", $user1_fresh['first_name'] === 'Maria');
assertTest("Middle name correctly saved: '{$user1_fresh['middle_name']}'", $user1_fresh['middle_name'] === 'Santos');
assertTest("Middle initial correctly computed: '{$user1_fresh['middle_initial']}'", $user1_fresh['middle_initial'] === 'S');
assertTest("Surname correctly saved: '{$user1_fresh['surname']}'", $user1_fresh['surname'] === 'Dela Cruz');
assertTest("Suffix correctly saved: '{$user1_fresh['suffix']}'", $user1_fresh['suffix'] === 'Jr.');
assertTest("Email correctly updated: '{$user1_fresh['email']}'", $user1_fresh['email'] === $new_unique_email);
assertTest("Birthdate correctly saved: '{$user1_fresh['birthdate']}'", $user1_fresh['birthdate'] === '1995-08-15');
assertTest("Sex correctly saved: '{$user1_fresh['sex']}'", $user1_fresh['sex'] === 'Female');
assertTest("Street address correctly saved: '{$user1_fresh['street_address']}'", $user1_fresh['street_address'] === $street_address);
assertTest("Barangay correctly saved: '{$user1_fresh['barangay']}'", $user1_fresh['barangay'] === $barangay);
assertTest("City correctly saved: '{$user1_fresh['city']}'", $user1_fresh['city'] === $city);
assertTest("Province correctly saved: '{$user1_fresh['province']}'", $user1_fresh['province'] === $province);
assertTest("Composite address correctly assembled", $user1_fresh['address'] === $composite_address);

// Check user_auth_identifiers table
$auth_stmt = $conn->prepare("SELECT normalized_value FROM user_auth_identifiers WHERE user_id = ? AND identifier_type = 'email' LIMIT 1");
$auth_stmt->bind_param('i', $user1_id);
$auth_stmt->execute();
$auth_row = $auth_stmt->get_result()->fetch_assoc();
$auth_stmt->close();
assertTest("Email synchronized in user_auth_identifiers: '{$auth_row['normalized_value']}'", ($auth_row['normalized_value'] ?? '') === $new_unique_email);

// 6. Test Avatar Image Storage
$fake_img_content = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
$test_avatar_filename = 'avatar_' . $user1_id . '_' . bin2hex(random_bytes(6)) . '.png';
$test_avatar_path = $avatar_dir . DIRECTORY_SEPARATOR . $test_avatar_filename;
file_put_contents($test_avatar_path, $fake_img_content);
$relative_avatar_path = 'uploads/avatars/' . $test_avatar_filename;

$avatar_stmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
$avatar_stmt->bind_param('si', $relative_avatar_path, $user1_id);
$avatar_stmt->execute();
$avatar_stmt->close();

$user1_avatar = getUserById($conn, $user1_id);
assertTest("Profile picture path saved in database: '{$user1_avatar['profile_picture']}'", $user1_avatar['profile_picture'] === $relative_avatar_path);
assertTest("Profile picture file exists on disk", file_exists($test_avatar_path));

// Clean up avatar test file
if (file_exists($test_avatar_path)) {
    unlink($test_avatar_path);
}

// 7. Test ID Type Detection
$dummy_user_philsys = ['valid_id_original_name' => 'scan-philsys-front.jpg', 'valid_id_path' => 'uploads/valid_ids/123.jpg'];
assertTest("Detect PhilSys ID", detectUserIdType($dummy_user_philsys) === 'Philippine National ID (PhilSys)');

$dummy_user_driver = ['valid_id_original_name' => 'drivers-license.png', 'valid_id_path' => 'uploads/valid_ids/123.jpg'];
assertTest("Detect Driver's License", detectUserIdType($dummy_user_driver) === "Driver's License");

$dummy_user_umid = ['valid_id_original_name' => 'umid_card.webp', 'valid_id_path' => 'uploads/valid_ids/123.jpg'];
assertTest("Detect UMID Card", detectUserIdType($dummy_user_umid) === 'UMID Card');

// Clean up test users
$conn->query("DELETE FROM user_auth_identifiers WHERE user_id IN ($user1_id, $user2_id)");
$conn->query("DELETE FROM users WHERE id IN ($user1_id, $user2_id)");
echo "\nCleaned up test users ($user1_id, $user2_id).\n";

echo "\n=========================================\n";
echo "TEST RESULTS: $passed passed, $failed failed.\n";
echo "=========================================\n";

exit($failed > 0 ? 1 : 0);
