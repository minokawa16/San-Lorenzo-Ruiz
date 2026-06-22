<?php
/**
 * PHASE 1 INTEGRATION EXAMPLES
 * Copy-paste templates to integrate Phase 1 features into your existing code
 */

// ============================================================================
// EXAMPLE 1: SECURE DASHBOARD WITH PAGINATION & CACHING
// ============================================================================

// File: admin/dashboard_improved.php

/*
<?php
include '../config/security.php';
include '../includes/Security.php';
include '../includes/Logger.php';
include '../includes/Pagination.php';
include '../database/config.php';
include '../database/BaseDB.php';
include '../includes/session.php';

requireAdmin();

// Initialize
$db = new BaseDB($conn);
$logger = new Logger();
$security = new Security();

try {
    // Get dashboard statistics with caching
    $cache_ttl = 5 * 60; // Cache for 5 minutes
    
    // Count queries with caching
    $total_users = $db->count(
        "SELECT COUNT(*) as count FROM users WHERE role = 'user' AND deleted_at IS NULL",
        '',
        [],
        $cache_ttl
    );
    
    $pending_requests = $db->count(
        "SELECT COUNT(*) as count FROM requests WHERE status = 'pending' AND deleted_at IS NULL",
        '',
        [],
        $cache_ttl
    );
    
    $approved_requests = $db->count(
        "SELECT COUNT(*) as count FROM requests WHERE status = 'approved' AND deleted_at IS NULL",
        '',
        [],
        $cache_ttl
    );

    // Get recent requests with pagination
    $total_records = $db->count("SELECT COUNT(*) as count FROM requests WHERE deleted_at IS NULL");
    $pagination = new Pagination($total_records, 10);
    
    $sql = "SELECT r.*, u.fullname FROM requests r 
            JOIN users u ON r.user_id = u.id 
            WHERE r.deleted_at IS NULL 
            ORDER BY r.date_requested DESC 
            {$pagination->getLimitClause()}";
    
    $recent_requests = $db->select($sql);

    // Log access
    $logger->info('Admin dashboard accessed', ['user_id' => $_SESSION['user_id']]);

} catch (Exception $e) {
    $logger->error('Dashboard error: ' . $e->getMessage());
    die('An error occurred');
}
?>

<!-- Display dashboard -->
<h1>Admin Dashboard</h1>

<!-- Stats Cards -->
<div class="row">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <h5>Total Users</h5>
                <p class="h2"><?php echo $total_users; ?></p>
            </div>
        </div>
    </div>
    <!-- More cards... -->
</div>

<!-- Recent Requests Table -->
<table class="table">
    <thead>
        <tr>
            <th>Name</th>
            <th>Request Type</th>
            <th>Status</th>
            <th>Date</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($recent_requests as $request): ?>
            <tr>
                <td><?php echo htmlspecialchars($request['fullname']); ?></td>
                <td><?php echo htmlspecialchars($request['request_type']); ?></td>
                <td><?php echo htmlspecialchars($request['status']); ?></td>
                <td><?php echo date('M d, Y', strtotime($request['date_requested'])); ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- Pagination -->
<?php echo $pagination->render('dashboard.php'); ?>
*/

// ============================================================================
// EXAMPLE 2: SECURE API ENDPOINT
// ============================================================================

// File: api/users/list.php

/*
<?php
header('Content-Type: application/json');

include '../../config/security.php';
include '../../includes/Security.php';
include '../../includes/ErrorHandler.php';
include '../../includes/Pagination.php';
include '../../database/BaseDB.php';
include '../../database/config.php';
include '../../includes/session.php';

$response = new Response();

try {
    // Verify authorization
    if (!isLoggedIn() || !isAdmin()) {
        $response->unauthorized('Unauthorized access')->send();
    }

    $db = new BaseDB($conn);
    
    // Get query parameters
    $page = (int)($_GET['page'] ?? 1);
    $search = trim($_GET['search'] ?? '');
    $status = trim($_GET['status'] ?? '');

    // Build query
    $sql = "SELECT id, fullname, email, role, status, created_at FROM users WHERE deleted_at IS NULL";
    $types = '';
    $params = [];

    if (!empty($search)) {
        $sql .= " AND (fullname LIKE ? OR email LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'ss';
    }

    if (!empty($status)) {
        $sql .= " AND status = ?";
        $params[] = $status;
        $types .= 's';
    }

    // Get count for pagination
    $count_sql = "SELECT COUNT(*) as count FROM users WHERE deleted_at IS NULL" .
                 (strpos($sql, 'AND') ? ' AND ' . explode('AND', $sql)[1] : '');
    $total = $db->count($count_sql, $types, array_slice($params, 0, count($params)));

    // Add pagination
    $pagination = new Pagination($total, 20, $page);
    $sql .= " ORDER BY created_at DESC {$pagination->getLimitClause()}";

    // Get users
    $users = $db->select($sql, $types, $params);

    $response->success([
        'users' => $users,
        'pagination' => $pagination->toArray()
    ])->send();

} catch (Exception $e) {
    $response->serverError($e->getMessage())->send();
}
*/

// ============================================================================
// EXAMPLE 3: SECURE DATA MODIFICATION
// ============================================================================

// File: api/requests/update.php

/*
<?php
include '../../config/security.php';
include '../../includes/Security.php';
include '../../includes/Pagination.php';
include '../../database/BaseDB.php';
include '../../database/config.php';
include '../../includes/session.php';

$response = new Response();

try {
    // Check authorization
    if (!isLoggedIn() || !isAdmin()) {
        $response->unauthorized()->send();
    }

    // Verify CSRF
    $security = new Security();
    if (!$security->verifyCSRFToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $response->badRequest('Invalid CSRF token')->send();
    }

    // Validate input
    $request_id = (int)($_POST['request_id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if (!$request_id || !in_array($status, ['pending', 'approved', 'rejected', 'processing', 'completed'])) {
        $response->badRequest('Invalid request data')->send();
    }

    $db = new BaseDB($conn);

    // Start transaction
    $db->beginTransaction();

    try {
        // Update request
        $sql = "UPDATE requests SET status = ?, admin_response = ? WHERE request_id = ?";
        $db->update($sql, 'ssi', [$status, $notes, $request_id]);

        // Log approval
        $sql = "INSERT INTO request_approvals (request_id, approved_by, approval_status, notes, reviewed_at) 
                VALUES (?, ?, ?, ?, NOW())";
        $db->insert($sql, 'iiss', [$request_id, $_SESSION['user_id'], $status, $notes]);

        // Commit transaction
        $db->commit();

        $response->success(['request_id' => $request_id, 'status' => $status], 'Request updated')->send();

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    $response->serverError($e->getMessage())->send();
}
*/

// ============================================================================
// EXAMPLE 4: USER REGISTRATION WITH VALIDATION
// ============================================================================

// File: auth/register_secure.php

/*
<?php
include '../config/security.php';
include '../includes/Security.php';
include '../includes/Pagination.php';
include '../database/BaseDB.php';
include '../database/config.php';
include '../includes/session.php';

$response = new Response();
$validator = new Validator();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    // Verify CSRF
    $security = new Security();
    if (!$security->verifyCSRFToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        throw new Exception('CSRF token invalid');
    }

    // Get inputs
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $phone = trim($_POST['phone'] ?? '');

    $errors = [];

    // Validate
    if (!$validator->required($fullname)) {
        $errors['fullname'] = 'Full name is required';
    }

    if (!$validator->email($email)) {
        $errors['email'] = 'Invalid email format';
    }

    if (!$security->passwordMeetsRequirements($password)) {
        $errors['password'] = 'Password must be at least 8 chars with uppercase, numbers, and special chars';
    }

    if ($password !== $password_confirm) {
        $errors['password_confirm'] = 'Passwords do not match';
    }

    if (!$validator->phone($phone)) {
        $errors['phone'] = 'Invalid phone number';
    }

    if (!empty($errors)) {
        $response->unprocessable('Validation failed', $errors)->send();
    }

    $db = new BaseDB($conn);

    // Check if email exists
    $existing = $db->selectOne(
        "SELECT id FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1",
        's',
        [$email]
    );

    if ($existing) {
        $response->conflict('Email already registered')->send();
    }

    // Hash password
    $hashed_password = $security->hashPassword($password);

    // Insert user
    $sql = "INSERT INTO users (fullname, email, password, phone_number, role, status) 
            VALUES (?, ?, ?, ?, 'user', 'active')";
    $user_id = $db->insert($sql, 'ssss', [$fullname, $email, $hashed_password, $phone]);

    // Create user preferences
    $sql = "INSERT INTO user_preferences (user_id, language) VALUES (?, 'en')";
    $db->insert($sql, 'i', [$user_id]);

    $response->created(
        ['user_id' => $user_id, 'email' => $email],
        'Account created successfully'
    )->send();

} catch (Exception $e) {
    $response->serverError($e->getMessage())->send();
}
*/

// ============================================================================
// EXAMPLE 5: SEARCH WITH FILTERING
// ============================================================================

// File: admin/search_records.php

/*
<?php
include '../config/security.php';
include '../includes/Pagination.php';
include '../database/BaseDB.php';
include '../database/config.php';
include '../includes/session.php';

requireAdmin();

$db = new BaseDB($conn);
$validator = new Validator();

// Get search parameters
$search = trim($_GET['q'] ?? '');
$type = trim($_GET['type'] ?? 'all');
$status = trim($_GET['status'] ?? 'all');

$page = (int)($_GET['page'] ?? 1);

// Build query
$tables = ['baptism_records', 'first_communion_records', 'confirmation_records', 'marriage_records'];

if ($type !== 'all' && in_array($type, $tables)) {
    $tables = [$type];
}

$all_results = [];

foreach ($tables as $table) {
    $sql = "SELECT * FROM $table WHERE deleted_at IS NULL";
    $types = '';
    $params = [];

    if (!empty($search)) {
        if ($table === 'marriage_records') {
            $sql .= " AND (husband_name LIKE ? OR wife_name LIKE ?)";
            $search_param = "%$search%";
            $params[] = $search_param;
            $params[] = $search_param;
            $types = 'ss';
        } else {
            $sql .= " AND fullname LIKE ?";
            $params[] = "%$search%";
            $types = 's';
        }
    }

    $results = $db->select($sql, $types, $params);
    $all_results = array_merge($all_results, $results);
}

// Paginate
$pagination = new Pagination(count($all_results), 20, $page);
$offset = $pagination->getOffset();
$paginated = array_slice($all_results, $offset, $pagination->getPageSize());
?>

<!-- Display results -->
<h2>Search Results</h2>
<p>Found <?php echo count($all_results); ?> records</p>

<table class="table">
    <!-- Table content -->
</table>

<?php echo $pagination->render('search_records.php?q=' . urlencode($search)); ?>
*/

// ============================================================================
// EXAMPLE 6: SAFE FILE UPLOAD
// ============================================================================

// File: api/profile/upload-photo.php

/*
<?php
include '../../config/security.php';
include '../../includes/Security.php';
include '../../database/BaseDB.php';
include '../../database/config.php';
include '../../includes/session.php';

$response = new Response();

try {
    if (!isLoggedIn()) {
        $response->unauthorized()->send();
    }

    // Validate file upload
    if (!isset($_FILES['photo'])) {
        $response->badRequest('No file provided')->send();
    }

    $security = new Security();
    $validation = $security->validateFileUpload(
        $_FILES['photo'],
        'image',
        MAX_UPLOAD_SIZE
    );

    if (!$validation['valid']) {
        $response->badRequest($validation['error'])->send();
    }

    // Generate unique filename
    $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
    $filename = 'profile_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
    $filepath = UPLOAD_DIR . $filename;

    // Move uploaded file
    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $filepath)) {
        throw new Exception('Failed to upload file');
    }

    // Update database
    $db = new BaseDB($conn);
    $sql = "UPDATE users SET profile_picture = ? WHERE id = ?";
    $db->update($sql, 'si', [$filename, $_SESSION['user_id']]);

    $response->success(['filename' => $filename], 'Photo uploaded')->send();

} catch (Exception $e) {
    $response->serverError($e->getMessage())->send();
}
*/

?>

<!-- 
INTEGRATION INSTRUCTIONS:

1. Choose the example that matches your need
2. Copy the code
3. Paste into your file
4. Replace placeholder paths with your actual paths
5. Adapt to your specific requirements
6. Test thoroughly

COMMON REPLACEMENTS:
- Include paths: Adjust to match your directory structure
- Database table names: Use your actual table names
- Field names: Match your database schema
- Validation rules: Customize for your data

REMEMBER:
- Always verify CSRF tokens for form submissions
- Use prepared statements (never concatenate user input)
- Check user authorization before operations
- Log important actions
- Validate all inputs
- Sanitize outputs
-->
