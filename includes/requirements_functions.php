<?php
/**
 * Requirements Module Functions
 * Handles requirement submissions, validations, and status management
 */

if (!function_exists('submitRequirement')) {
    /**
     * Submit a new requirement by parishioner
     * 
     * @param $conn Database connection
     * @param int $user_id User ID
     * @param string $certificate_type Type of certificate
     * @param string $notes Additional notes
     * @param array $files Uploaded files array from $_FILES
     * @return array ['success' => bool, 'submission_id' => int, 'error' => string]
     */
    function submitRequirement($conn, $user_id, $certificate_type, $notes = '', $files = []) {
        // Validate input
        if (empty($certificate_type)) {
            return ['success' => false, 'error' => 'Certificate type is required'];
        }
        
        $user_id = intval($user_id);
        $certificate_type = trim($certificate_type);
        $notes = trim($notes);
        
        // Create submission record
        $status = 'Pending Review';
        $created_at = date('Y-m-d H:i:s');
        
        $stmt = $conn->prepare(
            "INSERT INTO Requirements_Submissions (user_id, certificate_type, status, submission_notes, created_at)
             VALUES (?, ?, ?, ?, ?)"
        );
        
        if (!$stmt) {
            return ['success' => false, 'error' => 'Database error: ' . $conn->error];
        }
        
        $stmt->bind_param('issss', $user_id, $certificate_type, $status, $notes, $created_at);
        
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            return ['success' => false, 'error' => 'Failed to create submission: ' . $error];
        }
        
        $submission_id = $stmt->insert_id;
        $stmt->close();
        
        // Handle file uploads if provided
        if (!empty($files) && !empty($files['name'][0])) {
            uploadRequirementFiles($conn, $submission_id, $files);
        }
        
        // Audit log
        auditLog($conn, 'SUBMIT_REQUIREMENT', 'requirement', $submission_id, 'Submitted requirement for ' . $certificate_type);
        
        // Send notification to admin
        sendNotification($conn, 0, 'new_requirement', 'New Requirement Submission', 
            'User ' . $user_id . ' submitted a requirement for ' . $certificate_type, 
            'requirement', $submission_id);
        
        return ['success' => true, 'submission_id' => $submission_id];
    }
}

if (!function_exists('uploadRequirementFiles')) {
    /**
     * Upload files for a requirement submission
     * 
     * @param $conn Database connection
     * @param int $submission_id Submission ID
     * @param array $files Files array from $_FILES
     * @return int Count of successfully uploaded files
     */
    function uploadRequirementFiles($conn, $submission_id, $files) {
        $submission_id = intval($submission_id);
        $upload_dir = __DIR__ . '/../uploads/requirements/' . $submission_id;
        
        // Create directory if not exists
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $allowed_mime = ['application/pdf', 'image/jpeg', 'image/png'];
        $max_size = 5 * 1024 * 1024; // 5MB
        $count = 0;
        
        // Process each file
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }
            
            $tmp_file = $files['tmp_name'][$i];
            $orig_name = basename($files['name'][$i]);
            $file_size = filesize($tmp_file);
            
            // Validate file size
            if ($file_size > $max_size) {
                continue;
            }
            
            // Validate MIME type
            $mime_type = mime_content_type($tmp_file) ?: $files['type'][$i];
            if (!in_array($mime_type, $allowed_mime)) {
                continue;
            }
            
            // Generate safe filename
            $ext = pathinfo($orig_name, PATHINFO_EXTENSION);
            $safe_name = uniqid() . '.' . strtolower($ext);
            $dest_file = $upload_dir . '/' . $safe_name;
            
            // Move file
            if (!move_uploaded_file($tmp_file, $dest_file)) {
                continue;
            }
            
            // Get requirement name if provided
            $req_names = $_POST['requirement_names'] ?? [];
            $req_name = isset($req_names[$i]) ? trim($req_names[$i]) : null;
            
            // Store in database
            $file_path = 'uploads/requirements/' . $submission_id . '/' . $safe_name;
            $uploaded_at = date('Y-m-d H:i:s');
            
            $stmt = $conn->prepare(
                "INSERT INTO Requirement_Files (submission_id, file_name, file_path, requirement_name, file_size, mime_type, uploaded_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            
            if ($stmt) {
                $stmt->bind_param('isssiss', $submission_id, $orig_name, $file_path, $req_name, $file_size, $mime_type, $uploaded_at);
                if ($stmt->execute()) {
                    $count++;
                }
                $stmt->close();
            }
        }
        
        return $count;
    }
}

if (!function_exists('getParishionerRequirements')) {
    /**
     * Get all requirements for a parishioner
     * 
     * @param $conn Database connection
     * @param int $user_id User ID
     * @param array $options Filter options (status, limit, offset)
     * @return array List of requirements
     */
    function getParishionerRequirements($conn, $user_id, $options = []) {
        $user_id = intval($user_id);
        $status = isset($options['status']) ? trim($options['status']) : '';
        $limit = isset($options['limit']) ? intval($options['limit']) : 100;
        $offset = isset($options['offset']) ? intval($options['offset']) : 0;
        
        $query = "SELECT * FROM Requirements_Submissions WHERE user_id = ?";
        
        if (!empty($status)) {
            $query .= " AND status = '" . $conn->real_escape_string($status) . "'";
        }
        
        $query .= " ORDER BY created_at DESC LIMIT " . $limit . " OFFSET " . $offset;
        
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            return [];
        }
        
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $requirements = [];
        while ($row = $result->fetch_assoc()) {
            $requirements[] = $row;
        }
        $stmt->close();
        
        return $requirements;
    }
}

if (!function_exists('getAllRequirements')) {
    /**
     * Get all requirements (admin only)
     * 
     * @param $conn Database connection
     * @param array $options Filter options (status, user_id, limit, offset)
     * @return array List of all requirements
     */
    function getAllRequirements($conn, $options = []) {
        $status = isset($options['status']) ? trim($options['status']) : '';
        $user_id = isset($options['user_id']) ? intval($options['user_id']) : 0;
        $limit = isset($options['limit']) ? intval($options['limit']) : 100;
        $offset = isset($options['offset']) ? intval($options['offset']) : 0;
        
        $query = "SELECT * FROM Requirements_Submissions WHERE 1=1";
        
        if (!empty($status)) {
            $query .= " AND status = '" . $conn->real_escape_string($status) . "'";
        }
        
        if ($user_id > 0) {
            $query .= " AND user_id = " . $user_id;
        }
        
        $query .= " ORDER BY created_at DESC LIMIT " . $limit . " OFFSET " . $offset;
        
        $result = $conn->query($query);
        
        $requirements = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $requirements[] = $row;
            }
        }
        
        return $requirements;
    }
}

if (!function_exists('getRequirementDetails')) {
    /**
     * Get detailed information about a requirement including files
     * 
     * @param $conn Database connection
     * @param int $submission_id Submission ID
     * @return array Requirement details with files
     */
    function getRequirementDetails($conn, $submission_id) {
        $submission_id = intval($submission_id);
        
        $stmt = $conn->prepare("SELECT * FROM Requirements_Submissions WHERE submission_id = ?");
        if (!$stmt) {
            return null;
        }
        
        $stmt->bind_param('i', $submission_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $requirement = $result->fetch_assoc();
        $stmt->close();
        
        if (!$requirement) {
            return null;
        }
        
        // Get files
        $files_result = $conn->query("SELECT * FROM Requirement_Files WHERE submission_id = " . $submission_id);
        $requirement['files'] = [];
        
        if ($files_result) {
            while ($file = $files_result->fetch_assoc()) {
                $requirement['files'][] = $file;
            }
        }
        
        return $requirement;
    }
}

if (!function_exists('updateRequirementStatus')) {
    /**
     * Update requirement status (admin only)
     * 
     * @param $conn Database connection
     * @param int $submission_id Submission ID
     * @param string $status New status
     * @param string $remarks Admin remarks
     * @return array ['success' => bool, 'error' => string]
     */
    function updateRequirementStatus($conn, $submission_id, $status, $remarks = '') {
        $submission_id = intval($submission_id);
        $status = trim($status);
        $remarks = trim($remarks);
        
        $valid_statuses = ['Approved', 'Rejected', 'Pending Review', 'Needs Revision'];
        
        if (!in_array($status, $valid_statuses)) {
            return ['success' => false, 'error' => 'Invalid status'];
        }
        
        $updated_at = date('Y-m-d H:i:s');
        
        $stmt = $conn->prepare(
            "UPDATE Requirements_Submissions SET status = ?, admin_remarks = ?, updated_at = ? WHERE submission_id = ?"
        );
        
        if (!$stmt) {
            return ['success' => false, 'error' => 'Database error'];
        }
        
        $stmt->bind_param('sssi', $status, $remarks, $updated_at, $submission_id);
        
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            return ['success' => false, 'error' => $error];
        }
        
        $stmt->close();
        
        // Get requirement to notify user
        $requirement = getRequirementDetails($conn, $submission_id);
        if ($requirement) {
            $user_id = $requirement['user_id'];
            $message = 'Your requirement submission status has been updated to: ' . $status;
            if (!empty($remarks)) {
                $message .= ' - ' . $remarks;
            }
            sendNotification($conn, $user_id, 'requirement_updated', 'Requirement Status Updated', $message, 'requirement', $submission_id);
        }
        
        // Audit log
        auditLog($conn, 'UPDATE_REQUIREMENT_STATUS', 'requirement', $submission_id, $status . ': ' . $remarks);
        
        return ['success' => true];
    }
}

if (!function_exists('replaceRequirementFile')) {
    /**
     * Replace a requirement file
     * 
     * @param $conn Database connection
     * @param int $submission_id Submission ID
     * @param int $file_id File ID to replace
     * @param array $new_file New file from $_FILES
     * @return array ['success' => bool, 'error' => string]
     */
    function replaceRequirementFile($conn, $submission_id, $file_id, $new_file) {
        $submission_id = intval($submission_id);
        $file_id = intval($file_id);
        
        // Get old file
        $stmt = $conn->prepare("SELECT * FROM Requirement_Files WHERE file_id = ? AND submission_id = ?");
        $stmt->bind_param('ii', $file_id, $submission_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $old_file = $result->fetch_assoc();
        $stmt->close();
        
        if (!$old_file) {
            return ['success' => false, 'error' => 'File not found'];
        }
        
        // Validate new file
        if ($new_file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'Upload error'];
        }
        
        $allowed_mime = ['application/pdf', 'image/jpeg', 'image/png'];
        $max_size = 5 * 1024 * 1024;
        
        $mime_type = mime_content_type($new_file['tmp_name']) ?: $new_file['type'];
        if (!in_array($mime_type, $allowed_mime)) {
            return ['success' => false, 'error' => 'Invalid file type'];
        }
        
        if (filesize($new_file['tmp_name']) > $max_size) {
            return ['success' => false, 'error' => 'File too large'];
        }
        
        // Delete old file
        $old_path = __DIR__ . '/../' . $old_file['file_path'];
        if (file_exists($old_path)) {
            unlink($old_path);
        }
        
        // Upload new file
        $upload_dir = dirname($old_path);
        $ext = pathinfo($new_file['name'], PATHINFO_EXTENSION);
        $safe_name = uniqid() . '.' . strtolower($ext);
        $dest_file = $upload_dir . '/' . $safe_name;
        
        if (!move_uploaded_file($new_file['tmp_name'], $dest_file)) {
            return ['success' => false, 'error' => 'Failed to upload file'];
        }
        
        // Update database
        $file_path = 'uploads/requirements/' . $submission_id . '/' . $safe_name;
        $file_size = filesize($dest_file);
        $uploaded_at = date('Y-m-d H:i:s');
        
        $stmt = $conn->prepare(
            "UPDATE Requirement_Files SET file_path = ?, file_size = ?, mime_type = ?, uploaded_at = ? WHERE file_id = ?"
        );
        
        if (!$stmt) {
            return ['success' => false, 'error' => 'Database error'];
        }
        
        $stmt->bind_param('sissi', $file_path, $file_size, $mime_type, $uploaded_at, $file_id);
        
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            return ['success' => false, 'error' => $error];
        }
        
        $stmt->close();
        
        // Audit log
        auditLog($conn, 'REPLACE_REQUIREMENT_FILE', 'requirement_file', $file_id, 'Replaced file for requirement ' . $submission_id);
        
        return ['success' => true];
    }
}

if (!function_exists('canAccessRequirement')) {
    /**
     * Check if user can access a requirement
     * 
     * @param $conn Database connection
     * @param int $submission_id Submission ID
     * @param int $user_id User ID
     * @param string $user_role User role
     * @return bool
     */
    function canAccessRequirement($conn, $submission_id, $user_id, $user_role) {
        // Admins can access all
        if ($user_role === 'admin') {
            return true;
        }
        
        // Parishioners can only access their own
        $submission_id = intval($submission_id);
        $user_id = intval($user_id);
        
        $stmt = $conn->prepare("SELECT user_id FROM Requirements_Submissions WHERE submission_id = ?");
        $stmt->bind_param('i', $submission_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $submission = $result->fetch_assoc();
        $stmt->close();
        
        return $submission && $submission['user_id'] == $user_id;
    }
}
