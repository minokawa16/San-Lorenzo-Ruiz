<?php
/**
 * Service Request Module - Handles reservation and parish service request submissions.
 */
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

$service_types = [
    'baptism_service' => 'Baptism',
    'confirmation_service' => 'Confirmation',
    'first_communion_service' => 'First Communion / Eucharist',
    'marriage_wedding_service' => 'Marriage / Wedding',
    'anointing_of_the_sick' => 'Anointing of the Sick',
    'funeral_mass' => 'Funeral Mass',
    'patronal_fiesta' => 'Patronal Fiesta'
];
$service_meta = [
    'baptism_service' => ['icon' => 'fa-water', 'hint' => 'Schedule a baptism service with parish coordination.'],
    'confirmation_service' => ['icon' => 'fa-dove', 'hint' => 'Request Holy Confirmation service scheduling.'],
    'first_communion_service' => ['icon' => 'fa-bread-slice', 'hint' => 'Request First Holy Communion service scheduling.'],
    'marriage_wedding_service' => ['icon' => 'fa-ring', 'hint' => 'Request wedding or marriage service scheduling.'],
    'anointing_of_the_sick' => ['icon' => 'fa-hand-holding-medical', 'hint' => 'Request pastoral care and anointing schedule.'],
    'funeral_mass' => ['icon' => 'fa-cross', 'hint' => 'Coordinate funeral Mass details with the parish.'],
    'patronal_fiesta' => ['icon' => 'fa-church', 'hint' => 'Submit Patronal Fiesta details for parish review.']
];
$baptism_requirements = [
    'chapel_recommendation' => 'Chapel Recommendation',
    'latest_marriage_contract' => 'Latest Marriage Contract (if parents are married)',
    'marriage_certificate_receipt' => 'Latest Marriage Certificate / Marriage Contract Receipt',
    'marriage_certificate_photocopy' => 'Photocopy of Marriage Certificate (if married)',
    'live_birth_certificate' => 'Photocopy of Live Birth Certificate with Official Registry Number',
    'sponsor_white_cards' => 'Two (2) White Cards of Sponsors (Ninong and Ninang)',
    'parent_white_cards' => 'White Cards of Parents'
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
$marriage_requirements = [
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

        // Single source of truth: Bind schedule date automatically from investigation sheets
        if ($request_type === 'baptism_service') {
            $preferred_date = $baptism_sheet['baptism_date'] ?? '';
        } elseif ($request_type === 'marriage_wedding_service') {
            $preferred_date = $marriage_sheet['wedding_date'] ?? '';
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

        if (!array_key_exists($request_type, $service_types)) {
            $respond(false, 'Please choose a sacramental service.', ['status_code' => 422]);
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
        } elseif ($request_type === 'patronal_fiesta' && $patronal_fiesta_date === '') {
            $respond(false, 'Please choose the date of the Patronal Fiesta.', ['status_code' => 422]);
        } elseif ($preferred_date === '') {
            $respond(false, 'Please choose a scheduled service date.', ['status_code' => 422]);
        } elseif ($preferred_time === '') {
            $respond(false, 'Please choose a preferred time.', ['status_code' => 422]);
        } elseif ($location === '') {
            $respond(false, 'Please provide the service location.', ['status_code' => 422]);
        } else {
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
                $description_parts[] = 'Baptism requirement uploads: ' . implode(', ', array_values($baptism_requirements));
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
                $description_parts[] = 'Funeral Mass requirement uploads: ' . implode(', ', array_values($funeral_requirements));
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
                createNotification($conn, $user_id, 'Sacramental Service Request Created', 'Your service request has been submitted with reference: ' . $reference_number . ' (' . $doc_count . ' ' . $file_text . ' attached)');
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

    <section class="request-status-grid">
        <?php foreach ($status_counts as $status_name => $count): ?>
            <?php $status_info = $status_meta[$status_name] ?? ['icon' => 'fa-circle', 'description' => 'Request status', 'tone' => 'secondary']; ?>
            <a class="request-status-card" href="?status=<?php echo urlencode($status_name); ?>">
                <div class="status-card-top">
                    <i class="fas <?php echo e($status_info['icon']); ?> text-<?php echo e($status_info['tone']); ?>"></i>
                    <strong><?php echo intval($count); ?></strong>
                </div>
                <span><?php echo e(serviceLabel($status_name)); ?></span>
                <small><?php echo e($status_info['description']); ?></small>
            </a>
        <?php endforeach; ?>
    </section>

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
            <div class="request-validation-banner" id="serviceValidationBanner" role="alert" hidden>
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
                                        <input type="file" class="requirement-file-input" name="baptism_requirement_files[<?php echo e($key); ?>]" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,image/jpeg,image/png,image/gif,application/pdf,text/plain" data-requirement-file data-requirement-group="baptism">
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
                                        <input type="file" class="requirement-file-input" name="marriage_requirement_files[male][<?php echo e($key); ?>]" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,image/jpeg,image/png,image/gif,application/pdf,text/plain" data-requirement-file data-requirement-group="marriage" data-requirement-mandatory="<?php echo $is_mandatory ? 'true' : 'false'; ?>">
                                    </label>
                                    <a class="requirement-view-btn marriage-file-view" href="#" target="_blank" rel="noopener" data-file-view hidden>
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                                <div role="cell" class="marriage-upload-cell">
                                    <label class="requirement-upload-btn">
                                        <i class="fas fa-folder-open"></i> <span data-upload-label>Choose File</span>
                                        <input type="file" class="requirement-file-input" name="marriage_requirement_files[female][<?php echo e($key); ?>]" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,image/jpeg,image/png,image/gif,application/pdf,text/plain" data-requirement-file data-requirement-group="marriage" data-requirement-mandatory="<?php echo $is_mandatory ? 'true' : 'false'; ?>">
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
                        <span class="active"><i class="fas fa-list-check"></i> Step 1: Review Requirements</span>
                        <span><i class="fas fa-calendar-check"></i> Step 2: Fill Request Form</span>
                        <span><i class="fas fa-paper-plane"></i> Step 3: Submit Request</span>
                    </div>

                    <div class="baptism-requirements-header">
                        <div>
                            <span class="request-kicker"><i class="fas fa-cross"></i> Funeral Mass Requirements</span>
                            <h3>Requirements for Funeral Mass</h3>
                            <p>Upload a clear copy of the Death Certificate before submitting the Funeral Mass request.</p>
                        </div>
                        <div class="baptism-review-badge">
                            <i class="fas fa-file-shield"></i>
                            <strong>Office Review</strong>
                            <small>Required before scheduling.</small>
                        </div>
                    </div>

                    <div class="baptism-requirements-grid">
                        <?php foreach ($funeral_requirements as $key => $label): ?>
                            <div class="baptism-requirement-item requirement-upload-item">
                                <span><i class="fas fa-file-arrow-up"></i></span>
                                <div class="requirement-upload-main">
                                    <strong><?php echo e($label); ?></strong>
                                    <small data-file-name>No file selected</small>
                                </div>
                                <div class="requirement-upload-actions">
                                    <label class="requirement-upload-btn">
                                        <i class="fas fa-folder-open"></i> <span data-upload-label>Choose File</span>
                                        <input type="file" class="requirement-file-input" name="funeral_requirement_files[<?php echo e($key); ?>]" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,image/jpeg,image/png,image/gif,application/pdf,text/plain" data-requirement-file data-requirement-group="funeral">
                                    </label>
                                    <a class="requirement-view-btn" href="#" target="_blank" rel="noopener" data-file-view hidden>
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="baptism-warning" id="funeralRequirementWarning">
                        <i class="fas fa-triangle-exclamation"></i>
                        <span>Upload the Death Certificate before submitting.</span>
                    </div>
                </div>
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
                <div class="step-heading">
                    <span class="step-number">3</span>
                    <div>
                        <h3>Schedule and Location</h3>
                        <p>Provide your preferred service schedule and complete location.</p>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6" id="patronalDateGroup" style="display:none;">
                        <label for="patronal_fiesta_date" class="form-label">Date of Patronal Fiesta <span class="text-danger">*</span></label>
                        <input type="date" class="form-control request-form-control" id="patronal_fiesta_date" name="patronal_fiesta_date">
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
                        <input type="time" class="form-control request-form-control" id="preferred_time" name="preferred_time" required>
                    </div>
                    <div class="col-12">
                        <label for="location" class="form-label">Location <span class="text-danger">*</span></label>
                        <input type="text" class="form-control request-form-control" id="location" name="location" placeholder="Church, chapel, home, hospital, cemetery, or venue" required>
                    </div>
                    <div class="col-12">
                        <label for="details" class="form-label">Additional Details</label>
                        <textarea class="form-control request-form-control" id="details" name="details" rows="4" placeholder="Add names, family contact, special notes, or other details."></textarea>
                        <div class="form-text"><i class="fas fa-wand-magic-sparkles"></i> TUGON tip: include names, family contact, location notes, and special instructions when available.</div>
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

                <div class="request-review-section">
                    <h3><i class="fas fa-calendar-check"></i> Applicant, Schedule, and Location</h3>
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
    const scheduleSyncCard = document.getElementById('scheduleSyncCard');
    const scheduleSyncTitle = document.getElementById('scheduleSyncTitle');
    const scheduleSyncText = document.getElementById('scheduleSyncText');
    const preferredDate = document.getElementById('preferred_date');
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
    const requirementFileInputs = Array.from(document.querySelectorAll('[data-requirement-file]'));
    const submitRequestBtn = document.getElementById('submitRequestBtn');

    function requirementFilesReady(group) {
        const inputs = requirementFileInputs.filter(function(input) {
            return input.dataset.requirementGroup === group && input.dataset.requirementMandatory !== 'false';
        });
        return inputs.length === 0 || inputs.every(function(input) {
            return input.files && input.files.length > 0;
        });
    }

    function updateRequirementFileLabel(input) {
        const button = input.closest('.requirement-upload-btn');
        const container = input.closest('.marriage-upload-cell') || input.closest('.requirement-upload-item') || button;
        const fileLabel = container ? container.querySelector('[data-file-name]') : null;
        const uploadLabel = button ? button.querySelector('[data-upload-label]') : null;
        const viewButton = container ? container.querySelector('[data-file-view]') : null;
        const hasFile = input.files && input.files.length > 0;
        if (fileLabel) {
            fileLabel.textContent = hasFile ? input.files[0].name : (input.dataset.requirementGroup === 'marriage' ? 'No file' : 'No file selected');
        }
        if (uploadLabel && hasFile) {
            uploadLabel.textContent = 'Change File';
        } else if (uploadLabel) {
            uploadLabel.textContent = 'Choose File';
        }
        if (button) {
            button.classList.toggle('has-file', hasFile);
        }
        if (container) {
            container.classList.toggle('has-file', hasFile);
        }
        if (viewButton) {
            if (viewButton.dataset.objectUrl) {
                URL.revokeObjectURL(viewButton.dataset.objectUrl);
                delete viewButton.dataset.objectUrl;
            }
            if (hasFile) {
                const objectUrl = URL.createObjectURL(input.files[0]);
                viewButton.href = objectUrl;
                viewButton.dataset.objectUrl = objectUrl;
                viewButton.hidden = false;
            } else {
                viewButton.href = '#';
                viewButton.hidden = true;
            }
        }
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
        if (isBaptismSelected()) {
            return baptismDateInput ? baptismDateInput.value.trim() : '';
        }
        if (isMarriageSelected()) {
            return weddingDateInput ? weddingDateInput.value.trim() : '';
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
                scheduleSyncText.innerHTML = 'Sacramental date: <strong style="color: #0f766e;">' + displayDate(dateVal) + '</strong> (automatically bound from investigation sheet above).';
            } else {
                scheduleSyncText.innerHTML = 'Your sacramental service date will automatically synchronize from the investigation sheet above once selected.';
            }
        }
    }

    function toggleDateInputs() {
        const baptismSelected = isBaptismSelected();
        const marriageSelected = isMarriageSelected();
        const patronalSelected = isPatronalSelected();

        if (baptismSelected) {
            if (scheduleSyncCard) {
                scheduleSyncCard.hidden = false;
                scheduleSyncCard.style.display = '';
            }
            if (scheduleSyncTitle) {
                scheduleSyncTitle.textContent = 'Baptism Schedule Synchronized';
            }
            if (patronalGroup) patronalGroup.style.display = 'none';
            if (patronalDate) patronalDate.required = false;
            if (generalServiceDateGroup) generalServiceDateGroup.style.display = 'none';
            if (generalServiceDate) generalServiceDate.required = false;
        } else if (marriageSelected) {
            if (scheduleSyncCard) {
                scheduleSyncCard.hidden = false;
                scheduleSyncCard.style.display = '';
            }
            if (scheduleSyncTitle) {
                scheduleSyncTitle.textContent = 'Wedding Schedule Synchronized';
            }
            if (patronalGroup) patronalGroup.style.display = 'none';
            if (patronalDate) patronalDate.required = false;
            if (generalServiceDateGroup) generalServiceDateGroup.style.display = 'none';
            if (generalServiceDate) generalServiceDate.required = false;
        } else if (patronalSelected) {
            if (scheduleSyncCard) {
                scheduleSyncCard.hidden = true;
                scheduleSyncCard.style.display = 'none';
            }
            if (patronalGroup) patronalGroup.style.display = '';
            if (patronalDate) patronalDate.required = true;
            if (generalServiceDateGroup) generalServiceDateGroup.style.display = 'none';
            if (generalServiceDate) generalServiceDate.required = false;
        } else {
            // Funeral mass, anointing of the sick, or other services
            if (scheduleSyncCard) {
                scheduleSyncCard.hidden = true;
                scheduleSyncCard.style.display = 'none';
            }
            if (patronalGroup) patronalGroup.style.display = 'none';
            if (patronalDate) patronalDate.required = false;
            if (generalServiceDateGroup) generalServiceDateGroup.style.display = '';
            if (generalServiceDate) generalServiceDate.required = true;
        }
        syncScheduleDate();
        updateSpecialRequirementsState();
    }

    function updateSpecialRequirementsState() {
        const baptismSelected = isBaptismSelected();
        const marriageSelected = isMarriageSelected();
        const funeralSelected = isFuneralSelected();

        if (baptismRequirementsCard) {
            baptismRequirementsCard.hidden = !baptismSelected;
        }
        if (marriageRequirementsCard) {
            marriageRequirementsCard.hidden = !marriageSelected;
        }
        if (funeralRequirementsCard) {
            funeralRequirementsCard.hidden = !funeralSelected;
        }

        baptismSheetFields.forEach(function(field) {
            field.required = baptismSelected;
            if (!field.required) {
                clearFieldError(field);
            }
        });

        marriageSheetFields.forEach(function(field) {
            const isOptional = field.id === 'marriage_additional_sponsors';
            field.required = marriageSelected && !isOptional;
            if (!field.required) {
                clearFieldError(field);
            }
        });

        requirementFileInputs.forEach(function(input) {
            const isMandatory = input.dataset.requirementMandatory !== 'false';
            input.required = isMandatory && (
                (input.dataset.requirementGroup === 'baptism' && baptismSelected) ||
                (input.dataset.requirementGroup === 'marriage' && marriageSelected) ||
                (input.dataset.requirementGroup === 'funeral' && funeralSelected)
            );
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
            if (funeralRequirementWarning) {
                funeralRequirementWarning.classList.toggle('is-complete', funeralReady);
                funeralRequirementWarning.innerHTML = funeralReady
                    ? '<i class="fas fa-circle-check"></i><span>Death Certificate is ready for parish review.</span>'
                    : '<i class="fas fa-triangle-exclamation"></i><span>Upload the Death Certificate before submitting.</span>';
            }
        }
    }

    function validationWrapper(field) {
        if (field.type === 'radio') {
            return document.querySelector('.request-type-grid');
        }
        return field.closest('.investigation-field')
            || field.closest('.investigation-grid-full')
            || field.closest('.baptism-sheet-field')
            || field.closest('.marriage-upload-cell')
            || field.closest('.requirement-upload-item')
            || field.closest('.col-md-6')
            || field.closest('.col-12')
            || field.parentElement;
    }

    function clearFieldError(field) {
        const wrapper = validationWrapper(field);
        if (!wrapper) {
            return;
        }
        wrapper.classList.remove('request-field-error', 'is-missing');
        const error = wrapper.querySelector(':scope > .request-inline-error');
        if (error) {
            error.remove();
        }
        if (field.type === 'radio') {
            document.querySelectorAll('input[name="request_type"]').forEach(function(radio) {
                radio.removeAttribute('aria-invalid');
            });
        } else {
            field.removeAttribute('aria-invalid');
        }
    }

    function addFieldError(field) {
        const wrapper = validationWrapper(field);
        if (!wrapper || wrapper.classList.contains('request-field-error')) {
            return;
        }
        wrapper.classList.add('request-field-error');
        field.setAttribute('aria-invalid', 'true');
        const error = document.createElement('div');
        error.className = 'request-inline-error';
        error.innerHTML = '<i class="fas fa-triangle-exclamation"></i><span>You haven\'t filled up this field yet.</span>';
        wrapper.appendChild(error);
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

    function validateForReview() {
        updateSpecialRequirementsState();
        const invalidFields = [];
        const seenRadioGroups = new Set();
        serviceForm.querySelectorAll('[required]').forEach(function(field) {
            if (field.disabled) {
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

        validationBanner.hidden = invalidFields.length === 0;
        if (invalidFields.length) {
            const firstWrapper = validationWrapper(invalidFields[0]);
            if (firstWrapper) {
                firstWrapper.scrollIntoView({behavior: 'smooth', block: 'center'});
            }
            window.setTimeout(function() {
                if (invalidFields[0].type !== 'file') {
                    invalidFields[0].focus({preventScroll: true});
                }
            }, 350);
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

        const baptismSelected = isBaptismSelected();
        const marriageSelected = isMarriageSelected();

        document.getElementById('reviewChildSection').hidden = !baptismSelected;
        document.getElementById('reviewParentsSection').hidden = !baptismSelected;
        document.getElementById('reviewGodparentsSection').hidden = !baptismSelected;

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

        const scheduleDate = getScheduledDate();
        let scheduleDateLabel = 'Preferred Date';
        if (baptismSelected) {
            scheduleDateLabel = 'Date of Baptism';
        } else if (marriageSelected) {
            scheduleDateLabel = 'Date of Marriage';
        } else if (isPatronalSelected()) {
            scheduleDateLabel = 'Date of Patronal Fiesta';
        }

        renderReviewItems('reviewScheduleInfo', [
            ['Applicant', <?php echo json_encode((string) ($_SESSION['fullname'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>],
            ['Email Address', <?php echo json_encode((string) ($_SESSION['email'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>],
            [scheduleDateLabel, displayDate(scheduleDate)],
            ['Preferred Time', displayTime(document.getElementById('preferred_time').value)],
            ['Location', document.getElementById('location').value.trim()],
            ['Additional Details', document.getElementById('details').value.trim() || 'None']
        ]);
    }

    function openReview() {
        if (!validateForReview()) {
            return;
        }
        populateReview();
        validationBanner.hidden = true;
        entryPanel.hidden = true;
        reviewPanel.hidden = false;
        reviewPanel.scrollIntoView({behavior: 'smooth', block: 'start'});
    }

    const typeRadios = document.querySelectorAll('input[name="request_type"]');

    if (typeRadios.length) {
        typeRadios.forEach(function(radio) {
            radio.addEventListener('change', toggleDateInputs);
        });
        baptismSheetFields.forEach(function(field) {
            field.addEventListener('input', updateSpecialRequirementsState);
            field.addEventListener('change', updateSpecialRequirementsState);
        });
        marriageSheetFields.forEach(function(field) {
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
        toggleDateInputs();
        updateSpecialRequirementsState();
    }

    serviceForm.addEventListener('input', function(event) {
        if (event.target.matches('[required]') && fieldIsComplete(event.target)) {
            clearFieldError(event.target);
            validationBanner.hidden = !serviceForm.querySelector('.request-field-error');
        }
    });
    serviceForm.addEventListener('change', function(event) {
        if (event.target.matches('[required]') && fieldIsComplete(event.target)) {
            clearFieldError(event.target);
            validationBanner.hidden = !serviceForm.querySelector('.request-field-error');
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

        // 1. Client-side file size validation (prevent silent post_max_size drop)
        let totalFileSize = 0;
        const oversizedFiles = [];
        requirementFileInputs.forEach(function(input) {
            if (input.files && input.files[0]) {
                const f = input.files[0];
                totalFileSize += f.size;
                if (f.size > 10 * 1024 * 1024) {
                    oversizedFiles.push(f.name + ' (' + (f.size / (1024 * 1024)).toFixed(1) + ' MB)');
                }
            }
        });
        if (oversizedFiles.length > 0) {
            showServiceError('The following files exceed the 10 MB limit: ' + oversizedFiles.join(', ') + '. Please compress or use smaller files.');
            return;
        }
        if (totalFileSize > 25 * 1024 * 1024) {
            showServiceError('Total uploaded files (' + (totalFileSize / (1024 * 1024)).toFixed(1) + ' MB) exceed the 25 MB server upload limit. Please compress your files before submitting.');
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

    submitRequestBtn.addEventListener('click', openReview);
    reviewBackBtn.addEventListener('click', function() {
        reviewPanel.hidden = true;
        entryPanel.hidden = false;
        entryPanel.scrollIntoView({behavior: 'smooth', block: 'start'});
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

<script src="../assets/js/request-modern.js"></script>
<?php include '../templates/footer.php'; ?>
