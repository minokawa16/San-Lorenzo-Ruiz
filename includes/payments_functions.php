<?php
/**
 * Retired legacy payment workflow.
 *
 * Modern request payments are handled by request_payments through the current
 * request pages. This file remains only as historical reference and
 * intentionally registers no runtime functions.
 */

return;

if (!function_exists('submitPayment')) {
    /**
     * Submit a payment receipt by parishioner
     * 
     * @param $conn Database connection
     * @param int $user_id User ID
     * @param string $payment_method Payment method (gcash, bank_transfer, check, etc.)
     * @param float $amount Payment amount
     * @param string $notes Payment notes/reference
     * @param array $receipt Receipt file from $_FILES
     * @return array ['success' => bool, 'payment_id' => int, 'error' => string]
     */
    function submitPayment($conn, $user_id, $payment_method, $amount, $notes = '', $receipt = null) {
        // Validate input
        if (empty($payment_method)) {
            return ['success' => false, 'error' => 'Payment method is required'];
        }
        
        $amount = floatval($amount);
        if ($amount <= 0) {
            return ['success' => false, 'error' => 'Invalid amount'];
        }
        
        $user_id = intval($user_id);
        $payment_method = trim($payment_method);
        $notes = trim($notes);
        
        // Create payment record
        $verification_status = 'Pending Verification';
        $created_at = date('Y-m-d H:i:s');
        
        $stmt = $conn->prepare(
            "INSERT INTO Payment_Transactions (user_id, payment_method, amount, notes, verification_status, created_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        
        if (!$stmt) {
            return ['success' => false, 'error' => 'Database error: ' . $conn->error];
        }
        
        $stmt->bind_param('isdsss', $user_id, $payment_method, $amount, $notes, $verification_status, $created_at);
        
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            return ['success' => false, 'error' => 'Failed to create payment: ' . $error];
        }
        
        $payment_id = $stmt->insert_id;
        $stmt->close();
        
        // Upload receipt file if provided
        if ($receipt && $receipt['error'] === UPLOAD_ERR_OK) {
            uploadPaymentReceipt($conn, $payment_id, $receipt);
        }
        
        // Audit log
        auditLog($conn, 'SUBMIT_PAYMENT', 'payment', $payment_id, 'Payment ' . $amount . ' via ' . $payment_method);
        
        // Send notification to admin
        sendNotification($conn, 0, 'new_payment', 'New Payment Submitted', 
            'User ' . $user_id . ' submitted a payment of ' . $amount . ' via ' . $payment_method, 
            'payment', $payment_id);
        
        return ['success' => true, 'payment_id' => $payment_id];
    }
}

if (!function_exists('uploadPaymentReceipt')) {
    /**
     * Upload payment receipt image/PDF
     * 
     * @param $conn Database connection
     * @param int $payment_id Payment ID
     * @param array $file File from $_FILES
     * @return bool Success
     */
    function uploadPaymentReceipt($conn, $payment_id, $file) {
        $payment_id = intval($payment_id);
        $upload_dir = __DIR__ . '/../uploads/payments/' . $payment_id;
        
        // Create directory if not exists
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $allowed_mime = ['application/pdf', 'image/jpeg', 'image/png'];
        $max_size = 10 * 1024 * 1024; // 10MB for receipt images
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return false;
        }
        
        $tmp_file = $file['tmp_name'];
        $orig_name = basename($file['name']);
        $file_size = filesize($tmp_file);
        
        // Validate file size
        if ($file_size > $max_size) {
            return false;
        }
        
        // Validate MIME type
        $mime_type = mime_content_type($tmp_file) ?: $file['type'];
        if (!in_array($mime_type, $allowed_mime)) {
            return false;
        }
        
        // Generate safe filename
        $ext = pathinfo($orig_name, PATHINFO_EXTENSION);
        $safe_name = uniqid() . '.' . strtolower($ext);
        $dest_file = $upload_dir . '/' . $safe_name;
        
        // Move file
        if (!move_uploaded_file($tmp_file, $dest_file)) {
            return false;
        }
        
        // Store in database
        $receipt_path = 'uploads/payments/' . $payment_id . '/' . $safe_name;
        $uploaded_at = date('Y-m-d H:i:s');
        
        $stmt = $conn->prepare(
            "UPDATE Payment_Transactions SET receipt_file = ?, receipt_uploaded_at = ? WHERE payment_id = ?"
        );
        
        if ($stmt) {
            $stmt->bind_param('ssi', $receipt_path, $uploaded_at, $payment_id);
            $stmt->execute();
            $stmt->close();
            return true;
        }
        
        return false;
    }
}

if (!function_exists('getParishionerPayments')) {
    /**
     * Get all payments for a parishioner
     * 
     * @param $conn Database connection
     * @param int $user_id User ID
     * @param array $options Filter options (status, limit, offset)
     * @return array List of payments
     */
    function getParishionerPayments($conn, $user_id, $options = []) {
        $user_id = intval($user_id);
        $status = isset($options['status']) ? trim($options['status']) : '';
        $limit = isset($options['limit']) ? intval($options['limit']) : 100;
        $offset = isset($options['offset']) ? intval($options['offset']) : 0;
        
        $query = "SELECT * FROM Payment_Transactions WHERE user_id = ?";
        
        if (!empty($status)) {
            $query .= " AND verification_status = '" . $conn->real_escape_string($status) . "'";
        }
        
        $query .= " ORDER BY created_at DESC LIMIT " . $limit . " OFFSET " . $offset;
        
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            return [];
        }
        
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $payments = [];
        while ($row = $result->fetch_assoc()) {
            $payments[] = $row;
        }
        $stmt->close();
        
        return $payments;
    }
}

if (!function_exists('getAllPayments')) {
    /**
     * Get all payments (admin only)
     * 
     * @param $conn Database connection
     * @param array $options Filter options (status, user_id, limit, offset)
     * @return array List of all payments
     */
    function getAllPayments($conn, $options = []) {
        $status = isset($options['status']) ? trim($options['status']) : '';
        $user_id = isset($options['user_id']) ? intval($options['user_id']) : 0;
        $limit = isset($options['limit']) ? intval($options['limit']) : 100;
        $offset = isset($options['offset']) ? intval($options['offset']) : 0;
        
        $query = "SELECT * FROM Payment_Transactions WHERE 1=1";
        
        if (!empty($status)) {
            $query .= " AND verification_status = '" . $conn->real_escape_string($status) . "'";
        }
        
        if ($user_id > 0) {
            $query .= " AND user_id = " . $user_id;
        }
        
        $query .= " ORDER BY created_at DESC LIMIT " . $limit . " OFFSET " . $offset;
        
        $result = $conn->query($query);
        
        $payments = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $payments[] = $row;
            }
        }
        
        return $payments;
    }
}

if (!function_exists('getPaymentDetails')) {
    /**
     * Get detailed information about a payment
     * 
     * @param $conn Database connection
     * @param int $payment_id Payment ID
     * @return array Payment details or null
     */
    function getPaymentDetails($conn, $payment_id) {
        $payment_id = intval($payment_id);
        
        $stmt = $conn->prepare("SELECT * FROM Payment_Transactions WHERE payment_id = ?");
        if (!$stmt) {
            return null;
        }
        
        $stmt->bind_param('i', $payment_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $payment = $result->fetch_assoc();
        $stmt->close();
        
        return $payment;
    }
}

if (!function_exists('verifyPayment')) {
    /**
     * Verify/approve a payment (admin only)
     * 
     * @param $conn Database connection
     * @param int $payment_id Payment ID
     * @param string $verification_status Verification status (Verified, Rejected)
     * @param string $admin_remarks Admin remarks
     * @return array ['success' => bool, 'error' => string]
     */
    function verifyPayment($conn, $payment_id, $verification_status, $admin_remarks = '') {
        $payment_id = intval($payment_id);
        $verification_status = trim($verification_status);
        $admin_remarks = trim($admin_remarks);
        
        $valid_statuses = ['Verified', 'Rejected', 'Pending Verification'];
        
        if (!in_array($verification_status, $valid_statuses)) {
            return ['success' => false, 'error' => 'Invalid verification status'];
        }
        
        $verified_at = date('Y-m-d H:i:s');
        $verified_by = getCurrentUserId();
        
        $stmt = $conn->prepare(
            "UPDATE Payment_Transactions SET verification_status = ?, admin_remarks = ?, verified_at = ?, verified_by = ? WHERE payment_id = ?"
        );
        
        if (!$stmt) {
            return ['success' => false, 'error' => 'Database error'];
        }
        
        $stmt->bind_param('sssii', $verification_status, $admin_remarks, $verified_at, $verified_by, $payment_id);
        
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            return ['success' => false, 'error' => $error];
        }
        
        $stmt->close();
        
        // Get payment to notify user
        $payment = getPaymentDetails($conn, $payment_id);
        if ($payment) {
            $user_id = $payment['user_id'];
            $message = 'Your payment of ' . $payment['amount'] . ' has been ' . strtolower($verification_status);
            if (!empty($admin_remarks)) {
                $message .= ' - ' . $admin_remarks;
            }
            sendNotification($conn, $user_id, 'payment_verified', 'Payment Verification Status', $message, 'payment', $payment_id);
        }
        
        // Audit log
        auditLog($conn, 'VERIFY_PAYMENT', 'payment', $payment_id, $verification_status . ': ' . $admin_remarks);
        
        return ['success' => true];
    }
}

if (!function_exists('rejectPayment')) {
    /**
     * Reject a payment with reason
     * 
     * @param $conn Database connection
     * @param int $payment_id Payment ID
     * @param string $reason Rejection reason
     * @return array ['success' => bool, 'error' => string]
     */
    function rejectPayment($conn, $payment_id, $reason = '') {
        return verifyPayment($conn, $payment_id, 'Rejected', $reason);
    }
}

if (!function_exists('canAccessPayment')) {
    /**
     * Check if user can access a payment
     * 
     * @param $conn Database connection
     * @param int $payment_id Payment ID
     * @param int $user_id User ID
     * @param string $user_role User role
     * @return bool
     */
    function canAccessPayment($conn, $payment_id, $user_id, $user_role) {
        // Admins can access all
        if ($user_role === 'admin') {
            return true;
        }
        
        // Parishioners can only access their own
        $payment_id = intval($payment_id);
        $user_id = intval($user_id);
        
        $stmt = $conn->prepare("SELECT user_id FROM Payment_Transactions WHERE payment_id = ?");
        $stmt->bind_param('i', $payment_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $payment = $result->fetch_assoc();
        $stmt->close();
        
        return $payment && $payment['user_id'] == $user_id;
    }
}

if (!function_exists('getPaymentStats')) {
    /**
     * Get payment statistics for admin dashboard
     * 
     * @param $conn Database connection
     * @return array Statistics
     */
    function getPaymentStats($conn) {
        $stats = [
            'total_payments' => 0,
            'pending_verification' => 0,
            'verified' => 0,
            'rejected' => 0,
            'total_amount' => 0,
            'verified_amount' => 0
        ];
        
        // Total payments
        $result = $conn->query("SELECT COUNT(*) as count, SUM(amount) as total FROM Payment_Transactions");
        if ($result) {
            $row = $result->fetch_assoc();
            $stats['total_payments'] = intval($row['count']);
            $stats['total_amount'] = floatval($row['total'] ?? 0);
        }
        
        // By status
        $result = $conn->query("SELECT verification_status, COUNT(*) as count FROM Payment_Transactions GROUP BY verification_status");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $status = $row['verification_status'];
                if ($status === 'Pending Verification') {
                    $stats['pending_verification'] = intval($row['count']);
                } elseif ($status === 'Verified') {
                    $stats['verified'] = intval($row['count']);
                } elseif ($status === 'Rejected') {
                    $stats['rejected'] = intval($row['count']);
                }
            }
        }
        
        // Verified amount
        $result = $conn->query("SELECT SUM(amount) as total FROM Payment_Transactions WHERE verification_status = 'Verified'");
        if ($result) {
            $row = $result->fetch_assoc();
            $stats['verified_amount'] = floatval($row['total'] ?? 0);
        }
        
        return $stats;
    }
}
