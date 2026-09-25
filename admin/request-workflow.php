<?php
/**
 * Admin Request Workflow - Top-to-bottom formal review workflow.
 * Reviews requirements, inspects submitted application forms, verifies payments, and updates statuses.
 */
require_once '../includes/session.php';
require_once '../database/config.php';
require_once '../includes/helpers.php';

requireAdmin();
requirePermission('requests.manage');
ensureRequestDocumentsSchema($conn);
ensureRequestPaymentsSchema($conn);
ensureEmailNotificationSchema($conn);
ensureRequestMatchingSchema($conn);

$request_id = intval($_GET['id'] ?? $_POST['request_id'] ?? 0);
if ($request_id <= 0) {
    redirect('manage-requests.php');
}

$error = '';
$success = '';

$stmt = $conn->prepare("
    SELECT r.*, u.fullname, u.email, u.phone_number, u.profile_picture, staff.fullname AS assigned_staff_name
    FROM requests r
    JOIN users u ON u.id = r.user_id
    LEFT JOIN users staff ON staff.id = r.assigned_to
    WHERE r.request_id = ?
    LIMIT 1
");
if (!$stmt) {
    redirect('manage-requests.php');
}
$stmt->bind_param('i', $request_id);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request) {
    redirect('manage-requests.php');
}

// Category Classification
$raw_type = strtolower(trim((string)($request['request_type'] ?? '')));
$request_category = 'other';
if (str_contains($raw_type, 'certif')) {
    $request_category = 'certificate';
} elseif (str_contains($raw_type, 'blessing')) {
    $request_category = 'blessing';
} else {
    $request_category = 'sacramental';
}

$is_certificate = ($request_category === 'certificate');
$is_blessing = ($request_category === 'blessing');
$is_sacramental = ($request_category === 'sacramental');

$category_labels = [
    'certificate' => 'Certificate Request',
    'blessing'    => 'Blessing Service',
    'sacramental' => 'Sacramental Service',
    'other'       => 'Parish Request'
];
$category_badges = [
    'certificate' => 'primary',
    'blessing'    => 'warning text-dark',
    'sacramental' => 'success',
    'other'       => 'secondary'
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireValidCsrfToken();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        $raw_status = trim((string)($_POST['status'] ?? ''));
        $status = strtolower($raw_status);
        $admin_response = trim($_POST['admin_response'] ?? '');
        $allowed_statuses = ['pending', 'processing', 'completed', 'rejected'];

        $requirement_count = requestDocumentCount($conn, $request_id, 'requirement');
        $released_count = requestDocumentCount($conn, $request_id, 'released_certificate') + requestDocumentCount($conn, $request_id, 'admin_file');
        $current_payment_summary = getRequestPaymentSummary($conn, $request_id);

        $request_type = strtolower(trim((string)($request['request_type'] ?? '')));
        $zero_requirement_services = ['patronal_fiesta', 'anointing_of_the_sick', 'mass_offering', 'mass_intention', 'blessing_service', 'general_blessing'];
        $requires_supporting_docs = !in_array($request_type, $zero_requirement_services, true);

        if (!in_array($status, $allowed_statuses, true)) {
            $error = 'Invalid request status. Allowed: Pending, Processing, Completed, Rejected.';
        } elseif (in_array($status, ['processing', 'completed'], true) && $requires_supporting_docs && $requirement_count <= 0) {
            $error = 'This request cannot move forward until at least one supporting requirement is attached.';
        } elseif ($status === 'completed' && $is_certificate && $released_count <= 0 && $requires_supporting_docs) {
            $error = 'Upload a released certificate or parish office file before marking this request completed.';
        } elseif ($status === 'completed' && $is_certificate && intval($current_payment_summary['total']) > 0 && intval($current_payment_summary['verified']) <= 0) {
            $error = 'A submitted payment receipt must be verified before marking this request completed.';
        } elseif ($status === 'completed') {
            require_once __DIR__ . '/../services/SacramentalApprovalService.php';
            $is_sacramental_type = SacramentalApprovalService::isSacramentalRequestType($request_type);

            if ($is_sacramental_type) {
                try {
                    $sacramentalService = new SacramentalApprovalService($conn);
                    $officiating_priest = trim($_POST['officiating_priest'] ?? $_POST['minister'] ?? '');
                    $parish_priest = trim($_POST['parish_priest'] ?? '');
                    $completionResult = $sacramentalService->completeRequest($request_id, (int)$_SESSION['user_id'], [
                        'admin_response' => $admin_response,
                        'officiating_priest' => $officiating_priest,
                        'parish_priest' => $parish_priest,
                        'target_status' => 'completed'
                    ]);
                    $request['status'] = 'completed';
                    $request['admin_response'] = $admin_response;
                    $success = 'Request marked as completed! Sacramental record registered and calendar schedule updated.';
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
            } else {
                $stmt = $conn->prepare("UPDATE requests SET status = 'completed', admin_response = ?, updated_at = NOW() WHERE request_id = ?");
                if ($stmt) {
                    $stmt->bind_param('si', $admin_response, $request_id);
                    if ($stmt->execute()) {
                        createAuditLog($conn, $_SESSION['user_id'], 'UPDATE_REQUEST_STATUS', 'requests', $request_id);
                        createRequestStatusNotification($conn, $request, 'completed', $admin_response);
                        $request['status'] = 'completed';
                        $request['admin_response'] = $admin_response;

                        // Automatically sync day and time of event to calendar schedule
                        $calSync = syncApprovedRequestToCalendar($conn, $request_id, (int)$_SESSION['user_id']);
                        $calNotice = (!empty($calSync['success']) && !empty($calSync['message']) && str_contains($calSync['message'], 'skipped') === false)
                            ? ' Event schedule automatically added to the parish calendar.'
                            : '';
                        $success = 'Request marked as completed!' . $calNotice;
                    } else {
                        $error = 'Unable to update request status.';
                    }
                    $stmt->close();
                } else {
                    $error = 'Unable to prepare request update.';
                }
            }
        } else {
            $stmt = $conn->prepare("UPDATE requests SET status = ?, admin_response = ?, updated_at = NOW() WHERE request_id = ?");
            if ($stmt) {
                $stmt->bind_param('ssi', $status, $admin_response, $request_id);
                if ($stmt->execute()) {
                    createAuditLog($conn, $_SESSION['user_id'], 'UPDATE_REQUEST_STATUS', 'requests', $request_id);
                    createRequestStatusNotification($conn, $request, $status, $admin_response);
                    $request['status'] = $status;
                    $request['admin_response'] = $admin_response;
                    $success = 'Request status updated to ' . ucfirst($status) . '.';
                } else {
                    $error = 'Unable to update request status.';
                }
                $stmt->close();
            } else {
                $error = 'Unable to prepare request update.';
            }
        }
    } elseif ($action === 'verify_payment') {
        $payment_id = intval($_POST['payment_id'] ?? 0);
        $status = $_POST['payment_status'] ?? '';
        $admin_remarks = trim($_POST['admin_remarks'] ?? '');

        if (!in_array($status, ['verified', 'rejected', 'pending'], true)) {
            $error = 'Invalid payment status.';
        } else {
            $stmt = $conn->prepare("UPDATE request_payments SET status = ?, admin_remarks = ?, verified_by = ?, verified_at = NOW() WHERE payment_id = ? AND request_id = ?");
            if ($stmt) {
                $admin_id = intval($_SESSION['user_id']);
                $stmt->bind_param('ssiii', $status, $admin_remarks, $admin_id, $payment_id, $request_id);
                if ($stmt->execute() && $stmt->affected_rows >= 0) {
                    createAuditLog($conn, $_SESSION['user_id'], 'VERIFY_PAYMENT', 'request_payments', $payment_id);
                    createNotification($conn, $request['user_id'], 'Payment Receipt Reviewed', 'Your payment receipt for request ' . $request['reference_number'] . ' is now ' . ucfirst($status) . '.', true, 'requests', 'request', (int) $request_id, 'request.view');
                    $success = 'Payment status updated.';
                } else {
                    $error = 'Unable to update payment status.';
                }
                $stmt->close();
            } else {
                $error = 'Unable to prepare payment update.';
            }
        }
    } elseif ($action === 'upload_release') {
        $document = saveRequestDocument($conn, $request_id, $_SESSION['user_id'], $_FILES['release_file'] ?? null, 'released_certificate');

        if (!$document['ok'] || empty($document['saved'])) {
            $error = $document['error'] ?? 'Please choose a certificate file to upload and deliver.';
        } else {
            createAuditLog($conn, $_SESSION['user_id'], 'UPLOAD_REQUEST_FILE', 'request_documents', $document['document_id']);
            createNotification($conn, $request['user_id'], 'Certificate Ready for Download', 'Your official certificate for request ' . $request['reference_number'] . ' has been released and is ready for download in your portal.', true, 'requests', 'request', (int) $request_id, 'request.view');

            if (!empty($_POST['mark_completed'])) {
                $stmt = $conn->prepare("UPDATE requests SET status = 'completed' WHERE request_id = ?");
                if ($stmt) {
                    $stmt->bind_param('i', $request_id);
                    $stmt->execute();
                    $stmt->close();
                    $request['status'] = 'completed';
                }
            }
            $success = 'Certificate file successfully uploaded and sent to parishioner.';
        }
    }
}

// Fetch documents
$documents = [];
$stmt = $conn->prepare("SELECT * FROM request_documents WHERE request_id = ? AND deleted_at IS NULL ORDER BY uploaded_at DESC");
if ($stmt) {
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $documents[] = $row;
    }
    $stmt->close();
}

$documents_by_type = ['requirement' => [], 'payment_receipt' => [], 'admin_file' => [], 'released_certificate' => []];
foreach ($documents as $document) {
    $type = $document['document_type'] ?: 'requirement';
    if (!isset($documents_by_type[$type])) {
        $documents_by_type[$type] = [];
    }
    $documents_by_type[$type][] = $document;
}

if (!empty($documents_by_type['requirement'])) {
    $requirement_order = [
        'Chapel Recommendation' => 1,
        'Photocopy of Marriage Certificate (if married)' => 2,
        'Latest Marriage Certificate / Marriage Contract Receipt' => 2,
        'Latest Marriage Contract (if parents are married)' => 2,
        'Photocopy of Live Birth Certificate with Official Registry Number' => 3,
        'Two (2) White Cards of Sponsors (Ninong and Ninang)' => 4,
        'White Cards of Parents' => 5,
    ];
    usort($documents_by_type['requirement'], function ($left, $right) use ($requirement_order) {
        $left_name = trim((string) ($left['requirement_name'] ?? ''));
        $right_name = trim((string) ($right['requirement_name'] ?? ''));
        $left_order = $requirement_order[$left_name] ?? 999;
        $right_order = $requirement_order[$right_name] ?? 999;
        if ($left_order !== $right_order) {
            return $left_order <=> $right_order;
        }
        return strcmp($left_name ?: (string) $left['original_name'], $right_name ?: (string) $right['original_name']);
    });
}

$payments = getRequestPayments($conn, $request_id);
$payment_summary = getRequestPaymentSummary($conn, $request_id);

$linked_reservation = null;
$res_stmt = $conn->prepare("SELECT * FROM reservations WHERE request_id = ? LIMIT 1");
if ($res_stmt) {
    $res_stmt->bind_param('i', $request_id);
    $res_stmt->execute();
    $linked_reservation = $res_stmt->get_result()->fetch_assoc();
    $res_stmt->close();
}

require_once '../services/SacramentalRecordMatcher.php';

$matched_record = null;
$match_status = $request['match_status'] ?? 'unmatched';
$matched_record_id = !empty($request['matched_record_id']) ? (int)$request['matched_record_id'] : null;
$matched_record_type = $request['matched_record_type'] ?? SacramentalRecordMatcher::mapRequestTypeToRecordType($request['request_type'] ?? '');
$match_details = !empty($request['match_details']) ? json_decode((string)$request['match_details'], true) : [];

// If status is 'unmatched' or not evaluated yet, run matcher now
if ($is_certificate && ($match_status === 'unmatched' || empty($match_status))) {
    $match_eval = SacramentalRecordMatcher::matchAndLinkRequest($conn, $request_id);
    if ($match_eval && isset($match_eval['status'])) {
        $match_status = $match_eval['status'];
        $matched_record_id = $match_eval['record_id'];
        $matched_record_type = $match_eval['record_type'];
        $match_details = [
            'evaluated_at' => date('Y-m-d H:i:s'),
            'record_holder_name' => $request['record_holder_name'] ?? '',
            'candidates_count' => count($match_eval['candidates'] ?? []),
            'candidates' => $match_eval['candidates'] ?? [],
        ];
        $request['match_status'] = $match_status;
        $request['matched_record_id'] = $matched_record_id;
        $request['matched_record_type'] = $matched_record_type;
    }
}

// If we have a matched record, fetch its full row
if ($matched_record_id && $matched_record_type) {
    $matched_record = SacramentalRecordMatcher::getRecordDetails($conn, $matched_record_type, $matched_record_id);
}

// Backward compatibility for legacy baptism_meta reference
$baptism_meta = null;
if ($matched_record_type === 'baptism' && $matched_record) {
    $baptism_meta = [
        'matched_baptism_id' => $matched_record['baptism_id'],
        'book_no' => $matched_record['book_no'] ?? '',
        'page_no' => $matched_record['page_no'] ?? '',
        'entry_no' => $matched_record['entry_no'] ?? '',
        'priest' => $matched_record['priest'] ?? '',
        'fullname' => $matched_record['fullname'] ?? '',
    ];
}


/**
 * Parses full raw submission description into structured sections.
 */
if (!function_exists('parseSubmittedApplicationForm')) {
    function parseSubmittedApplicationForm(string $description): array {
        $lines = array_filter(array_map('trim', explode("\n", str_replace(["\r\n", "\r"], "\n", $description))), static fn($l) => $l !== '');

        $sections = [];
        $currentSection = 'Application Overview';
        $sections[$currentSection] = [];

        foreach ($lines as $line) {
            if (preg_match('/^---\s*(.*?)\s*---$/', $line, $m)) {
                $currentSection = ucwords(strtolower(trim($m[1])));
                if (!isset($sections[$currentSection])) {
                    $sections[$currentSection] = [];
                }
                continue;
            }
            if (preg_match('/^\d+\.\s*(.*?):?$/', $line, $m)) {
                $currentSection = trim($m[1]);
                if (!isset($sections[$currentSection])) {
                    $sections[$currentSection] = [];
                }
                continue;
            }

            if (str_contains($line, ':')) {
                if (str_contains($line, '|')) {
                    $parts = explode('|', $line);
                    foreach ($parts as $part) {
                        if (str_contains($part, ':')) {
                            [$k, $v] = explode(':', $part, 2);
                            $sections[$currentSection][] = [
                                'type' => 'field',
                                'label' => trim($k),
                                'value' => trim($v)
                            ];
                        } else {
                            $sections[$currentSection][] = [
                                'type' => 'note',
                                'text' => trim($part)
                            ];
                        }
                    }
                } else {
                    [$k, $v] = explode(':', $line, 2);
                    $sections[$currentSection][] = [
                        'type' => 'field',
                        'label' => trim($k),
                        'value' => trim($v)
                    ];
                }
            } else {
                $sections[$currentSection][] = [
                    'type' => 'note',
                    'text' => $line
                ];
            }
        }

        return array_filter($sections, static fn($items) => !empty($items));
    }
}

/**
 * Extracts the 3 standardized formal church document sections:
 * Section 1: Application Overview & Schedule
 * Section 2: Applicant & Candidate Details
 * Section 3: Special Remarks / Attached Documents
 */
if (!function_exists('extractFormalDocumentDetails')) {
    function extractFormalDocumentDetails(array $request, ?array $linked_reservation, array $documents_by_type): array {
        $desc = (string)($request['description'] ?? '');
        $lines = array_filter(array_map('trim', explode("\n", str_replace(["\r\n", "\r"], "\n", $desc))), static fn($l) => $l !== '');

        $kv = [];
        $raw_remarks = [];
        foreach ($lines as $line) {
            if (preg_match('/^---.*---$/', $line) || preg_match('/^\d+\.\s*.*:?$/', $line)) {
                continue;
            }
            if (str_contains($line, ':')) {
                $parts = explode('|', $line);
                foreach ($parts as $part) {
                    if (str_contains($part, ':')) {
                        [$k, $v] = explode(':', $part, 2);
                        $key = strtolower(trim($k));
                        $kv[$key] = trim($v);
                    } else {
                        $raw_remarks[] = trim($part);
                    }
                }
            } else {
                $raw_remarks[] = $line;
            }
        }

        // Section 1: Application Overview & Schedule
        $prefDate = $linked_reservation['event_date'] ?? null;
        if (!$prefDate) {
            $prefDate = $kv['preferred date'] ?? $kv['date of baptism'] ?? $kv['date of marriage'] ?? $kv['wedding date'] ?? $kv['date of patronal fiesta'] ?? null;
        }
        $displayDate = $prefDate ? formatDate($prefDate) : formatDate($request['date_requested']);

        $prefTime = $linked_reservation['event_time'] ?? null;
        if (!$prefTime) {
            $prefTime = $kv['preferred time'] ?? null;
        }
        $displayTime = $prefTime ? date('h:i A', strtotime($prefTime)) : 'Regular Parish Hours / TBA';

        $assignedPriest = $request['assigned_staff_name'] ?? null;
        if (!$assignedPriest) {
            $assignedPriest = $kv['assigned priest'] ?? $kv['priest'] ?? $kv['minister'] ?? $kv['celebrant'] ?? 'Parish Priest / Assigned Minister';
        }

        $venue = $kv['location'] ?? $kv['venue'] ?? null;
        if (!$venue) {
            $rawType = strtolower(trim((string)($request['request_type'] ?? '')));
            $venue = str_contains($rawType, 'certif') ? 'San Lorenzo Ruiz Parish Office' : 'San Lorenzo Ruiz Parish Church / Chapel';
        }

        // Section 2: Applicant & Candidate Details
        $candidateName = !empty($request['record_holder_name']) ? $request['record_holder_name'] : null;
        $rawType = strtolower(trim((string)($request['request_type'] ?? '')));
        $isMarriage = str_contains($rawType, 'marriage') || str_contains($rawType, 'wedding');

        if ($isMarriage && (!empty($kv['full name']) || !empty($kv['groom'])) && (!empty($kv['full maiden name']) || !empty($kv['bride']))) {
            $groom = $kv['full name'] ?? $kv['groom'] ?? 'Groom';
            $bride = $kv['full maiden name'] ?? $kv['bride'] ?? 'Bride';
            $candidateName = $groom . ' & ' . $bride . ' (Couple)';

            $fatherName = (!empty($kv['father']) ? $kv['father'] : 'N/A');
            if (preg_match('/Groom.*?Father:\s*([^\|\n]+)/i', $desc, $gm) && preg_match('/Bride.*?Father:\s*([^\|\n]+)/i', $desc, $bm)) {
                $fatherName = 'Groom: ' . trim($gm[1]) . ' | Bride: ' . trim($bm[1]);
            }
            $motherName = (!empty($kv['mother']) ? $kv['mother'] : 'N/A');
            if (preg_match('/Groom.*?Mother:\s*([^\|\n]+)/i', $desc, $gm) && preg_match('/Bride.*?Mother:\s*([^\|\n]+)/i', $desc, $bm)) {
                $motherName = 'Groom: ' . trim($gm[1]) . ' | Bride: ' . trim($bm[1]);
            }
        } else {
            if (!$candidateName) {
                $candidateName = $kv['name of child'] ?? $kv['child\'s name'] ?? $kv['full name'] ?? $kv['full maiden name'] ?? $kv['record holder name'] ?? $request['fullname'];
            }
            $fatherName = $kv['father'] ?? $kv['father\'s name'] ?? 'Not specified / N/A';
            $motherName = $kv['mother'] ?? $kv['mother\'s maiden name'] ?? $kv['mother\'s name'] ?? 'Not specified / N/A';
        }

        $contactInfo = [
            'name'  => $request['fullname'],
            'email' => $request['email'],
            'phone' => !empty($request['phone_number']) ? $request['phone_number'] : 'None on file'
        ];

        // Section 3: Special Remarks / Attached Documents
        $remarks = $kv['details'] ?? $kv['purpose'] ?? $kv['requested blessing'] ?? null;
        if (!$remarks && !empty($raw_remarks)) {
            $remarks = implode('; ', $raw_remarks);
        }
        if (!$remarks || strtolower($remarks) === 'none') {
            $remarks = 'No special remarks or instructions provided.';
        }

        $reqDocs = [];
        foreach ($documents_by_type['requirement'] ?? [] as $doc) {
            $reqDocs[] = [
                'id'        => (int)$doc['document_id'],
                'name'      => !empty($doc['requirement_name']) ? $doc['requirement_name'] : $doc['original_name'],
                'file_name' => $doc['original_name'],
                'size'      => formatFileSize($doc['file_size']),
                'mime'      => $doc['mime_type'] ?? ''
            ];
        }

        return [
            'schedule' => [
                'preferred_date'  => $displayDate,
                'preferred_time'  => $displayTime,
                'assigned_priest' => $assignedPriest,
                'venue'           => $venue
            ],
            'candidate' => [
                'candidate_name' => $candidateName,
                'father_name'    => $fatherName,
                'mother_name'    => $motherName,
                'contact'        => $contactInfo
            ],
            'remarks_documents' => [
                'remarks'      => $remarks,
                'requirements' => $reqDocs
            ]
        ];
    }
}

$formalDetails = extractFormalDocumentDetails($request, $linked_reservation, $documents_by_type);
$parsedSections = parseSubmittedApplicationForm((string)($request['description'] ?? ''));

$disp_status = strtolower($request['status'] ?? 'pending');
if ($disp_status === 'submitted') {
    $disp_status = 'pending';
}

$page_title = 'Request Workflow';
$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Manage Requests' => 'manage-requests.php',
    'Request Workflow' => null
];
?>
<?php include '../templates/header.php'; ?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,500;0,600;0,700;1,400&family=Work+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
/* --- Dense & Compact Request Workflow Layout --- */
.workflow-wrap {
    max-width: 980px;
    margin: 0 auto;
    font-family: 'Work Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    color: #1e293b;
    font-size: 12.5px;
    line-height: 1.45;
}

/* Page Header - Dense, Serif Title with Avatar Chip */
.rw-header-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 6px 0 12px 0;
    min-height: 48px; /* Reserve height upfront to avoid layout reflow */
}

.rw-header-left {
    display: flex;
    align-items: center;
    gap: 10px;
}

.rw-icon-badge {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: #8c6225;
    color: #ffffff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    flex-shrink: 0;
    box-shadow: 0 1px 3px rgba(140, 98, 37, 0.2);
}

.rw-header-title {
    font-family: 'Lora', Georgia, serif;
    font-size: 17px;
    font-weight: 600;
    color: #1e293b;
    line-height: 1.2;
    margin: 0;
}

.rw-header-subtitle {
    font-size: 12px;
    color: #64748b;
    margin: 1px 0 0 0;
}

.rw-admin-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #ffffff;
    border: 1px solid #E7E0D2;
    padding: 3px 10px 3px 4px;
    border-radius: 20px;
    font-size: 11.5px;
    font-weight: 600;
    color: #334155;
    box-shadow: 0 1px 2px rgba(0,0,0,0.02);
}

.rw-admin-avatar {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: #e2e8f0;
    color: #475569;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    font-weight: 700;
}

/* Compact Section Cards */
.rw-card {
    background: #ffffff;
    border: 1px solid #E7E0D2;
    border-radius: 8px;
    margin-bottom: 12px;
    overflow: hidden;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
}

.rw-section-header {
    padding: 8px 14px;
    background: #ffffff;
    border-bottom: 1px solid #E7E0D2;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.rw-section-title {
    font-size: 12.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #1e293b;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 7px;
}

.rw-section-body {
    padding: 12px 14px;
}

/* Micro Typography */
.micro-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #64748b;
    margin-bottom: 3px;
    display: block;
}

.meta-value {
    color: #0f172a;
    font-weight: 600;
    font-size: 12.5px;
    word-break: break-word;
}

/* Section 1 Warning & Info Boxes */
.rw-alert-warning {
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 7px;
    padding: 9px 12px;
    display: flex;
    align-items: flex-start;
    gap: 9px;
}

.rw-alert-success {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 7px;
    padding: 9px 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
}

/* Section 2 Two-Column & File Card */
.rw-file-card {
    background: #fdfdfd;
    border: 1px solid #E7E0D2;
    border-radius: 7px;
    padding: 6px 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    min-height: 42px;
}

.btn-icon-gold {
    width: 28px;
    height: 28px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #8c6225;
    border: 1px solid #8c6225;
    color: #ffffff !important;
    border-radius: 6px;
    font-size: 11px;
    transition: background 0.15s ease-in-out;
}
.btn-icon-gold:hover {
    background: #734f1d;
    border-color: #734f1d;
}

.btn-icon-neutral {
    width: 28px;
    height: 28px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    color: #475569 !important;
    border-radius: 6px;
    font-size: 11px;
    transition: all 0.15s ease-in-out;
}
.btn-icon-neutral:hover {
    background: #f1f5f9;
    color: #1e293b !important;
}

/* Parish Buttons */
.btn-parish-gold {
    background-color: #8c6225 !important;
    border-color: #8c6225 !important;
    color: #ffffff !important;
    font-weight: 600;
    font-size: 12.5px;
    padding: 5px 14px;
    border-radius: 6px;
    box-shadow: 0 1px 3px rgba(140, 98, 37, 0.2);
    transition: all 0.15s ease-in-out;
}

.btn-parish-gold:hover {
    background-color: #734f1d !important;
    border-color: #734f1d !important;
    color: #ffffff !important;
}

/* Formal Document Box inside Collapsible */
.formal-document-container {
    background: #ffffff;
    border: 1px solid #E7E0D2;
    border-radius: 8px;
    overflow: hidden;
}

.formal-doc-header {
    background: #fafaf8;
    border-bottom: 1px solid #E7E0D2;
    padding: 8px 14px;
}

.formal-doc-body {
    padding: 14px 16px;
}

.formal-section-title {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #1e293b;
    border-bottom: 1px solid #f1f5f9;
    padding-bottom: 4px;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.formal-inset-card {
    background-color: #fafaf8;
    border: 1px solid #E7E0D2;
    border-radius: 7px;
    padding: 10px 12px;
}

.formal-doc-footer {
    border-top: 1px dashed #cbd5e1;
    margin-top: 14px;
    padding-top: 8px;
    text-align: center;
    font-size: 11px;
    color: #64748b;
    font-style: italic;
}

/* Document Toggle Button */
.btn-toggle-doc {
    transition: all 0.2s ease-in-out;
    border-width: 1px;
    font-size: 12px;
}

/* Clean Form Controls */
.form-control,
.form-select {
    border-color: #cbd5e1;
    border-radius: 6px;
    font-size: 12px;
}

.form-control:focus,
.form-select:focus {
    border-color: #8c6225;
    box-shadow: 0 0 0 0.15rem rgba(140, 98, 37, 0.12);
}

/* Print Optimization */
@media print {
    body * {
        visibility: hidden;
    }
    #submittedFormCollapse,
    #submittedFormCollapse * {
        visibility: visible;
    }
    #submittedFormCollapse {
        display: block !important;
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
        box-shadow: none !important;
    }
    .no-print {
        display: none !important;
    }
}
</style>

<div class="container-fluid px-0">
    <div class="workflow-wrap pb-4">
        <!-- Compact Page Header -->
        <div class="rw-header-bar no-print">
            <div class="rw-header-left">
                <div class="rw-icon-badge">
                    <i class="fas fa-route"></i>
                </div>
                <div>
                    <h1 class="rw-header-title">Request Workflow</h1>
                    <p class="rw-header-subtitle">Track request progress and operational steps.</p>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="manage-requests.php" class="btn btn-sm btn-light border text-secondary d-inline-flex align-items-center gap-1 py-1 px-2.5" style="font-size: 11.5px; border-radius: 6px;" title="Back to Requests">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
                <div class="rw-admin-chip">
                    <div class="rw-admin-avatar">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <span>Parish Admin</span>
                </div>
            </div>
        </div>
        <?php if ($error): ?>
            <div class="alert alert-danger shadow-sm mb-4 d-flex align-items-center gap-2">
                <i class="fas fa-exclamation-circle fs-5"></i>
                <div><?php echo e($error); ?></div>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success shadow-sm mb-4 d-flex align-items-center gap-2">
                <i class="fas fa-check-circle fs-5"></i>
                <div><?php echo e($success); ?></div>
            </div>
        <?php endif; ?>

        <!-- 1. TOP OVERVIEW CARD (Summary Metadata + Expandable Toggle) -->
        <div class="rw-card">
            <div class="rw-section-header">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="badge bg-light text-dark border font-monospace px-2 py-1 fw-bold" style="font-size: 11.5px;">
                        <i class="fas fa-receipt me-1 text-primary"></i><?php echo e($request['reference_number']); ?>
                    </span>
                    <span class="badge bg-<?php echo $category_badges[$request_category] ?? 'secondary'; ?>-subtle text-<?php echo $category_badges[$request_category] ?? 'secondary'; ?> border border-<?php echo $category_badges[$request_category] ?? 'secondary'; ?>-subtle text-uppercase fw-semibold px-2 py-0.5" style="font-size: 10.5px;">
                        <?php echo e($category_labels[$request_category] ?? 'Parish Request'); ?>
                    </span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge rounded-pill px-2.5 py-1 fw-semibold text-uppercase <?php echo getStatusBadgeClass($disp_status); ?>" style="font-size: 11px;">
                        <?php echo e(ucfirst(str_replace('_', ' ', $disp_status))); ?>
                    </span>
                </div>
            </div>

            <div class="rw-section-body">
                <!-- Metadata Grid -->
                <div class="row g-2.5">
                    <div class="col-6 col-md-3">
                        <span class="micro-label">Tracking Reference</span>
                        <div class="meta-value font-monospace text-primary">
                            <?php echo e($request['reference_number']); ?>
                        </div>
                    </div>

                    <div class="col-6 col-md-3">
                        <span class="micro-label">Service Name</span>
                        <div class="meta-value">
                            <?php echo e(ucfirst(str_replace('_', ' ', $request['request_type']))); ?>
                        </div>
                    </div>

                    <div class="col-6 col-md-3">
                        <span class="micro-label">Category Badge</span>
                        <div>
                            <span class="badge bg-<?php echo $category_badges[$request_category] ?? 'secondary'; ?> text-uppercase" style="font-size: 10.5px;">
                                <?php echo e($category_labels[$request_category] ?? 'Request'); ?>
                            </span>
                        </div>
                    </div>

                    <div class="col-6 col-md-3">
                        <span class="micro-label">Service Type</span>
                        <div class="meta-value">
                            <?php echo e($category_labels[$request_category] ?? 'Standard Service'); ?>
                        </div>
                    </div>

                    <div class="col-6 col-md-3">
                        <span class="micro-label">Parishioner Name</span>
                        <div class="meta-value d-flex align-items-center gap-1.5">
                            <?php echo renderUserAvatar($request, 22); ?>
                            <span><?php echo e($request['fullname']); ?></span>
                        </div>
                    </div>

                    <div class="col-6 col-md-3">
                        <span class="micro-label">Email Address</span>
                        <div class="meta-value text-secondary text-truncate" title="<?php echo e($request['email']); ?>">
                            <?php echo e($request['email']); ?>
                        </div>
                    </div>

                    <div class="col-6 col-md-3">
                        <span class="micro-label">Date/Time Requested</span>
                        <div class="meta-value">
                            <?php echo formatDate($request['date_requested']); ?>
                            <span class="text-muted small fw-normal d-block" style="font-size: 10.5px;"><?php echo date('h:i A', strtotime($request['date_requested'])); ?></span>
                        </div>
                    </div>

                    <div class="col-6 col-md-3">
                        <span class="micro-label">Contact Number</span>
                        <div class="meta-value">
                            <?php if (!empty($request['phone_number'])): ?>
                                <a href="tel:<?php echo e($request['phone_number']); ?>" class="text-decoration-none fw-semibold">
                                    <i class="fas fa-phone small me-1"></i><?php echo e($request['phone_number']); ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted fw-normal" style="font-size: 11px;">None provided</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Primary Toggle Button Bar -->
                <div class="mt-2.5 pt-2 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <button class="btn btn-outline-primary btn-toggle-doc px-3 py-1 fw-semibold d-inline-flex align-items-center gap-1.5" 
                            type="button" 
                            id="toggleApplicationFormBtn" 
                            data-bs-toggle="collapse" 
                            data-bs-target="#submittedFormCollapse" 
                            aria-expanded="false" 
                            aria-controls="submittedFormCollapse">
                        <i class="fas fa-file-invoice" id="toggleIcon"></i>
                        <span id="toggleText">View Submitted Application Form</span>
                        <i class="fas fa-chevron-down ms-1 small" id="toggleChevron"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- ========================================================= -->
        <!-- 2. COLLAPSIBLE FORMAL BOX: "SUBMITTED APPLICATION FORM"   -->
        <!-- (Hidden/collapsed by default until toggled open)          -->
        <!-- ========================================================= -->
        <div class="collapse mb-4" id="submittedFormCollapse">
            <div class="formal-document-container">
                <!-- Neutral Gray Header Border with Title and Reference Tracking Badge -->
                <div class="formal-doc-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-scroll text-secondary fs-5"></i>
                        <h5 class="mb-0 fw-bold text-dark text-uppercase tracking-wider" style="font-size: 1.05rem; letter-spacing: 0.04em;">
                            Submitted Application Form
                        </h5>
                    </div>
                    <div class="d-flex align-items-center gap-2 no-print">
                        <span class="badge bg-white text-secondary border font-monospace px-2.5 py-1.5">
                            REF: <?php echo e($request['reference_number']); ?>
                        </span>
                        <button type="button" class="btn btn-sm btn-light border" onclick="window.print()" title="Print this formal document">
                            <i class="fas fa-print me-1"></i> Print
                        </button>
                    </div>
                </div>

                <div class="formal-doc-body">
                    <!-- Subtle Institution Header -->
                    <div class="text-center mb-4 pb-3 border-bottom">
                        <h6 class="text-uppercase fw-bold text-secondary mb-1" style="letter-spacing: 0.1em; font-size: 0.85rem;">
                            San Lorenzo Ruiz Mission Station
                        </h6>
                        <div class="text-muted small">Parish Administrative Document &bull; Electronic Service Filing</div>
                    </div>

                    <?php if ($linked_reservation): ?>
                    <div class="alert alert-info d-flex align-items-center gap-3 mb-4 py-3 px-3 border-0 shadow-sm rounded-3">
                        <div class="rounded-circle bg-white text-info p-2 d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; flex-shrink: 0;">
                            <i class="fas fa-calendar-check fa-lg"></i>
                        </div>
                        <div>
                            <strong class="d-block text-dark">Parish Schedule Reservation Linked</strong>
                            <div class="small text-muted mt-1">
                                Scheduled Date: <strong class="text-dark"><?php echo formatDate($linked_reservation['event_date']); ?></strong>
                                <?php if (!empty($linked_reservation['event_time'])): ?>
                                    at <strong class="text-dark"><?php echo e(date('h:i A', strtotime($linked_reservation['event_time']))); ?></strong>
                                <?php endif; ?>
                                &bull; Reservation Status: <span class="badge bg-<?php echo $linked_reservation['status'] === 'approved' ? 'success' : 'warning text-dark'; ?> ms-1"><?php echo e(ucfirst($linked_reservation['status'])); ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Section 1: Application Overview & Schedule -->
                    <div class="mb-4">
                        <div class="formal-section-title">
                            <i class="fas fa-calendar-day text-primary"></i>
                            Section 1: Application Overview & Schedule
                        </div>
                        <div class="row g-3">
                            <div class="col-12 col-sm-6 col-md-3">
                                <span class="micro-label">Preferred Date</span>
                                <div class="meta-value">
                                    <?php echo e($formalDetails['schedule']['preferred_date']); ?>
                                </div>
                            </div>
                            <div class="col-12 col-sm-6 col-md-3">
                                <span class="micro-label">Preferred Time</span>
                                <div class="meta-value">
                                    <?php echo e($formalDetails['schedule']['preferred_time']); ?>
                                </div>
                            </div>
                            <div class="col-12 col-sm-6 col-md-3">
                                <span class="micro-label">Assigned Priest / Minister</span>
                                <div class="meta-value">
                                    <?php echo e($formalDetails['schedule']['assigned_priest']); ?>
                                </div>
                            </div>
                            <div class="col-12 col-sm-6 col-md-3">
                                <span class="micro-label">Venue / Location</span>
                                <div class="meta-value">
                                    <?php echo e($formalDetails['schedule']['venue']); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Section 2: Applicant & Candidate Details -->
                    <div class="mb-4">
                        <div class="formal-section-title">
                            <i class="fas fa-id-card text-primary"></i>
                            Section 2: Applicant & Candidate Details
                        </div>
                        <div class="row g-3">
                            <div class="col-12 col-sm-6 col-md-3">
                                <span class="micro-label">Candidate Full Name</span>
                                <div class="meta-value text-primary fw-bold">
                                    <?php echo e($formalDetails['candidate']['candidate_name']); ?>
                                </div>
                            </div>
                            <div class="col-12 col-sm-6 col-md-3">
                                <span class="micro-label">Father's Name</span>
                                <div class="meta-value">
                                    <?php echo e($formalDetails['candidate']['father_name']); ?>
                                </div>
                            </div>
                            <div class="col-12 col-sm-6 col-md-3">
                                <span class="micro-label">Mother's Maiden Name</span>
                                <div class="meta-value">
                                    <?php echo e($formalDetails['candidate']['mother_name']); ?>
                                </div>
                            </div>
                            <div class="col-12 col-sm-6 col-md-3">
                                <span class="micro-label">Contact Information</span>
                                <div class="meta-value small">
                                    <div><strong><?php echo e($formalDetails['candidate']['contact']['name']); ?></strong></div>
                                    <div class="text-secondary"><?php echo e($formalDetails['candidate']['contact']['email']); ?></div>
                                    <div class="text-muted"><?php echo e($formalDetails['candidate']['contact']['phone']); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Section 3: Attached Supporting Documents -->
                    <div class="mb-3">
                        <div class="formal-section-title">
                            <i class="fas fa-paperclip text-primary"></i>
                            Section 3: Attached Supporting Documents
                        </div>
                        <div class="formal-inset-card">
                            <span class="micro-label mb-2 d-block">Attached Supporting Documents</span>
                            <?php if (empty($formalDetails['remarks_documents']['requirements'])): ?>
                                <div class="small text-muted fst-italic py-1">No requirement documents attached yet.</div>
                            <?php else: ?>
                                <div class="row g-2.5">
                                    <?php foreach ($formalDetails['remarks_documents']['requirements'] as $reqDoc): ?>
                                        <div class="col-12 col-md-6">
                                            <div class="d-flex align-items-center justify-content-between p-2.5 bg-white rounded-3 border small h-100 shadow-none">
                                                <div class="text-truncate me-2" title="<?php echo e($reqDoc['name']); ?>">
                                                    <i class="fas fa-file-check text-success me-1.5"></i>
                                                    <strong class="text-dark"><?php echo e($reqDoc['name']); ?></strong>
                                                    <span class="text-muted small ms-1">(<?php echo e($reqDoc['size']); ?>)</span>
                                                </div>
                                                <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                                    <button type="button" 
                                                            class="btn btn-sm btn-outline-primary py-1 px-2.5 btn-preview-doc fw-semibold"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#documentPreviewModal"
                                                            data-doc-id="<?php echo (int)$reqDoc['id']; ?>"
                                                            data-doc-name="<?php echo e($reqDoc['name']); ?>"
                                                            data-doc-file="<?php echo e($reqDoc['file_name']); ?>"
                                                            data-doc-size="<?php echo e($reqDoc['size']); ?>"
                                                            data-doc-mime="<?php echo e($reqDoc['mime'] ?? ''); ?>">
                                                        <i class="fas fa-eye me-1"></i> View
                                                    </button>
                                                    <a href="../request-document.php?id=<?php echo (int)$reqDoc['id']; ?>&download=1" 
                                                       class="btn btn-sm btn-outline-secondary py-1 px-2" 
                                                       title="Download File" 
                                                       download>
                                                        <i class="fas fa-download"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Additional Canonical Details (Investigation Sheets / Witnesses / Sponsors) -->
                    <?php if (!empty($parsedSections)): ?>
                        <?php 
                        // Filter out sections already summarized if desired, or present additional canonical details
                        $extraSections = array_filter($parsedSections, function($sec) {
                            $lower = strtolower($sec);
                            return !str_contains($lower, 'overview');
                        }, ARRAY_FILTER_USE_KEY);
                        ?>
                        <?php if (!empty($extraSections)): ?>
                            <div class="mt-4 pt-3 border-top">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="text-muted fw-bold text-uppercase small mb-0" style="letter-spacing: 0.05em;">
                                        <i class="fas fa-list-check me-1 text-primary"></i> Canonical Investigation &amp; Detailed Records
                                    </h6>
                                    <button class="btn btn-sm btn-light border text-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#canonicalSheetsCollapse" aria-expanded="false">
                                        <i class="fas fa-layer-group me-1"></i> Toggle Canonical Details
                                    </button>
                                </div>
                                <div class="collapse" id="canonicalSheetsCollapse">
                                    <div class="row g-3">
                                        <?php foreach ($extraSections as $secTitle => $secItems): ?>
                                            <div class="col-12">
                                                <div class="p-3 bg-light rounded-3 border">
                                                    <div class="fw-bold text-dark border-bottom pb-2 mb-3 small d-flex align-items-center gap-2">
                                                        <i class="fas fa-circle-dot text-primary small"></i>
                                                        <?php echo e($secTitle); ?>
                                                    </div>
                                                    <div class="row g-2">
                                                        <?php foreach ($secItems as $item): ?>
                                                            <?php if ($item['type'] === 'field'): ?>
                                                                <div class="col-sm-6">
                                                                    <span class="micro-label"><?php echo e($item['label']); ?></span>
                                                                    <div class="small fw-semibold text-dark text-break">
                                                                        <?php echo e($item['value']); ?>
                                                                    </div>
                                                                </div>
                                                            <?php else: ?>
                                                                <div class="col-12">
                                                                    <div class="small text-secondary bg-white p-2 rounded border">
                                                                        <?php echo e($item['text']); ?>
                                                                    </div>
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>


                    <!-- Formal Document Footer Text -->
                    <div class="formal-doc-footer">
                        Submitted electronically via Parish Portal &bull; Reference: <?php echo e($request['reference_number']); ?> &bull; Date Requested: <?php echo formatDate($request['date_requested']); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========================================================= -->
        <!-- RECEIPTS & RELEASES                                       -->
        <!-- ========================================================= -->
        
        <!-- Payment Receipts (For Certificate Requests) -->
        <?php if ($is_certificate): ?>
        <div class="rw-card">
            <div class="rw-section-header">
                <h6 class="rw-section-title">
                    <i class="fas fa-receipt" style="color: #8c6225; font-size: 13px;"></i>
                    PAYMENT RECEIPTS &amp; VERIFICATION
                </h6>
                <span class="badge" style="background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 12px;">
                    Verified Total: PHP <?php echo number_format($payment_summary['verified_amount'], 2); ?>
                </span>
            </div>
            <div class="rw-section-body">
                <?php if (empty($payments) && empty($documents_by_type['payment_receipt'])): ?>
                    <div class="text-muted fst-italic" style="font-size: 11.5px;">No payment receipts submitted yet.</div>
                <?php else: ?>
                    <?php foreach ($payments as $payment): ?>
                        <?php
                        $badge_map = [
                            'pending' => ['class' => 'warning text-dark', 'icon' => 'fa-clock', 'label' => 'Pending'],
                            'verified' => ['class' => 'success', 'icon' => 'fa-circle-check', 'label' => 'Verified'],
                            'rejected' => ['class' => 'danger', 'icon' => 'fa-circle-xmark', 'label' => 'Rejected']
                        ];
                        $curr_badge = $badge_map[$payment['status']] ?? ['class' => 'secondary', 'icon' => 'fa-info-circle', 'label' => ucfirst($payment['status'])];
                        ?>
                        <div class="border rounded p-2.5 mb-2 bg-light-subtle">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1.5 pb-1.5 border-bottom">
                                <div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="fw-bold text-dark" style="font-size: 13px;">PHP <?php echo number_format(floatval($payment['amount']), 2); ?></span>
                                        <span class="badge bg-primary text-uppercase font-monospace" style="font-size: 10px;"><?php echo e($payment['payment_method']); ?></span>
                                    </div>
                                    <div class="text-muted mt-0.5" style="font-size: 10.5px;">
                                        <?php if (!empty($payment['reference_number'])): ?>
                                            <span class="me-2"><i class="fas fa-hashtag me-1"></i>Ref: <strong><?php echo e($payment['reference_number']); ?></strong></span>
                                        <?php endif; ?>
                                        <?php if (!empty($payment['created_at'])): ?>
                                            <span><i class="fas fa-calendar-alt me-1"></i>Submitted: <?php echo formatDate($payment['created_at']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="badge bg-<?php echo e($curr_badge['class']); ?> px-2 py-0.5" style="font-size: 10.5px;">
                                    <i class="fas <?php echo e($curr_badge['icon']); ?> me-1"></i><?php echo e($curr_badge['label']); ?>
                                </span>
                            </div>

                            <?php if (!empty($payment['notes'])): ?>
                                <div class="p-1.5 bg-white rounded border mb-1.5 text-secondary" style="font-size: 11px;">
                                    <i class="fas fa-comment-dots me-1 text-muted"></i><strong>Parishioner Note:</strong> <?php echo e($payment['notes']); ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($payment['receipt_document_id'])): ?>
                                <div class="mb-2 d-flex align-items-center gap-1.5">
                                    <button type="button" 
                                            class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1 btn-preview-doc py-0.5 px-2"
                                            style="font-size: 11.5px; border-radius: 5px;"
                                            data-bs-toggle="modal"
                                            data-bs-target="#documentPreviewModal"
                                            data-doc-id="<?php echo intval($payment['receipt_document_id']); ?>"
                                            data-doc-name="Payment Receipt - <?php echo e($payment['reference_number'] ?: 'Ref #' . $payment['payment_id']); ?>"
                                            data-doc-file="<?php echo e($payment['original_name'] ?: 'receipt'); ?>"
                                            data-doc-size="<?php echo !empty($payment['file_size']) ? formatFileSize($payment['file_size']) : ''; ?>"
                                            data-doc-mime="<?php echo e($payment['mime_type'] ?? ''); ?>">
                                        <i class="fas fa-file-invoice"></i>
                                        <span>View Receipt (<?php echo e($payment['original_name'] ?: 'Receipt'); ?><?php echo !empty($payment['file_size']) ? ' &bull; ' . formatFileSize($payment['file_size']) : ''; ?>)</span>
                                    </button>
                                    <a class="btn btn-sm btn-outline-secondary py-0.5 px-1.5" 
                                       style="font-size: 11.5px; border-radius: 5px;"
                                       href="../request-document.php?id=<?php echo intval($payment['receipt_document_id']); ?>&download=1" 
                                       title="Download Receipt" 
                                       download>
                                        <i class="fas fa-download"></i>
                                    </a>
                                </div>
                            <?php endif; ?>

                            <form method="POST" class="row g-2 align-items-center pt-1.5 border-top">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="action" value="verify_payment">
                                <input type="hidden" name="request_id" value="<?php echo intval($request_id); ?>">
                                <input type="hidden" name="payment_id" value="<?php echo intval($payment['payment_id']); ?>">
                                <div class="col-md-3">
                                    <span class="micro-label">Status</span>
                                    <select class="form-select form-select-sm" name="payment_status" required style="font-size: 11.5px; padding: 2px 6px; height: 28px;">
                                        <option value="pending" <?php echo $payment['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="verified" <?php echo $payment['status'] === 'verified' ? 'selected' : ''; ?>>Verified</option>
                                        <option value="rejected" <?php echo $payment['status'] === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <span class="micro-label">Admin Remarks</span>
                                    <input type="text" class="form-control form-control-sm" name="admin_remarks" value="<?php echo e($payment['admin_remarks'] ?? ''); ?>" placeholder="Enter note or check..." style="font-size: 11.5px; padding: 2px 8px; height: 28px;">
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <button type="submit" class="btn btn-sm btn-primary w-100 mt-auto fw-semibold" style="font-size: 11.5px; padding: 3px 8px; height: 28px;">
                                        <i class="fas fa-check-double me-1"></i> Update
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($is_certificate): ?>
        <!-- SECTION 1: Sacramental Registry Cross-Check -->
        <div class="rw-card">
            <div class="rw-section-header">
                <h6 class="rw-section-title">
                    <i class="fas fa-certificate" style="color: #0284c7; font-size: 13px;"></i>
                    SACRAMENTAL REGISTRY CROSS-CHECK
                </h6>
                <div>
                    <?php if ($match_status === 'matched' && $matched_record): ?>
                        <span class="badge" style="background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 12px;">
                            <i class="fas fa-check-circle me-1"></i> Registry record matched
                        </span>
                    <?php elseif ($match_status === 'multiple'): ?>
                        <span class="badge" style="background: #fef9c3; color: #854d0e; border: 1px solid #fde047; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 12px;">
                            <i class="fas fa-triangle-exclamation me-1"></i> Possible matches (verify)
                        </span>
                    <?php else: ?>
                        <span class="badge" style="background: #fef3c7; color: #b45309; border: 1px solid #fde68a; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 12px;">
                            <i class="fas fa-search me-1"></i> Manual lookup needed
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="rw-section-body">
                <?php if ($match_status === 'matched' && $matched_record): ?>
                    <?php
                    $rec_title = ucfirst($matched_record_type ?: 'Sacramental');
                    $rec_name = $matched_record['fullname'] ?? ($matched_record['husband_name'] ?? ($matched_record['deceased_name'] ?? 'N/A'));
                    $rec_date = $matched_record['baptism_date'] ?? ($matched_record['communion_date'] ?? ($matched_record['confirmation_date'] ?? ($matched_record['wedding_date'] ?? ($matched_record['date_of_burial'] ?? ''))));
                    $rec_priest = $matched_record['priest'] ?? ($matched_record['officiating_priest'] ?? ($matched_record['bishop_priest'] ?? ($matched_record['minister'] ?? '')));
                    ?>
                    <div class="rw-alert-success mb-2">
                        <div class="d-flex align-items-center gap-2 text-truncate">
                            <i class="fas fa-circle-check text-success flex-shrink-0" style="font-size: 14px;"></i>
                            <div class="text-truncate">
                                <span class="fw-bold text-dark d-block text-truncate" style="font-size: 12px;">
                                    Matched <?php echo e($rec_title); ?> Record #<?php echo intval($matched_record_id); ?>: <?php echo e($rec_name); ?>
                                </span>
                                <span class="text-muted text-truncate d-block" style="font-size: 11px;">
                                    <?php if ($rec_date): ?>
                                        Date: <strong><?php echo e(displayDate($rec_date, 'M j, Y')); ?></strong> &bull; 
                                    <?php endif; ?>
                                    Book: <strong><?php echo e($matched_record['book_no'] ?? 'N/A'); ?></strong> &bull; 
                                    Page: <strong><?php echo e($matched_record['page_no'] ?? 'N/A'); ?></strong> &bull; 
                                    Entry: <strong><?php echo e($matched_record['entry_no'] ?? 'N/A'); ?></strong>
                                    <?php if (!empty($rec_priest)): ?>
                                        &bull; Priest: <em><?php echo e($rec_priest); ?></em>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        <a href="certificate-generator.php?request_id=<?php echo intval($request_id); ?>&cert_type=<?php echo urlencode($matched_record_type ?: 'baptism'); ?>&record_id=<?php echo intval($matched_record_id); ?>" class="btn btn-sm btn-primary d-inline-flex align-items-center gap-1.5 flex-shrink-0" style="font-size: 11.5px; padding: 4px 10px; border-radius: 6px; font-weight: 600;">
                            <i class="fas fa-file-signature"></i>
                            <span>Generate Certificate</span>
                        </a>
                    </div>
                <?php elseif ($match_status === 'multiple' && !empty($match_details['candidates'])): ?>
                    <div class="rw-alert-warning mb-2">
                        <i class="fas fa-triangle-exclamation flex-shrink-0" style="color: #d97706; font-size: 13px; margin-top: 2px;"></i>
                        <div style="flex: 1;">
                            <div class="fw-bold" style="color: #92400e; font-size: 12px; line-height: 1.3;">Multiple possible records found</div>
                            <div style="color: #78350f; font-size: 11.5px; line-height: 1.35; margin-top: 2px;">
                                More than one sacramental entry matched the name "<strong><?php echo e($request['record_holder_name'] ?? ''); ?></strong>". Please verify the correct record below:
                            </div>
                        </div>
                    </div>
                    <div class="d-flex flex-column gap-2 mb-2">
                        <?php foreach ($match_details['candidates'] as $c): ?>
                            <div class="d-flex align-items-center justify-content-between p-2 rounded border bg-white" style="font-size: 11.5px;">
                                <div>
                                    <div class="fw-bold text-dark">
                                        Record #<?php echo intval($c['record_id']); ?> &mdash; <?php echo e($c['name']); ?>
                                        <span class="badge bg-secondary-subtle text-secondary ms-1" style="font-size: 10px; font-weight: 500;">
                                            Match: <?php echo round(($c['score'] ?? 0) * 100); ?>%
                                        </span>
                                    </div>
                                    <div class="text-muted small">
                                        Date: <strong><?php echo e(!empty($c['sacrament_date']) ? displayDate($c['sacrament_date'], 'M j, Y') : 'N/A'); ?></strong>
                                        <?php if (!empty($c['priest'])): ?>
                                            &bull; Priest: <em><?php echo e($c['priest']); ?></em>
                                        <?php endif; ?>
                                        <?php if (!empty($c['father_name'])): ?>
                                            &bull; Father: <?php echo e($c['father_name']); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <a href="certificate-generator.php?request_id=<?php echo intval($request_id); ?>&cert_type=<?php echo urlencode($matched_record_type ?: 'baptism'); ?>&record_id=<?php echo intval($c['record_id']); ?>" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1" style="font-size: 11px; padding: 3px 8px;">
                                    <i class="fas fa-check"></i>
                                    <span>Select &amp; Generate</span>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="rw-alert-warning mb-2">
                        <i class="fas fa-triangle-exclamation flex-shrink-0" style="color: #d97706; font-size: 13px; margin-top: 2px;"></i>
                        <div style="flex: 1;">
                            <div class="fw-bold" style="color: #92400e; font-size: 12px; line-height: 1.3;">No exact registry match found</div>
                            <div style="color: #78350f; font-size: 11.5px; line-height: 1.35; margin-top: 2px;">
                                The applicant's submitted name and sacrament date did not match an active entry automatically. Search the parish archives or open Certificate Generator to select a record manually.
                            </div>
                        </div>
                    </div>
                    <a href="certificate-generator.php?request_id=<?php echo intval($request_id); ?>&cert_type=<?php echo urlencode($matched_record_type ?: 'baptism'); ?>" class="btn btn-sm btn-outline-warning text-dark d-inline-flex align-items-center gap-1.5" style="font-size: 11.5px; padding: 4px 10px; border-radius: 6px; font-weight: 600; border-color: #d97706;">
                        <i class="fas fa-search"></i>
                        <span>Search Records in Generator</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($is_certificate): ?>
        <?php
        $is_online_release = (stripos((string)($request['description'] ?? ''), 'Online Release') !== false) || (stripos((string)($request['description'] ?? ''), 'online') !== false);
        $is_walkin_release = (stripos((string)($request['description'] ?? ''), 'Walk-in') !== false);
        $released_files = array_merge($documents_by_type['released_certificate'], $documents_by_type['admin_file']);
        $first_released = !empty($released_files) ? $released_files[0] : null;
        ?>
        <!-- SECTION 2: Certificate Issuance & Release -->
        <div class="rw-card">
            <div class="rw-section-header">
                <h6 class="rw-section-title">
                    <i class="fas fa-certificate" style="color: #16a34a; font-size: 13px;"></i>
                    CERTIFICATE ISSUANCE &amp; RELEASE
                </h6>
                <div>
                    <?php if ($is_online_release): ?>
                        <span class="badge" style="background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 12px;">
                            <i class="fas fa-globe me-1"></i> Online Release
                        </span>
                    <?php elseif ($is_walkin_release): ?>
                        <span class="badge" style="background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 12px;">
                            <i class="fas fa-person-walking me-1"></i> Walk-in Pickup
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="rw-section-body">
                <form method="POST" enctype="multipart/form-data">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="action" value="upload_release">
                    <input type="hidden" name="request_id" value="<?php echo intval($request_id); ?>">

                    <div class="row g-3">
                        <!-- Left Column: Upload -->
                        <div class="col-12 col-md-6">
                            <div class="fw-bold text-dark" style="font-size: 12px; margin-bottom: 2px;">Upload signed certificate</div>
                            <div class="text-muted" style="font-size: 11.5px; margin-bottom: 6px;">Sends instantly to the parishioner's portal for download.</div>
                            
                            <div class="d-flex align-items-center gap-2">
                                <input type="file" class="form-control form-control-sm" id="release_file" name="release_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required style="font-size: 11.5px; padding: 3px 8px; height: 30px;">
                            </div>
                            <div class="text-muted" style="font-size: 10.5px; margin-top: 3px;">PDF, JPG, PNG up to 10MB</div>
                        </div>

                        <!-- Right Column: Released File -->
                        <div class="col-12 col-md-6">
                            <div class="fw-bold text-dark" style="font-size: 12px; margin-bottom: 2px;">Released file</div>
                            <div class="text-muted" style="font-size: 11.5px; margin-bottom: 6px;">Official file currently delivered to applicant.</div>

                            <?php if ($first_released): ?>
                                <div class="rw-file-card">
                                    <div class="d-flex align-items-center gap-2 text-truncate me-1">
                                        <i class="fas fa-file-pdf text-danger flex-shrink-0" style="font-size: 15px;"></i>
                                        <div class="text-truncate">
                                            <span class="fw-semibold text-dark d-block text-truncate" style="font-size: 11.5px; max-width: 200px;" title="<?php echo e($first_released['original_name']); ?>">
                                                <?php echo e($first_released['original_name']); ?>
                                            </span>
                                            <span class="text-muted d-block text-truncate" style="font-size: 10.5px;">
                                                <?php echo e(formatFileSize($first_released['file_size'])); ?> &bull; <?php echo formatDate($first_released['uploaded_at']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                        <button type="button" 
                                                class="btn-icon-neutral btn-preview-doc"
                                                data-bs-toggle="modal"
                                                data-bs-target="#documentPreviewModal"
                                                data-doc-id="<?php echo intval($first_released['document_id']); ?>"
                                                data-doc-name="<?php echo e($first_released['original_name']); ?>"
                                                data-doc-file="<?php echo e($first_released['original_name']); ?>"
                                                data-doc-size="<?php echo e(formatFileSize($first_released['file_size'])); ?>"
                                                data-doc-mime="<?php echo e($first_released['mime_type'] ?? ''); ?>"
                                                title="View file">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <a class="btn-icon-gold" 
                                           href="../request-document.php?id=<?php echo intval($first_released['document_id']); ?>&download=1" 
                                           title="Download file" 
                                           download>
                                            <i class="fas fa-download"></i>
                                        </a>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="rw-file-card text-muted fst-italic justify-content-center" style="font-size: 11.5px; background: #fafaf8; border-style: dashed;">
                                    <i class="fas fa-file-circle-question me-1.5 text-secondary" style="font-size: 12px;"></i>
                                    No certificate released yet.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Bottom inline checkbox and submit button -->
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-2 pt-2 border-top">
                        <div class="form-check m-0 d-flex align-items-center gap-1.5">
                            <input class="form-check-input" type="checkbox" name="mark_completed" id="mark_completed" value="1" checked style="margin-top: 0; width: 14px; height: 14px;">
                            <label class="form-check-label" for="mark_completed" style="font-size: 11.5px; color: #334155; cursor: pointer;">
                                Mark this request as <strong>Completed (Fulfilled)</strong> and notify the parishioner.
                            </label>
                        </div>
                        <button type="submit" class="btn btn-sm btn-success fw-semibold d-inline-flex align-items-center gap-1.5" style="font-size: 11.5px; padding: 4px 12px; border-radius: 6px;">
                            <i class="fas fa-paper-plane"></i> Upload &amp; Release
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- 3. BOTTOM ACTION SECTION: "REVIEW STATUS & UPDATES" -->
        <div class="rw-card">
            <div class="rw-section-header">
                <h6 class="rw-section-title">
                    <i class="fas fa-clipboard-check" style="color: #8c6225; font-size: 13px;"></i>
                    REVIEW STATUS &amp; UPDATES
                </h6>
            </div>

            <div class="rw-section-body">
                <form method="POST" id="reviewStatusForm">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="request_id" value="<?php echo intval($request_id); ?>">

                    <div class="row g-3 mb-3">
                        <!-- Status Dropdown -->
                        <div class="col-md-5">
                            <label for="status" class="micro-label">REQUEST STATUS</label>
                            <select class="form-select border-secondary-subtle py-1.5 fw-semibold" id="status" name="status" required style="font-size: 12px; height: 32px;">
                                <option value="pending" <?php echo (strtolower($request['status'] ?? '') === 'pending') ? 'selected' : ''; ?>>Pending</option>
                                <option value="processing" <?php echo (strtolower($request['status'] ?? '') === 'processing') ? 'selected' : ''; ?>>Processing</option>
                                <option value="completed" <?php echo (strtolower($request['status'] ?? '') === 'completed') ? 'selected' : ''; ?>>Completed</option>
                                <option value="rejected" <?php echo (strtolower($request['status'] ?? '') === 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                            <div class="text-muted small mt-1" style="font-size: 11px;">
                                <i class="fas fa-info-circle me-1"></i> Changing this updates parishioner tracking status.
                            </div>
                        </div>

                        <!-- Admin Response / Remarks Textarea -->
                        <div class="col-md-7">
                            <label class="micro-label" for="admin_response">Admin Response / Remarks</label>
                            <textarea class="form-control border-secondary-subtle" 
                                      id="admin_response" 
                                      name="admin_response" 
                                      rows="2" 
                                      style="font-size: 12px;"
                                      placeholder="Enter remarks, instructions, or pickup/event reminders sent back to parishioner..."><?php echo e($request['admin_response'] ?? ''); ?></textarea>
                            <div class="text-muted small mt-1" style="font-size: 11px;">
                                <i class="fas fa-bell me-1"></i> Sent in parishioner email &amp; portal notification.
                            </div>
                        </div>

                        <?php if ($is_sacramental): 
                            $priest_roster = getParishPriestRoster($conn);
                            $default_parish_priest = getParishPriestName($conn);
                        ?>
                            <div class="col-md-6">
                                <label for="workflow_minister" class="micro-label">Minister / Officiating Priest <span class="text-danger">*</span></label>
                                <select class="form-select border-secondary-subtle py-1 fw-semibold" id="workflow_minister" name="officiating_priest" style="font-size: 11.5px; height: 30px;">
                                    <option value="">-- Select Minister (Who performed sacrament) --</option>
                                    <?php foreach ($priest_roster as $p_opt): ?>
                                        <option value="<?php echo htmlspecialchars($p_opt); ?>"><?php echo htmlspecialchars($p_opt); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="text-muted small mt-1" style="font-size: 10.5px;">Required when marking sacramental request completed.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="workflow_parish_priest" class="micro-label">Parish Priest <span class="text-danger">*</span></label>
                                <select class="form-select border-secondary-subtle py-1 fw-semibold" id="workflow_parish_priest" name="parish_priest" style="font-size: 11.5px; height: 30px;">
                                    <option value="">-- Select Parish Priest --</option>
                                    <?php foreach ($priest_roster as $p_opt): ?>
                                        <option value="<?php echo htmlspecialchars($p_opt); ?>" <?php echo ($p_opt === $default_parish_priest) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($p_opt); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="text-muted small mt-1" style="font-size: 10.5px;">Confirmed canonical Parish Priest for official registry.</div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Bottom Action Bar -->
                    <div class="pt-2 border-top d-flex flex-column flex-sm-row justify-content-between align-items-stretch align-items-sm-center gap-2">
                        <a href="manage-requests.php" class="btn btn-sm btn-outline-secondary px-3 py-1 fw-semibold d-inline-flex align-items-center justify-content-center gap-1.5" style="font-size: 12px; border-radius: 6px;">
                            <i class="fas fa-arrow-left"></i>
                            <span>Back to Requests</span>
                        </a>

                        <button type="submit" class="btn btn-sm btn-parish-gold d-inline-flex align-items-center justify-content-center gap-1.5">
                            <i class="fas fa-check-circle"></i>
                            <span>Update Request</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const formCollapse = document.getElementById('submittedFormCollapse');
    const toggleBtn = document.getElementById('toggleApplicationFormBtn');
    const toggleText = document.getElementById('toggleText');
    const toggleIcon = document.getElementById('toggleIcon');
    const toggleChevron = document.getElementById('toggleChevron');

    if (formCollapse && toggleBtn && toggleText) {
        formCollapse.addEventListener('show.bs.collapse', function () {
            toggleText.textContent = 'Hide Application Form';
            if (toggleIcon) {
                toggleIcon.className = 'fas fa-folder-open';
            }
            if (toggleChevron) {
                toggleChevron.className = 'fas fa-chevron-up ms-1 small';
            }
            toggleBtn.classList.remove('btn-outline-primary');
            toggleBtn.classList.add('btn-primary');
        });

        formCollapse.addEventListener('hide.bs.collapse', function () {
            toggleText.textContent = 'View Submitted Application Form';
            if (toggleIcon) {
                toggleIcon.className = 'fas fa-file-invoice';
            }
            if (toggleChevron) {
                toggleChevron.className = 'fas fa-chevron-down ms-1 small';
            }
            toggleBtn.classList.remove('btn-primary');
            toggleBtn.classList.add('btn-outline-primary');
        });
    }
});
</script>

<!-- Supporting Document Preview Modal -->
<div class="modal fade" id="documentPreviewModal" tabindex="-1" aria-labelledby="docPreviewModalLabel" aria-hidden="true" style="z-index: 1060;">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; overflow: hidden;">
            <div class="modal-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2 text-truncate me-3">
                    <div class="p-2 rounded bg-primary-subtle text-primary flex-shrink-0">
                        <i class="fas fa-file-lines fa-lg"></i>
                    </div>
                    <div class="min-w-0">
                        <h5 class="modal-title fw-bold text-dark mb-0 text-truncate" id="docPreviewModalLabel">Document Preview</h5>
                        <div class="text-muted small text-truncate" id="docPreviewMeta">Loading document details...</div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2 flex-shrink-0">
                    <a id="docPreviewExternalBtn" href="#" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary d-none d-sm-inline-flex align-items-center gap-1.5" title="Open in new tab">
                        <i class="fas fa-arrow-up-right-from-square"></i>
                        <span>Open Tab</span>
                    </a>
                    <a id="docPreviewDownloadBtn" href="#" class="btn btn-sm btn-primary d-inline-flex align-items-center gap-1.5 fw-semibold" download title="Download file to device">
                        <i class="fas fa-download"></i>
                        <span>Download</span>
                    </a>
                    <button type="button" class="btn-close ms-2" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body p-0 position-relative d-flex flex-column align-items-center justify-content-center" style="min-height: 520px; background-color: #0f172a10;">
                
                <!-- Loading State -->
                <div id="docPreviewLoader" class="text-center py-5">
                    <div class="spinner-border text-primary mb-3" role="status" style="width: 3.2rem; height: 3.2rem;">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <div class="fw-bold text-dark fs-6">Loading Document Content...</div>
                    <div class="text-muted small mt-1">Retrieving and verifying the file stream.</div>
                </div>

                <!-- Error State -->
                <div id="docPreviewError" class="text-center py-5 px-4" style="display: none;">
                    <div class="mb-3 text-danger">
                        <i class="fas fa-triangle-exclamation fa-3x"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-2">Unable to Render Preview</h5>
                    <p id="docPreviewErrorMessage" class="text-secondary small mb-4" style="max-width: 480px; margin: 0 auto;">
                        The browser could not display this document directly. You can still download the file to inspect it securely on your device.
                    </p>
                    <a id="docPreviewErrorDownloadBtn" href="#" class="btn btn-primary px-4 py-2 fw-semibold d-inline-flex align-items-center gap-2" download>
                        <i class="fas fa-download"></i>
                        <span>Download Document</span>
                    </a>
                </div>

                <!-- Fallback Container (for non-previewable formats like .docx, .zip, etc.) -->
                <div id="docPreviewFallback" class="text-center py-5 px-4" style="display: none;">
                    <div class="mb-3 text-secondary">
                        <i class="fas fa-file-arrow-down fa-3x text-primary"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-1">In-Browser Preview Not Available</h5>
                    <p class="text-muted small mb-3" style="max-width: 460px; margin: 0 auto;">
                        This file format cannot be rendered directly inside the browser. Use the download button below to view the file on your device.
                    </p>
                    <div class="badge bg-light text-dark border px-3 py-2 mb-4 font-monospace" id="docPreviewFallbackFileName">filename.ext</div>
                    <div>
                        <a id="docPreviewFallbackDownloadBtn" href="#" class="btn btn-primary px-4 py-2 fw-semibold d-inline-flex align-items-center gap-2" download>
                            <i class="fas fa-download"></i>
                            <span>Download File</span>
                        </a>
                    </div>
                </div>

                <!-- Image Viewer Container -->
                <div id="docPreviewImageContainer" class="w-100 h-100 p-3 text-center d-flex align-items-center justify-content-center overflow-auto" style="display: none;">
                    <img id="docPreviewImage" src="" alt="Document Preview" class="img-fluid rounded shadow-sm" style="max-height: 76vh; max-width: 100%; object-fit: contain; display: none;">
                </div>

                <!-- PDF Viewer Container -->
                <div id="docPreviewPdfContainer" class="w-100 h-100" style="display: none;">
                    <iframe id="docPreviewPdfFrame" src="about:blank" class="w-100 border-0" style="height: 78vh; min-height: 520px; display: block;" title="Document PDF Preview"></iframe>
                </div>

            </div>
            <div class="modal-footer bg-white border-top py-2.5 px-4 d-flex justify-content-between align-items-center">
                <div class="text-muted small">
                    <i class="fas fa-shield-halved text-success me-1"></i> Verified authenticated document stream
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary px-3" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// Document Viewer Controller
(function () {
    let initialized = false;
    let currentDocId = null;
    let activeBlobUrl = null;
    let activeAbortController = null;

    function initDocViewer() {
        if (initialized) return;
        const previewModalEl = document.getElementById('documentPreviewModal');
        if (!previewModalEl) return;
        initialized = true;

        const modalLabel = document.getElementById('docPreviewModalLabel');
        const modalMeta = document.getElementById('docPreviewMeta');
        const externalBtn = document.getElementById('docPreviewExternalBtn');
        const downloadBtn = document.getElementById('docPreviewDownloadBtn');
        const errorDownloadBtn = document.getElementById('docPreviewErrorDownloadBtn');
        const fallbackDownloadBtn = document.getElementById('docPreviewFallbackDownloadBtn');
        const fallbackFileName = document.getElementById('docPreviewFallbackFileName');
        
        const loader = document.getElementById('docPreviewLoader');
        const errorBox = document.getElementById('docPreviewError');
        const errorMsg = document.getElementById('docPreviewErrorMessage');
        const fallbackBox = document.getElementById('docPreviewFallback');
        const imgContainer = document.getElementById('docPreviewImageContainer');
        const imgElement = document.getElementById('docPreviewImage');
        const pdfContainer = document.getElementById('docPreviewPdfContainer');
        const pdfFrame = document.getElementById('docPreviewPdfFrame');

        function cleanupActiveStream() {
            if (activeAbortController) {
                try { activeAbortController.abort(); } catch (e) {}
                activeAbortController = null;
            }
            if (activeBlobUrl) {
                try { URL.revokeObjectURL(activeBlobUrl); } catch (e) {}
                activeBlobUrl = null;
            }
            if (imgElement) {
                imgElement.onload = null;
                imgElement.onerror = null;
                imgElement.removeAttribute('src');
                imgElement.style.display = 'none';
            }
            if (pdfFrame) {
                pdfFrame.onload = null;
                pdfFrame.onerror = null;
                pdfFrame.src = 'about:blank';
            }
        }

        function showErrorState(message) {
            if (loader) loader.style.display = 'none';
            if (imgContainer) imgContainer.style.display = 'none';
            if (pdfContainer) pdfContainer.style.display = 'none';
            if (fallbackBox) fallbackBox.style.display = 'none';
            if (errorMsg) {
                errorMsg.textContent = message || 'Unable to preview this file — try downloading it instead.';
            }
            if (errorBox) errorBox.style.display = 'block';
        }

        function renderDocPreview(button) {
            if (!button) return;
            const docId = button.getAttribute('data-doc-id');
            if (!docId) return;

            // Strict deduplication: if already loading or loaded this doc, do not re-run
            if (currentDocId === docId) {
                return;
            }
            currentDocId = docId;

            const docName = button.getAttribute('data-doc-name') || 'Document Preview';
            const docFile = button.getAttribute('data-doc-file') || '';
            const docSize = button.getAttribute('data-doc-size') || '';
            const docMime = (button.getAttribute('data-doc-mime') || '').toLowerCase();

            cleanupActiveStream();

            if (loader) loader.style.display = 'block';
            if (errorBox) errorBox.style.display = 'none';
            if (fallbackBox) fallbackBox.style.display = 'none';
            if (imgContainer) imgContainer.style.display = 'none';
            if (pdfContainer) pdfContainer.style.display = 'none';

            const previewUrl = '../request-document.php?id=' + encodeURIComponent(docId);
            const downloadUrl = '../request-document.php?id=' + encodeURIComponent(docId) + '&download=1';

            if (modalLabel) modalLabel.textContent = docName;
            if (modalMeta) modalMeta.textContent = (docFile || 'Document file') + (docSize ? ' • ' + docSize : '');
            
            if (externalBtn) externalBtn.href = previewUrl;
            if (downloadBtn) downloadBtn.href = downloadUrl;
            if (errorDownloadBtn) errorDownloadBtn.href = downloadUrl;
            if (fallbackDownloadBtn) fallbackDownloadBtn.href = downloadUrl;
            if (fallbackFileName) fallbackFileName.textContent = docFile || 'Attached Document';

            const ext = (docFile.split('.').pop() || '').toLowerCase();
            const isImage = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'svg', 'ico', 'tif', 'tiff'].includes(ext) || (docMime && docMime.startsWith('image/'));
            const isPdf = ext === 'pdf' || docMime === 'application/pdf';

            if (!isImage && !isPdf) {
                if (loader) loader.style.display = 'none';
                if (fallbackBox) fallbackBox.style.display = 'block';
                return;
            }

            activeAbortController = new AbortController();
            const fetchSignal = activeAbortController.signal;

            fetch(previewUrl, {
                signal: fetchSignal,
                credentials: 'same-origin',
                headers: {
                    'Accept': isImage ? 'image/*,*/*' : 'application/pdf,*/*'
                }
            })
            .then(async function (response) {
                if (currentDocId !== docId) return;

                if (!response.ok) {
                    let errText = 'Unable to preview this file — try downloading it instead.';
                    if (response.status === 404) {
                        errText = 'The requested document file was not found on server storage. Try downloading it instead.';
                    } else if (response.status === 403) {
                        errText = 'Access denied. You do not have permission to view this document.';
                    } else if (response.status >= 500) {
                        errText = 'A server error occurred while retrieving this document. Try downloading it instead.';
                    }
                    showErrorState(errText);
                    return;
                }

                const contentType = (response.headers.get('content-type') || '').toLowerCase();
                if (contentType.includes('application/json')) {
                    try {
                        const json = await response.json();
                        showErrorState(json.message || 'Unable to preview this file — try downloading it instead.');
                        return;
                    } catch (e) {}
                }

                const blob = await response.blob();
                if (currentDocId !== docId) return;

                if (!blob || blob.size === 0) {
                    showErrorState('The uploaded document is empty or unreadable. Try downloading it instead.');
                    return;
                }

                const resolvedBlobUrl = URL.createObjectURL(blob);
                activeBlobUrl = resolvedBlobUrl;

                if (isImage) {
                    if (imgContainer && imgElement) {
                        imgElement.onload = function () {
                            if (currentDocId !== docId) return;
                            if (loader) loader.style.display = 'none';
                            if (errorBox) errorBox.style.display = 'none';
                            if (fallbackBox) fallbackBox.style.display = 'none';
                            if (imgContainer) imgContainer.style.display = 'flex';
                            imgElement.style.display = 'block';
                        };
                        imgElement.onerror = function () {
                            if (currentDocId !== docId) return;
                            showErrorState('Unable to preview this image file — try downloading it instead.');
                        };
                        imgElement.src = resolvedBlobUrl;
                    }
                } else if (isPdf) {
                    if (pdfContainer && pdfFrame) {
                        pdfContainer.style.display = 'block';
                        pdfFrame.onload = function () {
                            if (currentDocId !== docId) return;
                            if (loader) loader.style.display = 'none';
                            if (errorBox) errorBox.style.display = 'none';
                        };
                        pdfFrame.onerror = function () {
                            if (currentDocId !== docId) return;
                            showErrorState('Unable to preview this PDF document — try downloading it instead.');
                        };
                        pdfFrame.src = resolvedBlobUrl;
                    }
                }
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') return;
                if (currentDocId !== docId) return;
                console.warn('Document preview fetch error:', err);
                showErrorState('Unable to preview this file — try downloading it instead.');
            });
        }

        // Bootstrap show.bs.modal listener
        previewModalEl.addEventListener('show.bs.modal', function (event) {
            renderDocPreview(event.relatedTarget);
        });

        // Global click listener for .btn-preview-doc
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.btn-preview-doc');
            if (btn) {
                renderDocPreview(btn);
                if (window.bootstrap && window.bootstrap.Modal) {
                    try {
                        const modalInstance = bootstrap.Modal.getOrCreateInstance(previewModalEl);
                        modalInstance.show();
                    } catch (err) {
                        console.warn('Bootstrap modal show failed:', err);
                    }
                }
            }
        });

        previewModalEl.addEventListener('hidden.bs.modal', function () {
            currentDocId = null;
            cleanupActiveStream();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDocViewer);
    } else {
        initDocViewer();
    }
    window.addEventListener('load', initDocViewer);
})();
</script>

<?php include '../templates/footer.php'; ?>
