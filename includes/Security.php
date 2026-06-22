<?php
/**
 * Security Class - Core Security Functions
 * Handles session management, password hashing, CSRF protection
 */

class Security {
    /**
     * Hash password using bcrypt
     */
    public static function hashPassword($password) {
        require_once __DIR__ . '/../config/security.php';
        return password_hash($password, PASSWORD_HASH_ALGO, ['cost' => PASSWORD_HASH_COST]);
    }

    /**
     * Verify password
     */
    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }

    /**
     * Generate CSRF token
     */
    public static function generateCSRFToken() {
        require_once __DIR__ . '/../config/security.php';

        if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
            $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
            $_SESSION[CSRF_TOKEN_NAME . '_time'] = time();
        }

        return $_SESSION[CSRF_TOKEN_NAME];
    }

    /**
     * Verify CSRF token
     */
    public static function verifyCSRFToken($token) {
        require_once __DIR__ . '/../config/security.php';

        if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
            return false;
        }

        // Check token validity
        if (!hash_equals($_SESSION[CSRF_TOKEN_NAME], $token)) {
            return false;
        }

        // Check token expiry
        if (time() - $_SESSION[CSRF_TOKEN_NAME . '_time'] > CSRF_TOKEN_EXPIRY) {
            unset($_SESSION[CSRF_TOKEN_NAME]);
            return false;
        }

        return true;
    }

    /**
     * Regenerate session ID (prevent fixation attacks)
     */
    public static function regenerateSessionId() {
        session_regenerate_id(true);
        $_SESSION['session_regenerated_at'] = time();
    }

    /**
     * Check if session should be regenerated
     */
    public static function shouldRegenerateSession() {
        require_once __DIR__ . '/../config/security.php';

        if (!isset($_SESSION['session_regenerated_at'])) {
            return true;
        }

        return time() - $_SESSION['session_regenerated_at'] > SESSION_REGENERATE_INTERVAL;
    }

    /**
     * Get secure cookie
     */
    public static function setSecureCookie($name, $value, $expiry = 0) {
        require_once __DIR__ . '/../config/security.php';

        setcookie(
            $name,
            $value,
            [
                'expires' => $expiry,
                'path' => '/',
                'domain' => $_SERVER['HTTP_HOST'] ?? '',
                'secure' => SESSION_COOKIE_SECURE,
                'httponly' => SESSION_COOKIE_HTTPONLY,
                'samesite' => SESSION_COOKIE_SAMESITE
            ]
        );
    }

    /**
     * Delete cookie
     */
    public static function deleteCookie($name) {
        setcookie($name, '', ['expires' => time() - 3600]);
        unset($_COOKIE[$name]);
    }

    /**
     * Check login attempts and enforce lockout
     */
    public static function checkLoginAttempts($email, $conn) {
        require_once __DIR__ . '/../config/security.php';

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $window = time() - LOGIN_ATTEMPT_WINDOW;

        // Get recent failed attempts
        $sql = "SELECT COUNT(*) as attempts FROM login_attempts 
                WHERE email = ? AND ip_address = ? AND attempt_status = 'failed' 
                AND attempted_at > FROM_UNIXTIME(?)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssi', $email, $ip, $window);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        
        return $data['attempts'] ?? 0;
    }

    /**
     * Log login attempt
     */
    public static function logLoginAttempt($email, $status, $conn, $reason = '') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        $sql = "INSERT INTO login_attempts (email, ip_address, attempt_status, reason) 
                VALUES (?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssss', $email, $ip, $status, $reason);
        $stmt->execute();
    }

    /**
     * Lock account
     */
    public static function lockAccount($user_id, $conn) {
        require_once __DIR__ . '/../config/security.php';

        $lockout_until = date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT_DURATION);
        
        $sql = "UPDATE users SET account_locked_until = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $lockout_until, $user_id);
        return $stmt->execute();
    }

    /**
     * Unlock account
     */
    public static function unlockAccount($user_id, $conn) {
        $sql = "UPDATE users SET account_locked_until = NULL, failed_login_attempts = 0 WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $user_id);
        return $stmt->execute();
    }

    /**
     * Check if account is locked
     */
    public static function isAccountLocked($user_id, $conn) {
        $sql = "SELECT account_locked_until FROM users WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if (!$user || !$user['account_locked_until']) {
            return false;
        }

        $lockout_time = strtotime($user['account_locked_until']);
        
        // Auto-unlock if lockout period has expired
        if (time() > $lockout_time) {
            self::unlockAccount($user_id, $conn);
            return false;
        }

        return true;
    }

    /**
     * Get client IP address
     */
    public static function getClientIp() {
        $ip = '';

        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        }

        // Validate IP
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = 'unknown';
        }

        return $ip;
    }

    /**
     * Check rate limiting
     */
    public static function checkRateLimit($key, $cache) {
        require_once __DIR__ . '/../config/security.php';

        $cache_key = 'rate_limit_' . $key;
        $current_count = (int)$cache->get($cache_key);

        if ($current_count >= RATE_LIMIT_REQUESTS) {
            return false;
        }

        $cache->set($cache_key, $current_count + 1, RATE_LIMIT_WINDOW);
        return true;
    }

    /**
     * Encrypt data
     */
    public static function encrypt($data) {
        require_once __DIR__ . '/../config/security.php';

        $key = hash('sha256', ENCRYPTION_KEY, true);
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($data, ENCRYPTION_CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt data
     */
    public static function decrypt($data) {
        require_once __DIR__ . '/../config/security.php';

        $key = hash('sha256', ENCRYPTION_KEY, true);
        $data = base64_decode($data);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        
        return openssl_decrypt($encrypted, ENCRYPTION_CIPHER, $key, OPENSSL_RAW_DATA, $iv);
    }

    /**
     * Generate secure random token
     */
    public static function generateToken($length = 32) {
        return bin2hex(random_bytes($length));
    }

    /**
     * Set security headers
     */
    public static function setSecurityHeaders() {
        require_once __DIR__ . '/../config/security.php';

        foreach ($SECURITY_HEADERS as $header => $value) {
            header("$header: $value");
        }
    }

    /**
     * Validate file upload
     */
    public static function validateFileUpload($file, $type = 'image') {
        require_once __DIR__ . '/../config/security.php';

        $allowed_extensions = ALLOWED_UPLOAD_EXTENSIONS;
        $max_size = MAX_UPLOAD_SIZE;

        // Check file size
        if ($file['size'] > $max_size) {
            return ['valid' => false, 'error' => 'File too large'];
        }

        // Get file extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        // Check file extension
        if (!in_array($ext, $allowed_extensions)) {
            return ['valid' => false, 'error' => 'Invalid file type'];
        }

        // Check MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        // Additional security: check file content
        if ($type === 'image') {
            if (!getimagesize($file['tmp_name'])) {
                return ['valid' => false, 'error' => 'Invalid image file'];
            }
        }

        return ['valid' => true];
    }
}
?>
