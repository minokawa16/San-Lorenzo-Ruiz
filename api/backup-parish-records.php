<?php
/**
 * POST /api/backup-parish-records
 * 
 * Generates and streams a fresh binary ZIP archive of CSV spreadsheets 
 * for requested parish record types directly from the database.
 * 
 * Excludes all password and credential columns server-side for privacy and security.
 */

// Handle preflight OPTIONS request if needed
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
    http_response_code(204);
    exit;
}

// Ensure error details don't corrupt binary output
@ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method Not Allowed. Please send a POST request with the record types to export.'
    ]);
    exit;
}

// Parse request types from JSON body or POST form data
$types = [];
$raw_input = file_get_contents('php://input');

if (!empty($raw_input)) {
    $json_data = json_decode($raw_input, true);
    if (is_array($json_data) && isset($json_data['types']) && is_array($json_data['types'])) {
        $types = $json_data['types'];
    }
}

if (empty($types) && isset($_POST['types'])) {
    $types = is_array($_POST['types']) ? $_POST['types'] : explode(',', (string) $_POST['types']);
}

// Normalize type keys
$types = array_filter(array_map('trim', $types));

if (empty($types)) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Please select at least one record type to include in the backup.'
    ]);
    exit;
}

// Map alias keys if sent (e.g. 'sacramental' => 'sacramental_records')
$normalized_types = [];
foreach ($types as $t) {
    if ($t === 'sacramental' || $t === 'sacramental_records') {
        $normalized_types[] = 'sacramental_records';
    } elseif ($t === 'parishioners') {
        $normalized_types[] = 'parishioners';
    } elseif ($t === 'requests') {
        $normalized_types[] = 'requests';
    }
}
$normalized_types = array_unique($normalized_types);

if (empty($normalized_types)) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'No valid record types selected. Supported types: sacramental_records, parishioners, requests.'
    ]);
    exit;
}

// Sensitive columns strictly excluded from exports
$sensitive_columns = [
    'password', 'salt', 'remember_token', 'token', 'reset_token',
    'otp_code', 'otp_expiry', 'otp_verified', 'auth_token',
    'two_factor_secret', 'two_factor_recovery_codes', 'failed_login_attempts',
    'lockout_time', 'email_verification_token', 'verification_token',
    'id_number_hash', 'id_number_encrypted'
];

/**
 * Helper to export a database query to CSV string with UTF-8 BOM
 */
function queryToCsv($conn, $query, array $excluded_cols = [], array $custom_headers = []) {
    $res = $conn->query($query);
    if (!$res) {
        throw new Exception("Query error: " . $conn->error);
    }

    $fp = fopen('php://temp', 'r+');
    if (!$fp) {
        throw new Exception("Failed to open temporary buffer");
    }

    // Write UTF-8 BOM for seamless Microsoft Excel and LibreOffice support
    fwrite($fp, "\xEF\xBB\xBF");

    $fields = [];
    $field_info = $res->fetch_fields();
    foreach ($field_info as $info) {
        $col_name = $info->name;
        if (!in_array(strtolower($col_name), $excluded_cols, true)) {
            $fields[] = $col_name;
        }
    }

    // Headers
    $headers = [];
    foreach ($fields as $col) {
        if (isset($custom_headers[$col])) {
            $headers[] = $custom_headers[$col];
        } else {
            $headers[] = ucwords(str_replace('_', ' ', $col));
        }
    }
    fputcsv($fp, $headers);

    // Rows
    while ($row = $res->fetch_assoc()) {
        $clean_row = [];
        foreach ($fields as $col) {
            $clean_row[] = $row[$col] ?? '';
        }
        fputcsv($fp, $clean_row);
    }

    rewind($fp);
    $csv_content = stream_get_contents($fp);
    fclose($fp);

    return $csv_content;
}

try {
    $csv_files = [];

    // 1. SACRAMENTAL RECORDS
    if (in_array('sacramental_records', $normalized_types, true)) {
        // Baptism Registers
        $check = $conn->query("SHOW TABLES LIKE 'baptism_records'");
        if ($check && $check->num_rows > 0) {
            $csv_files['Sacramental_Baptism_Registers.csv'] = queryToCsv(
                $conn,
                "SELECT * FROM baptism_records ORDER BY baptism_id DESC",
                $sensitive_columns
            );
        }

        // Confirmation Registers
        $check = $conn->query("SHOW TABLES LIKE 'confirmation_records'");
        if ($check && $check->num_rows > 0) {
            $csv_files['Sacramental_Confirmation_Registers.csv'] = queryToCsv(
                $conn,
                "SELECT * FROM confirmation_records ORDER BY confirmation_id DESC",
                $sensitive_columns
            );
        }

        // Marriage Registers
        $check = $conn->query("SHOW TABLES LIKE 'marriage_records'");
        if ($check && $check->num_rows > 0) {
            $csv_files['Sacramental_Marriage_Registers.csv'] = queryToCsv(
                $conn,
                "SELECT * FROM marriage_records ORDER BY marriage_id DESC",
                $sensitive_columns
            );
        }

        // First Communion Registers
        $check = $conn->query("SHOW TABLES LIKE 'first_communion_records'");
        if ($check && $check->num_rows > 0) {
            $csv_files['Sacramental_First_Communion_Registers.csv'] = queryToCsv(
                $conn,
                "SELECT * FROM first_communion_records ORDER BY communion_id DESC",
                $sensitive_columns
            );
        }

        // Funeral / Burial Registers (if table exists)
        $check = $conn->query("SHOW TABLES LIKE 'funeral_records'");
        if ($check && $check->num_rows > 0) {
            $csv_files['Sacramental_Funeral_Burial_Registers.csv'] = queryToCsv(
                $conn,
                "SELECT * FROM funeral_records ORDER BY funeral_id DESC",
                $sensitive_columns
            );
        }
    }

    // 2. PARISHIONERS DIRECTORY
    if (in_array('parishioners', $normalized_types, true)) {
        $check = $conn->query("SHOW TABLES LIKE 'users'");
        if ($check && $check->num_rows > 0) {
            $csv_files['Parishioners_Directory.csv'] = queryToCsv(
                $conn,
                "SELECT id, fullname, first_name, surname, middle_initial, phone_number, email, 
                        verification_method, chapel_district, address, birthdate, role, status, 
                        face_verification_status, verified_at, created_at, updated_at 
                 FROM users 
                 ORDER BY id ASC",
                $sensitive_columns
            );
        }
    }

    // 3. REQUESTS (Certificates, Blessings, Sacramental Services)
    if (in_array('requests', $normalized_types, true)) {
        $check = $conn->query("SHOW TABLES LIKE 'requests'");
        if ($check && $check->num_rows > 0) {
            $csv_files['Parish_Requests.csv'] = queryToCsv(
                $conn,
                "SELECT r.request_id, r.reference_number, r.user_id, u.fullname AS requested_by, 
                        u.email AS requester_email, u.phone_number AS requester_phone,
                        r.request_type, r.description, r.status, r.admin_response, 
                        r.date_requested, r.updated_at
                 FROM requests r
                 LEFT JOIN users u ON r.user_id = u.id
                 ORDER BY r.request_id DESC",
                $sensitive_columns
            );
        }
    }

    if (empty($csv_files)) {
        throw new Exception("No records found in database for selected record types.");
    }

    // Create temporary zip archive
    $zip_filename = 'parish-backup-' . date('Y-m-d') . '.zip';
    $temp_zip = tempnam(sys_get_temp_dir(), 'tugon_bak_');
    
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($temp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception("Unable to create ZIP archive.");
        }

        foreach ($csv_files as $fname => $content) {
            $zip->addFromString($fname, $content);
        }

        // Add backup manifest summary
        $manifest = [
            'backup_name' => 'Tugon Parish Records Backup',
            'exported_at' => date('c'),
            'record_types' => $normalized_types,
            'files_included' => array_keys($csv_files),
            'security' => 'All passwords, authentication tokens, and credentials strictly omitted server-side.'
        ];
        $zip->addFromString('backup-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $zip->close();
    } else {
        throw new Exception("ZipArchive PHP extension is not available.");
    }

    $filesize = filesize($temp_zip);
    if ($filesize === false || $filesize === 0) {
        throw new Exception("Generated ZIP archive is empty.");
    }

    // Clear any previous output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Stream binary ZIP file response
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
    header('Content-Length: ' . $filesize);
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $handle = fopen($temp_zip, 'rb');
    if ($handle) {
        while (!feof($handle)) {
            echo fread($handle, 1024 * 1024);
            flush();
        }
        fclose($handle);
    } else {
        readfile($temp_zip);
    }

    @unlink($temp_zip);
    exit;

} catch (Exception $e) {
    if (isset($temp_zip) && file_exists($temp_zip)) {
        @unlink($temp_zip);
    }
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Backup export failed: ' . $e->getMessage()
    ]);
    exit;
}
