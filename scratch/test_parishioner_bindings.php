<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';

// Check users with documents
$res = $conn->query("SELECT id, fullname, status, verified_at, email, phone_number, verification_method, valid_id_path, valid_id_back_path, face_image_path FROM users WHERE role = 'user' LIMIT 5");

echo "=== Testing Parishioner Data Bindings ===\n";
while ($row = $res->fetch_assoc()) {
    echo "ID: {$row['id']} | Name: {$row['fullname']} | Status: {$row['status']}\n";
    
    // Test Date Verified
    if (in_array($row['status'], ['active', 'approved'], true)) {
        $v_time = !empty($row['verified_at']) ? $row['verified_at'] : $row['created_at'];
        echo "  Date Verified: " . date('M d, Y h:i A', strtotime($v_time)) . "\n";
    } else {
        echo "  Date Verified: Pending Verification\n";
    }
    
    // Test Dynamic Contact
    $contact_label = ($row['verification_method'] ?? '') === 'mobile' ? 'Registered Mobile' : 'Registered Email';
    $contact_val = ($row['verification_method'] ?? '') === 'mobile' ? ($row['phone_number'] ?: $row['email']) : ($row['email'] ?: $row['phone_number']);
    echo "  {$contact_label}: {$contact_val} (Verified)\n";
    echo "  Front ID: " . ($row['valid_id_path'] ?: 'None') . "\n";
    echo "  Back ID: " . ($row['valid_id_back_path'] ?: 'None') . "\n";
    echo "  Face: " . ($row['face_image_path'] ?: 'None') . "\n";
    echo "----------------------------------------\n";
}
