<?php
/**
 * Authentication and Role-Based Authorization Functions
 * Handles user authentication, session management, and permission checks
 */

if (!defined('BASE_URL')) {
    define('BASE_URL', '/ParishSystem/');
}

if (!function_exists('initSession')) {
    /**
     * Initialize user session
     */
    function initSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}

if (!function_exists('isAuthenticated')) {
    /**
     * Check if user is authenticated
     */
    function isAuthenticated() {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }
}

if (!function_exists('getCurrentUserId')) {
    /**
     * Get current logged-in user ID
     */
    function getCurrentUserId() {
        return isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
    }
}

if (!function_exists('getUserRole')) {
    /**
     * Get current user's role (admin or user)
     */
    function getUserRole() {
        return isset($_SESSION['role']) ? $_SESSION['role'] : null;
    }
}

if (!function_exists('normalizeUserRole')) {
    /**
     * Normalize legacy and future role names into one permission model.
     */
    function normalizeUserRole($role) {
        $role = strtolower(trim((string) $role));
        $aliases = [
            'administrator' => 'admin',
            'staff' => 'parish_staff',
            'parish staff' => 'parish_staff',
            'records clerk' => 'records_clerk',
            'finance' => 'finance_staff',
            'cashier' => 'finance_staff',
            'district_coordinator' => 'coordinator',
            'chapel_coordinator' => 'coordinator',
            'member' => 'user',
            'parishioner' => 'user',
            'volunteer' => 'user',
        ];

        return $aliases[$role] ?? ($role !== '' ? $role : 'guest');
    }
}

if (!function_exists('rolePermissions')) {
    /**
     * Central permission map. Existing admin accounts keep full access, while
     * future staff/coordinator roles can be granted narrower capabilities.
     */
    function rolePermissions($role) {
        $role = normalizeUserRole($role);
        $permissions = [
            'admin' => ['*'],
            'parish_staff' => [
                'admin.access',
                'dashboard.view',
                'users.view',
                'registrations.verify',
                'requests.manage',
                'records.manage',
                'certificates.manage',
                'announcements.manage',
                'calendar.manage',
                'reservations.manage',
                'reports.view',
                'ai.use',
            ],
            'records_clerk' => [
                'admin.access',
                'dashboard.view',
                'records.manage',
                'certificates.manage',
                'requests.view',
                'reports.view',
            ],
            'finance_staff' => [
                'admin.access',
                'dashboard.view',
                'requests.view',
                'payments.verify',
                'reports.view',
            ],
            'coordinator' => [
                'dashboard.view',
                'members.view_district',
                'announcements.view',
                'calendar.view',
                'reservations.view',
                'ai.use',
            ],
            'user' => [
                'profile.manage',
                'requests.create',
                'requests.view_own',
                'documents.upload_own',
                'payments.upload_own',
                'announcements.view',
                'calendar.view',
                'notifications.view_own',
                'ai.use',
            ],
            'guest' => [
                'auth.register',
                'auth.login',
                'announcements.view_public',
            ],
        ];

        return $permissions[$role] ?? $permissions['guest'];
    }
}

if (!function_exists('hasPermission')) {
    function hasPermission($permission, $role = null) {
        $role = normalizeUserRole($role ?? getUserRole());
        $permissions = rolePermissions($role);
        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }
}

if (!function_exists('hasAnyPermission')) {
    function hasAnyPermission($permissions, $role = null) {
        foreach ((array) $permissions as $permission) {
            if (hasPermission($permission, $role)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('isAdmin')) {
    /**
     * Check if current user is admin
     */
    function isAdmin() {
        return normalizeUserRole($_SESSION['role'] ?? '') === 'admin';
    }
}

if (!function_exists('isBackOfficeUser')) {
    function isBackOfficeUser() {
        return hasPermission('admin.access');
    }
}

if (!function_exists('isParishioner')) {
    /**
     * Check if current user is parishioner (regular user)
     */
    function isParishioner() {
        return normalizeUserRole($_SESSION['role'] ?? '') === 'user';
    }
}

if (!function_exists('getUserFullName')) {
    /**
     * Get current user's full name
     */
    function getUserFullName() {
        return isset($_SESSION['fullname']) ? $_SESSION['fullname'] : 'Guest';
    }
}

if (!function_exists('getUserEmail')) {
    /**
     * Get current user's email
     */
    function getUserEmail() {
        return isset($_SESSION['email']) ? $_SESSION['email'] : '';
    }
}

if (!function_exists('requireAuth')) {
    /**
     * Require user to be authenticated, redirect to login if not
     */
    function requireAuth() {
        if (!isAuthenticated()) {
            header('Location: ' . BASE_URL . 'auth/login.php');
            exit;
        }
    }
}

if (!function_exists('requireAdmin')) {
    /**
     * Require user to be admin, redirect to dashboard if not
     */
    function requireAdmin() {
        if (!isAuthenticated() || !hasPermission('admin.access')) {
            header('Location: ' . BASE_URL . 'users/dashboard.php');
            exit;
        }
    }
}

if (!function_exists('requirePermission')) {
    function requirePermission($permission, $redirect = null) {
        if (!isAuthenticated()) {
            header('Location: ' . BASE_URL . 'auth/login.php');
            exit;
        }

        if (!hasPermission($permission)) {
            http_response_code(403);
            header('Location: ' . ($redirect ?: getUserDashboardURL()) . '?error=forbidden');
            exit;
        }
    }
}

if (!function_exists('requireParishioner')) {
    /**
     * Require user to be parishioner, redirect to admin if not
     */
    function requireParishioner() {
        if (!isAuthenticated() || !isParishioner()) {
            header('Location: ' . BASE_URL . 'admin/dashboard.php');
            exit;
        }
    }
}

if (!function_exists('loginUser')) {
    /**
     * Set user session after successful login
     * 
     * @param int $user_id User ID
     * @param string $fullname User's full name
     * @param string $email User's email
     * @param string $role User's role (admin or user)
     */
    function loginUser($user_id, $fullname, $email, $role = 'user') {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION['user_id'] = intval($user_id);
        $_SESSION['fullname'] = $fullname;
        $_SESSION['email'] = $email;
        $_SESSION['role'] = normalizeUserRole($role);
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['session_regenerated_at'] = time();
    }
}

if (!function_exists('logoutUser')) {
    /**
     * Clear user session and logout
     */
    function logoutUser() {
        $message = 'Logout successful.';
        $_SESSION = [];
        session_destroy();
        if (function_exists('queueActionNotification')) {
            session_write_close();
            session_start();
            session_regenerate_id(true);
            queueActionNotification($message, 'success');
        }
        // Redirect to login
        header('Location: ' . BASE_URL . 'auth/login.php');
        exit;
    }
}

if (!function_exists('redirectAfterLogin')) {
    /**
     * Redirect user to appropriate dashboard based on role
     */
    function redirectAfterLogin() {
        if (function_exists('queueActionNotification')) {
            queueActionNotification('Login successful. Welcome back!', 'success');
        }
        header('Location: ' . BASE_URL . ltrim(getUserDashboardURL(), '/'));
        exit;
    }
}

if (!function_exists('checkPermission')) {
    /**
     * Check if user has permission to access a resource
     * 
     * @param string $action Action being performed (view, edit, delete, etc.)
     * @param string $resource Resource type (requirement, request, payment, etc.)
     * @param int|null $owner_id Owner of the resource (for ownership checks)
     * @return bool
     */
    function checkPermission($action, $resource, $owner_id = null) {
        if (!isAuthenticated()) {
            return false;
        }
        
        $user_id = getCurrentUserId();
        $role = getUserRole();
        
        if (hasPermission($resource . '.' . $action, $role) || hasPermission($action . '.' . $resource, $role)) {
            return true;
        }
        
        // Parishioners can only view/edit their own resources
        if (normalizeUserRole($role) === 'user') {
            if ($owner_id !== null && $owner_id != $user_id) {
                return false;
            }
            return true;
        }
        
        return false;
    }
}

if (!function_exists('canViewRequirement')) {
    /**
     * Check if user can view a requirement submission
     * 
     * @param $conn Database connection
     * @param int $submission_id Requirement submission ID
     * @return bool
     */
    function canViewRequirement($conn, $submission_id) {
        if (!isAuthenticated()) {
            return false;
        }
        
        if (isAdmin()) {
            return true;
        }
        
        $user_id = getCurrentUserId();
        $stmt = $conn->prepare("SELECT user_id FROM Requirements_Submissions WHERE submission_id = ?");
        $stmt->bind_param('i', $submission_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $submission = $result->fetch_assoc();
        $stmt->close();
        
        return $submission && $submission['user_id'] == $user_id;
    }
}

if (!function_exists('canViewRequest')) {
    /**
     * Check if user can view a certificate request
     * 
     * @param $conn Database connection
     * @param int $request_id Certificate request ID
     * @return bool
     */
    function canViewRequest($conn, $request_id) {
        if (!isAuthenticated()) {
            return false;
        }
        
        if (isAdmin()) {
            return true;
        }
        
        $user_id = getCurrentUserId();
        $stmt = $conn->prepare("SELECT user_id FROM Certificate_Requests WHERE request_id = ?");
        $stmt->bind_param('i', $request_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $request = $result->fetch_assoc();
        $stmt->close();
        
        return $request && $request['user_id'] == $user_id;
    }
}

if (!function_exists('canEditRequest')) {
    /**
     * Check if user can edit/modify a certificate request
     * 
     * @param $conn Database connection
     * @param int $request_id Certificate request ID
     * @param string $request_status Current status of request
     * @return bool
     */
    function canEditRequest($conn, $request_id, $request_status = null) {
        if (!isAuthenticated()) {
            return false;
        }
        
        // Only admins can edit requests, or users can add files to their pending requests
        if (isAdmin()) {
            return true;
        }
        
        if (!canViewRequest($conn, $request_id)) {
            return false;
        }
        
        // Parishioners can only add files to pending/in_review requests
        if ($request_status === null) {
            $user_id = getCurrentUserId();
            $stmt = $conn->prepare("SELECT request_status FROM Certificate_Requests WHERE request_id = ? AND user_id = ?");
            $stmt->bind_param('ii', $request_id, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $request = $result->fetch_assoc();
            $stmt->close();
            
            $request_status = $request['request_status'] ?? 'unknown';
        }
        
        return in_array($request_status, ['pending', 'in_review', 'requesting_files']);
    }
}

if (!function_exists('canReplaceFile')) {
    /**
     * Check if user can replace/edit a specific file
     * 
     * @param $conn Database connection
     * @param int $request_id Certificate request ID
     * @return bool
     */
    function canReplaceFile($conn, $request_id) {
        return canEditRequest($conn, $request_id);
    }
}

if (!function_exists('auditLog')) {
    /**
     * Log an audit event to the Audit_Logs table
     * 
     * @param $conn Database connection
     * @param string $action Action performed
     * @param string $entity Entity type (requirement, request, payment, etc.)
     * @param int $entity_id Entity ID
     * @param string $details Additional details
     */
    function auditLog($conn, $action, $entity, $entity_id, $details = '') {
        $user_id = getCurrentUserId();
        $user_role = getUserRole();
        $timestamp = date('Y-m-d H:i:s');
        
        $stmt = $conn->prepare(
            "INSERT INTO Audit_Logs (user_id, action, entity_type, entity_id, details, user_role, timestamp)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        
        if ($stmt) {
            $stmt->bind_param('issiiss', $user_id, $action, $entity, $entity_id, $details, $user_role, $timestamp);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('sendNotification')) {
    /**
     * Send a notification to a user
     * 
     * @param $conn Database connection
     * @param int $user_id Recipient user ID
     * @param string $type Notification type
     * @param string $title Notification title
     * @param string $message Notification message
     * @param string $reference_type Reference entity type (optional)
     * @param int $reference_id Reference entity ID (optional)
     */
    function sendNotification($conn, $user_id, $type, $title, $message, $reference_type = null, $reference_id = null) {
        $created_at = date('Y-m-d H:i:s');
        
        $stmt = $conn->prepare(
            "INSERT INTO Notifications (user_id, type, title, message, reference_type, reference_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        
        if ($stmt) {
            $stmt->bind_param('issssii', $user_id, $type, $title, $message, $reference_type, $reference_id, $created_at);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('getUnreadNotificationCount')) {
    /**
     * Get count of unread notifications for current user
     * 
     * @param $conn Database connection
     * @return int
     */
    function getUnreadNotificationCount($conn) {
        if (!isAuthenticated()) {
            return 0;
        }
        
        $user_id = getCurrentUserId();
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM Notifications WHERE user_id = ? AND read_at IS NULL");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        return $row['count'] ?? 0;
    }
}

if (!function_exists('getUserDashboardURL')) {
    /**
     * Get the appropriate dashboard URL based on user role
     * 
     * @return string Dashboard URL
     */
    function getUserDashboardURL() {
        if (hasPermission('admin.access')) {
            return '/admin/dashboard.php';
        } else {
            return '/users/dashboard.php';
        }
    }
}

if (!function_exists('isSessionExpired')) {
    /**
     * Check if user session has expired (configurable timeout)
     * 
     * @param int $timeout Session timeout in seconds (default: 30 minutes)
     * @return bool
     */
    function isSessionExpired($timeout = 1800) {
        if (!isAuthenticated()) {
            return true;
        }
        
        if (!isset($_SESSION['login_time'])) {
            return false;
        }
        
        if ((time() - $_SESSION['login_time']) > $timeout) {
            return true;
        }
        
        // Update last activity time
        $_SESSION['login_time'] = time();
        return false;
    }
}
