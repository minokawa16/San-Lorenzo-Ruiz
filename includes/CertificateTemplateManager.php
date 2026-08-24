<?php
/**
 * Certificate template file management for uploaded PDF/image certificate designs.
 */

if (!defined('CERTIFICATE_TEMPLATE_UPLOAD_DIR')) {
    define('CERTIFICATE_TEMPLATE_UPLOAD_DIR', dirname(__DIR__) . '/uploads/certificate_templates');
}
if (!defined('CERTIFICATE_LAYOUT_ASSET_DIR')) {
    define('CERTIFICATE_LAYOUT_ASSET_DIR', dirname(__DIR__) . '/uploads/certificate_layout_assets');
}

require_once __DIR__ . '/schema.php';

function certificateTemplateTypes() {
    return [
        'baptism' => 'Baptism Certificate',
        'confirmation' => 'Confirmation Certificate',
        'communion' => 'First Communion Certificate',
        'marriage' => 'Marriage Certificate',
        'baptism_certification' => 'Baptismal Certification',
        'first_communion_certification' => 'First Communion Certification',
        'confirmation_certification' => 'Confirmation Certification',
        'marriage_certification' => 'Marriage Certification',
        'funeral_certification' => 'Funeral Certification',
        'other' => 'Other Parish Certificate',
    ];
}

function certificateTemplateTypeLabel($type) {
    $types = certificateTemplateTypes();
    return $types[$type] ?? ucwords(str_replace('_', ' ', (string) $type));
}

function normalizeCertificateTemplateType($type, $custom_type = '') {
    $type = trim((string) $type);
    if ($type === 'custom') {
        $type = trim((string) $custom_type);
    }
    $type = strtolower($type);
    $type = preg_replace('/[^a-z0-9_ -]/', '', $type);
    $type = preg_replace('/[\s-]+/', '_', $type);
    return substr($type, 0, 80);
}

function ensureCertificateTemplateSchema($conn) {
    $schemaReady = requireSchemaColumns($conn, 'certificate_file_templates', [
        'template_id', 'template_name', 'certificate_type', 'file_original_name',
        'file_stored_name', 'file_path', 'mime_type', 'file_size', 'description',
        'version', 'is_active', 'status', 'created_by', 'updated_by', 'created_at', 'updated_at'
    ], 'certificate templates')
        && requireSchemaColumns($conn, 'certificate_issuances', [
            'certificate_id', 'certificate_type', 'record_table', 'record_id',
            'template_id', 'layout_snapshot', 'certificate_number', 'verification_code'
        ], 'certificate issuances')
        && requireSchemaColumns($conn, 'certificate_layouts', [
            'layout_id', 'certificate_type', 'layout_name', 'layout_settings',
            'created_by', 'updated_by', 'created_at', 'updated_at'
        ], 'certificate layouts');

    if (!is_dir(CERTIFICATE_TEMPLATE_UPLOAD_DIR)) {
        @mkdir(CERTIFICATE_TEMPLATE_UPLOAD_DIR, 0755, true);
    }
    if (!is_dir(CERTIFICATE_LAYOUT_ASSET_DIR)) {
        @mkdir(CERTIFICATE_LAYOUT_ASSET_DIR, 0755, true);
    }

    return $schemaReady;
}

function defaultCertificateLayoutSettings($certificate_type) {
    return [
        'static_text' => [
            'church_title' => 'ROMAN CATHOLIC CHURCH',
            'diocese_name' => 'ARCHDIOCESE OF COTABATO',
            'parish_name' => 'SAN LORENZO RUIZ MISSION STATION',
            'parish_address' => 'ALEOSAN, COTABATO',
            'certificate_title' => strtoupper(certificateTemplateTypeLabel($certificate_type)),
            'certificate_subtitle' => 'Issued from the Official Parish Records',
            'body_text' => 'This certificate is issued according to the official records of this parish.',
            'footer_text' => 'Unauthorized alteration invalidates this certificate.',
            'bible_verse' => '',
            'official_remarks' => '',
            'priest_name' => 'REV. FR. ROGELIO C. CAALIM, OMJ',
            'priest_position' => 'Parish Priest',
            'secretary_name' => 'PARISH SECRETARY',
            'secretary_position' => 'Parish Secretary',
            'other_official_name' => '',
            'other_official_position' => '',
            'watermark_text' => 'OFFICIAL PARISH DOCUMENT',
        ],
        'typography' => [
            'font_family' => 'Times New Roman',
            'font_size' => 8.5,
            'font_color' => '#151515',
            'font_weight' => '700',
            'bold' => true,
            'italic' => false,
            'underline' => false,
            'text_align' => 'center',
            'letter_spacing' => 0,
            'line_height' => 1.2,
        ],
        'border' => [
            'visible' => true,
            'style' => 'double',
            'thickness' => 2,
            'color' => '#111111',
            'decorative_corners' => true,
        ],
        'images' => [
            'parish_logo' => '',
            'diocese_logo' => '',
            'official_seal' => '',
            'watermark' => '',
            'priest_signature' => '',
            'secretary_signature' => '',
        ],
        'elements' => [
            'diocese_logo' => ['x' => 10, 'y' => 8, 'w' => 15, 'h' => 15, 'rotate' => 0, 'opacity' => 1],
            'parish_logo' => ['x' => 127, 'y' => 8, 'w' => 15, 'h' => 15, 'rotate' => 0, 'opacity' => 1],
            'church_info' => ['x' => 30, 'y' => 8, 'w' => 92, 'h' => 24, 'rotate' => 0, 'opacity' => 1],
            'certificate_title' => ['x' => 32, 'y' => 31, 'w' => 88, 'h' => 10, 'rotate' => 0, 'opacity' => 1],
            'body_text' => ['x' => 24, 'y' => 54, 'w' => 105, 'h' => 38, 'rotate' => 0, 'opacity' => 1],
            'watermark' => ['x' => 26, 'y' => 62, 'w' => 100, 'h' => 84, 'rotate' => 0, 'opacity' => .12],
            'official_seal' => ['x' => 18, 'y' => 171, 'w' => 22, 'h' => 22, 'rotate' => 0, 'opacity' => 1],
            'priest_signature' => ['x' => 48, 'y' => 177, 'w' => 38, 'h' => 16, 'rotate' => 0, 'opacity' => 1],
            'secretary_signature' => ['x' => 92, 'y' => 177, 'w' => 38, 'h' => 16, 'rotate' => 0, 'opacity' => 1],
            'footer' => ['x' => 6, 'y' => 218, 'w' => 140, 'h' => 7, 'rotate' => 0, 'opacity' => 1],
            'qr_code' => ['x' => 130, 'y' => 204, 'w' => 15, 'h' => 15, 'rotate' => 0, 'opacity' => 1],
        ],
    ];
}

function mergeCertificateLayoutSettings($settings, $defaults) {
    foreach ($defaults as $key => $value) {
        if (!array_key_exists($key, $settings)) {
            $settings[$key] = $value;
            continue;
        }
        if (is_array($value) && is_array($settings[$key])) {
            $settings[$key] = mergeCertificateLayoutSettings($settings[$key], $value);
        }
    }
    return $settings;
}

function getCertificateLayout($conn, $certificate_type) {
    ensureCertificateTemplateSchema($conn);
    $certificate_type = normalizeCertificateTemplateType($certificate_type);
    $defaults = defaultCertificateLayoutSettings($certificate_type);
    $stmt = $conn->prepare("SELECT * FROM certificate_layouts WHERE certificate_type = ? LIMIT 1");
    if (!$stmt) {
        return ['layout_id' => 0, 'certificate_type' => $certificate_type, 'layout_name' => certificateTemplateTypeLabel($certificate_type), 'settings' => $defaults];
    }
    $stmt->bind_param('s', $certificate_type);
    $stmt->execute();
    $layout = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$layout) {
        return ['layout_id' => 0, 'certificate_type' => $certificate_type, 'layout_name' => certificateTemplateTypeLabel($certificate_type), 'settings' => $defaults];
    }
    $settings = json_decode($layout['layout_settings'], true);
    if (!is_array($settings)) {
        $settings = [];
    }
    $layout['settings'] = mergeCertificateLayoutSettings($settings, $defaults);
    return $layout;
}

function saveCertificateLayout($conn, $certificate_type, $settings, $user_id = null) {
    ensureCertificateTemplateSchema($conn);
    $certificate_type = normalizeCertificateTemplateType($certificate_type);
    $defaults = defaultCertificateLayoutSettings($certificate_type);
    $settings = mergeCertificateLayoutSettings(is_array($settings) ? $settings : [], $defaults);
    $json = json_encode($settings, JSON_UNESCAPED_SLASHES);
    $layout_name = certificateTemplateTypeLabel($certificate_type);
    $uid = intval($user_id ?? 0);

    $existing = getCertificateLayout($conn, $certificate_type);
    if (!empty($existing['layout_id'])) {
        $stmt = $conn->prepare("UPDATE certificate_layouts SET layout_name = ?, layout_settings = ?, updated_by = ? WHERE certificate_type = ?");
        $stmt->bind_param('ssis', $layout_name, $json, $uid, $certificate_type);
    } else {
        $stmt = $conn->prepare("INSERT INTO certificate_layouts (certificate_type, layout_name, layout_settings, created_by, updated_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('sssii', $certificate_type, $layout_name, $json, $uid, $uid);
    }
    if (!$stmt) {
        return false;
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function certificateLayoutAllowedMimes() {
    return ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
}

function saveCertificateLayoutAsset($file, $certificate_type, $asset_key) {
    if (!isset($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => certificateTemplateUploadError($file['error'] ?? UPLOAD_ERR_NO_FILE)];
    }
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'File exceeds maximum size.'];
    }
    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $allowed = certificateLayoutAllowedMimes();
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Invalid file format.'];
    }
    if (!is_dir(CERTIFICATE_LAYOUT_ASSET_DIR) && !@mkdir(CERTIFICATE_LAYOUT_ASSET_DIR, 0755, true)) {
        return ['ok' => false, 'error' => 'Failed to upload image.'];
    }
    $safe_type = normalizeCertificateTemplateType($certificate_type);
    $safe_asset = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $asset_key));
    $stored_name = $safe_type . '-' . $safe_asset . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $destination = CERTIFICATE_LAYOUT_ASSET_DIR . '/' . $stored_name;
    if (!move_uploaded_file($tmp, $destination)) {
        return ['ok' => false, 'error' => 'Failed to upload image.'];
    }
    return ['ok' => true, 'path' => 'uploads/certificate_layout_assets/' . $stored_name, 'mime_type' => $mime];
}

function certificateLayoutAssetUrl($relative_path, $download = false) {
    $relative_path = trim((string) $relative_path);
    if ($relative_path === '') {
        return '';
    }
    $asset = basename(str_replace('\\', '/', $relative_path));
    return '../certificate-layout-asset.php?asset=' . rawurlencode($asset) . ($download ? '&download=1' : '');
}

function certificateTemplateAllowedMimes() {
    return [
        'application/pdf' => 'pdf',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
    ];
}

function certificateTemplateUploadError($code) {
    $messages = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds maximum size.',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds maximum size.',
        UPLOAD_ERR_PARTIAL => 'Upload failed.',
        UPLOAD_ERR_NO_FILE => 'Please choose a template file.',
        UPLOAD_ERR_NO_TMP_DIR => 'Upload failed.',
        UPLOAD_ERR_CANT_WRITE => 'Upload failed.',
        UPLOAD_ERR_EXTENSION => 'Upload failed.',
    ];
    return $messages[$code] ?? 'Upload failed.';
}

function saveCertificateTemplateUpload($conn, $file, $certificate_type) {
    if (!isset($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => certificateTemplateUploadError($file['error'] ?? UPLOAD_ERR_NO_FILE)];
    }

    $max_bytes = 10 * 1024 * 1024;
    if (($file['size'] ?? 0) > $max_bytes) {
        return ['ok' => false, 'error' => 'File exceeds maximum size.'];
    }

    $original_name = basename((string) ($file['name'] ?? 'template'));
    $safe_original = preg_replace('/[^A-Za-z0-9._-]/', '-', $original_name);
    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $allowed = certificateTemplateAllowedMimes();
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Invalid file format.'];
    }

    $duplicate_stmt = $conn->prepare("SELECT template_id FROM certificate_file_templates WHERE certificate_type = ? AND file_original_name = ? AND status <> 'deleted' LIMIT 1");
    if ($duplicate_stmt) {
        $duplicate_stmt->bind_param('ss', $certificate_type, $original_name);
        $duplicate_stmt->execute();
        $duplicate = $duplicate_stmt->get_result()->fetch_assoc();
        $duplicate_stmt->close();
        if ($duplicate) {
            return ['ok' => false, 'error' => 'Template already exists. Rename the file or replace the existing template.'];
        }
    }

    if (!is_dir(CERTIFICATE_TEMPLATE_UPLOAD_DIR) && !@mkdir(CERTIFICATE_TEMPLATE_UPLOAD_DIR, 0755, true)) {
        return ['ok' => false, 'error' => 'Upload failed.'];
    }

    $stored_name = date('YmdHis') . '-' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    $destination = CERTIFICATE_TEMPLATE_UPLOAD_DIR . '/' . $stored_name;
    if (!move_uploaded_file($tmp, $destination)) {
        return ['ok' => false, 'error' => 'Upload failed.'];
    }

    return [
        'ok' => true,
        'original_name' => $original_name,
        'safe_original' => $safe_original,
        'stored_name' => $stored_name,
        'file_path' => 'uploads/certificate_templates/' . $stored_name,
        'mime_type' => $mime,
        'file_size' => intval($file['size'] ?? filesize($destination)),
    ];
}

function getActiveCertificateTemplate($conn, $certificate_type) {
    ensureCertificateTemplateSchema($conn);
    $stmt = $conn->prepare("SELECT * FROM certificate_file_templates WHERE certificate_type = ? AND is_active = 1 AND status = 'available' ORDER BY updated_at DESC, template_id DESC LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $certificate_type);
    $stmt->execute();
    $template = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $template ?: null;
}

function getCertificateTemplateById($conn, $template_id) {
    ensureCertificateTemplateSchema($conn);
    $template_id = intval($template_id);
    if ($template_id <= 0) {
        return null;
    }
    $stmt = $conn->prepare("SELECT * FROM certificate_file_templates WHERE template_id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $template_id);
    $stmt->execute();
    $template = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $template ?: null;
}

function activateCertificateTemplate($conn, $template_id, $user_id = null) {
    $template = getCertificateTemplateById($conn, $template_id);
    if (!$template || $template['status'] !== 'available') {
        return false;
    }
    $type = $template['certificate_type'];
    $conn->begin_transaction();
    $stmt = $conn->prepare("UPDATE certificate_file_templates SET is_active = 0, updated_by = ? WHERE certificate_type = ?");
    $uid = intval($user_id ?? 0);
    $stmt->bind_param('is', $uid, $type);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("UPDATE certificate_file_templates SET is_active = 1, status = 'available', updated_by = ? WHERE template_id = ?");
    $stmt->bind_param('ii', $uid, $template_id);
    $ok = $stmt->execute();
    $stmt->close();
    $ok ? $conn->commit() : $conn->rollback();
    return $ok;
}

function templateIsUsedByIssuedCertificate($conn, $template_id) {
    $template_id = intval($template_id);
    $stmt = $conn->prepare("SELECT certificate_id FROM certificate_issuances WHERE template_id = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $template_id);
    $stmt->execute();
    $used = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $used;
}
?>
