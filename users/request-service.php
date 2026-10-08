<?php
/**
 * Service Request Module - Handles reservation and parish service request submissions.
 */
header("Cache-Control: private, no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

include '../includes/session.php';
include '../config/security.php';
include '../database/config.php';
include '../includes/helpers.php';

requireLogin();
if (!hasPermission('requests.create')) {
    redirect('../auth/login.php');
}

$page_title = 'Sacramental Services';
$user_id = intval($_SESSION['user_id']);
$error = '';
$success = '';
ensureExpandedRequestTypeSchema($conn);
ensureRequestDocumentsSchema($conn);
ensureEmailNotificationSchema($conn);

$user_stmt = $conn->prepare("SELECT status, address, street_address, barangay, city, province FROM users WHERE id = ? LIMIT 1");
$user_profile = null;
$prefill_domicile = '';
if ($user_stmt) {
    $user_stmt->bind_param('i', $user_id);
    $user_stmt->execute();
    $user_profile = $user_stmt->get_result()->fetch_assoc();
    $user_stmt->close();
}
if (!empty($user_profile)) {
    $domicile_parts = array_filter([
        !empty($user_profile['barangay']) ? $user_profile['barangay'] : (!empty($user_profile['street_address']) ? $user_profile['street_address'] : ''),
        $user_profile['city'] ?? '',
        $user_profile['province'] ?? ''
    ]);
    if (!empty($domicile_parts)) {
        $prefill_domicile = implode(', ', $domicile_parts);
    } elseif (!empty($user_profile['address'])) {
        $prefill_domicile = $user_profile['address'];
    }
}

$service_types = [
    'baptism_service' => 'Baptism',
    'first_communion_service' => 'First Communion',
    'confirmation_service' => 'Confirmation',
    'marriage_wedding_service' => 'Marriage / Wedding',
    'funeral_mass' => 'Funeral Mass',
    'anointing_of_the_sick' => 'Anointing of the Sick',
    'patronal_fiesta' => 'Patronal Fiesta'
];
$service_meta = [
    'baptism_service' => ['icon' => 'fa-water', 'hint' => 'Schedule a baptism service with parish coordination.'],
    'first_communion_service' => ['icon' => 'fa-bread-slice', 'hint' => 'Request First Communion service scheduling.'],
    'confirmation_service' => ['icon' => 'fa-dove', 'hint' => 'Request Holy Confirmation service scheduling.'],
    'marriage_wedding_service' => ['icon' => 'fa-ring', 'hint' => 'Request wedding or marriage service scheduling.'],
    'funeral_mass' => ['icon' => 'fa-cross', 'hint' => 'Coordinate funeral Mass details with the parish.'],
    'anointing_of_the_sick' => ['icon' => 'fa-hand-holding-medical', 'hint' => 'Request pastoral care and anointing schedule.'],
    'patronal_fiesta' => ['icon' => 'fa-church', 'hint' => 'Submit Patronal Fiesta details for parish review.']
];
$baptism_requirements = function_exists('getParishBaptismRequirements') ? getParishBaptismRequirements() : [
    'live_birth_certificate' => 'Photocopy of Live Birth Certificate with Official Registry Number (PSA)',
    'marriage_certificate_photocopy' => 'Photocopy of Marriage Certificate (if married)',
    'chapel_recommendation' => 'Chapel Recommendation',
    'parent_white_cards' => 'White Cards of Parents',
    'sponsor_white_cards' => 'Two (2) White Cards of Sponsors (Ninong and Ninang)',
    'pre_baptism_seminar' => 'Pre-Baptismal Seminar attendance'
];
$baptism_sheet_fields = [
    'child_name' => 'Name of Child',
    'birth_date' => 'Date of Birth',
    'birth_place' => 'Place of Birth',
    'father_name' => "Father's Complete Name",
    'father_origin' => "Father's Place of Origin / Residence",
    'mother_name' => "Mother's Complete Maiden Name",
    'mother_origin' => "Mother's Place of Origin / Residence",
    'parents_marriage' => "Parents' Marriage Status",
    'sponsor_male_name' => 'Principal Male Sponsor (Ninong)',
    'sponsor_male_origin' => 'Ninong Place of Origin / Residence',
    'sponsor_female_name' => 'Principal Female Sponsor (Ninang)',
    'sponsor_female_origin' => 'Ninang Place of Origin / Residence',
    'godparents' => 'Additional Sponsors',
    'baptism_date' => 'Date of Baptism'
];

$marriage_sheet_fields = [
    'groom_name' => "Groom's Full Name",
    'groom_birth_date' => "Groom's Date of Birth",
    'groom_birth_place' => "Groom's Place of Birth",
    'groom_residence' => "Groom's Place of Origin / Residence",
    'groom_religion' => "Groom's Religion / Church of Baptism",
    'groom_father_name' => "Groom's Father's Complete Name",
    'groom_mother_name' => "Groom's Mother's Maiden Name",
    'bride_name' => "Bride's Full Maiden Name",
    'bride_birth_date' => "Bride's Date of Birth",
    'bride_birth_place' => "Bride's Place of Birth",
    'bride_residence' => "Bride's Place of Origin / Residence",
    'bride_religion' => "Bride's Religion / Church of Baptism",
    'bride_father_name' => "Bride's Father's Complete Name",
    'bride_mother_name' => "Bride's Mother's Maiden Name",
    'witness_male' => "Male Principal Sponsor (Ninong)",
    'witness_female' => "Female Principal Sponsor (Ninang)",
    'additional_sponsors' => "Additional Sponsors / Entourage",
    'wedding_date' => "Date of Marriage / Wedding"
];
$marriage_requirements = function_exists('getParishMarriageRequirements') ? getParishMarriageRequirements() : [
    'pre_cana' => ['label' => 'Pre-Cana', 'mandatory' => true],
    'municipal_license' => ['label' => 'Municipal License', 'mandatory' => true],
    'bec_recommendation' => ['label' => 'BEC Recommendation', 'mandatory' => true],
    'baptismal_certificate_marriage_purpose' => ['label' => 'Baptismal Certificate for Marriage Purpose', 'mandatory' => true],
    'confirmation_certificate' => ['label' => 'Confirmation Certificate', 'mandatory' => true],
    'permit_to_marry' => ['label' => 'Permit to Marry', 'mandatory' => true],
    'co_permit_police_army' => ['label' => 'CO Permit (Police / Army)', 'mandatory' => false, 'badge' => 'Optional / If Applicable']
];
$funeral_requirements = [
    'death_certificate' => ['label' => 'Death Certificate', 'mandatory' => true]
];
$funeral_sheet_fields = [
    'deceased_name'  => 'Deceased Full Name',
    'date_of_death'  => 'Date of Death',
    'date_of_burial' => 'Date of Burial / Funeral Mass',
    'civil_status'   => 'Civil Status of Deceased',
    'funeral_rites'  => 'Type of Funeral Rites',
    'cause_of_death' => 'Cause of Death',
    'place_of_burial'=> 'Place of Burial / Cemetery',
    'minister'       => 'Minister / Officiant Name',
];
$status_meta = [
    'pending' => ['icon' => 'fa-hourglass-half', 'description' => 'Waiting for parish review', 'tone' => 'warning'],
    'approved' => ['icon' => 'fa-circle-check', 'description' => 'Approved by the office', 'tone' => 'success'],
    'processing' => ['icon' => 'fa-gears', 'description' => 'Being coordinated', 'tone' => 'primary'],
    'completed' => ['icon' => 'fa-file-circle-check', 'description' => 'Service completed', 'tone' => 'info'],
    'rejected' => ['icon' => 'fa-circle-xmark', 'description' => 'Needs correction', 'tone' => 'danger'],
    'cancelled' => ['icon' => 'fa-ban', 'description' => 'Cancelled request', 'tone' => 'secondary'],
];
$service_type_keys = array_keys($service_types);
$allowed_statuses = ['pending', 'approved', 'processing', 'completed', 'rejected', 'cancelled'];

$breadcrumbs = [
    'Dashboard' => 'index.php',
    'Sacramental Services' => null
];

// Service Label Function - Documents this helper's role in the parish management workflow.
function serviceLabel($value, $labels = []) {
    return $labels[$value] ?? ucfirst(str_replace('_', ' ', (string) $value));
}

function serviceValidDate($value) {
    $date = DateTime::createFromFormat('!Y-m-d', (string) $value);
    $errors = DateTime::getLastErrors();
    return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
}

function serviceRequirementUploadPlan($request_type, $baptism_requirements, $marriage_requirements, $funeral_requirements) {
    $plan = [];
    if ($request_type === 'baptism_service') {
        foreach ($baptism_requirements as $key => $meta) {
            $label = is_array($meta) ? $meta['label'] : $meta;
            $mandatory = is_array($meta) ? ($meta['mandatory'] ?? true) : true;
            $plan[] = ['keys' => [$key], 'label' => $label, 'mandatory' => $mandatory];
        }
    }
    if ($request_type === 'marriage_wedding_service') {
        foreach (['male' => 'Male', 'female' => 'Female'] as $side_key => $side_label) {
            foreach ($marriage_requirements as $key => $meta) {
                $label = is_array($meta) ? $meta['label'] : $meta;
                $mandatory = is_array($meta) ? ($meta['mandatory'] ?? true) : true;
                $plan[] = ['keys' => [$side_key, $key], 'label' => $side_label . ' - ' . $label, 'mandatory' => $mandatory];
            }
        }
    }
    if ($request_type === 'funeral_mass') {
        foreach ($funeral_requirements as $key => $meta) {
            $label = is_array($meta) ? $meta['label'] : $meta;
            $mandatory = is_array($meta) ? ($meta['mandatory'] ?? true) : true;
            $plan[] = ['keys' => [$key], 'label' => $label, 'mandatory' => $mandatory];
        }
    }
    return $plan;
}

function serviceRequirementFileAt($files, $keys) {
    if (empty($files) || !is_array($files)) {
        return null;
    }

    $single_file = [];
    foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $field) {
        $value = $files[$field] ?? null;
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        $single_file[$field] = $value;
    }

    return $single_file;
}

function serviceRequirementFileUploaded($files, $keys) {
    $file = serviceRequirementFileAt($files, $keys);
    return $file && trim((string) ($file['name'] ?? '')) !== '' && intval($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
}

function missingServiceRequirementUploads($files, $plan) {
    $missing = [];
    foreach ($plan as $item) {
        if (!empty($item['mandatory']) && !serviceRequirementFileUploaded($files, $item['keys'])) {
            $missing[] = $item['label'];
        }
    }
    return $missing;
}

function saveServiceRequirementUploads($conn, $request_id, $uploaded_by, $files, $plan) {
    $results = ['ok' => true, 'saved' => 0, 'documents' => [], 'errors' => []];
    foreach ($plan as $item) {
        $file = serviceRequirementFileAt($files, $item['keys']);
        if (!$file || trim((string) ($file['name'] ?? '')) === '') {
            continue;
        }
        $document = saveRequestDocument($conn, $request_id, $uploaded_by, $file, 'requirement', $item['label']);
        if ($document['ok'] && !empty($document['saved'])) {
            $results['saved']++;
            $results['documents'][] = $document['document_id'];
        } else {
            $results['errors'][] = $item['label'] . ': ' . ($document['error'] ?? 'Unknown error');
        }
    }

    if ($results['saved'] === 0 && !empty($results['errors'])) {
        $results['ok'] = false;
        $results['error'] = 'No files were uploaded successfully. ' . implode(', ', $results['errors']);
    } elseif (!empty($results['errors'])) {
        $results['ok'] = false;
        $results['error'] = 'Some files were not uploaded. ' . implode(', ', $results['errors']);
    }

    return $results;
}

/**
 * Server-side validation for service requirements documents.
 * Validates extension, finfo MIME type, magic bytes, file size (<= 5 MB), and image integrity.
 */
function validateServiceRequirementFile(array $file, int $maxBytes = 5242880): array {
    if (!isset($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Please upload the required certificate.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'File upload error occurred. Please try again.'];
    }
    if (empty($file['tmp_name']) || (!is_uploaded_file($file['tmp_name']) && php_sapi_name() !== 'cli')) {
        return ['ok' => false, 'error' => 'Invalid file upload.'];
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size < 1) {
        return ['ok' => false, 'error' => 'Uploaded file is empty.'];
    }
    if ($size > $maxBytes) {
        return ['ok' => false, 'error' => 'File exceeds maximum allowed size of 5 MB.'];
    }
    $origName = basename((string) ($file['name'] ?? ''));
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowed_exts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
    if (!in_array($ext, $allowed_exts, true)) {
        return ['ok' => false, 'error' => 'Only JPG, PNG, WEBP, and PDF files up to 5 MB are allowed.'];
    }

    $tmpPath = (string) $file['tmp_name'];
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $mime = $finfo ? (string) finfo_file($finfo, $tmpPath) : (string) mime_content_type($tmpPath);
    if ($finfo) {
        finfo_close($finfo);
    }
    $mime = strtolower(trim($mime));
    $allowed_mimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowed_mimes, true)) {
        return ['ok' => false, 'error' => 'File format rejected. Only valid JPG, PNG, WEBP, or PDF files are accepted.'];
    }

    $handle = @fopen($tmpPath, 'rb');
    if (!$handle) {
        return ['ok' => false, 'error' => 'Unable to read uploaded file.'];
    }
    $header = fread($handle, 16);
    fclose($handle);

    if ($header === false || strlen($header) < 4) {
        return ['ok' => false, 'error' => 'Corrupt or unreadable file.'];
    }

    $valid = false;
    if (str_starts_with($header, '%PDF-')) {
        $valid = ($ext === 'pdf') && ($mime === 'application/pdf');
    } elseif (str_starts_with($header, "\xFF\xD8\xFF")) {
        $valid = in_array($ext, ['jpg', 'jpeg'], true) && ($mime === 'image/jpeg');
    } elseif (str_starts_with($header, "\x89PNG") || str_starts_with($header, "\x89\x50\x4E\x47")) {
        $valid = ($ext === 'png') && ($mime === 'image/png');
    } elseif (strlen($header) >= 12 && substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WEBP') {
        $valid = ($ext === 'webp') && ($mime === 'image/webp');
    }

    if (!$valid) {
        return ['ok' => false, 'error' => 'File content does not match its file extension.'];
    }

    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        $imgInfo = @getimagesize($tmpPath);
        if ($imgInfo === false || empty($imgInfo[0]) || empty($imgInfo[1])) {
            return ['ok' => false, 'error' => 'File is not a valid image.'];
        }
    }

    return [
        'ok' => true,
        'size' => $size,
        'mime' => $mime,
        'extension' => $ext,
        'original_name' => $origName
    ];
}

/**
 * Upload rate limit per user session (max 30 uploads per 10 minutes) to prevent abuse.
 */
function checkUserUploadRateLimit($user_id): bool {
    if (!isset($_SESSION['upload_attempts'])) {
        $_SESSION['upload_attempts'] = [];
    }
    $now = time();
    $_SESSION['upload_attempts'] = array_filter($_SESSION['upload_attempts'], function($ts) use ($now) {
        return ($now - $ts) < 600;
    });
    if (count($_SESSION['upload_attempts']) >= 30) {
        return false;
    }
    $_SESSION['upload_attempts'][] = $now;
    return true;
}

$is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos(strtolower($_SERVER['HTTP_ACCEPT']), 'application/json') !== false)
    || (isset($_POST['is_ajax']) && $_POST['is_ajax'] === '1');

$respond = function($ok, $message, $extra = []) use ($is_ajax, &$error, &$success) {
    if ($is_ajax) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        $status_code = isset($extra['status_code']) ? intval($extra['status_code']) : ($ok ? 200 : 400);
        http_response_code($status_code);
        $payload = array_merge([
            'success' => (bool) $ok,
            'message' => (string) $message
        ], $extra);
        unset($payload['status_code']);
        echo json_encode($payload);
        exit;
    }
    if ($ok) {
        $success = $message;
    } else {
        $error = $message;
    }
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        requireValidCsrfToken();

        $request_type = trim((string) ($_POST['request_type'] ?? ''));
        $type_aliases = [
            'confirmation' => 'confirmation_service',
            'matrimony' => 'marriage_wedding_service',
            'marriage' => 'marriage_wedding_service',
            'wedding' => 'marriage_wedding_service',
            'eucharist' => 'first_communion_service',
            'first_communion' => 'first_communion_service',
            'baptism' => 'baptism_service',
            'anointing' => 'anointing_of_the_sick'
        ];
        if (isset($type_aliases[$request_type])) {
            $request_type = $type_aliases[$request_type];
        }

        $preferred_date = trim((string) ($_POST['preferred_date'] ?? ''));
        $preferred_time = trim((string) ($_POST['preferred_time'] ?? ''));
        $patronal_fiesta_date = trim((string) ($_POST['patronal_fiesta_date'] ?? ''));
        $service_date = trim((string) ($_POST['service_date'] ?? ''));
        $location = trim((string) ($_POST['location'] ?? ''));
        $details = trim((string) ($_POST['details'] ?? ''));
        $requirement_upload_plan = serviceRequirementUploadPlan($request_type, $baptism_requirements, $marriage_requirements, $funeral_requirements);
        $requirement_upload_files = $request_type === 'baptism_service'
            ? ($_FILES['baptism_requirement_files'] ?? null)
            : ($request_type === 'marriage_wedding_service'
                ? ($_FILES['marriage_requirement_files'] ?? null)
                : ($request_type === 'funeral_mass' ? ($_FILES['funeral_requirement_files'] ?? null) : null));
        $missing_requirement_uploads = missingServiceRequirementUploads($requirement_upload_files, $requirement_upload_plan);

        // Extract Pre-Baptismal Sheet
        $baptism_sheet = [];
        $raw_baptism = (isset($_POST['baptism_sheet']) && is_array($_POST['baptism_sheet'])) ? $_POST['baptism_sheet'] : [];
        foreach ($baptism_sheet_fields as $field_key => $field_label) {
            $baptism_sheet[$field_key] = trim((string) ($raw_baptism[$field_key] ?? ''));
        }

        // Extract Pre-Nuptial / Marriage Sheet
        $marriage_sheet = [];
        $raw_marriage = (isset($_POST['marriage_sheet']) && is_array($_POST['marriage_sheet'])) ? $_POST['marriage_sheet'] : [];
        foreach ($marriage_sheet_fields as $field_key => $field_label) {
            $marriage_sheet[$field_key] = trim((string) ($raw_marriage[$field_key] ?? ''));
        }

        // Extract Funeral Investigation Sheet
        $funeral_sheet = [];
        $raw_funeral = (isset($_POST['funeral_sheet']) && is_array($_POST['funeral_sheet'])) ? $_POST['funeral_sheet'] : [];
        foreach ($funeral_sheet_fields as $field_key => $field_label) {
            $funeral_sheet[$field_key] = trim((string) ($raw_funeral[$field_key] ?? ''));
        }

        // Single source of truth: Bind schedule date automatically from investigation sheets
        if ($request_type === 'baptism_service') {
            $preferred_date = $baptism_sheet['baptism_date'] ?? '';
        } elseif ($request_type === 'marriage_wedding_service') {
            $preferred_date = $marriage_sheet['wedding_date'] ?? '';
        } elseif ($request_type === 'funeral_mass') {
            $preferred_date = $funeral_sheet['date_of_burial'] ?? '';
        } elseif ($request_type === 'patronal_fiesta' && $patronal_fiesta_date !== '') {
            $preferred_date = $patronal_fiesta_date;
        } elseif ($service_date !== '') {
            $preferred_date = $service_date;
        }

        // Validate required fields for Baptism Sheet
        $missing_baptism_sheet = [];
        if ($request_type === 'baptism_service') {
            $required_baptism_keys = [
                'child_name', 'birth_date', 'birth_place',
                'father_name', 'father_origin',
                'mother_name', 'mother_origin',
                'parents_marriage',
                'sponsor_male_name', 'sponsor_female_name',
                'baptism_date'
            ];
            foreach ($required_baptism_keys as $k) {
                if (empty($baptism_sheet[$k])) {
                    $missing_baptism_sheet[] = $baptism_sheet_fields[$k] ?? $k;
                }
            }
        }

        // Validate required fields for Marriage Sheet
        $missing_marriage_sheet = [];
        if ($request_type === 'marriage_wedding_service') {
            $required_marriage_keys = [
                'groom_name', 'groom_birth_date', 'groom_birth_place', 'groom_residence', 'groom_religion', 'groom_father_name', 'groom_mother_name',
                'bride_name', 'bride_birth_date', 'bride_birth_place', 'bride_residence', 'bride_religion', 'bride_father_name', 'bride_mother_name',
                'witness_male', 'witness_female',
                'wedding_date'
            ];
            foreach ($required_marriage_keys as $k) {
                if (empty($marriage_sheet[$k])) {
                    $missing_marriage_sheet[] = $marriage_sheet_fields[$k] ?? $k;
                }
            }
        }

        // Validate required fields for Funeral Sheet
        $missing_funeral_sheet = [];
        if ($request_type === 'funeral_mass') {
            $required_funeral_keys = ['deceased_name', 'date_of_death', 'date_of_burial', 'civil_status', 'funeral_rites', 'cause_of_death', 'place_of_burial'];
            foreach ($required_funeral_keys as $k) {
                if (empty($funeral_sheet[$k])) {
                    $missing_funeral_sheet[] = $funeral_sheet_fields[$k] ?? $k;
                }
            }
        }

        $cleanName = function($name) {
            $trimmed = preg_replace('/\s+/', ' ', trim((string)$name));
            return mb_strtoupper($trimmed, 'UTF-8');
        };
        $validNamePattern = '/^[a-zA-Z\s\.\'\-ñÑ\x{00C0}-\x{017F}]+$/u';

        if (empty($user_profile) || ($user_profile['status'] ?? '') !== 'active') {
            $respond(false, 'Only verified parishioners with active accounts can submit service requests.', ['status_code' => 403]);
        }

        if (!array_key_exists($request_type, $service_types)) {
            $respond(false, 'Please choose a sacramental service.', ['status_code' => 422]);
        } elseif ($request_type === 'first_communion_service') {
            $comm_name = trim((string) ($_POST['communion_communicant_name'] ?? ''));
            $comm_domicile = trim((string) ($_POST['communion_domicile'] ?? ''));
            $comm_father = trim((string) ($_POST['communion_father_name'] ?? ''));
            $comm_mother = trim((string) ($_POST['communion_mother_name'] ?? ''));
            $comm_bap_date = trim((string) ($_POST['communion_baptismal_date'] ?? ''));
            $comm_bap_place = trim((string) ($_POST['communion_baptismal_place'] ?? ''));

            if ($comm_name === '') {
                $respond(false, 'Name of Communicant is required.', ['status_code' => 422]);
            }
            if (!preg_match($validNamePattern, $comm_name)) {
                $respond(false, 'Name of Communicant may only contain letters, spaces, periods, hyphens, apostrophes, and the letter ñ.', ['status_code' => 422]);
            }
            $comm_name_upper = $cleanName($comm_name);

            if ($comm_domicile === '') {
                $respond(false, 'Domicile is required.', ['status_code' => 422]);
            }

            if ($comm_father === '') {
                $respond(false, 'Father\'s Full Name is required.', ['status_code' => 422]);
            }
            if (!preg_match($validNamePattern, $comm_father)) {
                $respond(false, 'Father\'s name may only contain letters, spaces, periods, hyphens, apostrophes, and the letter ñ.', ['status_code' => 422]);
            }

            if ($comm_mother === '') {
                $respond(false, 'Mother\'s Full Maiden Name is required.', ['status_code' => 422]);
            }
            if (!preg_match($validNamePattern, $comm_mother)) {
                $respond(false, 'Mother\'s maiden name may only contain letters, spaces, periods, hyphens, apostrophes, and the letter ñ.', ['status_code' => 422]);
            }
            $father_upper = $cleanName($comm_father);
            $mother_upper = $cleanName($comm_mother);
            $parents_str = $father_upper . ' / ' . $mother_upper;

            $today_manila = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
            if ($comm_bap_date === '' || !serviceValidDate($comm_bap_date)) {
                $respond(false, 'Please provide a valid Baptismal Date.', ['status_code' => 422]);
            }
            if ($comm_bap_date > $today_manila) {
                $respond(false, 'Baptismal Date cannot be in the future. Please provide a valid past date.', ['status_code' => 422]);
            }

            if ($comm_bap_place === '') {
                $respond(false, 'Baptismal Place is required.', ['status_code' => 422]);
            }

            $comm_location = $location !== '' ? $location : 'San Lorenzo Ruiz Parish Church';

            $description_parts = [
                'Location: ' . $comm_location,
                'Service: First Communion',
                "\n--- FIRST COMMUNION APPLICATION ---",
                'Name of Communicant: ' . $comm_name_upper,
                'Domicile: ' . $comm_domicile,
                'Father: ' . $father_upper,
                'Mother: ' . $mother_upper,
                'Parents: ' . $parents_str,
                'Baptismal Date: ' . $comm_bap_date,
                'Baptismal Place: ' . $comm_bap_place,
                'Details: ' . ($details !== '' ? $details : 'None')
            ];

            $description = implode("\n", $description_parts);
            $reference_number = generateReferenceNumber();
            $status = 'pending';

            // Server-side requirement validation for First Communion (Baptismal Certificate required)
            if (!checkUserUploadRateLimit($user_id)) {
                $respond(false, 'Too many upload attempts. Please wait a few minutes before trying again.', ['status_code' => 429]);
            }
            if (!isset($_FILES['baptismal_certificate']) || ($_FILES['baptismal_certificate']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $respond(false, 'Baptismal Certificate is required for First Communion. Please upload a clear photo or scan.', ['status_code' => 422]);
            }
            $bapValidation = validateServiceRequirementFile($_FILES['baptismal_certificate']);
            if (!$bapValidation['ok']) {
                $respond(false, 'Baptismal Certificate error: ' . $bapValidation['error'], ['status_code' => 422]);
            }

            $stmt = $conn->prepare("INSERT INTO requests (user_id, request_type, record_holder_name, description, status, reference_number) VALUES (?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception("Unable to prepare your First Communion request: " . $conn->error);
            }
            $stmt->bind_param('isssss', $user_id, $request_type, $comm_name_upper, $description, $status, $reference_number);
            if (!$stmt->execute()) {
                $exec_err = $stmt->error;
                $stmt->close();
                throw new Exception("Error executing First Communion request: " . $exec_err);
            }
            $request_id = $conn->insert_id;
            $stmt->close();

            $docResult = saveRequestDocument($conn, $request_id, $user_id, $_FILES['baptismal_certificate'], 'requirement', 'Baptismal Certificate');
            $doc_count = ($docResult['ok'] && !empty($docResult['saved'])) ? 1 : 0;

            createAuditLog($conn, $user_id, 'CREATE_REQUEST', 'requests', $request_id);
            createNotification($conn, $user_id, 'First Communion Request Created', 'Your First Communion request has been submitted with reference: ' . $reference_number . '.', true, 'requests', 'request', $request_id, 'request.view');
            $success_msg = 'First Communion request submitted successfully! Reference: ' . $reference_number . '.';
            $respond(true, $success_msg, [
                'reference_number' => $reference_number,
                'request_id' => $request_id,
                'doc_count' => $doc_count,
                'redirect_url' => 'my-requests.php?q=' . urlencode($reference_number)
            ]);
        } elseif ($request_type === 'confirmation_service') {
            $conf_name = trim((string) ($_POST['confirmation_fullname'] ?? ''));
            $conf_age = trim((string) ($_POST['confirmation_age'] ?? ''));
            $conf_origin_parish = trim((string) ($_POST['confirmation_origin_parish'] ?? ''));
            $conf_province = trim((string) ($_POST['confirmation_province'] ?? 'Cotabato'));
            $conf_bap_place = trim((string) ($_POST['confirmation_baptismal_place'] ?? ''));
            $conf_father = trim((string) ($_POST['confirmation_father_name'] ?? ''));
            $conf_mother = trim((string) ($_POST['confirmation_mother_name'] ?? ''));
            $conf_sponsor = trim((string) ($_POST['confirmation_sponsor'] ?? ''));

            if ($conf_name === '') {
                $respond(false, 'Name of Confirmed Person is required.', ['status_code' => 422]);
            }
            if (!preg_match($validNamePattern, $conf_name)) {
                $respond(false, 'Name of Confirmed Person may only contain letters, spaces, periods, hyphens, apostrophes, and the letter ñ.', ['status_code' => 422]);
            }
            $conf_name_upper = $cleanName($conf_name);

            if (!ctype_digit($conf_age) || intval($conf_age) < 7 || intval($conf_age) > 120) {
                $respond(false, 'Age must be a whole number between 7 and 120.', ['status_code' => 422]);
            }

            if ($conf_origin_parish === '') {
                $respond(false, 'Parish of Origin is required.', ['status_code' => 422]);
            }

            if ($conf_province === '') {
                $conf_province = 'Cotabato';
            }

            if ($conf_bap_place === '') {
                $respond(false, 'Place of Baptism is required.', ['status_code' => 422]);
            }

            if ($conf_father === '') {
                $respond(false, 'Father\'s Full Name is required.', ['status_code' => 422]);
            }
            if (!preg_match($validNamePattern, $conf_father)) {
                $respond(false, 'Father\'s name may only contain letters, spaces, periods, hyphens, apostrophes, and the letter ñ.', ['status_code' => 422]);
            }

            if ($conf_mother === '') {
                $respond(false, 'Mother\'s Full Maiden Name is required.', ['status_code' => 422]);
            }
            if (!preg_match($validNamePattern, $conf_mother)) {
                $respond(false, 'Mother\'s maiden name may only contain letters, spaces, periods, hyphens, apostrophes, and the letter ñ.', ['status_code' => 422]);
            }
            $father_upper = $cleanName($conf_father);
            $mother_upper = $cleanName($conf_mother);
            $parents_str = $father_upper . ' / ' . $mother_upper;

            if ($conf_sponsor === '') {
                $respond(false, 'Sponsor / Godparent full name is required.', ['status_code' => 422]);
            }
            if (!preg_match($validNamePattern, $conf_sponsor)) {
                $respond(false, 'Sponsor / Godparent name may only contain letters, spaces, periods, hyphens, apostrophes, and the letter ñ.', ['status_code' => 422]);
            }
            $conf_sponsor_upper = $cleanName($conf_sponsor);

            $conf_location = $location !== '' ? $location : 'San Lorenzo Ruiz Parish Church';

            $description_parts = [
                'Location: ' . $conf_location,
                'Service: Confirmation',
                "\n--- CONFIRMATION APPLICATION ---",
                'Name of Confirmed Person: ' . $conf_name_upper,
                'Age: ' . $conf_age,
                'Parish of Origin: ' . $conf_origin_parish,
                'Province: ' . $conf_province,
                'Place of Baptism: ' . $conf_bap_place,
                'Father: ' . $father_upper,
                'Mother: ' . $mother_upper,
                'Parents: ' . $parents_str,
                'Sponsor / Godparent: ' . $conf_sponsor_upper,
                'Details: ' . ($details !== '' ? $details : 'None')
            ];

            $description = implode("\n", $description_parts);
            $reference_number = generateReferenceNumber();
            $status = 'pending';

            // Server-side requirement validation for Confirmation (both Baptismal & First Communion Certificates required)
            if (!checkUserUploadRateLimit($user_id)) {
                $respond(false, 'Too many upload attempts. Please wait a few minutes before trying again.', ['status_code' => 429]);
            }
            if (!isset($_FILES['baptismal_certificate']) || ($_FILES['baptismal_certificate']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $respond(false, 'Baptismal Certificate is required for Confirmation. Please upload a clear photo or scan.', ['status_code' => 422]);
            }
            $bapValidation = validateServiceRequirementFile($_FILES['baptismal_certificate']);
            if (!$bapValidation['ok']) {
                $respond(false, 'Baptismal Certificate error: ' . $bapValidation['error'], ['status_code' => 422]);
            }

            if (!isset($_FILES['first_communion_certificate']) || ($_FILES['first_communion_certificate']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $respond(false, 'First Communion Certificate is required for Confirmation. Please upload a clear photo or scan.', ['status_code' => 422]);
            }
            $commValidation = validateServiceRequirementFile($_FILES['first_communion_certificate']);
            if (!$commValidation['ok']) {
                $respond(false, 'First Communion Certificate error: ' . $commValidation['error'], ['status_code' => 422]);
            }

            $stmt = $conn->prepare("INSERT INTO requests (user_id, request_type, record_holder_name, description, status, reference_number) VALUES (?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception("Unable to prepare your Confirmation request: " . $conn->error);
            }
            $stmt->bind_param('isssss', $user_id, $request_type, $conf_name_upper, $description, $status, $reference_number);
            if (!$stmt->execute()) {
                $exec_err = $stmt->error;
                $stmt->close();
                throw new Exception("Error executing Confirmation request: " . $exec_err);
            }
            $request_id = $conn->insert_id;
            $stmt->close();

            $doc1 = saveRequestDocument($conn, $request_id, $user_id, $_FILES['baptismal_certificate'], 'requirement', 'Baptismal Certificate');
            $doc2 = saveRequestDocument($conn, $request_id, $user_id, $_FILES['first_communion_certificate'], 'requirement', 'First Communion Certificate');
            $doc_count = (($doc1['ok'] && !empty($doc1['saved'])) ? 1 : 0) + (($doc2['ok'] && !empty($doc2['saved'])) ? 1 : 0);

            createAuditLog($conn, $user_id, 'CREATE_REQUEST', 'requests', $request_id);
            createNotification($conn, $user_id, 'Confirmation Request Created', 'Your Confirmation request has been submitted with reference: ' . $reference_number . '.', true, 'requests', 'request', $request_id, 'request.view');
            $success_msg = 'Confirmation request submitted successfully! Reference: ' . $reference_number . '.';
            $respond(true, $success_msg, [
                'reference_number' => $reference_number,
                'request_id' => $request_id,
                'doc_count' => $doc_count,
                'redirect_url' => 'my-requests.php?q=' . urlencode($reference_number)
            ]);
        } elseif ($request_type === 'funeral_mass' && !empty($missing_funeral_sheet)) {
            $respond(false, 'Please complete the Funeral Investigation Sheet before submitting. Missing: ' . implode(', ', array_slice($missing_funeral_sheet, 0, 4)) . (count($missing_funeral_sheet) > 4 ? ', and more.' : '.'), ['status_code' => 422]);
        } elseif ($request_type === 'funeral_mass' && !serviceValidDate($funeral_sheet['date_of_death'])) {
            $respond(false, 'Please provide a valid Date of Death.', ['status_code' => 422]);
        } elseif ($request_type === 'funeral_mass' && !serviceValidDate($funeral_sheet['date_of_burial'])) {
            $respond(false, 'Please provide a valid Date of Burial / Funeral Mass.', ['status_code' => 422]);
        } elseif ($request_type === 'baptism_service' && !empty($missing_baptism_sheet)) {
            $respond(false, 'Please complete the Pre-Baptismal Investigation Sheet before requesting Baptism. Missing: ' . implode(', ', array_slice($missing_baptism_sheet, 0, 4)) . (count($missing_baptism_sheet) > 4 ? ', and more.' : '.'), ['status_code' => 422]);
        } elseif ($request_type === 'baptism_service' && !serviceValidDate($baptism_sheet['birth_date'])) {
            $respond(false, 'Please provide a valid date of birth for the child.', ['status_code' => 422]);
        } elseif ($request_type === 'baptism_service' && !serviceValidDate($baptism_sheet['baptism_date'])) {
            $respond(false, 'Please provide a valid date of Baptism.', ['status_code' => 422]);
        } elseif ($request_type === 'marriage_wedding_service' && !empty($missing_marriage_sheet)) {
            $respond(false, 'Please complete the Pre-Nuptial / Marriage Investigation Sheet before requesting Marriage. Missing: ' . implode(', ', array_slice($missing_marriage_sheet, 0, 4)) . (count($missing_marriage_sheet) > 4 ? ', and more.' : '.'), ['status_code' => 422]);
        } elseif ($request_type === 'marriage_wedding_service' && !serviceValidDate($marriage_sheet['groom_birth_date'])) {
            $respond(false, 'Please provide a valid date of birth for the groom.', ['status_code' => 422]);
        } elseif ($request_type === 'marriage_wedding_service' && !serviceValidDate($marriage_sheet['bride_birth_date'])) {
            $respond(false, 'Please provide a valid date of birth for the bride.', ['status_code' => 422]);
        } elseif ($request_type === 'marriage_wedding_service' && !serviceValidDate($marriage_sheet['wedding_date'])) {
            $respond(false, 'Please provide a valid date of Marriage.', ['status_code' => 422]);
        } elseif (in_array($request_type, ['baptism_service', 'marriage_wedding_service', 'funeral_mass'], true) && !empty($missing_requirement_uploads)) {
            $respond(false, 'Please upload a file for each requirement. Missing: ' . implode(', ', array_slice($missing_requirement_uploads, 0, 4)) . (count($missing_requirement_uploads) > 4 ? ', and more.' : '.'), ['status_code' => 422]);
        } elseif (!empty($requirement_upload_files) && ($fileGroupVal = validateUploadedDocumentGroup($requirement_upload_files)) && !$fileGroupVal['ok']) {
            $respond(false, $fileGroupVal['error'], ['status_code' => 422]);
        } elseif ($request_type === 'patronal_fiesta' && $patronal_fiesta_date === '') {
            $respond(false, 'Please choose the date of the Patronal Fiesta.', ['status_code' => 422]);
        } elseif ($preferred_date === '') {
            $respond(false, 'Please choose a scheduled service date.', ['status_code' => 422]);
        } elseif ($preferred_time === '') {
            $respond(false, 'Please choose a preferred time.', ['status_code' => 422]);
        } elseif ($location === '') {
            $respond(false, 'Please provide the service location.', ['status_code' => 422]);
        } else {
            $normPrefTime = ScheduleConflictService::normalizeTime($preferred_time);
            $allowNonHourly = defined('ALLOW_NON_HOURLY_SLOTS') ? (bool) ALLOW_NON_HOURLY_SLOTS : false;
            if (!$allowNonHourly) {
                $timeParts = explode(':', $normPrefTime);
                if (!isset($timeParts[1]) || $timeParts[1] !== '00') {
                    $respond(false, 'Schedule time must be on the hour (e.g. 09:00, 10:00). Half-hour or custom minute slots are not permitted.', ['status_code' => 422]);
                }
            }

            if (ScheduleConflictService::isPastDateTime($preferred_date, $normPrefTime)) {
                $respond(false, 'Cannot book a past date or time. Please choose an upcoming schedule.', ['status_code' => 422]);
            }

            // Concurrency Slot Lock & Transaction to prevent simultaneous race conditions
            $slotLockName = 'parish_schedule_booking_' . $preferred_date;
            $lockRes = $conn->query("SELECT GET_LOCK('" . $conn->real_escape_string($slotLockName) . "', 10)");
            $lockAcquired = $lockRes && ($lRow = $lockRes->fetch_row()) && ((int) $lRow[0] === 1);

            try {
                $conn->begin_transaction();

                // Double-booking check: Prevent creating schedule requests for already occupied slots
                $conflictCheck = checkScheduleConflict($conn, $preferred_date, $preferred_time, $location);
                if (!empty($conflictCheck['has_conflict'])) {
                    $conn->rollback();
                    if ($lockAcquired) {
                        $conn->query("SELECT RELEASE_LOCK('" . $conn->real_escape_string($slotLockName) . "')");
                    }
                    $respond(false, $conflictCheck['message'], [
                        'status_code' => 409,
                        'conflict' => true,
                        'conflict_details' => $conflictCheck['conflicting_schedule'] ?? [],
                        'conflicts' => $conflictCheck['conflicts'] ?? [],
                        'suggestions' => $conflictCheck['suggestions'] ?? []
                    ]);
                }

            $description_parts = [
                'Preferred date: ' . $preferred_date,
                'Preferred time: ' . $preferred_time,
                'Location: ' . $location,
            ];
            if ($request_type === 'patronal_fiesta') {
                $description_parts[] = 'Date of Patronal Fiesta: ' . $patronal_fiesta_date;
            } elseif ($request_type === 'confirmation_service') {
                $description_parts[] = 'Service: Holy Confirmation';
            } elseif ($request_type === 'first_communion_service') {
                $description_parts[] = 'Service: First Holy Communion / Eucharist';
            } elseif ($request_type === 'anointing_of_the_sick') {
                $description_parts[] = 'Service: Anointing of the Sick (Pastoral Care)';
            }
            if ($request_type === 'baptism_service') {
                $baptism_labels = [];
                foreach ($baptism_requirements as $b_req) {
                    $baptism_labels[] = is_array($b_req) ? ($b_req['label'] ?? '') : (string) $b_req;
                }
                $description_parts[] = 'Baptism requirement uploads: ' . implode(', ', array_filter($baptism_labels));
                $description_parts[] = "\n--- PRE-BAPTISMAL INVESTIGATION SHEET ---";
                $description_parts[] = "1. Child's Information:";
                $description_parts[] = "Name of Child: " . $baptism_sheet['child_name'];
                $description_parts[] = "Date of Birth: " . $baptism_sheet['birth_date'] . " | Place of Birth: " . $baptism_sheet['birth_place'];
                $description_parts[] = "\n2. Parents' Information:";
                $description_parts[] = "Father: " . $baptism_sheet['father_name'] . " (Origin/Residence: " . $baptism_sheet['father_origin'] . ")";
                $description_parts[] = "Mother: " . $baptism_sheet['mother_name'] . " (Origin/Residence: " . $baptism_sheet['mother_origin'] . ")";
                $description_parts[] = "Parents' Marriage Status: " . $baptism_sheet['parents_marriage'];
                $description_parts[] = "\n3. Sponsors (Godparents / Ninong & Ninang):";
                $description_parts[] = "Principal Male Sponsor (Ninong): " . $baptism_sheet['sponsor_male_name'] . (!empty($baptism_sheet['sponsor_male_origin']) ? " (" . $baptism_sheet['sponsor_male_origin'] . ")" : "");
                $description_parts[] = "Principal Female Sponsor (Ninang): " . $baptism_sheet['sponsor_female_name'] . (!empty($baptism_sheet['sponsor_female_origin']) ? " (" . $baptism_sheet['sponsor_female_origin'] . ")" : "");
                if (!empty($baptism_sheet['godparents'])) {
                    $description_parts[] = "Additional Sponsors: " . $baptism_sheet['godparents'];
                }
                $description_parts[] = "\n4. Proposed Baptism Schedule:";
                $description_parts[] = "Date of Baptism: " . $baptism_sheet['baptism_date'];
            }
            if ($request_type === 'marriage_wedding_service') {
                $description_parts[] = 'Marriage requirement uploads: Male and Female files submitted for each requirement.';
                $description_parts[] = "\n--- PRE-NUPTIAL / MARRIAGE INVESTIGATION SHEET ---";
                $description_parts[] = "1. Groom (Nobyo) Information:";
                $description_parts[] = "Full Name: " . $marriage_sheet['groom_name'];
                $description_parts[] = "Date of Birth: " . $marriage_sheet['groom_birth_date'] . " | Place of Birth: " . $marriage_sheet['groom_birth_place'];
                $description_parts[] = "Place of Origin / Current Residence: " . $marriage_sheet['groom_residence'];
                $description_parts[] = "Religion / Church of Baptism: " . $marriage_sheet['groom_religion'];
                $description_parts[] = "Father: " . $marriage_sheet['groom_father_name'] . " | Mother: " . $marriage_sheet['groom_mother_name'];
                $description_parts[] = "\n2. Bride (Nobya) Information:";
                $description_parts[] = "Full Maiden Name: " . $marriage_sheet['bride_name'];
                $description_parts[] = "Date of Birth: " . $marriage_sheet['bride_birth_date'] . " | Place of Birth: " . $marriage_sheet['bride_birth_place'];
                $description_parts[] = "Place of Origin / Current Residence: " . $marriage_sheet['bride_residence'];
                $description_parts[] = "Religion / Church of Baptism: " . $marriage_sheet['bride_religion'];
                $description_parts[] = "Father: " . $marriage_sheet['bride_father_name'] . " | Mother: " . $marriage_sheet['bride_mother_name'];
                $description_parts[] = "\n3. Principal Witnesses / Sponsors (Ninong & Ninang):";
                $description_parts[] = "Male Principal Sponsor: " . $marriage_sheet['witness_male'];
                $description_parts[] = "Female Principal Sponsor: " . $marriage_sheet['witness_female'];
                if (!empty($marriage_sheet['additional_sponsors'])) {
                    $description_parts[] = "Additional Sponsors / Entourage: " . $marriage_sheet['additional_sponsors'];
                }
                $description_parts[] = "\n4. Wedding Ceremony Schedule:";
                $description_parts[] = "Date of Marriage: " . $marriage_sheet['wedding_date'];
            }
            if ($request_type === 'funeral_mass') {
                $funeral_labels = [];
                foreach ($funeral_requirements as $f_req) {
                    $funeral_labels[] = is_array($f_req) ? ($f_req['label'] ?? '') : (string) $f_req;
                }
                $description_parts[] = 'Funeral Mass requirement uploads: ' . implode(', ', array_filter($funeral_labels));
                $description_parts[] = "\n--- FUNERAL INVESTIGATION SHEET ---";
                $description_parts[] = "Deceased Full Name: " . $funeral_sheet['deceased_name'];
                $description_parts[] = "Date of Death: " . $funeral_sheet['date_of_death'];
                $description_parts[] = "Date of Burial: " . $funeral_sheet['date_of_burial'];
                $description_parts[] = "Civil Status: " . $funeral_sheet['civil_status'];
                $description_parts[] = "Type of Funeral Rites: " . $funeral_sheet['funeral_rites'];
                $description_parts[] = "Cause of Death: " . $funeral_sheet['cause_of_death'];
                $description_parts[] = "Place of Burial: " . $funeral_sheet['place_of_burial'];
                if (!empty($funeral_sheet['minister'])) {
                    $description_parts[] = "Minister / Officiant Name: " . $funeral_sheet['minister'];
                }
            }
            $description_parts[] = 'Details: ' . ($details !== '' ? $details : 'None');

            $description = implode("\n", $description_parts);
            $reference_number = generateReferenceNumber();
            $status = 'pending';

            $stmt = $conn->prepare("INSERT INTO requests (user_id, request_type, description, status, reference_number) VALUES (?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception("Unable to prepare your sacramental service request: " . $conn->error);
            }

            $stmt->bind_param('issss', $user_id, $request_type, $description, $status, $reference_number);
            if (!$stmt->execute()) {
                $exec_err = $stmt->error;
                $stmt->close();
                throw new Exception("Error executing service request insert: " . $exec_err);
            }
            $request_id = $conn->insert_id;
            $stmt->close();

            // Record slot lock in schedule_slot_locks if table exists
            $slotEndTime = date('H:i:s', strtotime("2000-01-01 $normPrefTime:00 +" . ScheduleConflictService::SLOT_DURATION_MINUTES . " minutes"));
            @$conn->query("INSERT INTO schedule_slot_locks (slot_date, slot_time, slot_end_time, source_type, source_id) VALUES ('" . $conn->real_escape_string($preferred_date) . "', '" . $conn->real_escape_string($normPrefTime) . "', '" . $conn->real_escape_string($slotEndTime) . "', 'request', $request_id)");

            $conn->commit();
        } catch (Throwable $svcEx) {
            $conn->rollback();
            throw $svcEx;
        } finally {
            if ($lockAcquired) {
                $conn->query("SELECT RELEASE_LOCK('" . $conn->real_escape_string($slotLockName) . "')");
            }
        }

            $documents = saveServiceRequirementUploads($conn, $request_id, $user_id, $requirement_upload_files, $requirement_upload_plan);
            $doc_count = intval($documents['saved'] ?? 0);
            $file_text = $doc_count === 1 ? 'file' : 'files';

            if (!$documents['ok'] && empty($documents['saved']) && !empty($requirement_upload_plan)) {
                $error_msg = ($documents['error'] ?? 'File upload error') . ' Your request was recorded, but files could not be attached. Reference: ' . $reference_number;
                $respond(false, $error_msg, [
                    'status_code' => 500,
                    'reference_number' => $reference_number,
                    'request_id' => $request_id
                ]);
            } else {
                createAuditLog($conn, $user_id, 'CREATE_REQUEST', 'requests', $request_id);
                createNotification($conn, $user_id, 'Sacramental Service Request Created', 'Your service request has been submitted with reference: ' . $reference_number . ' (' . $doc_count . ' ' . $file_text . ' attached)', true, 'requests', 'request', $request_id, 'request.view');
                $success_msg = 'Sacramental service request submitted successfully! Reference: ' . $reference_number . ' (' . $doc_count . ' file' . ($doc_count === 1 ? '' : 's') . ' attached)';
                $respond(true, $success_msg, [
                    'reference_number' => $reference_number,
                    'request_id' => $request_id,
                    'doc_count' => $doc_count,
                    'redirect_url' => 'my-requests.php?q=' . urlencode($reference_number)
                ]);
            }
        }
    } catch (Throwable $e) {
        error_log("Sacramental Request Controller Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        $respond(false, 'Unable to complete sacramental service request: ' . $e->getMessage(), ['status_code' => 500]);
    }
}

$service_placeholders = implode(',', array_fill(0, count($service_type_keys), '?'));
$status_map = [
    'submitted' => 'pending',
    'pending' => 'pending',
    'requirements_review' => 'pending',
    'under_review' => 'pending',
    'needs_information' => 'pending',
    'payment_required' => 'pending',
    'payment_review' => 'pending',
    'approved' => 'approved',
    'processing' => 'processing',
    'scheduled' => 'processing',
    'ready_for_release' => 'processing',
    'completed' => 'completed',
    'rejected' => 'rejected',
    'cancelled' => 'cancelled',
];

$status_counts = array_fill_keys($allowed_statuses, 0);
$count_types = 'i' . str_repeat('s', count($service_type_keys));
$count_params = array_merge([$user_id], $service_type_keys);
$stmt = $conn->prepare("SELECT status, COUNT(*) AS count FROM requests WHERE user_id = ? AND request_type IN ($service_placeholders) GROUP BY status");
if ($stmt) {
    $stmt->bind_param($count_types, ...$count_params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $raw_status = strtolower(trim((string) $row['status']));
        $target_status = $status_map[$raw_status] ?? (isset($status_counts[$raw_status]) ? $raw_status : 'pending');
        if (isset($status_counts[$target_status])) {
            $status_counts[$target_status] += intval($row['count']);
        }
    }
    $stmt->close();
}
?>
<?php include '../templates/header.php'; ?>

<?php include '../includes/breadcrumb.php'; ?>
<?php include '../includes/back_button.php'; ?>
<link rel="stylesheet" href="../assets/css/request-modern.css?v=<?php echo filemtime('../assets/css/request-modern.css'); ?>">
<style>
.pds-input-icon-wrap {
    position: relative !important;
    display: block !important;
    width: 100% !important;
    box-sizing: border-box !important;
}
.pds-input-icon-wrap > i:first-child {
    position: absolute !important;
    left: 14px !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    width: 20px !important;
    height: 20px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    pointer-events: none !important;
    z-index: 5 !important;
    font-size: 15px !important;
    color: #64748b !important;
}
.pds-input-icon-wrap .form-control,
.pds-input-icon-wrap .form-select,
.pds-input-icon-wrap input,
.pds-input-icon-wrap select {
    display: block !important;
    width: 100% !important;
    min-height: 48px !important;
    height: 48px !important;
    padding-left: 44px !important;
    padding-right: 16px !important;
    box-sizing: border-box !important;
    line-height: 1.5 !important;
    font-size: 0.95rem !important;
}
.pds-input-icon-wrap .form-select {
    padding-right: 40px !important;
    background-position: right 14px center !important;
}
.text-uppercase {
    text-transform: uppercase !important;
}

/* Two-column aligned row with equal 44px height, top alignment, and icon centering */
.pds-aligned-row {
    display: grid !important;
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    align-items: start !important;
    gap: 14px !important;
}
@media (max-width: 640px) {
    .pds-aligned-row {
        grid-template-columns: 1fr !important;
    }
}
.pds-aligned-row .investigation-field {
    display: flex !important;
    flex-direction: column !important;
    justify-content: flex-start !important;
    margin: 0 !important;
    padding: 0 !important;
}
.pds-aligned-row label {
    display: block !important;
    margin-bottom: 6px !important;
    font-size: 0.9rem !important;
    font-weight: 600 !important;
    line-height: 1.3 !important;
    min-height: 20px !important;
}
.pds-aligned-row .pds-input-icon-wrap {
    position: relative !important;
    height: 44px !important;
    min-height: 44px !important;
    max-height: 44px !important;
    width: 100% !important;
    box-sizing: border-box !important;
}
.pds-aligned-row .pds-input-icon-wrap > i:first-child {
    position: absolute !important;
    left: 14px !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    font-size: 15px !important;
    line-height: 1 !important;
    z-index: 5 !important;
}
.pds-aligned-row .pds-input-icon-wrap .form-control,
.pds-aligned-row .pds-input-icon-wrap input {
    height: 44px !important;
    min-height: 44px !important;
    max-height: 44px !important;
    line-height: 44px !important;
    padding-top: 0 !important;
    padding-bottom: 0 !important;
    padding-left: 44px !important;
    padding-right: 14px !important;
    font-size: 0.92rem !important;
    box-sizing: border-box !important;
}

/* Requirements Upload Section for First Communion & Confirmation */
.req-dropzone {
    border: 2px dashed #cbd5e1;
    border-radius: 12px;
    background-color: #f8fafc;
    padding: 16px;
    transition: all 0.2s ease-in-out;
    outline: none;
}
.req-dropzone:hover, .req-dropzone.drag-over {
    border-color: #2563eb;
    background-color: #eff6ff;
}
.req-dropzone:focus-visible {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.25);
}
.req-dropzone.has-file {
    border-style: solid;
    border-color: #cbd5e1;
    background-color: #ffffff;
}
.req-dropzone.has-error {
    border-color: #dc2626;
    background-color: #fef2f2;
}
.req-btn-choose, .req-btn-camera, .req-btn-replace, .req-btn-remove {
    min-height: 44px;
    min-width: 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.submit-request-btn:disabled {
    opacity: 0.65;
    cursor: not-allowed !important;
}
</style>

<div class="container-fluid mt-4">
    <div class="request-modern-page">
        <section class="request-hero">
            <div class="request-hero-main">
                <span class="request-kicker"><i class="fas fa-church"></i> Sacramental Services</span>
                <h1>New Sacramental Service Request</h1>
                <p>Submit sacramental service schedules securely and efficiently. Add your preferred date, time, location, and supporting details so the parish office can coordinate the service.</p>
                <div class="request-badges">
                    <span><i class="fas fa-lock"></i> Secure Request Submission</span>
                    <span><i class="fas fa-bell"></i> Status Notifications</span>
                    <span><i class="fas fa-robot"></i> TUGON AI Assisted</span>
                </div>
            </div>
            <aside class="request-secure-note">
                <i class="fas fa-shield-halved"></i>
                <strong>Your request details are protected.</strong>
                <p>Uploaded requirements and service details are used only for parish scheduling, verification, and coordination.</p>
            </aside>
        </section>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <?php preg_match('/Reference:\s*([A-Z0-9-]+)/', $success, $reference_match); ?>
        <div class="alert alert-success alert-dismissible fade show">
            <div class="success-reference">
                <span><i class="fas fa-circle-check"></i> <?php echo e($success); ?> The parish office will review your service request.</span>
                <?php if (!empty($reference_match[1])): ?>
                    <a class="btn btn-sm btn-outline-success" href="my-requests.php?q=<?php echo urlencode($reference_match[1]); ?>">
                        <i class="fas fa-receipt"></i> Track <?php echo e($reference_match[1]); ?>
                    </a>
                <?php endif; ?>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php echo mobileStepRail(['Details', 'Requirements', 'Review'], 1, 'Sacramental service request progress'); ?>

    <div class="request-form-card">
        <div class="request-form-header">
            <div>
                <h2><i class="fas fa-file-signature"></i> Sacramental Service Request Form</h2>
                <p>Complete the sections below so the parish office can prepare and confirm your requested service.</p>
            </div>
            <span class="request-kicker"><i class="fas fa-clock"></i> Schedule review</span>
        </div>

        <form method="POST" action="" enctype="multipart/form-data" id="serviceRequestForm" data-modern-request-form novalidate>
            <?php echo csrfInput(); ?>
            <div class="request-validation-banner" id="serviceValidationBanner" role="alert" aria-live="polite" hidden>
                <i class="fas fa-triangle-exclamation"></i>
                <span>Please fill up the highlighted fields before continuing.</span>
            </div>

            <div id="serviceEntryPanel">
            <section class="request-step">
                <div class="step-heading">
                    <span class="step-number">1</span>
                    <div>
                        <h3>Service Information</h3>
                        <p>Select the sacramental service you are requesting.</p>
                    </div>
                </div>

                <div class="request-type-grid" role="radiogroup" aria-label="Sacramental service">
                    <?php foreach ($service_types as $value => $label): ?>
                        <?php $meta = $service_meta[$value] ?? ['icon' => 'fa-church', 'hint' => 'Sacramental service request']; ?>
                        <label class="request-type-option">
                            <input type="radio" name="request_type" value="<?php echo e($value); ?>" required>
                            <span>
                                <i class="fas <?php echo e($meta['icon']); ?>"></i>
                                <strong><?php echo e($label); ?></strong>
                                <small><?php echo e($meta['hint']); ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="mt-3">
                    <label for="requestSearchSelect" class="form-label">Searchable service selector</label>
                    <input class="form-control request-form-control" id="requestSearchSelect" list="requestTypeOptions" placeholder="Type to search sacramental service" autocomplete="off">
                    <datalist id="requestTypeOptions">
                        <?php foreach ($service_types as $value => $label): ?>
                            <option value="<?php echo e($label); ?>" data-value="<?php echo e($value); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="baptism-requirements-card" id="baptismRequirementsCard" hidden>
                    <div class="baptism-progress" aria-label="Baptism request progress">
                        <span class="active"><i class="fas fa-list-check"></i> Step 1: Review Requirements</span>
                        <span><i class="fas fa-pen-to-square"></i> Step 2: Fill Request Form</span>
                        <span><i class="fas fa-paper-plane"></i> Step 3: Submit Request</span>
                    </div>

                    <div class="baptism-requirements-header">
                        <div>
                            <span class="request-kicker"><i class="fas fa-water"></i> Requirements for Baptism</span>
                            <h3>Baptism Requirements</h3>
                            <p>Upload one clear supporting document for each requirement so the parish office can review every item separately.</p>
                            <small class="text-muted d-block mt-1">Accepted formats: PDF, JPG, PNG, WEBP (max 5 MB each).</small>
                        </div>
                        <div class="baptism-review-badge">
                            <i class="fas fa-clipboard-check"></i>
                            <strong>Office Review</strong>
                            <small>Incomplete documents may delay approval.</small>
                        </div>
                    </div>

                    <div class="baptism-requirements-grid">
                        <?php foreach ($baptism_requirements as $key => $label): ?>
                            <div class="baptism-requirement-item requirement-upload-item">
                                <span><i class="fas fa-file-arrow-up"></i></span>
                                <div class="requirement-upload-main">
                                    <strong><?php echo e($label); ?></strong>
                                    <small data-file-name>No file selected</small>
                                </div>
                                <div class="requirement-upload-actions">
                                    <label class="requirement-upload-btn">
                                        <i class="fas fa-folder-open"></i> <span data-upload-label>Choose File</span>
                                        <input type="file" class="requirement-file-input" name="baptism_requirement_files[<?php echo e($key); ?>]" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*" data-requirement-file data-requirement-group="baptism">
                                    </label>
                                    <a class="requirement-view-btn" href="#" target="_blank" rel="noopener" data-file-view hidden>
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="baptism-warning" id="baptismRequirementWarning">
                        <i class="fas fa-triangle-exclamation"></i>
                        <span>Upload one file for every Baptism requirement before submitting.</span>
                    </div>

                    <div class="investigation-sheet-card baptism-sheet-card" id="baptismSheetCard">
                        <div class="investigation-sheet-heading baptism-sheet-heading">
                            <span class="request-kicker"><i class="fas fa-file-lines"></i> Pre-Baptismal Investigation Sheet</span>
                            <h3>Fill Out This Form Before Requesting Baptism</h3>
                            <p>Enter the child, parent, and godparent details exactly as they should appear for parish canonical records.</p>
                        </div>

                        <!-- A. Child's Information -->
                        <div class="investigation-subcard">
                            <h4 class="investigation-subcard-title"><i class="fas fa-child"></i> 1. Child's Information</h4>
                            <div class="investigation-grid-full">
                                <label for="baptism_child_name">Full Name of Child <span class="text-danger">*</span></label>
                                <input type="text" class="form-control request-form-control" id="baptism_child_name" name="baptism_sheet[child_name]" data-baptism-sheet placeholder="Complete name of child (First, Middle, Surname)" required>
                            </div>
                            <div class="investigation-grid-2">
                                <div class="investigation-field">
                                    <label for="baptism_birth_date">Date of Birth <span class="text-danger">*</span></label>
                                    <input type="date" min="1900-01-01" max="<?php echo date('Y-m-d'); ?>" class="form-control request-form-control" id="baptism_birth_date" name="baptism_sheet[birth_date]" data-baptism-sheet required>
                                </div>
                                <div class="investigation-field">
                                    <label for="baptism_birth_place">Place of Birth <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control request-form-control" id="baptism_birth_place" name="baptism_sheet[birth_place]" data-baptism-sheet placeholder="Municipality / City, Province" required>
                                </div>
                            </div>
                        </div>

                        <!-- B. Parents' Information -->
                        <div class="investigation-subcard">
                            <h4 class="investigation-subcard-title"><i class="fas fa-people-roof"></i> 2. Parents' Information</h4>
                            <div class="investigation-grid-2">
                                <div class="investigation-field">
                                    <label for="baptism_father_name">Father's Complete Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control request-form-control" id="baptism_father_name" name="baptism_sheet[father_name]" data-baptism-sheet placeholder="Father's full name" required>
                                </div>
                                <div class="investigation-field">
                                    <label for="baptism_father_origin">Father's Place of Origin / Residence <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control request-form-control" id="baptism_father_origin" name="baptism_sheet[father_origin]" data-baptism-sheet placeholder="Municipality / Province / Residence" required>
                                </div>
                            </div>
                            <div class="investigation-grid-2">
                                <div class="investigation-field">
                                    <label for="baptism_mother_name">Mother's Complete Maiden Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control request-form-control" id="baptism_mother_name" name="baptism_sheet[mother_name]" data-baptism-sheet placeholder="First, Middle, Maiden Surname" required>
                                </div>
                                <div class="investigation-field">
                                    <label for="baptism_mother_origin">Mother's Place of Origin / Residence <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control request-form-control" id="baptism_mother_origin" name="baptism_sheet[mother_origin]" data-baptism-sheet placeholder="Municipality / Province / Residence" required>
                                </div>
                            </div>
                            <div class="investigation-grid-full">
                                <label for="baptism_parents_marriage">Parents' Marriage Status <span class="text-danger">*</span></label>
                                <select class="form-select request-form-control" id="baptism_parents_marriage" name="baptism_sheet[parents_marriage]" data-baptism-sheet required>
                                    <option value="">-- Select Marriage Status --</option>
                                    <option value="Catholic Church Marriage">Catholic Church Marriage</option>
                                    <option value="Civil Marriage">Civil Marriage</option>
                                    <option value="Not Married / Common-Law">Not Married / Common-Law</option>
                                    <option value="Other Christian / Religious Rite">Other Christian / Religious Rite</option>
                                </select>
                            </div>
                        </div>

                        <!-- C. Sponsors (Godparents / Ninong & Ninang) -->
                        <div class="investigation-subcard">
                            <h4 class="investigation-subcard-title"><i class="fas fa-hands-holding-child"></i> 3. Sponsors (Godparents / Ninong &amp; Ninang)</h4>
                            <div class="investigation-grid-2">
                                <div class="investigation-field">
                                    <label for="baptism_sponsor_male_name">Principal Male Sponsor (Ninong) <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control request-form-control" id="baptism_sponsor_male_name" name="baptism_sheet[sponsor_male_name]" data-baptism-sheet placeholder="Ninong full name" required>
                                </div>
                                <div class="investigation-field">
                                    <label for="baptism_sponsor_male_origin">Ninong Place of Origin / Residence</label>
                                    <input type="text" class="form-control request-form-control" id="baptism_sponsor_male_origin" name="baptism_sheet[sponsor_male_origin]" data-baptism-sheet placeholder="Municipality / Province">
                                </div>
                            </div>
                            <div class="investigation-grid-2">
                                <div class="investigation-field">
                                    <label for="baptism_sponsor_female_name">Principal Female Sponsor (Ninang) <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control request-form-control" id="baptism_sponsor_female_name" name="baptism_sheet[sponsor_female_name]" data-baptism-sheet placeholder="Ninang full name" required>
                                </div>
                                <div class="investigation-field">
                                    <label for="baptism_sponsor_female_origin">Ninang Place of Origin / Residence</label>
                                    <input type="text" class="form-control request-form-control" id="baptism_sponsor_female_origin" name="baptism_sheet[sponsor_female_origin]" data-baptism-sheet placeholder="Municipality / Province">
                                </div>
                            </div>
                            <div class="investigation-grid-full">
                                <label for="baptism_godparents">Additional Sponsors</label>
                                <textarea class="form-control request-form-control" id="baptism_godparents" name="baptism_sheet[godparents]" rows="2" data-baptism-sheet placeholder="List any additional godparents / sponsors (one per line)"></textarea>
                            </div>
                        </div>

                        <!-- D. Proposed Baptism Schedule -->
                        <div class="investigation-subcard" style="background: #fefce8; border-color: #fde047;">
                            <h4 class="investigation-subcard-title" style="color: #854d0e; border-bottom-color: #fef08a;"><i class="fas fa-calendar-check"></i> 4. Proposed Baptism Schedule</h4>
                            <div class="investigation-grid-full">
                                <label for="baptism_date">Date of Baptism <span class="text-danger">*</span> <small class="text-muted fw-normal">(Serves as your scheduled service date)</small></label>
                                <input type="date" min="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d', strtotime('+2 years')); ?>" class="form-control request-form-control" id="baptism_date" name="baptism_sheet[baptism_date]" data-baptism-sheet required>
                            </div>
                        </div>

                        <div class="baptism-warning" id="baptismSheetWarning">
                            <i class="fas fa-pen-to-square"></i>
                            <span>Complete all required fields in the Pre-Baptismal Investigation Sheet before submitting.</span>
                        </div>
                    </div>
                </div>

                <div class="baptism-requirements-card marriage-requirements-card" id="marriageRequirementsCard" hidden>
                    <div class="baptism-progress" aria-label="Marriage request progress">
                        <span class="active"><i class="fas fa-list-check"></i> Step 1: Review Requirements</span>
                        <span><i class="fas fa-calendar-check"></i> Step 2: Fill Request Form</span>
                        <span><i class="fas fa-paper-plane"></i> Step 3: Submit Request</span>
                    </div>

                    <div class="baptism-requirements-header">
                        <div>
                            <span class="request-kicker"><i class="fas fa-ring"></i> Marriage Requirements</span>
                            <h3>Requirements for Marriage</h3>
                            <p>Upload one file for the Male applicant and one file for the Female applicant for each requirement.</p>
                            <small class="text-muted d-block mt-1">Accepted formats: PDF, JPG, PNG, WEBP (max 5 MB each).</small>
                        </div>
                        <div class="baptism-review-badge">
                            <i class="fas fa-file-shield"></i>
                            <strong>Couple Review</strong>
                            <small>Both columns must be completed.</small>
                        </div>
                    </div>

                    <div class="marriage-requirements-table" role="table" aria-label="Marriage requirements uploads">
                        <div class="marriage-requirements-row header" role="row">
                            <span role="columnheader">Requirement</span>
                            <strong role="columnheader">Male</strong>
                            <strong role="columnheader">Female</strong>
                        </div>
                        <?php foreach ($marriage_requirements as $key => $meta): 
                            $label = is_array($meta) ? $meta['label'] : $meta;
                            $is_mandatory = is_array($meta) ? ($meta['mandatory'] ?? true) : true;
                            $badge = is_array($meta) ? ($meta['badge'] ?? '') : '';
                        ?>
                            <div class="marriage-requirements-row" role="row">
                                <span role="cell">
                                    <?php echo e($label); ?>
                                    <?php if ($badge): ?>
                                        <span class="badge-optional" style="display:inline-block; margin-left:6px; font-size:0.72rem; font-weight:700; color:#92400e; background:#fef3c7; border:1px solid #fde68a; padding:2px 8px; border-radius:999px; vertical-align:middle;">(<?php echo e($badge); ?>)</span>
                                    <?php endif; ?>
                                </span>
                                <div role="cell" class="marriage-upload-cell">
                                    <label class="requirement-upload-btn">
                                        <i class="fas fa-folder-open"></i> <span data-upload-label>Choose File</span>
                                        <input type="file" class="requirement-file-input" name="marriage_requirement_files[male][<?php echo e($key); ?>]" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*" data-requirement-file data-requirement-group="marriage" data-requirement-mandatory="<?php echo $is_mandatory ? 'true' : 'false'; ?>">
                                    </label>
                                    <a class="requirement-view-btn marriage-file-view" href="#" target="_blank" rel="noopener" data-file-view hidden>
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                                <div role="cell" class="marriage-upload-cell">
                                    <label class="requirement-upload-btn">
                                        <i class="fas fa-folder-open"></i> <span data-upload-label>Choose File</span>
                                        <input type="file" class="requirement-file-input" name="marriage_requirement_files[female][<?php echo e($key); ?>]" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*" data-requirement-file data-requirement-group="marriage" data-requirement-mandatory="<?php echo $is_mandatory ? 'true' : 'false'; ?>">
                                    </label>
                                    <a class="requirement-view-btn marriage-file-view" href="#" target="_blank" rel="noopener" data-file-view hidden>
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="baptism-warning" id="marriageRequirementWarning">
                        <i class="fas fa-triangle-exclamation"></i>
                        <span>Upload all Marriage requirement files for both Male and Female before submitting.</span>
                    </div>

                    <!-- Pre-Nuptial / Marriage Investigation Sheet -->
                    <div class="investigation-sheet-card marriage-sheet-card" id="marriageSheetCard">
                        <div class="investigation-sheet-heading">
                            <span class="request-kicker"><i class="fas fa-ring"></i> Pre-Nuptial Investigation Sheet</span>
                            <h3>Fill Out This Canonical Form Before Requesting Marriage</h3>
                            <p>Official Catholic Sacramental Book IV (Marriage Register) data requirements for the groom, bride, and principal sponsors.</p>
                        </div>

                        <!-- 1. Groom (Nobyo) Information -->
                        <div class="investigation-subcard">
                            <h4 class="investigation-subcard-title"><i class="fas fa-user-tie"></i> 1. Groom (Nobyo) Information</h4>
                            <div class="investigation-grid-full">
                                <label for="marriage_groom_name">Full Name of Groom <span class="text-danger">*</span></label>
                                <input type="text" class="form-control request-form-control" id="marriage_groom_name" name="marriage_sheet[groom_name]" data-marriage-sheet placeholder="First Name, Middle Name, Last Name, Suffix" required>
                            </div>
                            <div class="investigation-grid-2">
                                <div class="investigation-field">
                                    <label for="marriage_groom_birth_date">Date of Birth <span class="text-danger">*</span></label>
                                    <input type="date" min="1920-01-01" max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>" class="form-control request-form-control" id="marriage_groom_birth_date" name="marriage_sheet[groom_birth_date]" data-marriage-sheet required>
                                </div>
                                <div class="investigation-field">
                                    <label for="marriage_groom_birth_place">Place of Birth <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control request-form-control" id="marriage_groom_birth_place" name="marriage_sheet[groom_birth_place]" data-marriage-sheet placeholder="Municipality / City, Province" required>
                                </div>
                            </div>
                            <div class="investigation-grid-2">
                                <div class="investigation-field">
                                    <label for="marriage_groom_residence">Place of Origin / Current Residence <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control request-form-control" id="marriage_groom_residence" name="marriage_sheet[groom_residence]" data-marriage-sheet placeholder="Barangay, City / Municipality, Province" required>
                                </div>
                                <div class="investigation-field">
                                    <label for="marriage_groom_religion">Religion / Church of Baptism <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control request-form-control" id="marriage_groom_religion" name="marriage_sheet[groom_religion]" data-marriage-sheet placeholder="e.g., Roman Catholic / Parish of Baptism" required>
                                </div>
                            </div>
                            <div class="investigation-grid-2">
                                <div class="investigation-field">
                                    <label for="marriage_groom_father_name">Father's Complete Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control request-form-control" id="marriage_groom_father_name" name="marriage_sheet[groom_father_name]" data-marriage-sheet placeholder="Father's complete name" required>
                                </div>
                                <div class="investigation-field">
                                    <label for="marriage_groom_mother_name">Mother's Complete Maiden Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control request-form-control" id="marriage_groom_mother_name" name="marriage_sheet[groom_mother_name]" data-marriage-sheet placeholder="Mother's complete maiden name" required>
                                </div>
                            </div>
                        </div>

                        <!-- 2. Bride (Nobya) Information -->
                        <div class="investigation-subcard">
                            <h4 class="investigation-subcard-title"><i class="fas fa-person-dress"></i> 2. Bride (Nobya) Information</h4>
                            <div class="investigation-grid-full">
                                <label for="marriage_bride_name">Full Maiden Name of Bride <span class="text-danger">*</span></label>
                                <input type="text" class="form-control request-form-control" id="marriage_bride_name" name="marriage_sheet[bride_name]" data-marriage-sheet placeholder="First Name, Middle Name, Maiden Surname" required>
                            </div>
                            <div class="investigation-grid-2">
                                <div class="investigation-field">
                                    <label for="marriage_bride_birth_date">Date of Birth <span class="text-danger">*</span></label>
                                    <input type="date" min="1920-01-01" max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>" class="form-control request-form-control" id="marriage_bride_birth_date" name="marriage_sheet[bride_birth_date]" data-marriage-sheet required>
                                </div>
                                <div class="investigation-field">
                                    <label for="marriage_bride_birth_place">Place of Birth <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control request-form-control" id="marriage_bride_birth_place" name="marriage_sheet[bride_birth_place]" data-marriage-sheet placeholder="Municipality / City, Province" required>
                                </div>
                            </div>
                            <div class="investigation-grid-2">
                                <div class="investigation-field">
                                    <label for="marriage_bride_residence">Place of Origin / Current Residence <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control request-form-control" id="marriage_bride_residence" name="marriage_sheet[bride_residence]" data-marriage-sheet placeholder="Barangay, City / Municipality, Province" required>
                                </div>
                                <div class="investigation-field">
                                    <label for="marriage_bride_religion">Religion / Church of Baptism <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control request-form-control" id="marriage_bride_religion" name="marriage_sheet[bride_religion]" data-marriage-sheet placeholder="e.g., Roman Catholic / Parish of Baptism" required>
                                </div>
                            </div>
                            <div class="investigation-grid-2">
                                <div class="investigation-field">
                                    <label for="marriage_bride_father_name">Father's Complete Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control request-form-control" id="marriage_bride_father_name" name="marriage_sheet[bride_father_name]" data-marriage-sheet placeholder="Father's complete name" required>
                                </div>
                                <div class="investigation-field">
                                    <label for="marriage_bride_mother_name">Mother's Complete Maiden Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control request-form-control" id="marriage_bride_mother_name" name="marriage_sheet[bride_mother_name]" data-marriage-sheet placeholder="Mother's complete maiden name" required>
                                </div>
                            </div>
                        </div>

                        <!-- 3. Principal Witnesses / Sponsors (Ninong & Ninang) -->
                        <div class="investigation-subcard">
                            <h4 class="investigation-subcard-title"><i class="fas fa-users"></i> 3. Principal Witnesses / Sponsors (Ninong &amp; Ninang)</h4>
                            <div class="investigation-grid-2">
                                <div class="investigation-field">
                                    <label for="marriage_witness_male">Male Principal Sponsor (Full Name &amp; Residence) <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control request-form-control" id="marriage_witness_male" name="marriage_sheet[witness_male]" data-marriage-sheet placeholder="Full Name, City / Municipality" required>
                                </div>
                                <div class="investigation-field">
                                    <label for="marriage_witness_female">Female Principal Sponsor (Full Name &amp; Residence) <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control request-form-control" id="marriage_witness_female" name="marriage_sheet[witness_female]" data-marriage-sheet placeholder="Full Name, City / Municipality" required>
                                </div>
                            </div>
                            <div class="investigation-grid-full">
                                <label for="marriage_additional_sponsors">Additional Sponsors / Entourage (Optional)</label>
                                <textarea class="form-control request-form-control" id="marriage_additional_sponsors" name="marriage_sheet[additional_sponsors]" rows="2" data-marriage-sheet placeholder="List additional secondary sponsors, bridesmaid, groomsmen (optional)"></textarea>
                            </div>
                        </div>

                        <!-- 4. Wedding Ceremony Schedule -->
                        <div class="investigation-subcard" style="background: #fdf4ff; border-color: #f0abfc;">
                            <h4 class="investigation-subcard-title" style="color: #86198f; border-bottom-color: #f5d0fe;"><i class="fas fa-calendar-heart"></i> 4. Wedding Ceremony Schedule</h4>
                            <div class="investigation-grid-full">
                                <label for="marriage_wedding_date">Date of Marriage <span class="text-danger">*</span> <small class="text-muted fw-normal">(Serves as your scheduled wedding ceremony date)</small></label>
                                <input type="date" min="<?php echo date('Y-m-d', strtotime('+1 month')); ?>" max="<?php echo date('Y-m-d', strtotime('+3 years')); ?>" class="form-control request-form-control" id="marriage_wedding_date" name="marriage_sheet[wedding_date]" data-marriage-sheet required>
                            </div>
                        </div>

                        <div class="baptism-warning" id="marriageSheetWarning">
                            <i class="fas fa-pen-to-square"></i>
                            <span>Complete all required fields in the Pre-Nuptial Investigation Sheet before submitting.</span>
                        </div>
                    </div>
                </div>

                <div class="baptism-requirements-card funeral-requirements-card" id="funeralRequirementsCard" hidden>
                    <div class="baptism-progress" aria-label="Funeral Mass request progress">
                        <span class="active" id="funeralStep1"><i class="fas fa-list-check"></i> Step 1: Requirements &amp; Details</span>
                        <span><i class="fas fa-calendar-check"></i> Step 2: Schedule</span>
                        <span><i class="fas fa-paper-plane"></i> Step 3: Submit</span>
                    </div>

                    <div class="baptism-requirements-header">
                        <div>
                            <span class="request-kicker"><i class="fas fa-cross"></i> Funeral Mass Investigation Sheet</span>
                            <h3>Funeral Mass Details</h3>
                            <p>Please fill in all details below. This information will directly populate the official Parish Funeral Records upon approval.</p>
                            <small class="text-muted d-block mt-1">All fields marked <span class="text-danger">*</span> are required.</small>
                        </div>
                        <div class="baptism-review-badge">
                            <i class="fas fa-file-shield"></i>
                            <strong>Official Records</strong>
                            <small>Auto-saved upon completion.</small>
                        </div>
                    </div>

                    <!-- Funeral Investigation Sheet -->
                    <div class="baptism-sheet-body">
                        <div class="investigation-section-label"><i class="fas fa-cross"></i> Deceased Information</div>
                        <div class="investigation-grid">
                            <div class="investigation-field investigation-grid-full">
                                <label for="funeral_deceased_name">Deceased Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control request-form-control" id="funeral_deceased_name" name="funeral_sheet[deceased_name]" placeholder="e.g. Juan dela Cruz" data-funeral-sheet autocomplete="off">
                            </div>
                            <div class="investigation-field">
                                <label for="funeral_date_of_death">Date of Death <span class="text-danger">*</span></label>
                                <input type="date" class="form-control request-form-control" id="funeral_date_of_death" name="funeral_sheet[date_of_death]" max="<?php echo date('Y-m-d'); ?>" data-funeral-sheet>
                            </div>
                            <div class="investigation-field">
                                <label for="funeral_date_of_burial">Date of Burial / Funeral Mass <span class="text-danger">*</span></label>
                                <input type="date" class="form-control request-form-control" id="funeral_date_of_burial" name="funeral_sheet[date_of_burial]" min="<?php echo date('Y-m-d'); ?>" data-funeral-sheet>
                            </div>
                            <div class="investigation-field">
                                <label for="funeral_civil_status">Civil Status of Deceased <span class="text-danger">*</span></label>
                                <select class="form-control request-form-control" id="funeral_civil_status" name="funeral_sheet[civil_status]" data-funeral-sheet>
                                    <option value="">— Select —</option>
                                    <option value="Single">Single</option>
                                    <option value="Married">Married</option>
                                    <option value="Widowed">Widowed</option>
                                    <option value="Separated">Separated</option>
                                    <option value="Annulled">Annulled</option>
                                </select>
                            </div>
                            <div class="investigation-field">
                                <label for="funeral_rites">Type of Funeral Rites <span class="text-danger">*</span></label>
                                <select class="form-control request-form-control" id="funeral_rites" name="funeral_sheet[funeral_rites]" data-funeral-sheet>
                                    <option value="">— Select —</option>
                                    <option value="Full Catholic Rites">Full Catholic Rites</option>
                                    <option value="Simple Blessing">Simple Blessing</option>
                                    <option value="Graveside Service">Graveside Service</option>
                                    <option value="Memorial Mass">Memorial Mass</option>
                                    <option value="Cremation Blessing">Cremation Blessing</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="investigation-field investigation-grid-full">
                                <label for="funeral_cause_of_death">Cause of Death <span class="text-danger">*</span></label>
                                <input type="text" class="form-control request-form-control" id="funeral_cause_of_death" name="funeral_sheet[cause_of_death]" placeholder="e.g. Natural causes, heart disease" data-funeral-sheet autocomplete="off">
                            </div>
                            <div class="investigation-field investigation-grid-full">
                                <label for="funeral_place_of_burial">Place of Burial / Cemetery <span class="text-danger">*</span></label>
                                <input type="text" class="form-control request-form-control" id="funeral_place_of_burial" name="funeral_sheet[place_of_burial]" placeholder="e.g. Minokawa Municipal Cemetery" data-funeral-sheet autocomplete="off">
                            </div>
                            <div class="investigation-field investigation-grid-full">
                                <label for="funeral_minister">Preferred Minister / Officiant <small class="text-muted">(Optional — leave blank for parish assignment)</small></label>
                                <input type="text" class="form-control request-form-control" id="funeral_minister" name="funeral_sheet[minister]" placeholder="e.g. Rev. Fr. Parish Priest" data-funeral-sheet autocomplete="off">
                            </div>
                        </div>

                        <div class="investigation-section-label mt-3"><i class="fas fa-file-arrow-up"></i> Upload Requirements</div>
                        <p class="text-muted" style="font-size:13px;margin-bottom:8px;">Accepted formats: PDF, JPG, PNG, WEBP (max 5 MB each).</p>
                        <div class="baptism-requirements-grid">
                            <?php foreach ($funeral_requirements as $key => $meta):
                                $label = is_array($meta) ? ($meta['label'] ?? '') : $meta;
                                $mandatory = is_array($meta) ? ($meta['mandatory'] ?? true) : true;
                            ?>
                                <div class="baptism-requirement-item requirement-upload-item">
                                    <span><i class="fas fa-file-arrow-up"></i></span>
                                    <div class="requirement-upload-main">
                                        <strong><?php echo e($label); ?></strong><?php if ($mandatory): ?><span class="text-danger"> *</span><?php endif; ?>
                                        <small data-file-name>No file selected</small>
                                    </div>
                                    <div class="requirement-upload-actions">
                                        <label class="requirement-upload-btn">
                                            <i class="fas fa-folder-open"></i> <span data-upload-label>Choose File</span>
                                            <input type="file" class="requirement-file-input" name="funeral_requirement_files[<?php echo e($key); ?>]" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*" data-requirement-file data-requirement-group="funeral" data-requirement-mandatory="<?php echo $mandatory ? 'true' : 'false'; ?>">
                                        </label>
                                        <a class="requirement-view-btn" href="#" target="_blank" rel="noopener" data-file-view hidden>
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="baptism-warning" id="funeralRequirementWarning">
                        <i class="fas fa-triangle-exclamation"></i>
                        <span>Complete all Funeral details and upload the Death Certificate before submitting.</span>
                    </div>

                    <div class="baptism-warning is-complete" id="funeralSheetWarning" style="display:none;">
                        <i class="fas fa-circle-check"></i>
                        <span>Funeral Investigation Sheet is complete.</span>
                    </div>
                </div>

                <!-- First Communion Form Card -->
                <div class="investigation-sheet-card" id="firstCommunionCard" hidden>
                    <div class="investigation-sheet-heading">
                        <span class="request-kicker"><i class="fas fa-bread-slice"></i> First Communion Request</span>
                        <h3>First Communion Application Form</h3>
                        <p>Complete the candidate and canonical registry details below. Approved requests map directly into official parish registries.</p>
                    </div>

                    <div class="investigation-subcard">
                        <h4 class="investigation-subcard-title"><i class="fas fa-church"></i> Communicant &amp; Registry Details</h4>

                        <div class="investigation-grid-full">
                            <label for="communion_communicant_name">1. Name of Communicant <span class="text-danger">*</span></label>
                            <div class="pds-input-icon-wrap">
                                <i class="fas fa-user"></i>
                                <input type="text" class="form-control request-form-control text-uppercase" id="communion_communicant_name" name="communion_communicant_name" placeholder="Full name of communicant (e.g. REY MARK C. CAVANAS)" data-communion-field autocomplete="off">
                            </div>
                            <small class="text-muted">Enter full name without numbers or symbols. Saved in UPPERCASE.</small>
                        </div>

                        <div class="investigation-grid-full mt-3">
                            <label for="communion_domicile">2. Domicile <span class="text-danger">*</span></label>
                            <div class="pds-input-icon-wrap">
                                <i class="fas fa-location-dot"></i>
                                <input type="text" class="form-control request-form-control" id="communion_domicile" name="communion_domicile" value="<?php echo e($prefill_domicile); ?>" placeholder="Barangay / Purok, Municipality, Province" data-communion-field autocomplete="off">
                            </div>
                        </div>

                        <div class="investigation-grid-2 mt-3 pds-aligned-row">
                            <div class="investigation-field">
                                <label for="communion_father_name">3a. Father's Full Name <span class="text-danger">*</span></label>
                                <div class="pds-input-icon-wrap">
                                    <i class="fas fa-person"></i>
                                    <input type="text" class="form-control request-form-control text-uppercase" id="communion_father_name" name="communion_father_name" placeholder="Father's complete name" data-communion-field autocomplete="off" required>
                                </div>
                            </div>
                            <div class="investigation-field">
                                <label for="communion_mother_name">3b. Mother's Full Maiden Name <span class="text-danger">*</span></label>
                                <div class="pds-input-icon-wrap">
                                    <i class="fas fa-person-dress"></i>
                                    <input type="text" class="form-control request-form-control text-uppercase" id="communion_mother_name" name="communion_mother_name" placeholder="Mother's complete maiden name" data-communion-field autocomplete="off" required>
                                </div>
                            </div>
                        </div>

                        <div class="investigation-grid-2 mt-3 pds-aligned-row">
                            <div class="investigation-field">
                                <label for="communion_baptismal_date">4. Baptismal Date <span class="text-danger">*</span></label>
                                <div class="pds-input-icon-wrap">
                                    <i class="fas fa-water"></i>
                                    <input type="date" class="form-control request-form-control" id="communion_baptismal_date" name="communion_baptismal_date" max="<?php echo (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d'); ?>" data-communion-field required>
                                </div>
                            </div>
                            <div class="investigation-field">
                                <label for="communion_baptismal_place">5. Baptismal Place <span class="text-danger">*</span></label>
                                <div class="pds-input-icon-wrap">
                                    <i class="fas fa-place-of-worship"></i>
                                    <input type="text" class="form-control request-form-control" id="communion_baptismal_place" name="communion_baptismal_place" list="baptismalPlaceSuggestions" placeholder="Place of Baptism" data-communion-field autocomplete="off" required>
                                </div>
                            </div>
                        </div>
                        <div class="investigation-field mt-1">
                            <small class="text-muted">Must be a valid past date.</small>
                        </div>
                    </div>
                </div>

                <!-- Confirmation Form Card -->
                <div class="investigation-sheet-card" id="confirmationCard" hidden>
                    <div class="investigation-sheet-heading">
                        <span class="request-kicker"><i class="fas fa-dove"></i> Confirmation Request</span>
                        <h3>Confirmation Application Form</h3>
                        <p>Complete the candidate and canonical registry details below. Approved requests map directly into official parish registries.</p>
                    </div>

                    <div class="investigation-subcard">
                        <h4 class="investigation-subcard-title"><i class="fas fa-dove"></i> Candidate &amp; Registry Details</h4>

                        <div class="investigation-grid-full">
                            <label for="confirmation_fullname">1. Name of Confirmed Person <span class="text-danger">*</span></label>
                            <div class="pds-input-icon-wrap">
                                <i class="fas fa-user"></i>
                                <input type="text" class="form-control request-form-control text-uppercase" id="confirmation_fullname" name="confirmation_fullname" placeholder="Full name of confirmed person" data-confirmation-field autocomplete="off" required>
                            </div>
                            <small class="text-muted">Enter full name without numbers or symbols. Saved in UPPERCASE.</small>
                        </div>

                        <div class="investigation-grid-full mt-3">
                            <label for="confirmation_age">2. Age <span class="text-danger">*</span></label>
                            <div class="pds-input-icon-wrap">
                                <i class="fas fa-hashtag"></i>
                                <input type="number" class="form-control request-form-control" id="confirmation_age" name="confirmation_age" min="7" max="120" step="1" placeholder="Age (7 to 120)" data-confirmation-field required>
                            </div>
                            <small class="text-muted">Must be a whole number between 7 and 120.</small>
                        </div>

                        <div class="investigation-grid-2 mt-3 pds-aligned-row">
                            <div class="investigation-field">
                                <label for="confirmation_origin_parish">3. Parish of Origin <span class="text-danger">*</span></label>
                                <div class="pds-input-icon-wrap">
                                    <i class="fas fa-church"></i>
                                    <input type="text" class="form-control request-form-control" id="confirmation_origin_parish" name="confirmation_origin_parish" placeholder="e.g. San Lorenzo Ruiz Parish" data-confirmation-field autocomplete="off" required>
                                </div>
                            </div>
                            <div class="investigation-field">
                                <label for="confirmation_province">4. Province <span class="text-danger">*</span></label>
                                <div class="pds-input-icon-wrap">
                                    <i class="fas fa-map-location-dot"></i>
                                    <input type="text" class="form-control request-form-control" id="confirmation_province" name="confirmation_province" value="Cotabato" placeholder="Province" data-confirmation-field autocomplete="off" required>
                                </div>
                            </div>
                        </div>

                        <div class="investigation-grid-full mt-3">
                            <label for="confirmation_baptismal_place">5. Place of Baptism <span class="text-danger">*</span></label>
                            <div class="pds-input-icon-wrap">
                                <i class="fas fa-water"></i>
                                <input type="text" class="form-control request-form-control" id="confirmation_baptismal_place" name="confirmation_baptismal_place" list="baptismalPlaceSuggestions" placeholder="Place of Baptism" data-confirmation-field autocomplete="off" required>
                            </div>
                        </div>

                        <div class="investigation-grid-2 mt-3 pds-aligned-row">
                            <div class="investigation-field">
                                <label for="confirmation_father_name">6a. Father's Full Name <span class="text-danger">*</span></label>
                                <div class="pds-input-icon-wrap">
                                    <i class="fas fa-person"></i>
                                    <input type="text" class="form-control request-form-control text-uppercase" id="confirmation_father_name" name="confirmation_father_name" placeholder="Father's complete name" data-confirmation-field autocomplete="off" required>
                                </div>
                            </div>
                            <div class="investigation-field">
                                <label for="confirmation_mother_name">6b. Mother's Full Maiden Name <span class="text-danger">*</span></label>
                                <div class="pds-input-icon-wrap">
                                    <i class="fas fa-person-dress"></i>
                                    <input type="text" class="form-control request-form-control text-uppercase" id="confirmation_mother_name" name="confirmation_mother_name" placeholder="Mother's complete maiden name" data-confirmation-field autocomplete="off" required>
                                </div>
                            </div>
                        </div>

                        <div class="investigation-grid-full mt-3">
                            <label for="confirmation_sponsor">7. Sponsor / Godparent <span class="text-danger">*</span></label>
                            <div class="pds-input-icon-wrap">
                                <i class="fas fa-user-check"></i>
                                <input type="text" class="form-control request-form-control text-uppercase" id="confirmation_sponsor" name="confirmation_sponsor" placeholder="Full name of sponsor / godparent" data-confirmation-field autocomplete="off" required>
                            </div>
                            <small class="text-muted">Single Godparent / Sponsor full name. Saved in UPPERCASE.</small>
                        </div>
                    </div>
                </div>

                <datalist id="baptismalPlaceSuggestions">
                    <option value="San Lorenzo Ruiz Mission Station">
                    <option value="San Lorenzo Ruiz Parish Church">
                    <option value="Immaculate Conception Cathedral">
                    <option value="Our Lady of the Miraculous Medal Parish">
                </datalist>
            </section>

            <section class="request-step">
                <div class="step-heading">
                    <span class="step-number">2</span>
                    <div>
                        <h3>Applicant Details</h3>
                        <p>Confirm who is submitting this service request.</p>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-lg-4">
                        <label class="form-label">Full Name</label>
                        <input type="text" class="form-control request-form-control" value="<?php echo e($_SESSION['fullname'] ?? ''); ?>" readonly>
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label">Email Address</label>
                        <input type="email" class="form-control request-form-control" value="<?php echo e($_SESSION['email'] ?? ''); ?>" readonly>
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label">Notification</label>
                        <div class="form-control request-form-control d-flex align-items-center gap-2 text-muted">
                            <i class="fas fa-bell"></i> Updates appear in Notifications.
                        </div>
                    </div>
                </div>
            </section>

            <section class="request-step">
                <div class="step-heading" id="step3Heading">
                    <span class="step-number">3</span>
                    <div>
                        <h3 id="step3Title">Schedule and Location</h3>
                        <p id="step3Subtitle">Provide your preferred service schedule and complete location.</p>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6" id="patronalDateGroup" style="display:none;">
                        <label for="patronal_fiesta_date" class="form-label">Date of Patronal Fiesta <span class="text-danger">*</span></label>
                        <input type="date" class="form-control request-form-control" id="patronal_fiesta_date" name="patronal_fiesta_date" min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="col-md-6" id="generalServiceDateGroup" style="display:none;">
                        <label for="general_service_date" class="form-label">Requested Service Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control request-form-control" id="general_service_date" name="service_date" min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="col-12" id="scheduleSyncCard">
                        <div class="schedule-sync-card">
                            <i class="fas fa-calendar-check schedule-sync-icon"></i>
                            <div class="schedule-sync-body">
                                <strong id="scheduleSyncTitle">Schedule Date Synchronized</strong>
                                <p id="scheduleSyncText">The schedule date is automatically synchronized with your investigation sheet above.</p>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" id="preferred_date" name="preferred_date">
                    <div class="col-md-6" id="preferredTimeGroup">
                        <label for="preferred_time" class="form-label">Preferred Time <span class="text-danger">*</span></label>
                        <select class="form-select request-form-control" id="preferred_time" name="preferred_time" required>
                            <option value="">Select an hourly time slot</option>
                            <option value="08:00">08:00 AM</option>
                            <option value="09:00">09:00 AM</option>
                            <option value="10:00">10:00 AM</option>
                            <option value="11:00">11:00 AM</option>
                            <option value="12:00">12:00 PM</option>
                            <option value="13:00">01:00 PM</option>
                            <option value="14:00">02:00 PM</option>
                            <option value="15:00">03:00 PM</option>
                            <option value="16:00">04:00 PM</option>
                            <option value="17:00">05:00 PM</option>
                        </select>
                        <div class="form-text">Parish service schedules run on fixed 1-hour slots starting on the hour.</div>
                    </div>
                    <div class="col-12" id="locationGroup">
                        <label for="location" class="form-label">Location <span class="text-danger">*</span></label>
                        <input type="text" class="form-control request-form-control" id="location" name="location" placeholder="Church, chapel, home, hospital, cemetery, or venue" required>
                    </div>

                    <!-- Schedule Notice (First Communion and Confirmation only) -->
                    <div class="col-12" id="communionConfirmationScheduleNotice" style="display: none;">
                        <div class="alert alert-info d-flex align-items-center gap-2 mb-0" style="background-color: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; border-radius: 10px;">
                            <i class="fas fa-calendar-check text-success fs-5"></i>
                            <div>
                                <strong>Note:</strong> The date and time for First Communion / Confirmation will be scheduled and set directly by the Parish Administrator.
                            </div>
                        </div>
                    </div>

                    <!-- Requirements Upload Section (First Communion and Confirmation forms only) -->
                    <div class="col-12" id="communionConfirmationRequirementsSection" style="display: none;">
                        <div class="card border-0 shadow-sm req-section-card mb-2" style="background: #ffffff; border: 1px solid #e2e8f0 !important; border-radius: 12px;">
                            <div class="card-body p-3 p-md-4">
                                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 pb-2 border-bottom">
                                    <div>
                                        <h4 class="h5 mb-1 fw-bold text-dark d-flex align-items-center gap-2">
                                            <i class="fas fa-file-circle-check text-primary"></i> Requirements
                                        </h4>
                                        <p class="text-muted small mb-0">Upload required certificate documents before reviewing and submitting your request.</p>
                                    </div>
                                    <div id="reqChecklistContainer" class="req-checklist-container">
                                        <!-- Dynamic Checklist -->
                                    </div>
                                </div>

                                <div class="row g-3 req-upload-grid">
                                    <!-- Slot 1: Baptismal Certificate (Required for First Communion and Confirmation) -->
                                    <div class="col-12 col-md-6 req-slot-col" id="slotBaptismalWrap">
                                        <label for="communionBaptismalCertInput" class="form-label fw-semibold text-dark d-flex align-items-center gap-1 mb-1">
                                            Baptismal Certificate <span class="text-danger">*</span>
                                        </label>
                                        <div class="form-text text-muted small mt-0 mb-2" id="bapCertHelper">
                                            Upload a clear photo or scan. JPG, PNG, WEBP, or PDF, up to 5 MB.
                                        </div>
                                        <div class="req-dropzone" id="bapCertDropzone" tabindex="0" role="region" aria-label="Baptismal Certificate upload slot" aria-describedby="bapCertHelper bapCertError">
                                            <input type="file" id="communionBaptismalCertInput" name="baptismal_certificate" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf" class="d-none req-file-input">
                                            <input type="file" id="communionBaptismalCertCamera" accept="image/*" capture="environment" class="d-none req-camera-input">
                                            
                                            <!-- Empty State -->
                                            <div class="req-slot-empty text-center py-3">
                                                <i class="fas fa-cloud-arrow-up fa-2x text-muted mb-2 d-block"></i>
                                                <div class="small text-muted mb-2">Drag and drop file here, or</div>
                                                <div class="d-flex justify-content-center gap-2 flex-wrap">
                                                    <button type="button" class="btn btn-sm btn-outline-primary px-3 req-btn-choose" style="min-height: 44px; min-width: 44px;">
                                                        <i class="fas fa-folder-open me-1"></i> Choose file
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary px-3 req-btn-camera" style="min-height: 44px; min-width: 44px;">
                                                        <i class="fas fa-camera me-1"></i> Take photo
                                                    </button>
                                                </div>
                                            </div>

                                            <!-- Progress State -->
                                            <div class="req-slot-progress py-3 px-2 text-center" style="display: none;">
                                                <div class="small fw-semibold text-primary mb-2">Attaching document...</div>
                                                <div class="progress" style="height: 6px;">
                                                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar" style="width: 100%;"></div>
                                                </div>
                                            </div>

                                            <!-- Filled State -->
                                            <div class="req-slot-filled py-2 px-2" style="display: none;">
                                                <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                                                    <div class="d-flex align-items-center gap-2 text-truncate" style="max-width: 70%;">
                                                        <div class="req-thumb-box flex-shrink-0">
                                                            <img src="" alt="Thumbnail" class="req-thumb-img rounded" style="width: 48px; height: 48px; object-fit: cover; display: none;">
                                                            <i class="fas fa-file-pdf fa-2x text-danger req-pdf-icon" style="display: none;"></i>
                                                        </div>
                                                        <div class="text-truncate">
                                                            <div class="fw-semibold text-dark text-truncate small req-file-name">filename.pdf</div>
                                                            <div class="text-muted" style="font-size: 0.75rem;"><span class="req-file-size">1.2 MB</span></div>
                                                        </div>
                                                    </div>
                                                    <div class="d-flex align-items-center gap-1">
                                                        <button type="button" class="btn btn-sm btn-outline-secondary req-btn-replace" style="min-height: 44px; min-width: 44px;" title="Replace file">
                                                            <i class="fas fa-rotate"></i> Replace
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-outline-danger req-btn-remove" style="min-height: 44px; min-width: 44px;" title="Remove file">
                                                            <i class="fas fa-trash-can"></i> Remove
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="req-inline-error text-danger small mt-1" id="bapCertError" role="alert" style="display: none;">
                                            <i class="fas fa-circle-exclamation me-1"></i><span class="req-error-text"></span>
                                        </div>
                                    </div>

                                    <!-- Slot 2: First Communion Certificate (Required for Confirmation only) -->
                                    <div class="col-12 col-md-6 req-slot-col" id="slotFirstCommunionWrap" style="display: none;">
                                        <label for="confirmationCommunionCertInput" class="form-label fw-semibold text-dark d-flex align-items-center gap-1 mb-1">
                                            First Communion Certificate <span class="text-danger">*</span>
                                        </label>
                                        <div class="form-text text-muted small mt-0 mb-2" id="commCertHelper">
                                            Upload a clear photo or scan. JPG, PNG, WEBP, or PDF, up to 5 MB.
                                        </div>
                                        <div class="req-dropzone" id="commCertDropzone" tabindex="0" role="region" aria-label="First Communion Certificate upload slot" aria-describedby="commCertHelper commCertError">
                                            <input type="file" id="confirmationCommunionCertInput" name="first_communion_certificate" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf" class="d-none req-file-input">
                                            <input type="file" id="confirmationCommunionCertCamera" accept="image/*" capture="environment" class="d-none req-camera-input">
                                            
                                            <!-- Empty State -->
                                            <div class="req-slot-empty text-center py-3">
                                                <i class="fas fa-cloud-arrow-up fa-2x text-muted mb-2 d-block"></i>
                                                <div class="small text-muted mb-2">Drag and drop file here, or</div>
                                                <div class="d-flex justify-content-center gap-2 flex-wrap">
                                                    <button type="button" class="btn btn-sm btn-outline-primary px-3 req-btn-choose" style="min-height: 44px; min-width: 44px;">
                                                        <i class="fas fa-folder-open me-1"></i> Choose file
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary px-3 req-btn-camera" style="min-height: 44px; min-width: 44px;">
                                                        <i class="fas fa-camera me-1"></i> Take photo
                                                    </button>
                                                </div>
                                            </div>

                                            <!-- Progress State -->
                                            <div class="req-slot-progress py-3 px-2 text-center" style="display: none;">
                                                <div class="small fw-semibold text-primary mb-2">Attaching document...</div>
                                                <div class="progress" style="height: 6px;">
                                                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar" style="width: 100%;"></div>
                                                </div>
                                            </div>

                                            <!-- Filled State -->
                                            <div class="req-slot-filled py-2 px-2" style="display: none;">
                                                <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                                                    <div class="d-flex align-items-center gap-2 text-truncate" style="max-width: 70%;">
                                                        <div class="req-thumb-box flex-shrink-0">
                                                            <img src="" alt="Thumbnail" class="req-thumb-img rounded" style="width: 48px; height: 48px; object-fit: cover; display: none;">
                                                            <i class="fas fa-file-pdf fa-2x text-danger req-pdf-icon" style="display: none;"></i>
                                                        </div>
                                                        <div class="text-truncate">
                                                            <div class="fw-semibold text-dark text-truncate small req-file-name">filename.pdf</div>
                                                            <div class="text-muted" style="font-size: 0.75rem;"><span class="req-file-size">1.2 MB</span></div>
                                                        </div>
                                                    </div>
                                                    <div class="d-flex align-items-center gap-1">
                                                        <button type="button" class="btn btn-sm btn-outline-secondary req-btn-replace" style="min-height: 44px; min-width: 44px;" title="Replace file">
                                                            <i class="fas fa-rotate"></i> Replace
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-outline-danger req-btn-remove" style="min-height: 44px; min-width: 44px;" title="Remove file">
                                                            <i class="fas fa-trash-can"></i> Remove
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="req-inline-error text-danger small mt-1" id="commCertError" role="alert" style="display: none;">
                                            <i class="fas fa-circle-exclamation me-1"></i><span class="req-error-text"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12" id="additionalDetailsGroup">
                        <label for="details" class="form-label">Additional Details <span class="text-muted fw-normal">(optional)</span></label>
                        <textarea class="form-control request-form-control" id="details" name="details" rows="4"></textarea>
                    </div>
                </div>
            </section>

            <div class="request-form-actions">
                <div class="privacy-copy">
                    <i class="fas fa-lock"></i> Your request will not be sent until you confirm it on the next screen.
                </div>
                <button type="button" class="submit-request-btn" id="submitRequestBtn">
                    <span class="submit-label"><i class="fas fa-clipboard-check"></i> Review Before Submitting <i class="fas fa-arrow-right"></i></span>
                    <span class="submit-loading"><i class="fas fa-spinner fa-spin"></i> Preparing Review</span>
                </button>
            </div>
            </div>

            <section class="request-review-panel" id="serviceReviewPanel" hidden aria-labelledby="serviceReviewTitle">
                <div class="request-review-heading">
                    <span class="request-kicker"><i class="fas fa-magnifying-glass"></i> Final review</span>
                    <h2 id="serviceReviewTitle">Double-Check Everything Before Submitting</h2>
                    <p>Review the information below. You can go back without losing anything.</p>
                </div>

                <div class="request-review-section">
                    <h3><i class="fas fa-church"></i> Service Information</h3>
                    <dl class="request-review-grid" id="reviewServiceInfo"></dl>
                </div>

                <div class="request-review-section" id="reviewChildSection" hidden>
                    <h3><i class="fas fa-child-reaching"></i> Child Information</h3>
                    <dl class="request-review-grid" id="reviewChildInfo"></dl>
                </div>

                <div class="request-review-section" id="reviewParentsSection" hidden>
                    <h3><i class="fas fa-people-roof"></i> Parents</h3>
                    <dl class="request-review-grid" id="reviewParents"></dl>
                </div>

                <div class="request-review-section" id="reviewGodparentsSection" hidden>
                    <h3><i class="fas fa-people-group"></i> Godparents and Parish Details</h3>
                    <dl class="request-review-grid" id="reviewGodparents"></dl>
                </div>

                <div class="request-review-section" id="reviewGroomSection" hidden>
                    <h3><i class="fas fa-user-tie"></i> Groom (Nobyo) Information</h3>
                    <dl class="request-review-grid" id="reviewGroomInfo"></dl>
                </div>

                <div class="request-review-section" id="reviewBrideSection" hidden>
                    <h3><i class="fas fa-person-dress"></i> Bride (Nobya) Information</h3>
                    <dl class="request-review-grid" id="reviewBrideInfo"></dl>
                </div>

                <div class="request-review-section" id="reviewMarriageSponsorsSection" hidden>
                    <h3><i class="fas fa-users"></i> Principal Witnesses / Sponsors (Ninong &amp; Ninang)</h3>
                    <dl class="request-review-grid" id="reviewMarriageSponsorsInfo"></dl>
                </div>

                <div class="request-review-section" id="reviewFuneralSection" hidden>
                    <h3><i class="fas fa-cross"></i> Funeral Investigation Sheet</h3>
                    <dl class="request-review-grid" id="reviewFuneralInfo"></dl>
                </div>

                <div class="request-review-section" id="reviewCommunionSection" hidden>
                    <h3><i class="fas fa-bread-slice"></i> First Communion Details</h3>
                    <dl class="request-review-grid" id="reviewCommunionInfo"></dl>
                </div>

                <div class="request-review-section" id="reviewConfirmationSection" hidden>
                    <h3><i class="fas fa-dove"></i> Confirmation Details</h3>
                    <dl class="request-review-grid" id="reviewConfirmationInfo"></dl>
                </div>

                <div class="request-review-section" id="reviewRequirementsSection" hidden>
                    <h3><i class="fas fa-paperclip"></i> Uploaded Requirements</h3>
                    <dl class="request-review-grid" id="reviewRequirementsGrid"></dl>
                </div>

                <div class="request-review-section">
                    <h3 id="reviewScheduleHeading"><i class="fas fa-calendar-check"></i> Applicant, Schedule, and Location</h3>
                    <dl class="request-review-grid" id="reviewScheduleInfo"></dl>
                </div>

                <div class="request-review-confirm-note">
                    <i class="fas fa-circle-info"></i>
                    <span>By submitting, you confirm that this information is accurate and ready for parish review.</span>
                </div>

                <div class="request-review-actions">
                    <button type="button" class="request-review-back" id="serviceReviewBack"><i class="fas fa-arrow-left"></i> Back and Edit</button>
                    <button type="submit" class="submit-request-btn" id="confirmServiceSubmit">
                        <span class="submit-label"><i class="fas fa-paper-plane"></i> Confirm &amp; Submit Request</span>
                        <span class="submit-loading"><i class="fas fa-spinner fa-spin"></i> Submitting Request</span>
                    </button>
                </div>
            </section>
        </form>
    </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const serviceForm = document.getElementById('serviceRequestForm');
    const entryPanel = document.getElementById('serviceEntryPanel');
    const reviewPanel = document.getElementById('serviceReviewPanel');
    const validationBanner = document.getElementById('serviceValidationBanner');
    const reviewBackBtn = document.getElementById('serviceReviewBack');
    const confirmSubmitBtn = document.getElementById('confirmServiceSubmit');
    const patronalGroup = document.getElementById('patronalDateGroup');
    const patronalDate = document.getElementById('patronal_fiesta_date');
    const generalServiceDateGroup = document.getElementById('generalServiceDateGroup');
    const generalServiceDate = document.getElementById('general_service_date');
    const preferredTimeGroup = document.getElementById('preferredTimeGroup');
    const preferredTimeSelect = document.getElementById('preferred_time');
    const locGroup = document.getElementById('locationGroup');
    const locInput = document.getElementById('location');
    const step3Title = document.getElementById('step3Title');
    const step3Subtitle = document.getElementById('step3Subtitle');
    const communionConfNotice = document.getElementById('communionConfirmationScheduleNotice');
    const scheduleSyncCard = document.getElementById('scheduleSyncCard');
    const scheduleSyncTitle = document.getElementById('scheduleSyncTitle');
    const scheduleSyncText = document.getElementById('scheduleSyncText');
    const preferredDate = document.getElementById('preferred_date');

    const firstCommunionCard = document.getElementById('firstCommunionCard');
    const communionFields = Array.from(document.querySelectorAll('[data-communion-field]'));
    const confirmationCard = document.getElementById('confirmationCard');
    const confirmationFields = Array.from(document.querySelectorAll('[data-confirmation-field]'));

    const baptismRequirementsCard = document.getElementById('baptismRequirementsCard');
    const baptismRequirementWarning = document.getElementById('baptismRequirementWarning');
    const baptismSheetFields = Array.from(document.querySelectorAll('[data-baptism-sheet]'));
    const baptismSheetWarning = document.getElementById('baptismSheetWarning');
    const baptismDateInput = document.getElementById('baptism_date');
    const marriageRequirementsCard = document.getElementById('marriageRequirementsCard');
    const marriageRequirementWarning = document.getElementById('marriageRequirementWarning');
    const marriageSheetFields = Array.from(document.querySelectorAll('[data-marriage-sheet]'));
    const marriageSheetWarning = document.getElementById('marriageSheetWarning');
    const weddingDateInput = document.getElementById('marriage_wedding_date');
    const funeralRequirementsCard = document.getElementById('funeralRequirementsCard');
    const funeralRequirementWarning = document.getElementById('funeralRequirementWarning');
    const funeralSheetWarning = document.getElementById('funeralSheetWarning');
    const funeralSheetFields = Array.from(document.querySelectorAll('[data-funeral-sheet]'));
    const funeralBurialDateInput = document.getElementById('funeral_date_of_burial');
    const requirementFileInputs = Array.from(document.querySelectorAll('[data-requirement-file]'));
    const submitRequestBtn = document.getElementById('submitRequestBtn');

    // Requirements Section Elements (Communion & Confirmation)
    const commConfReqSection = document.getElementById('communionConfirmationRequirementsSection');
    const slotBaptismalWrap = document.getElementById('slotBaptismalWrap');
    const slotFirstCommunionWrap = document.getElementById('slotFirstCommunionWrap');
    const reqChecklistContainer = document.getElementById('reqChecklistContainer');

    const bapCertInput = document.getElementById('communionBaptismalCertInput');
    const bapCertCamera = document.getElementById('communionBaptismalCertCamera');
    const bapCertDropzone = document.getElementById('bapCertDropzone');
    const bapCertError = document.getElementById('bapCertError');

    const commCertInput = document.getElementById('confirmationCommunionCertInput');
    const commCertCamera = document.getElementById('confirmationCommunionCertCamera');
    const commCertDropzone = document.getElementById('commCertDropzone');
    const commCertError = document.getElementById('commCertError');

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatFileSize(bytes) {
        if (!bytes || bytes <= 0) return '0 B';
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
    }

    function validateRequirementFile(file) {
        if (!file) return { ok: false, error: 'No file selected.' };
        const maxBytes = 5 * 1024 * 1024;
        if (file.size > maxBytes) {
            return { ok: false, error: 'File exceeds maximum size of 5 MB.' };
        }
        if (file.size <= 0) {
            return { ok: false, error: 'Selected file is empty.' };
        }
        const ext = (file.name || '').split('.').pop().toLowerCase();
        const allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
        if (!allowed.includes(ext)) {
            return { ok: false, error: 'Invalid file type. Only JPG, PNG, WEBP, or PDF files are accepted.' };
        }
        const isPdf = ext === 'pdf' || (file.type && file.type === 'application/pdf');
        return { ok: true, isPdf, ext };
    }

    function updateCommConfRequirementsState() {
        if (!commConfReqSection) return;
        const communionSelected = isCommunionSelected();
        const confirmationSelected = isConfirmationSelected();

        if (communionSelected) {
            commConfReqSection.style.display = '';
            if (slotBaptismalWrap) {
                slotBaptismalWrap.style.display = '';
                slotBaptismalWrap.className = 'col-12 col-md-8 mx-auto req-slot-col';
            }
            if (slotFirstCommunionWrap) {
                slotFirstCommunionWrap.style.display = 'none';
            }

            if (bapCertInput) bapCertInput.required = true;
            if (commCertInput) commCertInput.required = false;

            const bapUploaded = Boolean(bapCertInput && bapCertInput.files && bapCertInput.files.length > 0);
            if (reqChecklistContainer) {
                reqChecklistContainer.innerHTML = '<span class="badge ' + (bapUploaded ? 'bg-success' : 'bg-danger') + ' text-white px-2.5 py-1.5"><i class="fas ' + (bapUploaded ? 'fa-circle-check' : 'fa-circle-xmark') + ' me-1"></i> Baptismal Certificate: ' + (bapUploaded ? 'uploaded' : 'missing') + '</span>';
            }

            if (submitRequestBtn) {
                submitRequestBtn.disabled = false;
                submitRequestBtn.removeAttribute('title');
            }
        } else if (confirmationSelected) {
            commConfReqSection.style.display = '';
            if (slotBaptismalWrap) {
                slotBaptismalWrap.style.display = '';
                slotBaptismalWrap.className = 'col-12 col-md-6 req-slot-col';
            }
            if (slotFirstCommunionWrap) {
                slotFirstCommunionWrap.style.display = '';
                slotFirstCommunionWrap.className = 'col-12 col-md-6 req-slot-col';
            }

            if (bapCertInput) bapCertInput.required = true;
            if (commCertInput) commCertInput.required = true;

            const bapUploaded = Boolean(bapCertInput && bapCertInput.files && bapCertInput.files.length > 0);
            const commUploaded = Boolean(commCertInput && commCertInput.files && commCertInput.files.length > 0);
            if (reqChecklistContainer) {
                reqChecklistContainer.innerHTML = '<div class="d-flex flex-wrap gap-2">' +
                    '<span class="badge ' + (bapUploaded ? 'bg-success' : 'bg-danger') + ' text-white px-2.5 py-1.5"><i class="fas ' + (bapUploaded ? 'fa-circle-check' : 'fa-circle-xmark') + ' me-1"></i> Baptismal Certificate: ' + (bapUploaded ? 'uploaded' : 'missing') + '</span>' +
                    '<span class="badge ' + (commUploaded ? 'bg-success' : 'bg-danger') + ' text-white px-2.5 py-1.5"><i class="fas ' + (commUploaded ? 'fa-circle-check' : 'fa-circle-xmark') + ' me-1"></i> First Communion Certificate: ' + (commUploaded ? 'uploaded' : 'missing') + '</span>' +
                    '</div>';
            }

            if (submitRequestBtn) {
                submitRequestBtn.disabled = false;
                submitRequestBtn.removeAttribute('title');
            }
        } else {
            commConfReqSection.style.display = 'none';
            if (slotFirstCommunionWrap) slotFirstCommunionWrap.style.display = 'none';
            if (bapCertInput) bapCertInput.required = false;
            if (commCertInput) commCertInput.required = false;
            if (submitRequestBtn) {
                submitRequestBtn.disabled = false;
                submitRequestBtn.removeAttribute('title');
            }
        }
    }

    function setupReqSlot(slotEl, fileInput, cameraInput, errorEl) {
        if (!slotEl || !fileInput) return;

        const emptyView = slotEl.querySelector('.req-slot-empty');
        const progressView = slotEl.querySelector('.req-slot-progress');
        const filledView = slotEl.querySelector('.req-slot-filled');
        const thumbImg = slotEl.querySelector('.req-thumb-img');
        const pdfIcon = slotEl.querySelector('.req-pdf-icon');
        const nameEl = slotEl.querySelector('.req-file-name');
        const sizeEl = slotEl.querySelector('.req-file-size');
        const progressBar = slotEl.querySelector('.progress-bar');

        const btnChoose = slotEl.querySelector('.req-btn-choose');
        const btnCamera = slotEl.querySelector('.req-btn-camera');
        const btnReplace = slotEl.querySelector('.req-btn-replace');
        const btnRemove = slotEl.querySelector('.req-btn-remove');

        let currentObjectUrl = null;

        function clearSlotError() {
            if (errorEl) {
                errorEl.style.display = 'none';
                const errText = errorEl.querySelector('.req-error-text');
                if (errText) errText.textContent = '';
            }
            slotEl.classList.remove('has-error');
        }

        function showSlotError(msg) {
            if (errorEl) {
                const errText = errorEl.querySelector('.req-error-text') || errorEl;
                errText.textContent = msg;
                errorEl.style.display = '';
            }
            slotEl.classList.add('has-error');
            slotEl.classList.remove('has-file');
        }

        function resetSlotToEmpty() {
            fileInput.value = '';
            if (cameraInput) cameraInput.value = '';
            if (currentObjectUrl) {
                URL.revokeObjectURL(currentObjectUrl);
                currentObjectUrl = null;
            }
            if (thumbImg) {
                thumbImg.src = '';
                thumbImg.style.display = 'none';
            }
            if (pdfIcon) pdfIcon.style.display = 'none';
            if (emptyView) emptyView.style.display = '';
            if (progressView) progressView.style.display = 'none';
            if (filledView) filledView.style.display = 'none';
            slotEl.classList.remove('has-file');
            clearSlotError();
            updateCommConfRequirementsState();
        }

        function displayUploadedFile(file) {
            clearSlotError();
            if (emptyView) emptyView.style.display = 'none';
            if (progressView) progressView.style.display = '';
            if (filledView) filledView.style.display = 'none';
            if (progressBar) {
                progressBar.style.width = '0%';
                progressBar.setAttribute('aria-valuenow', '0');
            }

            let progress = 0;
            const timer = setInterval(function() {
                progress += 25;
                if (progressBar) {
                    progressBar.style.width = progress + '%';
                    progressBar.setAttribute('aria-valuenow', progress);
                }
                if (progress >= 100) {
                    clearInterval(timer);
                    setTimeout(function() {
                        if (progressView) progressView.style.display = 'none';
                        if (filledView) filledView.style.display = '';
                        slotEl.classList.add('has-file');

                        if (nameEl) nameEl.textContent = file.name;
                        if (sizeEl) sizeEl.textContent = formatFileSize(file.size);

                        const ext = (file.name || '').split('.').pop().toLowerCase();
                        const isPdf = ext === 'pdf' || file.type === 'application/pdf';

                        if (currentObjectUrl) {
                            URL.revokeObjectURL(currentObjectUrl);
                            currentObjectUrl = null;
                        }

                        if (isPdf) {
                            if (thumbImg) thumbImg.style.display = 'none';
                            if (pdfIcon) pdfIcon.style.display = 'inline-block';
                        } else {
                            if (pdfIcon) pdfIcon.style.display = 'none';
                            try {
                                currentObjectUrl = URL.createObjectURL(file);
                                if (thumbImg) {
                                    thumbImg.src = currentObjectUrl;
                                    thumbImg.style.display = 'inline-block';
                                }
                            } catch (e) {
                                if (pdfIcon) pdfIcon.style.display = 'inline-block';
                            }
                        }
                        updateCommConfRequirementsState();
                        if (window._pendingReviewAfterUpload) {
                            window._pendingReviewAfterUpload = false;
                            openReview();
                        }
                    }, 120);
                }
            }, 50);
        }

        function handleSelectedFile(file) {
            if (!file) return;
            const check = validateRequirementFile(file);
            if (!check.ok) {
                fileInput.value = '';
                if (cameraInput) cameraInput.value = '';
                showSlotError(check.error);
                if (emptyView) emptyView.style.display = '';
                if (progressView) progressView.style.display = 'none';
                if (filledView) filledView.style.display = 'none';
                slotEl.classList.remove('has-file');
                updateCommConfRequirementsState();
                return;
            }
            displayUploadedFile(file);
        }

        if (btnChoose) {
            btnChoose.addEventListener('click', function(e) {
                e.stopPropagation();
                fileInput.click();
            });
        }
        if (btnCamera && cameraInput) {
            btnCamera.addEventListener('click', function(e) {
                e.stopPropagation();
                cameraInput.click();
            });
        }
        if (btnReplace) {
            btnReplace.addEventListener('click', function(e) {
                e.stopPropagation();
                fileInput.click();
            });
        }
        if (btnRemove) {
            btnRemove.addEventListener('click', function(e) {
                e.stopPropagation();
                resetSlotToEmpty();
            });
        }

        fileInput.addEventListener('change', function() {
            if (fileInput.files && fileInput.files.length) {
                handleSelectedFile(fileInput.files[0]);
            }
        });

        if (cameraInput) {
            cameraInput.addEventListener('change', function() {
                if (cameraInput.files && cameraInput.files.length) {
                    try {
                        const dt = new DataTransfer();
                        dt.items.add(cameraInput.files[0]);
                        fileInput.files = dt.files;
                    } catch (dtErr) {}
                    handleSelectedFile(cameraInput.files[0]);
                }
            });
        }

        slotEl.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            slotEl.classList.add('drag-over');
        });
        slotEl.addEventListener('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            slotEl.classList.remove('drag-over');
        });
        slotEl.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            slotEl.classList.remove('drag-over');
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
                try {
                    const dt = new DataTransfer();
                    dt.items.add(e.dataTransfer.files[0]);
                    fileInput.files = dt.files;
                } catch (dtErr) {}
                handleSelectedFile(e.dataTransfer.files[0]);
            }
        });

        slotEl.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                if (e.target === slotEl) {
                    e.preventDefault();
                    if (!fileInput.files || !fileInput.files.length) {
                        fileInput.click();
                    }
                }
            }
        });
    }

    function requirementFilesReady(group) {
        const inputs = requirementFileInputs.filter(function(input) {
            return input.dataset.requirementGroup === group && input.dataset.requirementMandatory !== 'false';
        });
        return inputs.length === 0 || inputs.every(function(input) {
            return input.files && input.files.length > 0;
        });
    }

    function validateSelectedFile(file) {
        if (!file) return { ok: true };
        const maxBytes = 5 * 1024 * 1024;
        const allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
        const allowedMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
        const invalidMsg = 'Only PDF or image files (JPG, PNG, WEBP) up to 5 MB are allowed. Please convert your document and upload again.';

        if (file.size <= 0 || file.size > maxBytes) {
            return { ok: false, error: invalidMsg };
        }
        const ext = (file.name || '').split('.').pop().toLowerCase();
        if (!allowedExts.includes(ext)) {
            return { ok: false, error: invalidMsg };
        }
        if (file.type && !allowedMimes.includes(file.type.toLowerCase()) && !file.type.startsWith('image/')) {
            return { ok: false, error: invalidMsg };
        }
        const isPdf = ext === 'pdf' || file.type === 'application/pdf';
        const isImg = !isPdf;
        return { ok: true, isPdf, isImg, ext };
    }

    function updateRequirementFileLabel(input) {
        const button = input.closest('.requirement-upload-btn');
        const container = input.closest('.marriage-upload-cell') || input.closest('.requirement-upload-item') || button;
        const fileLabel = container ? container.querySelector('[data-file-name]') : null;
        const uploadLabel = button ? button.querySelector('[data-upload-label]') : null;
        const viewButton = container ? container.querySelector('[data-file-view]') : null;
        const itemIcon = container ? container.querySelector(':scope > span i') : null;
        const hasFile = input.files && input.files.length > 0;

        if (hasFile) {
            const file = input.files[0];
            const check = validateSelectedFile(file);
            if (!check.ok) {
                input.value = '';
                if (typeof ParishToast !== 'undefined' && typeof ParishToast.show === 'function') {
                    ParishToast.show({ title: 'Invalid File', message: check.error, type: 'error', duration: 7000 });
                } else {
                    alert(check.error);
                }
                if (fileLabel) {
                    fileLabel.textContent = (input.dataset.requirementGroup === 'marriage' ? 'No file' : 'No file selected');
                }
                if (uploadLabel) uploadLabel.textContent = 'Choose File';
                if (button) button.classList.remove('has-file');
                if (container) container.classList.remove('has-file');
                if (viewButton) {
                    if (viewButton.dataset.objectUrl) URL.revokeObjectURL(viewButton.dataset.objectUrl);
                    delete viewButton.dataset.objectUrl;
                    viewButton.hidden = true;
                }
                return;
            }

            const sizeMb = (file.size / (1024 * 1024)).toFixed(2);
            if (fileLabel) {
                fileLabel.innerHTML = '<strong>' + escapeHtml(file.name) + '</strong> (' + sizeMb + ' MB)';
            }
            if (uploadLabel) uploadLabel.textContent = 'Change File';
            if (button) button.classList.add('has-file');
            if (container) container.classList.add('has-file');
            if (itemIcon) {
                if (check.isPdf) {
                    itemIcon.className = 'fas fa-file-pdf text-danger';
                } else {
                    itemIcon.className = 'fas fa-file-image text-success';
                }
            }

            if (viewButton) {
                if (viewButton.dataset.objectUrl) {
                    URL.revokeObjectURL(viewButton.dataset.objectUrl);
                    delete viewButton.dataset.objectUrl;
                }
                const objectUrl = URL.createObjectURL(file);
                viewButton.href = objectUrl;
                viewButton.dataset.objectUrl = objectUrl;
                viewButton.hidden = false;
            }
        } else {
            if (fileLabel) {
                fileLabel.textContent = (input.dataset.requirementGroup === 'marriage' ? 'No file' : 'No file selected');
            }
            if (uploadLabel) uploadLabel.textContent = 'Choose File';
            if (button) button.classList.remove('has-file');
            if (container) container.classList.remove('has-file');
            if (itemIcon) itemIcon.className = 'fas fa-file-arrow-up';
            if (viewButton) {
                if (viewButton.dataset.objectUrl) URL.revokeObjectURL(viewButton.dataset.objectUrl);
                delete viewButton.dataset.objectUrl;
                viewButton.hidden = true;
            }
        }
    }

    function isCommunionSelected() {
        const selectedType = document.querySelector('input[name="request_type"]:checked');
        return selectedType && selectedType.value === 'first_communion_service';
    }

    function isConfirmationSelected() {
        const selectedType = document.querySelector('input[name="request_type"]:checked');
        return selectedType && selectedType.value === 'confirmation_service';
    }

    function isBaptismSelected() {
        const selectedType = document.querySelector('input[name="request_type"]:checked');
        return selectedType && selectedType.value === 'baptism_service';
    }

    function isMarriageSelected() {
        const selectedType = document.querySelector('input[name="request_type"]:checked');
        return selectedType && selectedType.value === 'marriage_wedding_service';
    }

    function isFuneralSelected() {
        const selectedType = document.querySelector('input[name="request_type"]:checked');
        return selectedType && selectedType.value === 'funeral_mass';
    }

    function isPatronalSelected() {
        const selectedType = document.querySelector('input[name="request_type"]:checked');
        return selectedType && selectedType.value === 'patronal_fiesta';
    }

    function getScheduledDate() {
        if (isCommunionSelected() || isConfirmationSelected()) {
            return '';
        }
        if (isBaptismSelected()) {
            return baptismDateInput ? baptismDateInput.value.trim() : '';
        }
        if (isMarriageSelected()) {
            return weddingDateInput ? weddingDateInput.value.trim() : '';
        }
        if (isFuneralSelected()) {
            return funeralBurialDateInput ? funeralBurialDateInput.value.trim() : '';
        }
        if (isPatronalSelected()) {
            return patronalDate ? patronalDate.value.trim() : '';
        }
        return generalServiceDate ? generalServiceDate.value.trim() : (preferredDate ? preferredDate.value.trim() : '');
    }

    function syncScheduleDate() {
        const dateVal = getScheduledDate();
        if (preferredDate) {
            preferredDate.value = dateVal;
        }
        if (scheduleSyncCard && !scheduleSyncCard.hidden && scheduleSyncText) {
            if (dateVal) {
                scheduleSyncText.innerHTML = 'Sacramental date: <strong style="color: #0f766e;">' + displayDate(dateVal) + '</strong> (automatically bound from your form above).';
            } else {
                scheduleSyncText.innerHTML = 'Your sacramental service date will automatically synchronize from your form above once selected.';
            }
        }
    }

    function toggleDateInputs() {
        const communionSelected = isCommunionSelected();
        const confirmationSelected = isConfirmationSelected();
        const baptismSelected = isBaptismSelected();
        const marriageSelected = isMarriageSelected();
        const funeralSelected = isFuneralSelected();
        const patronalSelected = isPatronalSelected();

        if (communionSelected || confirmationSelected) {
            // First Communion & Confirmation: Date and Time are set by Admin only.
            // Hide and unrequire date, time, location, and sync card.
            if (scheduleSyncCard) {
                scheduleSyncCard.hidden = true;
                scheduleSyncCard.style.display = 'none';
            }
            if (patronalGroup) {
                patronalGroup.style.display = 'none';
            }
            if (patronalDate) {
                patronalDate.disabled = true;
                patronalDate.required = false;
                patronalDate.value = '';
                clearFieldError(patronalDate);
            }
            if (generalServiceDateGroup) {
                generalServiceDateGroup.style.display = 'none';
            }
            if (generalServiceDate) {
                generalServiceDate.disabled = true;
                generalServiceDate.required = false;
                generalServiceDate.value = '';
                clearFieldError(generalServiceDate);
            }
            if (preferredDate) {
                preferredDate.disabled = true;
                preferredDate.value = '';
            }

            if (preferredTimeGroup) {
                preferredTimeGroup.style.display = 'none';
            }
            if (preferredTimeSelect) {
                preferredTimeSelect.disabled = true;
                preferredTimeSelect.required = false;
                preferredTimeSelect.value = '';
                clearFieldError(preferredTimeSelect);
            }

            if (locGroup) {
                locGroup.style.display = 'none';
            }
            if (locInput) {
                locInput.required = false;
                if (!locInput.value.trim()) {
                    locInput.value = 'San Lorenzo Ruiz Parish Church';
                }
                clearFieldError(locInput);
            }

            if (step3Title) {
                step3Title.textContent = 'Requirements & Additional Details';
            }
            if (step3Subtitle) {
                step3Subtitle.textContent = 'Upload required certificate documents and provide any additional details.';
            }
            if (communionConfNotice) {
                communionConfNotice.style.display = '';
            }
        } else {
            // Other sacramental services: standard schedule & location selection
            if (communionConfNotice) {
                communionConfNotice.style.display = 'none';
            }
            if (locGroup) {
                locGroup.style.display = '';
            }
            if (locInput) {
                locInput.disabled = false;
                locInput.required = true;
            }
            if (step3Title) {
                step3Title.textContent = 'Schedule and Location';
            }
            if (step3Subtitle) {
                step3Subtitle.textContent = 'Provide your preferred service schedule and complete location.';
            }

            if (baptismSelected) {
                if (preferredTimeGroup) preferredTimeGroup.style.display = '';
                if (preferredTimeSelect) {
                    preferredTimeSelect.disabled = false;
                    preferredTimeSelect.required = true;
                }
                if (scheduleSyncCard) {
                    scheduleSyncCard.hidden = false;
                    scheduleSyncCard.style.display = '';
                }
                if (scheduleSyncTitle) {
                    scheduleSyncTitle.textContent = 'Baptism Schedule Synchronized';
                }
                if (patronalGroup) patronalGroup.style.display = 'none';
                if (patronalDate) {
                    patronalDate.disabled = true;
                    patronalDate.required = false;
                }
                if (generalServiceDateGroup) generalServiceDateGroup.style.display = 'none';
                if (generalServiceDate) {
                    generalServiceDate.disabled = true;
                    generalServiceDate.required = false;
                }
            } else if (marriageSelected) {
                if (preferredTimeGroup) preferredTimeGroup.style.display = '';
                if (preferredTimeSelect) {
                    preferredTimeSelect.disabled = false;
                    preferredTimeSelect.required = true;
                }
                if (scheduleSyncCard) {
                    scheduleSyncCard.hidden = false;
                    scheduleSyncCard.style.display = '';
                }
                if (scheduleSyncTitle) {
                    scheduleSyncTitle.textContent = 'Wedding Schedule Synchronized';
                }
                if (patronalGroup) patronalGroup.style.display = 'none';
                if (patronalDate) {
                    patronalDate.disabled = true;
                    patronalDate.required = false;
                }
                if (generalServiceDateGroup) generalServiceDateGroup.style.display = 'none';
                if (generalServiceDate) {
                    generalServiceDate.disabled = true;
                    generalServiceDate.required = false;
                }
            } else if (funeralSelected) {
                if (preferredTimeGroup) preferredTimeGroup.style.display = '';
                if (preferredTimeSelect) {
                    preferredTimeSelect.disabled = false;
                    preferredTimeSelect.required = true;
                }
                if (scheduleSyncCard) {
                    scheduleSyncCard.hidden = false;
                    scheduleSyncCard.style.display = '';
                }
                if (scheduleSyncTitle) {
                    scheduleSyncTitle.textContent = 'Funeral Mass Schedule Synchronized';
                }
                if (patronalGroup) patronalGroup.style.display = 'none';
                if (patronalDate) {
                    patronalDate.disabled = true;
                    patronalDate.required = false;
                }
                if (generalServiceDateGroup) generalServiceDateGroup.style.display = 'none';
                if (generalServiceDate) {
                    generalServiceDate.disabled = true;
                    generalServiceDate.required = false;
                }
            } else if (patronalSelected) {
                if (preferredTimeGroup) preferredTimeGroup.style.display = '';
                if (preferredTimeSelect) {
                    preferredTimeSelect.disabled = false;
                    preferredTimeSelect.required = true;
                }
                if (scheduleSyncCard) {
                    scheduleSyncCard.hidden = true;
                    scheduleSyncCard.style.display = 'none';
                }
                if (patronalGroup) patronalGroup.style.display = '';
                if (patronalDate) {
                    patronalDate.disabled = false;
                    patronalDate.required = true;
                }
                if (generalServiceDateGroup) generalServiceDateGroup.style.display = 'none';
                if (generalServiceDate) {
                    generalServiceDate.disabled = true;
                    generalServiceDate.required = false;
                }
            } else {
                if (preferredTimeGroup) preferredTimeGroup.style.display = '';
                if (preferredTimeSelect) {
                    preferredTimeSelect.disabled = false;
                    preferredTimeSelect.required = true;
                }
                if (scheduleSyncCard) {
                    scheduleSyncCard.hidden = true;
                    scheduleSyncCard.style.display = 'none';
                }
                if (patronalGroup) patronalGroup.style.display = 'none';
                if (patronalDate) {
                    patronalDate.disabled = true;
                    patronalDate.required = false;
                }
                if (generalServiceDateGroup) generalServiceDateGroup.style.display = '';
                if (generalServiceDate) {
                    generalServiceDate.disabled = false;
                    generalServiceDate.required = true;
                }
            }
        }
        syncScheduleDate();
        updateSpecialRequirementsState();
    }

    function updateSpecialRequirementsState() {
        const communionSelected = isCommunionSelected();
        const confirmationSelected = isConfirmationSelected();
        const baptismSelected = isBaptismSelected();
        const marriageSelected = isMarriageSelected();
        const funeralSelected = isFuneralSelected();

        if (firstCommunionCard) {
            firstCommunionCard.hidden = !communionSelected;
        }
        if (confirmationCard) {
            confirmationCard.hidden = !confirmationSelected;
        }
        if (baptismRequirementsCard) {
            baptismRequirementsCard.hidden = !baptismSelected;
        }
        if (marriageRequirementsCard) {
            marriageRequirementsCard.hidden = !marriageSelected;
        }
        if (funeralRequirementsCard) {
            funeralRequirementsCard.hidden = !funeralSelected;
        }

        updateCommConfRequirementsState();

        communionFields.forEach(function(field) {
            field.disabled = !communionSelected;
            field.required = communionSelected;
            if (!field.required) {
                clearFieldError(field);
            }
        });

        confirmationFields.forEach(function(field) {
            field.disabled = !confirmationSelected;
            field.required = confirmationSelected;
            if (!field.required) {
                clearFieldError(field);
            }
        });

        baptismSheetFields.forEach(function(field) {
            field.disabled = !baptismSelected;
            field.required = baptismSelected;
            if (!field.required) {
                clearFieldError(field);
            }
        });

        marriageSheetFields.forEach(function(field) {
            field.disabled = !marriageSelected;
            const isOptional = field.id === 'marriage_additional_sponsors';
            field.required = marriageSelected && !isOptional;
            if (!field.required) {
                clearFieldError(field);
            }
        });

        funeralSheetFields.forEach(function(field) {
            field.disabled = !funeralSelected;
            const isOptional = field.id === 'funeral_minister';
            field.required = funeralSelected && !isOptional;
            if (!field.required) {
                clearFieldError(field);
            }
        });

        requirementFileInputs.forEach(function(input) {
            const isMandatory = input.dataset.requirementMandatory !== 'false';
            const belongsToActive = (input.dataset.requirementGroup === 'baptism' && baptismSelected) ||
                (input.dataset.requirementGroup === 'marriage' && marriageSelected) ||
                (input.dataset.requirementGroup === 'funeral' && funeralSelected);
            input.disabled = !belongsToActive;
            input.required = isMandatory && belongsToActive;
            if (!input.required) {
                clearFieldError(input);
            }
        });

        syncScheduleDate();

        if (baptismSelected) {
            const baptismSheetComplete = baptismSheetFields.every(function(field) {
                if (!field.required) return true;
                return field.value.trim() !== '';
            });
            const baptismFilesReady = requirementFilesReady('baptism');

            if (baptismRequirementWarning) {
                baptismRequirementWarning.classList.toggle('is-complete', baptismFilesReady);
                baptismRequirementWarning.innerHTML = baptismFilesReady
                    ? '<i class="fas fa-circle-check"></i><span>Each Baptism requirement has its own uploaded file.</span>'
                    : '<i class="fas fa-triangle-exclamation"></i><span>Upload one file for every Baptism requirement before submitting.</span>';
            }

            if (baptismSheetWarning) {
                baptismSheetWarning.classList.toggle('is-complete', baptismSheetComplete);
                baptismSheetWarning.innerHTML = baptismSheetComplete
                    ? '<i class="fas fa-circle-check"></i><span>Pre-Baptismal Investigation Sheet is complete.</span>'
                    : '<i class="fas fa-pen-to-square"></i><span>Complete all required fields in the Pre-Baptismal Investigation Sheet before submitting.</span>';
            }
        }

        if (marriageSelected) {
            const marriageSheetComplete = marriageSheetFields.every(function(field) {
                if (!field.required) return true;
                return field.value.trim() !== '';
            });
            const marriageFilesReady = requirementFilesReady('marriage');

            if (marriageRequirementWarning) {
                marriageRequirementWarning.classList.toggle('is-complete', marriageFilesReady);
                marriageRequirementWarning.innerHTML = marriageFilesReady
                    ? '<i class="fas fa-circle-check"></i><span>All required Marriage files are ready for parish review.</span>'
                    : '<i class="fas fa-triangle-exclamation"></i><span>Upload required Marriage files for both Male and Female before submitting.</span>';
            }

            if (marriageSheetWarning) {
                marriageSheetWarning.classList.toggle('is-complete', marriageSheetComplete);
                marriageSheetWarning.innerHTML = marriageSheetComplete
                    ? '<i class="fas fa-circle-check"></i><span>Pre-Nuptial Investigation Sheet is complete.</span>'
                    : '<i class="fas fa-pen-to-square"></i><span>Complete all required fields in the Pre-Nuptial Investigation Sheet before submitting.</span>';
            }
        }

        if (funeralSelected) {
            const funeralReady = requirementFilesReady('funeral');
            const requiredFuneralFields = funeralSheetFields.filter(function(f) { return f.required; });
            const funeralSheetComplete = requiredFuneralFields.every(function(f) {
                return f.value.trim() !== '';
            });
            if (funeralRequirementWarning) {
                const allReady = funeralReady && funeralSheetComplete;
                funeralRequirementWarning.style.display = allReady ? 'none' : '';
                funeralRequirementWarning.classList.toggle('is-complete', allReady);
                if (!funeralReady) {
                    funeralRequirementWarning.innerHTML = '<i class="fas fa-triangle-exclamation"></i><span>Upload the Death Certificate before submitting.</span>';
                } else if (!funeralSheetComplete) {
                    funeralRequirementWarning.innerHTML = '<i class="fas fa-pen-to-square"></i><span>Complete all required fields in the Funeral Investigation Sheet.</span>';
                }
            }
            if (funeralSheetWarning) {
                const allReady = funeralReady && funeralSheetComplete;
                funeralSheetWarning.style.display = allReady ? '' : 'none';
            }
        } else {
            if (funeralRequirementWarning) funeralRequirementWarning.style.display = 'none';
            if (funeralSheetWarning) funeralSheetWarning.style.display = 'none';
        }
    }

    function validationWrapper(field) {
        if (!field) return null;
        if (field.type === 'radio') {
            return document.querySelector('.request-type-grid');
        }
        return field.closest('.req-slot-col')
            || field.closest('.req-dropzone')
            || field.closest('.investigation-field')
            || field.closest('.investigation-grid-full')
            || field.closest('.baptism-sheet-field')
            || field.closest('.marriage-upload-cell')
            || field.closest('.requirement-upload-item')
            || field.closest('.pds-input-icon-wrap')
            || field.closest('.col-md-6')
            || field.closest('.col-12')
            || field.parentElement;
    }

    function getFieldFriendlyErrorMessage(field) {
        if (!field) return 'Please fill in this required field.';
        const id = field.id || '';
        const fieldMessages = {
            'communion_communicant_name': "Enter the communicant's full name",
            'communion_domicile': "Enter the communicant's domicile / address",
            'communion_father_name': "Enter the father's full name",
            'communion_mother_name': "Enter the mother's full maiden name",
            'communion_baptismal_date': "Enter a valid past baptismal date",
            'communion_baptismal_place': "Enter the place of baptism",
            'confirmation_fullname': "Enter the name of the confirmed person",
            'confirmation_age': "Enter a valid age between 7 and 120",
            'confirmation_origin_parish': "Enter the parish of origin",
            'confirmation_province': "Enter the province",
            'confirmation_baptismal_place': "Enter the place of baptism",
            'confirmation_father_name': "Enter the father's full name",
            'confirmation_mother_name': "Enter the mother's full maiden name",
            'confirmation_sponsor': "Enter the sponsor / godparent's name",
            'baptism_child_name': "Enter the child's full name",
            'baptism_birth_date': "Enter the child's date of birth",
            'baptism_birth_place': "Enter the place of birth",
            'baptism_date': "Enter the scheduled date of baptism",
            'baptism_father_name': "Enter the father's complete name",
            'baptism_father_origin': "Enter the father's place of origin / residence",
            'baptism_mother_name': "Enter the mother's complete maiden name",
            'baptism_mother_origin': "Enter the mother's place of origin / residence",
            'baptism_parents_marriage': "Enter the parents' marriage status",
            'baptism_sponsor_male_name': "Enter the principal male sponsor",
            'baptism_sponsor_female_name': "Enter the principal female sponsor",
            'marriage_groom_name': "Enter the groom's full name",
            'marriage_groom_birth_date': "Enter the groom's date of birth",
            'marriage_groom_birth_place': "Enter the groom's place of birth",
            'marriage_groom_residence': "Enter the groom's residence",
            'marriage_groom_religion': "Enter the groom's religion",
            'marriage_groom_father_name': "Enter the groom's father's name",
            'marriage_groom_mother_name': "Enter the groom's mother's maiden name",
            'marriage_bride_name': "Enter the bride's full name",
            'marriage_bride_birth_date': "Enter the bride's date of birth",
            'marriage_bride_birth_place': "Enter the bride's place of birth",
            'marriage_bride_residence': "Enter the bride's residence",
            'marriage_bride_religion': "Enter the bride's religion",
            'marriage_bride_father_name': "Enter the bride's father's name",
            'marriage_bride_mother_name': "Enter the bride's mother's maiden name",
            'marriage_witness_male': "Enter the male principal sponsor",
            'marriage_witness_female': "Enter the female principal sponsor",
            'marriage_wedding_date': "Enter the scheduled wedding date",
            'funeral_deceased_name': "Enter the deceased's full name",
            'funeral_date_of_death': "Enter the date of death",
            'funeral_date_of_burial': "Enter the date of burial / funeral mass",
            'funeral_civil_status': "Select the deceased's civil status",
            'funeral_rites': "Select the type of funeral rites",
            'funeral_cause_of_death': "Enter the cause of death",
            'funeral_place_of_burial': "Enter the place of burial / cemetery",
            'preferred_time': "Select your preferred time slot",
            'general_service_date': "Select your requested service date",
            'patronal_fiesta_date': "Select the date of patronal fiesta",
            'location': "Enter the service location"
        };
        if (fieldMessages[id]) {
            return fieldMessages[id];
        }
        const wrapper = validationWrapper(field);
        if (wrapper) {
            const label = wrapper.querySelector('label');
            if (label) {
                const labelText = label.textContent.replace(/[*0-9\.\(\)]/g, '').replace(/optional/i, '').trim();
                if (labelText) return 'Please fill in ' + labelText + '.';
            }
        }
        return 'Please fill in this required field.';
    }

    function clearFieldError(field) {
        if (!field) return;
        const wrapper = validationWrapper(field);
        if (wrapper) {
            wrapper.classList.remove('request-field-error', 'is-missing');
            const error = wrapper.querySelector(':scope > .request-inline-error');
            if (error) {
                error.remove();
            }
        }
        if (field.type === 'radio') {
            document.querySelectorAll('input[name="request_type"]').forEach(function(radio) {
                radio.removeAttribute('aria-invalid');
                radio.classList.remove('is-invalid');
            });
        } else {
            field.removeAttribute('aria-invalid');
            field.classList.remove('is-invalid');
        }
    }

    function addFieldError(field, customMsg) {
        if (!field) return;
        const wrapper = validationWrapper(field);
        if (!wrapper) return;
        wrapper.classList.add('request-field-error');
        field.setAttribute('aria-invalid', 'true');
        field.classList.add('is-invalid');
        let error = wrapper.querySelector(':scope > .request-inline-error');
        if (!error) {
            error = document.createElement('div');
            error.className = 'request-inline-error';
            wrapper.appendChild(error);
        }
        const msgText = customMsg || getFieldFriendlyErrorMessage(field);
        error.innerHTML = '<i class="fas fa-triangle-exclamation"></i><span>' + escapeHtml(msgText) + '</span>';
    }

    function fieldIsComplete(field) {
        if (field.type === 'radio') {
            return Boolean(serviceForm.querySelector('input[name="' + field.name + '"]:checked'));
        }
        if (field.type === 'file') {
            return Boolean(field.files && field.files.length);
        }
        return field.value.trim() !== '' && field.checkValidity();
    }

    function validateSingleFieldOnBlur(field) {
        if (!field || field.disabled) return;
        if (field.type === 'button' || field.type === 'submit' || field.type === 'reset') return;
        if (field.type === 'file') return;

        const val = field.value.trim();
        if (field.required && !val) {
            addFieldError(field);
            return;
        }

        if (val) {
            if (field.id === 'communion_baptismal_date') {
                const todayStr = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila' }).format(new Date());
                if (val > todayStr) {
                    addFieldError(field, "Baptismal date must be a valid past date (cannot be in the future).");
                    return;
                }
            } else if (field.id === 'confirmation_age') {
                const age = parseInt(val, 10);
                if (isNaN(age) || age < 7 || age > 120) {
                    addFieldError(field, "Age must be a valid whole number between 7 and 120.");
                    return;
                }
            } else if (['communion_communicant_name', 'communion_father_name', 'communion_mother_name', 'confirmation_fullname', 'confirmation_father_name', 'confirmation_mother_name', 'confirmation_sponsor'].includes(field.id)) {
                const nameRegex = /^[a-zA-Z\s\.\'\-ñÑ\u00C0-\u017F]+$/;
                if (!nameRegex.test(val)) {
                    addFieldError(field, "Name should contain letters, spaces, hyphens, and apostrophes only.");
                    return;
                }
            }
        }

        clearFieldError(field);
        if (validationBanner) {
            validationBanner.hidden = !serviceForm.querySelector('.request-field-error');
        }
    }

    function validateForReview() {
        updateSpecialRequirementsState();
        let invalidFields = [];
        const seenRadioGroups = new Set();

        // 1. In-progress upload check
        const progressSlots = Array.from(document.querySelectorAll('.req-slot-progress'));
        const activeProgress = progressSlots.some(function(slot) {
            return slot.style.display !== 'none' && slot.offsetParent !== null;
        });
        if (activeProgress) {
            window._pendingReviewAfterUpload = true;
            showServiceError('Uploading your document... please wait.');
            return false;
        }

        // 2. Active required fields check
        serviceForm.querySelectorAll('[required]').forEach(function(field) {
            if (field.disabled) {
                return;
            }
            if (field === bapCertInput || field === commCertInput) {
                return;
            }
            if (field.type === 'radio') {
                if (seenRadioGroups.has(field.name)) {
                    return;
                }
                seenRadioGroups.add(field.name);
            }
            clearFieldError(field);
            if (!fieldIsComplete(field)) {
                invalidFields.push(field);
                addFieldError(field);
            }
        });

        // 3. Communion-specific validation
        if (isCommunionSelected()) {
            if (!bapCertInput || !bapCertInput.files || !bapCertInput.files.length) {
                if (bapCertInput && !invalidFields.includes(bapCertInput)) invalidFields.push(bapCertInput);
                if (bapCertDropzone) bapCertDropzone.classList.add('has-error');
                if (bapCertError) {
                    const t = bapCertError.querySelector('.req-error-text') || bapCertError;
                    t.textContent = 'Please upload your Baptismal Certificate before submitting.';
                    bapCertError.style.display = '';
                }
            } else {
                if (bapCertDropzone) bapCertDropzone.classList.remove('has-error');
                if (bapCertError) bapCertError.style.display = 'none';
            }

            const cEl = document.getElementById('communion_communicant_name');
            const cName = formValue('communion_communicant_name');
            if (!cName) {
                addFieldError(cEl, "Enter the communicant's full name");
                if (cEl && !invalidFields.includes(cEl)) invalidFields.push(cEl);
            } else {
                const nameRegex = /^[a-zA-Z\s\.\'\-ñÑ\u00C0-\u017F]+$/;
                if (!nameRegex.test(cName)) {
                    addFieldError(cEl, "Communicant's name should contain letters, spaces, hyphens, and apostrophes only.");
                    if (cEl && !invalidFields.includes(cEl)) invalidFields.push(cEl);
                } else {
                    clearFieldError(cEl);
                }
            }

            const domEl = document.getElementById('communion_domicile');
            const domVal = formValue('communion_domicile');
            if (!domVal) {
                addFieldError(domEl, "Enter the communicant's domicile / address");
                if (domEl && !invalidFields.includes(domEl)) invalidFields.push(domEl);
            } else {
                clearFieldError(domEl);
            }

            const fEl = document.getElementById('communion_father_name');
            const fName = formValue('communion_father_name');
            if (!fName) {
                addFieldError(fEl, "Enter the father's full name");
                if (fEl && !invalidFields.includes(fEl)) invalidFields.push(fEl);
            } else {
                const nameRegex = /^[a-zA-Z\s\.\'\-ñÑ\u00C0-\u017F]+$/;
                if (!nameRegex.test(fName)) {
                    addFieldError(fEl, "Father's name should contain letters, spaces, hyphens, and apostrophes only.");
                    if (fEl && !invalidFields.includes(fEl)) invalidFields.push(fEl);
                } else {
                    clearFieldError(fEl);
                }
            }

            const mEl = document.getElementById('communion_mother_name');
            const mName = formValue('communion_mother_name');
            if (!mName) {
                addFieldError(mEl, "Enter the mother's full maiden name");
                if (mEl && !invalidFields.includes(mEl)) invalidFields.push(mEl);
            } else {
                const nameRegex = /^[a-zA-Z\s\.\'\-ñÑ\u00C0-\u017F]+$/;
                if (!nameRegex.test(mName)) {
                    addFieldError(mEl, "Mother's maiden name should contain letters, spaces, hyphens, and apostrophes only.");
                    if (mEl && !invalidFields.includes(mEl)) invalidFields.push(mEl);
                } else {
                    clearFieldError(mEl);
                }
            }

            const bapEl = document.getElementById('communion_baptismal_date');
            const bapD = formValue('communion_baptismal_date');
            if (!bapD) {
                addFieldError(bapEl, "Enter a valid past baptismal date");
                if (bapEl && !invalidFields.includes(bapEl)) invalidFields.push(bapEl);
            } else {
                const todayStr = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila' }).format(new Date());
                if (bapD > todayStr) {
                    addFieldError(bapEl, "Baptismal date must be a valid past date (cannot be in the future).");
                    if (bapEl && !invalidFields.includes(bapEl)) invalidFields.push(bapEl);
                } else {
                    clearFieldError(bapEl);
                }
            }

            const placeEl = document.getElementById('communion_baptismal_place');
            const placeVal = formValue('communion_baptismal_place');
            if (!placeVal) {
                addFieldError(placeEl, "Enter the place of baptism");
                if (placeEl && !invalidFields.includes(placeEl)) invalidFields.push(placeEl);
            } else {
                clearFieldError(placeEl);
            }
        }

        // 4. Confirmation-specific validation
        if (isConfirmationSelected()) {
            if (!bapCertInput || !bapCertInput.files || !bapCertInput.files.length) {
                if (bapCertInput && !invalidFields.includes(bapCertInput)) invalidFields.push(bapCertInput);
                if (bapCertDropzone) bapCertDropzone.classList.add('has-error');
                if (bapCertError) {
                    const t = bapCertError.querySelector('.req-error-text') || bapCertError;
                    t.textContent = 'Please upload your Baptismal Certificate before submitting.';
                    bapCertError.style.display = '';
                }
            } else {
                if (bapCertDropzone) bapCertDropzone.classList.remove('has-error');
                if (bapCertError) bapCertError.style.display = 'none';
            }

            if (!commCertInput || !commCertInput.files || !commCertInput.files.length) {
                if (commCertInput && !invalidFields.includes(commCertInput)) invalidFields.push(commCertInput);
                if (commCertDropzone) commCertDropzone.classList.add('has-error');
                if (commCertError) {
                    const t = commCertError.querySelector('.req-error-text') || commCertError;
                    t.textContent = 'Please upload your First Communion Certificate before submitting.';
                    commCertError.style.display = '';
                }
            } else {
                if (commCertDropzone) commCertDropzone.classList.remove('has-error');
                if (commCertError) commCertError.style.display = 'none';
            }

            const nameRegex = /^[a-zA-Z\s\.\'\-ñÑ\u00C0-\u017F]+$/;
            const cfEl = document.getElementById('confirmation_fullname');
            const cfName = formValue('confirmation_fullname');
            if (!cfName) {
                addFieldError(cfEl, "Enter the name of the confirmed person");
                if (cfEl && !invalidFields.includes(cfEl)) invalidFields.push(cfEl);
            } else if (!nameRegex.test(cfName)) {
                addFieldError(cfEl, "Name should contain letters, spaces, hyphens, and apostrophes only.");
                if (cfEl && !invalidFields.includes(cfEl)) invalidFields.push(cfEl);
            } else {
                clearFieldError(cfEl);
            }

            const ageEl = document.getElementById('confirmation_age');
            const ageVal = parseInt(formValue('confirmation_age'), 10);
            if (isNaN(ageVal) || ageVal < 7 || ageVal > 120) {
                addFieldError(ageEl, "Age must be a valid whole number between 7 and 120.");
                if (ageEl && !invalidFields.includes(ageEl)) invalidFields.push(ageEl);
            } else {
                clearFieldError(ageEl);
            }

            const parEl = document.getElementById('confirmation_origin_parish');
            if (!formValue('confirmation_origin_parish')) {
                addFieldError(parEl, "Enter the parish of origin");
                if (parEl && !invalidFields.includes(parEl)) invalidFields.push(parEl);
            } else {
                clearFieldError(parEl);
            }

            const provEl = document.getElementById('confirmation_province');
            if (!formValue('confirmation_province')) {
                addFieldError(provEl, "Enter the province");
                if (provEl && !invalidFields.includes(provEl)) invalidFields.push(provEl);
            } else {
                clearFieldError(provEl);
            }

            const bPlEl = document.getElementById('confirmation_baptismal_place');
            if (!formValue('confirmation_baptismal_place')) {
                addFieldError(bPlEl, "Enter the place of baptism");
                if (bPlEl && !invalidFields.includes(bPlEl)) invalidFields.push(bPlEl);
            } else {
                clearFieldError(bPlEl);
            }

            const fEl = document.getElementById('confirmation_father_name');
            const fName = formValue('confirmation_father_name');
            if (!fName) {
                addFieldError(fEl, "Enter the father's full name");
                if (fEl && !invalidFields.includes(fEl)) invalidFields.push(fEl);
            } else if (!nameRegex.test(fName)) {
                addFieldError(fEl, "Father's name should contain letters, spaces, hyphens, and apostrophes only.");
                if (fEl && !invalidFields.includes(fEl)) invalidFields.push(fEl);
            } else {
                clearFieldError(fEl);
            }

            const mEl = document.getElementById('confirmation_mother_name');
            const mName = formValue('confirmation_mother_name');
            if (!mName) {
                addFieldError(mEl, "Enter the mother's full maiden name");
                if (mEl && !invalidFields.includes(mEl)) invalidFields.push(mEl);
            } else if (!nameRegex.test(mName)) {
                addFieldError(mEl, "Mother's maiden name should contain letters, spaces, hyphens, and apostrophes only.");
                if (mEl && !invalidFields.includes(mEl)) invalidFields.push(mEl);
            } else {
                clearFieldError(mEl);
            }

            const spEl = document.getElementById('confirmation_sponsor');
            const spName = formValue('confirmation_sponsor');
            if (!spName) {
                addFieldError(spEl, "Enter the sponsor / godparent's name");
                if (spEl && !invalidFields.includes(spEl)) invalidFields.push(spEl);
            } else if (!nameRegex.test(spName)) {
                addFieldError(spEl, "Sponsor's name should contain letters, spaces, hyphens, and apostrophes only.");
                if (spEl && !invalidFields.includes(spEl)) invalidFields.push(spEl);
            } else {
                clearFieldError(spEl);
            }
        }

        // Filter out any disabled fields
        invalidFields = invalidFields.filter(function(f) {
            return f && !f.disabled;
        });

        if (invalidFields.length > 0) {
            const count = invalidFields.length;
            const msg = count === 1 ? 'Please fix 1 field to continue.' : ('Please fix ' + count + ' fields to continue.');
            if (validationBanner) {
                validationBanner.innerHTML = '<i class="fas fa-triangle-exclamation"></i> <span>' + msg + '</span>';
                validationBanner.setAttribute('aria-live', 'polite');
                validationBanner.setAttribute('role', 'alert');
                validationBanner.hidden = false;
            }
            const firstWrapper = validationWrapper(invalidFields[0]) || invalidFields[0];
            firstWrapper.scrollIntoView({behavior: 'smooth', block: 'center'});
            window.setTimeout(function() {
                if (invalidFields[0].type !== 'file' && typeof invalidFields[0].focus === 'function') {
                    invalidFields[0].focus({preventScroll: true});
                }
            }, 350);
            return false;
        }

        if (validationBanner) {
            validationBanner.hidden = true;
        }

        if (!isCommunionSelected() && !isConfirmationSelected() && window.hasScheduleConflictState) {
            const prefTime = document.getElementById('preferred_time');
            if (prefTime) {
                clearFieldError(prefTime);
                addFieldError(prefTime, 'This date and time is already occupied. Please choose another slot.');
                prefTime.classList.add('is-invalid');
                const timeWrap = validationWrapper(prefTime);
                if (timeWrap) {
                    timeWrap.scrollIntoView({behavior: 'smooth', block: 'center'});
                }
                prefTime.focus({preventScroll: true});
            }
            showServiceError(window.lastConflictMessage || 'This date and time is already occupied. Please choose another date or time.');
            return false;
        }

        return true;
    }

    function displayDate(value) {
        if (!value) {
            return 'Not provided';
        }
        const date = new Date(value + 'T00:00:00');
        return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString('en-PH', {
            year: 'numeric', month: 'long', day: 'numeric'
        });
    }

    function displayTime(value) {
        if (!value) {
            return 'Not provided';
        }
        const parts = value.split(':');
        const date = new Date(2000, 0, 1, Number(parts[0]), Number(parts[1]));
        return date.toLocaleTimeString('en-PH', {hour: 'numeric', minute: '2-digit'});
    }

    function renderReviewItems(containerId, items) {
        const container = document.getElementById(containerId);
        container.replaceChildren();
        items.forEach(function(item) {
            const block = document.createElement('div');
            block.className = 'request-review-item';
            const term = document.createElement('dt');
            const description = document.createElement('dd');
            term.textContent = item[0];
            description.textContent = item[1] || 'Not provided';
            block.append(term, description);
            container.appendChild(block);
        });
    }

    function formValue(id) {
        const field = document.getElementById(id);
        return field ? field.value.trim() : '';
    }

    function populateReview() {
        const selectedType = serviceForm.querySelector('input[name="request_type"]:checked');
        const selectedLabel = selectedType ? selectedType.closest('label').querySelector('strong').textContent.trim() : '';
        const activeFileNames = requirementFileInputs.filter(function(input) {
            return input.required && input.files && input.files.length;
        }).map(function(input) {
            return input.files[0].name;
        });
        renderReviewItems('reviewServiceInfo', [
            ['Requested Service', selectedLabel],
            ['Requirements Attached', activeFileNames.length ? activeFileNames.join(', ') : 'Not required']
        ]);

        const communionSelected = isCommunionSelected();
        const confirmationSelected = isConfirmationSelected();
        const baptismSelected = isBaptismSelected();
        const marriageSelected = isMarriageSelected();
        const funeralSelected = isFuneralSelected();

        const reviewCommSec = document.getElementById('reviewCommunionSection');
        const reviewConfSec = document.getElementById('reviewConfirmationSection');
        const reviewReqSec = document.getElementById('reviewRequirementsSection');
        const reviewReqGrid = document.getElementById('reviewRequirementsGrid');
        if (reviewCommSec) reviewCommSec.hidden = !communionSelected;
        if (reviewConfSec) reviewConfSec.hidden = !confirmationSelected;
        document.getElementById('reviewChildSection').hidden = !baptismSelected;
        document.getElementById('reviewParentsSection').hidden = !baptismSelected;
        document.getElementById('reviewGodparentsSection').hidden = !baptismSelected;
        document.getElementById('reviewFuneralSection').hidden = !funeralSelected;

        if (reviewReqSec && reviewReqGrid) {
            if (communionSelected || confirmationSelected) {
                reviewReqSec.hidden = false;
                reviewReqGrid.replaceChildren();

                const addReviewRequirement = function(label, input) {
                    const block = document.createElement('div');
                    block.className = 'request-review-item';
                    const term = document.createElement('dt');
                    term.textContent = label;
                    const description = document.createElement('dd');

                    if (input && input.files && input.files[0]) {
                        const file = input.files[0];
                        const blobUrl = URL.createObjectURL(file);
                        const fileWrap = document.createElement('div');
                        fileWrap.className = 'd-flex align-items-center justify-content-between flex-wrap gap-2';
                        fileWrap.innerHTML = '<span><i class="fas fa-file-check text-success me-1"></i> <strong>' + escapeHtml(file.name) + '</strong> (' + formatFileSize(file.size) + ')</span>' +
                            '<a href="' + blobUrl + '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary" style="min-height: 44px; display: inline-flex; align-items: center;"><i class="fas fa-eye me-1"></i> Preview</a>';
                        description.appendChild(fileWrap);
                    } else {
                        description.innerHTML = '<span class="text-danger fw-semibold"><i class="fas fa-circle-exclamation me-1"></i> Missing</span>';
                    }
                    block.append(term, description);
                    reviewReqGrid.appendChild(block);
                };

                addReviewRequirement('Baptismal Certificate', bapCertInput);
                if (confirmationSelected) {
                    addReviewRequirement('First Communion Certificate', commCertInput);
                }
            } else {
                reviewReqSec.hidden = true;
            }
        }

        if (communionSelected) {
            const fName = formValue('communion_father_name');
            const mName = formValue('communion_mother_name');
            const parentsStr = (fName && mName) ? (fName + ' / ' + mName) : (fName || mName || 'Not provided');
            renderReviewItems('reviewCommunionInfo', [
                ['Name of Communicant', formValue('communion_communicant_name')],
                ['Domicile', formValue('communion_domicile')],
                ['Father\'s Full Name', fName || 'None'],
                ['Mother\'s Full Maiden Name', mName || 'None'],
                ['Parents', parentsStr],
                ['Baptismal Date', displayDate(formValue('communion_baptismal_date'))],
                ['Baptismal Place', formValue('communion_baptismal_place')]
            ]);
        }

        if (confirmationSelected) {
            const fName = formValue('confirmation_father_name');
            const mName = formValue('confirmation_mother_name');
            const parentsStr = (fName && mName) ? (fName + ' / ' + mName) : (fName || mName || 'Not provided');
            renderReviewItems('reviewConfirmationInfo', [
                ['Name of Confirmed Person', formValue('confirmation_fullname')],
                ['Age', formValue('confirmation_age')],
                ['Parish of Origin', formValue('confirmation_origin_parish')],
                ['Province', formValue('confirmation_province')],
                ['Place of Baptism', formValue('confirmation_baptismal_place')],
                ['Father\'s Full Name', fName || 'None'],
                ['Mother\'s Full Maiden Name', mName || 'None'],
                ['Parents', parentsStr],
                ['Sponsor / Godparent', formValue('confirmation_sponsor')]
            ]);
        }

        if (baptismSelected) {
            renderReviewItems('reviewChildInfo', [
                ['Name of Child', formValue('baptism_child_name')],
                ['Date of Birth', displayDate(formValue('baptism_birth_date'))],
                ['Place of Birth', formValue('baptism_birth_place')],
                ['Date of Baptism', displayDate(formValue('baptism_date'))]
            ]);
            renderReviewItems('reviewParents', [
                ['Father\'s Complete Name', formValue('baptism_father_name')],
                ['Father\'s Place of Origin / Residence', formValue('baptism_father_origin')],
                ['Mother\'s Complete Maiden Name', formValue('baptism_mother_name')],
                ['Mother\'s Place of Origin / Residence', formValue('baptism_mother_origin')],
                ['Parents\' Marriage Status', formValue('baptism_parents_marriage')]
            ]);
            const maleSponsor = formValue('baptism_sponsor_male_name') + (formValue('baptism_sponsor_male_origin') ? ' (' + formValue('baptism_sponsor_male_origin') + ')' : '');
            const femaleSponsor = formValue('baptism_sponsor_female_name') + (formValue('baptism_sponsor_female_origin') ? ' (' + formValue('baptism_sponsor_female_origin') + ')' : '');
            renderReviewItems('reviewGodparents', [
                ['Principal Male Sponsor (Ninong)', maleSponsor],
                ['Principal Female Sponsor (Ninang)', femaleSponsor],
                ['Additional Sponsors', formValue('baptism_godparents') || 'None']
            ]);
        }

        const reviewGroomSec = document.getElementById('reviewGroomSection');
        const reviewBrideSec = document.getElementById('reviewBrideSection');
        const reviewMarriageSponsorsSec = document.getElementById('reviewMarriageSponsorsSection');

        if (reviewGroomSec) reviewGroomSec.hidden = !marriageSelected;
        if (reviewBrideSec) reviewBrideSec.hidden = !marriageSelected;
        if (reviewMarriageSponsorsSec) reviewMarriageSponsorsSec.hidden = !marriageSelected;

        if (marriageSelected) {
            renderReviewItems('reviewGroomInfo', [
                ['Full Name of Groom', formValue('marriage_groom_name')],
                ['Date of Birth', displayDate(formValue('marriage_groom_birth_date'))],
                ['Place of Birth', formValue('marriage_groom_birth_place')],
                ['Place of Origin / Current Residence', formValue('marriage_groom_residence')],
                ['Religion / Church of Baptism', formValue('marriage_groom_religion')],
                ['Father\'s Complete Name', formValue('marriage_groom_father_name')],
                ['Mother\'s Complete Maiden Name', formValue('marriage_groom_mother_name')]
            ]);
            renderReviewItems('reviewBrideInfo', [
                ['Full Maiden Name of Bride', formValue('marriage_bride_name')],
                ['Date of Birth', displayDate(formValue('marriage_bride_birth_date'))],
                ['Place of Birth', formValue('marriage_bride_birth_place')],
                ['Place of Origin / Current Residence', formValue('marriage_bride_residence')],
                ['Religion / Church of Baptism', formValue('marriage_bride_religion')],
                ['Father\'s Complete Name', formValue('marriage_bride_father_name')],
                ['Mother\'s Complete Maiden Name', formValue('marriage_bride_mother_name')]
            ]);
            renderReviewItems('reviewMarriageSponsorsInfo', [
                ['Male Principal Sponsor (Ninong)', formValue('marriage_witness_male')],
                ['Female Principal Sponsor (Ninang)', formValue('marriage_witness_female')],
                ['Additional Sponsors / Entourage', formValue('marriage_additional_sponsors') || 'None'],
                ['Date of Marriage', displayDate(formValue('marriage_wedding_date'))]
            ]);
        }

        if (funeralSelected) {
            renderReviewItems('reviewFuneralInfo', [
                ['Deceased Full Name', formValue('funeral_deceased_name')],
                ['Date of Death', displayDate(formValue('funeral_date_of_death'))],
                ['Date of Burial / Funeral Mass', displayDate(formValue('funeral_date_of_burial'))],
                ['Civil Status', formValue('funeral_civil_status') || 'Not provided'],
                ['Type of Funeral Rites', formValue('funeral_rites') || 'Not provided'],
                ['Cause of Death', formValue('funeral_cause_of_death') || 'Not provided'],
                ['Place of Burial', formValue('funeral_place_of_burial') || 'Not provided'],
                ['Minister / Officiant', formValue('funeral_minister') || 'To be assigned by the parish']
            ]);
        }

        const reviewSchedHead = document.getElementById('reviewScheduleHeading');
        if (reviewSchedHead) {
            if (communionSelected || confirmationSelected) {
                reviewSchedHead.innerHTML = '<i class="fas fa-user-circle"></i> Applicant &amp; Additional Details';
            } else {
                reviewSchedHead.innerHTML = '<i class="fas fa-calendar-check"></i> Applicant, Schedule, and Location';
            }
        }

        const scheduleReviewItems = [
            ['Applicant', <?php echo json_encode((string) ($_SESSION['fullname'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>],
            ['Email Address', <?php echo json_encode((string) ($_SESSION['email'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>]
        ];

        if (!communionSelected && !confirmationSelected) {
            const scheduleDate = getScheduledDate();
            let scheduleDateLabel = 'Preferred Date';
            if (baptismSelected) {
                scheduleDateLabel = 'Date of Baptism';
            } else if (marriageSelected) {
                scheduleDateLabel = 'Date of Marriage';
            } else if (funeralSelected) {
                scheduleDateLabel = 'Date of Burial / Funeral Mass';
            } else if (isPatronalSelected()) {
                scheduleDateLabel = 'Date of Patronal Fiesta';
            }
            scheduleReviewItems.push([scheduleDateLabel, displayDate(scheduleDate)]);
            scheduleReviewItems.push(['Preferred Time', displayTime(formValue('preferred_time'))]);
            scheduleReviewItems.push(['Location', formValue('location')]);
        }

        scheduleReviewItems.push(['Additional Details', formValue('details') || 'None']);
        renderReviewItems('reviewScheduleInfo', scheduleReviewItems);
    }

    function openReview() {
        try {
            if (!validateForReview()) {
                return;
            }
            populateReview();
            if (validationBanner) {
                validationBanner.hidden = true;
            }
            entryPanel.hidden = true;
            reviewPanel.hidden = false;
            reviewPanel.scrollIntoView({behavior: 'smooth', block: 'start'});
        } catch (err) {
            console.error('Error opening review screen:', err);
            showServiceError('Something went wrong preparing the review screen. Please try again.');
        }
    }

    const typeRadios = document.querySelectorAll('input[name="request_type"]');

    if (typeRadios.length) {
        typeRadios.forEach(function(radio) {
            radio.addEventListener('change', toggleDateInputs);
        });
        communionFields.forEach(function(field) {
            field.addEventListener('input', function() {
                if (['communion_communicant_name', 'communion_father_name', 'communion_mother_name'].includes(field.id)) {
                    field.value = field.value.toUpperCase();
                }
                updateSpecialRequirementsState();
            });
            field.addEventListener('change', updateSpecialRequirementsState);
        });
        confirmationFields.forEach(function(field) {
            field.addEventListener('input', function() {
                if (['confirmation_fullname', 'confirmation_father_name', 'confirmation_mother_name', 'confirmation_sponsor'].includes(field.id)) {
                    field.value = field.value.toUpperCase();
                }
                updateSpecialRequirementsState();
            });
            field.addEventListener('change', updateSpecialRequirementsState);
        });
        baptismSheetFields.forEach(function(field) {
            field.addEventListener('input', updateSpecialRequirementsState);
            field.addEventListener('change', updateSpecialRequirementsState);
        });
        marriageSheetFields.forEach(function(field) {
            field.addEventListener('input', updateSpecialRequirementsState);
            field.addEventListener('change', updateSpecialRequirementsState);
        });
        funeralSheetFields.forEach(function(field) {
            field.addEventListener('input', updateSpecialRequirementsState);
            field.addEventListener('change', updateSpecialRequirementsState);
        });
        if (baptismDateInput) {
            baptismDateInput.addEventListener('input', syncScheduleDate);
            baptismDateInput.addEventListener('change', syncScheduleDate);
        }
        if (weddingDateInput) {
            weddingDateInput.addEventListener('input', syncScheduleDate);
            weddingDateInput.addEventListener('change', syncScheduleDate);
        }
        if (funeralBurialDateInput) {
            funeralBurialDateInput.addEventListener('input', syncScheduleDate);
            funeralBurialDateInput.addEventListener('change', syncScheduleDate);
        }
        if (patronalDate) {
            patronalDate.addEventListener('input', syncScheduleDate);
            patronalDate.addEventListener('change', syncScheduleDate);
        }
        if (generalServiceDate) {
            generalServiceDate.addEventListener('input', syncScheduleDate);
            generalServiceDate.addEventListener('change', syncScheduleDate);
        }
        requirementFileInputs.forEach(function(input) {
            input.addEventListener('change', function() {
                updateRequirementFileLabel(input);
                updateSpecialRequirementsState();
            });
        });

        // Initialize Communion & Confirmation requirement slots
        if (bapCertDropzone && bapCertInput) {
            setupReqSlot(bapCertDropzone, bapCertInput, bapCertCamera, bapCertError);
        }
        if (commCertDropzone && commCertInput) {
            setupReqSlot(commCertDropzone, commCertInput, commCertCamera, commCertError);
        }

        toggleDateInputs();
        updateSpecialRequirementsState();
    }

    serviceForm.addEventListener('focusout', function(event) {
        validateSingleFieldOnBlur(event.target);
    });
    serviceForm.addEventListener('input', function(event) {
        const field = event.target;
        if (field.classList.contains('is-invalid') || (validationWrapper(field) && validationWrapper(field).classList.contains('request-field-error'))) {
            validateSingleFieldOnBlur(field);
        }
    });
    serviceForm.addEventListener('change', function(event) {
        const field = event.target;
        if (field.classList.contains('is-invalid') || (validationWrapper(field) && validationWrapper(field).classList.contains('request-field-error'))) {
            validateSingleFieldOnBlur(field);
        }
    });
    serviceForm.addEventListener('keydown', function(event) {
        if (event.key === 'Enter' && event.target.tagName !== 'TEXTAREA') {
            if (reviewPanel && reviewPanel.hidden) {
                event.preventDefault();
                openReview();
            }
        }
    });
    function showServiceError(message) {
        if (validationBanner) {
            validationBanner.innerHTML = '<i class="fas fa-triangle-exclamation"></i> <span>' + message + '</span>';
            validationBanner.hidden = false;
            validationBanner.scrollIntoView({behavior: 'smooth', block: 'center'});
        }
        if (typeof ParishToast !== 'undefined' && typeof ParishToast.show === 'function') {
            ParishToast.show({
                title: 'Submission Error',
                message: message,
                type: 'error',
                duration: 7000
            });
        }
    }

    async function executeServiceSubmission(isRetry = false) {
        if (!validateForReview()) {
            reviewPanel.hidden = true;
            entryPanel.hidden = false;
            return;
        }

        // 1. Client-side file size and format validation (enforce 5 MB and PDF/images only)
        let totalFileSize = 0;
        const oversizedFiles = [];
        const invalidFormatFiles = [];
        const allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
        const invalidMsg = 'Only PDF or image files (JPG, PNG, WEBP) up to 5 MB are allowed. Please convert your document and upload again.';

        requirementFileInputs.forEach(function(input) {
            if (input.files && input.files[0]) {
                const f = input.files[0];
                totalFileSize += f.size;
                const ext = (f.name || '').split('.').pop().toLowerCase();
                if (!allowedExts.includes(ext)) {
                    invalidFormatFiles.push(f.name);
                }
                if (f.size > 5 * 1024 * 1024) {
                    oversizedFiles.push(f.name + ' (' + (f.size / (1024 * 1024)).toFixed(1) + ' MB)');
                }
            }
        });
        if (bapCertInput && bapCertInput.files && bapCertInput.files[0]) {
            const f = bapCertInput.files[0];
            totalFileSize += f.size;
            const ext = (f.name || '').split('.').pop().toLowerCase();
            if (!allowedExts.includes(ext)) {
                invalidFormatFiles.push(f.name);
            }
            if (f.size > 5 * 1024 * 1024) {
                oversizedFiles.push(f.name + ' (' + (f.size / (1024 * 1024)).toFixed(1) + ' MB)');
            }
        }
        if (commCertInput && commCertInput.files && commCertInput.files[0]) {
            const f = commCertInput.files[0];
            totalFileSize += f.size;
            const ext = (f.name || '').split('.').pop().toLowerCase();
            if (!allowedExts.includes(ext)) {
                invalidFormatFiles.push(f.name);
            }
            if (f.size > 5 * 1024 * 1024) {
                oversizedFiles.push(f.name + ' (' + (f.size / (1024 * 1024)).toFixed(1) + ' MB)');
            }
        }
        if (invalidFormatFiles.length > 0 || oversizedFiles.length > 0) {
            showServiceError(invalidMsg);
            return;
        }

        const activeSubmit = confirmSubmitBtn;
        activeSubmit.classList.add('is-loading');
        activeSubmit.disabled = true;
        if (validationBanner) {
            validationBanner.hidden = true;
        }

        try {
            const formData = new FormData(serviceForm);
            formData.append('is_ajax', '1');

            // Find CSRF token in form
            const csrfInput = serviceForm.querySelector('input[name="_csrf_token"], input[name="csrf_token"], input[name="_token"]');
            const tokenVal = csrfInput ? csrfInput.value : '';
            if (tokenVal) {
                formData.set('_csrf_token', tokenVal);
                formData.set('csrf_token', tokenVal);
            }

            const reqHeaders = {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            };
            if (tokenVal) {
                reqHeaders['X-CSRF-Token'] = tokenVal;
            }

            const response = await fetch(serviceForm.action || window.location.href, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: reqHeaders
            });

            let data = null;
            try {
                data = await response.json();
            } catch (jsonErr) {
                console.warn('Unable to parse JSON response:', jsonErr);
            }

            // AUTO-RETRY ON CSRF EXPIRY:
            // If the security token expired or session desynchronized, refresh the token and retry once automatically!
            if (!isRetry && (response.status === 403 || (data && data.error === 'SECURITY_VALIDATION_FAILED'))) {
                let freshToken = data && (data.token || data.csrf_token);
                if (!freshToken) {
                    try {
                        const tokenRes = await fetch('../api/csrf-token.php', {
                            method: 'GET',
                            credentials: 'same-origin',
                            headers: { 'Accept': 'application/json' }
                        });
                        const tokenData = await tokenRes.json();
                        if (tokenData && tokenData.success && tokenData.token) {
                            freshToken = tokenData.token;
                        }
                    } catch (tErr) {
                        console.warn('Failed to fetch new CSRF token:', tErr);
                    }
                }

                if (freshToken) {
                    if (csrfInput) {
                        csrfInput.value = freshToken;
                    }
                    // Automatically retry once with the fresh token
                    return await executeServiceSubmission(true);
                }
            }

            if (response.ok && data && data.success) {
                if (typeof ParishToast !== 'undefined' && typeof ParishToast.show === 'function') {
                    ParishToast.show({
                        title: 'Request Submitted',
                        message: data.message || 'Sacramental service request submitted successfully!',
                        type: 'success',
                        duration: 5000
                    });
                }
                const targetUrl = data.redirect_url || ('my-requests.php?q=' + encodeURIComponent(data.reference_number || ''));
                window.setTimeout(function() {
                    window.location.href = targetUrl;
                }, 600);
                return; // Keep button disabled while redirecting
            }

            // If payload too large (HTTP 413)
            if (response.status === 413 || (data && data.error === 'PAYLOAD_TOO_LARGE')) {
                showServiceError(data && data.message ? data.message : 'The uploaded files exceed the server limit. Please upload smaller files.');
                return;
            }

            const errorMsg = (data && data.message)
                ? data.message
                : ('Submission failed (HTTP ' + response.status + '). Please check your information and try again.');
            showServiceError(errorMsg);
        } catch (err) {
            console.error('Sacramental request submission error:', err);
            showServiceError('A network error occurred while submitting your request. Please check your internet connection and try again.');
        } finally {
            // GUARANTEE: Reset loading spinner and re-enable submit button
            activeSubmit.classList.remove('is-loading');
            activeSubmit.disabled = false;
        }
    }

    if (submitRequestBtn) {
        submitRequestBtn.addEventListener('click', openReview);
    }
    if (reviewBackBtn) {
        reviewBackBtn.addEventListener('click', function() {
            reviewPanel.hidden = true;
            entryPanel.hidden = false;
            entryPanel.scrollIntoView({behavior: 'smooth', block: 'start'});
        });
    }

    // Delegated click listener for absolute stability across DOM updates
    document.addEventListener('click', function(event) {
        const sBtn = event.target.closest('#submitRequestBtn');
        if (sBtn) {
            event.preventDefault();
            openReview();
            return;
        }
        const bBtn = event.target.closest('#serviceReviewBack');
        if (bBtn) {
            event.preventDefault();
            if (reviewPanel) reviewPanel.hidden = true;
            if (entryPanel) {
                entryPanel.hidden = false;
                entryPanel.scrollIntoView({behavior: 'smooth', block: 'start'});
            }
            return;
        }
    });

    serviceForm.addEventListener('submit', function(event) {
        event.preventDefault();
        if (event.submitter !== confirmSubmitBtn && reviewPanel.hidden) {
            openReview();
        } else {
            executeServiceSubmission();
        }
    });
});
</script>

<script src="../assets/js/request-modern.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/request-modern.js'); ?>"></script>
<?php include '../templates/footer.php'; ?>
