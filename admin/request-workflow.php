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
ensureRequestCertificateFileSchema($conn);

$request_id = intval($_GET['id'] ?? $_POST['request_id'] ?? 0);
if ($request_id <= 0) {
    redirect('manage-requests.php');
}

$error = $_SESSION['flash_error'] ?? '';
$success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

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
$is_funeral = ($raw_type === 'funeral_mass' || $raw_type === 'funeral' || str_contains($raw_type, 'funeral'));

$linked_funeral_record = null;
if ($is_funeral) {
    $stmtFun = $conn->prepare("SELECT * FROM funeral_records WHERE request_id = ? LIMIT 1");
    if ($stmtFun) {
        $stmtFun->bind_param('i', $request_id);
        $stmtFun->execute();
        $linked_funeral_record = $stmtFun->get_result()->fetch_assoc();
        $stmtFun->close();
    }
}
$funeral_fields = extractFuneralSheetFields((string)($request['description'] ?? ''), $request);
$applicant_name = trim((string)($request['fullname'] ?? ''));
if ($linked_funeral_record) {
    if (!empty($linked_funeral_record['deceased_name'])) {
        $linked_dec = trim($linked_funeral_record['deceased_name']);
        if (empty($funeral_fields['deceased_name'])) {
            $funeral_fields['deceased_name'] = $linked_dec;
        } elseif ($applicant_name !== '' && stripos($linked_dec, $applicant_name) !== false) {
            // Preserve clean name from request description
        } else {
            $funeral_fields['deceased_name'] = $linked_dec;
        }
    }
    if (!empty($linked_funeral_record['date_of_death'])) $funeral_fields['date_of_death'] = $linked_funeral_record['date_of_death'];
    if (!empty($linked_funeral_record['date_of_burial'])) $funeral_fields['date_of_burial'] = $linked_funeral_record['date_of_burial'];
    if (!empty($linked_funeral_record['civil_status'])) $funeral_fields['civil_status'] = $linked_funeral_record['civil_status'];
    if (!empty($linked_funeral_record['funeral_rites'])) $funeral_fields['funeral_rites'] = $linked_funeral_record['funeral_rites'];
    if (!empty($linked_funeral_record['cause_of_death'])) $funeral_fields['cause_of_death'] = $linked_funeral_record['cause_of_death'];
    if (!empty($linked_funeral_record['place_of_burial'])) $funeral_fields['place_of_burial'] = $linked_funeral_record['place_of_burial'];
    if (!empty($linked_funeral_record['minister'])) $funeral_fields['minister'] = $linked_funeral_record['minister'];
}

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
        $allowed_statuses = ['pending', 'processing', 'ready', 'ready_for_pickup', 'completed', 'rejected'];

        $requirement_count = requestDocumentCount($conn, $request_id, 'requirement');
        $released_count = requestDocumentCount($conn, $request_id, 'released_certificate') + requestDocumentCount($conn, $request_id, 'admin_file');
        $current_payment_summary = getRequestPaymentSummary($conn, $request_id);

        $request_type = strtolower(trim((string)($request['request_type'] ?? '')));
        $is_funeral_action = ($request_type === 'funeral_mass' || $request_type === 'funeral' || str_contains($request_type, 'funeral'));
        $zero_requirement_services = [
            'patronal_fiesta', 'anointing_of_the_sick', 'mass_offering', 'mass_intention',
            'blessing_service', 'general_blessing',
            'first_communion_service', 'first_communion', 'communion',
            'confirmation_service', 'confirmation'
        ];
        $requires_supporting_docs = !in_array($request_type, $zero_requirement_services, true);

        $funeral_validation_error = null;
        $updated_funeral_in_db = false;

        if ($is_funeral_action && isset($_POST['funeral_sheet']) && is_array($_POST['funeral_sheet'])) {
            $f_deceased = trim((string)($_POST['funeral_sheet']['deceased_name'] ?? ''));
            $f_death_date = trim((string)($_POST['funeral_sheet']['date_of_death'] ?? ''));
            $f_burial_date = trim((string)($_POST['funeral_sheet']['date_of_burial'] ?? ''));
            $f_civil_status = trim((string)($_POST['funeral_sheet']['civil_status'] ?? ''));
            $f_rites = trim((string)($_POST['funeral_sheet']['funeral_rites'] ?? ''));
            $f_cause = trim((string)($_POST['funeral_sheet']['cause_of_death'] ?? ''));
            $f_place = trim((string)($_POST['funeral_sheet']['place_of_burial'] ?? ''));
            $f_minister = trim((string)($_POST['officiating_priest'] ?? $_POST['funeral_sheet']['minister'] ?? ''));

            if ($status === 'completed') {
                $missing_fun = [];
                if ($f_deceased === '') $missing_fun[] = 'Deceased Name';
                if ($f_death_date === '' || !validDateValue($f_death_date)) $missing_fun[] = 'Date of Death';
                if ($f_burial_date === '' || !validDateValue($f_burial_date)) $missing_fun[] = 'Date of Burial';
                if ($f_civil_status === '') $missing_fun[] = 'Civil Status';
                if ($f_rites === '') $missing_fun[] = 'Funeral Rites';
                if ($f_place === '') $missing_fun[] = 'Place of Burial';

                if (!empty($missing_fun)) {
                    $funeral_validation_error = 'Cannot mark request as Completed: The following required Funeral Records fields are missing: ' . implode(', ', $missing_fun) . '. Please fill them in before completing.';
                }
            }

            if (!$funeral_validation_error) {
                // Update description with updated funeral sheet block
                $updated_desc = updateFuneralDescriptionBlock((string)($request['description'] ?? ''), [
                    'deceased_name' => $f_deceased,
                    'date_of_death' => $f_death_date,
                    'date_of_burial' => $f_burial_date,
                    'civil_status' => $f_civil_status,
                    'funeral_rites' => $f_rites,
                    'cause_of_death' => $f_cause,
                    'place_of_burial' => $f_place,
                    'minister' => $f_minister
                ]);
                $stmtDesc = $conn->prepare("UPDATE requests SET description = ? WHERE request_id = ?");
                if ($stmtDesc) {
                    $stmtDesc->bind_param('si', $updated_desc, $request_id);
                    $stmtDesc->execute();
                    $stmtDesc->close();
                    $request['description'] = $updated_desc;
                }

                // If a linked record already exists in funeral_records, update it too
                $chkFun = $conn->prepare("SELECT funeral_id FROM funeral_records WHERE request_id = ? LIMIT 1");
                if ($chkFun) {
                    $chkFun->bind_param('i', $request_id);
                    $chkFun->execute();
                    $existFun = $chkFun->get_result()->fetch_assoc();
                    $chkFun->close();
                    if ($existFun) {
                        $updFun = $conn->prepare("UPDATE funeral_records SET deceased_name = ?, date_of_death = ?, date_of_burial = ?, civil_status = ?, funeral_rites = ?, cause_of_death = ?, place_of_burial = ?, minister = ?, updated_at = NOW() WHERE request_id = ?");
                        if ($updFun) {
                            $updFun->bind_param('ssssssssi', $f_deceased, $f_death_date, $f_burial_date, $f_civil_status, $f_rites, $f_cause, $f_place, $f_minister, $request_id);
                            $updFun->execute();
                            $updFun->close();
                            $updated_funeral_in_db = true;
                        }
                    }
                }
            }
        }

        if (!in_array($status, $allowed_statuses, true)) {
            $error = 'Invalid request status. Allowed: Pending, Processing, Completed, Rejected.';
        } elseif ($funeral_validation_error !== null) {
            $error = $funeral_validation_error;
        } elseif (in_array($status, ['processing', 'ready', 'ready_for_pickup', 'completed'], true) && !$is_certificate && $requires_supporting_docs && $requirement_count <= 0) {
            $error = 'This request cannot move forward until at least one supporting requirement is attached.';
        } elseif ($status === 'completed' && $is_certificate && intval($current_payment_summary['total']) > 0 && intval($current_payment_summary['verified']) <= 0) {
            $error = 'A submitted payment receipt must be verified before marking this request completed.';
        } elseif ($status === 'completed') {
            require_once __DIR__ . '/../services/SacramentalApprovalService.php';
            $is_sacramental_type = SacramentalApprovalService::isSacramentalRequestType($request_type);
            $is_comm_or_conf_action = in_array($request_type, ['first_communion_service', 'first_communion', 'communion', 'confirmation_service', 'confirmation'], true);
            $ceremony_date = trim((string)($_POST['ceremony_date'] ?? ''));
            $ceremony_time = trim((string)($_POST['ceremony_time'] ?? ''));
            $ceremony_minister = trim((string)($_POST['ceremony_minister'] ?? ''));
            if ($ceremony_minister === 'Other' && !empty($_POST['ceremony_minister_other'])) {
                $ceremony_minister = trim((string)$_POST['ceremony_minister_other']);
            }

            if ($is_comm_or_conf_action) {
                $missing_schedule = [];
                if ($ceremony_date === '' || !validDateValue($ceremony_date)) {
                    $missing_schedule[] = 'Ceremony Date';
                }
                if ($ceremony_time === '') {
                    $missing_schedule[] = 'Ceremony Time';
                }
                if ($ceremony_minister === '' || $ceremony_minister === 'Other') {
                    $missing_schedule[] = 'Minister';
                }
                if (!empty($missing_schedule)) {
                    http_response_code(422);
                    $error = 'Cannot complete request: The following required fields are missing: ' . implode(', ', $missing_schedule) . '.';
                }
            }

            if (empty($error) && $is_sacramental_type) {
                try {
                    $sacramentalService = new SacramentalApprovalService($conn);
                    $officiating_priest = $is_comm_or_conf_action ? $ceremony_minister : trim($_POST['officiating_priest'] ?? $_POST['minister'] ?? '');
                    $parish_priest = trim($_POST['parish_priest'] ?? '');
                    $completionResult = $sacramentalService->completeRequest($request_id, (int)$_SESSION['user_id'], [
                        'admin_response' => $admin_response,
                        'officiating_priest' => $officiating_priest,
                        'parish_priest' => $parish_priest,
                        'ceremony_date' => $ceremony_date ?: null,
                        'ceremony_time' => $ceremony_time ?: null,
                        'ceremony_minister' => $ceremony_minister ?: null,
                        'target_status' => 'completed'
                    ]);
                    $request['status'] = 'completed';
                    $request['admin_response'] = $admin_response;
                    if ($is_comm_or_conf_action) {
                        $request['ceremony_date'] = $ceremony_date;
                        $request['ceremony_time'] = $ceremony_time;
                        $request['ceremony_minister'] = $ceremony_minister;
                    }
                    if ($is_funeral_action) {
                        $success = 'Funeral record has been added to Funeral Records. Request marked as completed and calendar schedule updated.';
                    } elseif ($is_comm_or_conf_action) {
                        $serviceName = str_contains($request_type, 'communion') ? 'First Communion' : 'Confirmation';
                        $success = "Request marked as completed! {$serviceName} record registered with ceremony date " . formatDate($ceremony_date) . " at " . date('g:i A', strtotime($ceremony_time)) . ".";
                    } else {
                        $success = 'Request marked as completed! Sacramental record registered and calendar schedule updated.';
                    }
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

                        // Explicitly trigger and await SMS delivery if phone exists
                        $smsResult = null;
                        $smsFeedback = '';
                        if (!empty($request['phone_number'])) {
                            $smsMsg = "TUGON Parish System: Your " . tugonRequestTypeLabel($request['request_type'] ?? 'parish') . " request (" . ($request['reference_number'] ?? '') . ") has been completed by the parish office." . ($admin_response ? " Note: " . $admin_response : "");
                            $smsResult = sendTugonSms($conn, $request['phone_number'], $smsMsg, (int)$request['user_id'], 'request_completed');
                            if (!empty($smsResult['ok'])) {
                                $smsFeedback = " • SMS queued to {$smsResult['phone']}" . (!empty($smsResult['batch_id']) ? " (Batch: {$smsResult['batch_id']})" : "");
                            } elseif (!empty($smsResult['error'])) {
                                $smsFeedback = " • SMS notice: " . $smsResult['error'];
                            }
                        }

                        // Automatically sync day and time of event to calendar schedule
                        $calSync = syncApprovedRequestToCalendar($conn, $request_id, (int)$_SESSION['user_id']);
                        $calNotice = (!empty($calSync['success']) && !empty($calSync['message']) && str_contains($calSync['message'], 'skipped') === false)
                            ? ' Event schedule automatically added to the parish calendar.'
                            : '';
                        $success = 'Request marked as completed!' . $calNotice . $smsFeedback;
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
                    if (in_array($status, ['pending', 'processing', 'ready', 'ready_for_pickup', 'rejected'], true)) {
                        cancelLinkedRequestCalendarEvent($conn, $request_id);
                    }

                    // Explicitly trigger and await SMS delivery if phone exists
                    $smsResult = null;
                    $smsFeedback = '';
                    if (!empty($request['phone_number'])) {
                        $statusLabel = ucfirst(str_replace('_', ' ', $status));
                        $smsMsg = "TUGON Parish System: Your " . tugonRequestTypeLabel($request['request_type'] ?? 'parish') . " request (" . ($request['reference_number'] ?? '') . ") status is now {$statusLabel}." . ($admin_response ? " Note: " . $admin_response : "");
                        $smsResult = sendTugonSms($conn, $request['phone_number'], $smsMsg, (int)$request['user_id'], 'request_' . $status);
                        if (!empty($smsResult['ok'])) {
                            $smsFeedback = " • SMS queued to {$smsResult['phone']}" . (!empty($smsResult['batch_id']) ? " (Batch: {$smsResult['batch_id']})" : "");
                        } elseif (!empty($smsResult['error'])) {
                            $smsFeedback = " • SMS notice: " . $smsResult['error'];
                        }
                    }

                    $noticePrefix = $updated_funeral_in_db ? 'Funeral record updated! ' : '';
                    $success = $noticePrefix . 'Request status updated to ' . ucfirst(str_replace('_', ' ', $status)) . '.' . $smsFeedback;
                } else {
                    $error = 'Unable to update request status.';
                }
                $stmt->close();
            } else {
                $error = 'Unable to prepare request update.';
            }
        }

        $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (isset($_SERVER['HTTP_ACCEPT']) && str_contains(strtolower($_SERVER['HTTP_ACCEPT']), 'application/json'));

        if ($is_ajax) {
            header('Content-Type: application/json; charset=utf-8');
            if ($error) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
            } else {
                echo json_encode([
                    'success' => true,
                    'message' => $success ?: ('Request status updated to ' . ucfirst(str_replace('_', ' ', $status)) . '.'),
                    'status' => $status,
                    'status_label' => ucfirst(str_replace('_', ' ', $status)),
                    'admin_response' => $admin_response,
                    'sms' => $smsResult ?? null
                ]);
            }
            exit;
        } else {
            if ($error) {
                $_SESSION['flash_error'] = $error;
            } else {
                $_SESSION['flash_success'] = $success ?: ('Request status updated to ' . ucfirst(str_replace('_', ' ', $status)) . '.');
            }
            header('Location: request-workflow.php?id=' . intval($request_id));
            exit;
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
    } elseif ($action === 'upload_certificate_release' || $action === 'upload_release') {
        $file = $_FILES['certificate_file'] ?? $_FILES['release_file'] ?? null;
        $release_note = trim((string)($_POST['release_note'] ?? $_POST['admin_note'] ?? ''));
        $mark_completed = !empty($_POST['mark_completed']);
        $db_file_name = '';
        $db_file_size = 0;
        $new_status = $request['status'] ?? 'processing';

        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $error = 'Please select a certificate file to upload.';
        } elseif (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $error = 'File upload failed. Please try again.';
        } elseif (($file['size'] ?? 0) > (10 * 1024 * 1024)) {
            $error = 'File size exceeds the 10 MB limit. Please select a smaller file.';
        } else {
            $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
            $allowed_exts = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
            if (!in_array($ext, $allowed_exts, true)) {
                $error = 'Invalid file type. Only PDF, JPG, PNG, and WEBP files up to 10 MB are accepted.';
            } else {
                $val = validateUploadedDocument($file);
                if (!$val['ok']) {
                    $error = $val['error'] ?? 'Invalid file format. Only valid PDF, JPG, PNG, or WEBP documents are allowed.';
                } else {
                    $doc_result = saveRequestDocument($conn, $request_id, (int)$_SESSION['user_id'], $file, 'released_certificate', 'Official Released Certificate');
                    if (!$doc_result['ok'] || empty($doc_result['saved'])) {
                        $error = $doc_result['error'] ?? 'Failed to save the certificate file to secure storage.';
                    } else {
                        $doc_id = (int)$doc_result['document_id'];

                        $stmtDoc = $conn->prepare("SELECT file_path, original_name, file_size, mime_type, uploaded_at FROM request_documents WHERE document_id = ? LIMIT 1");
                        $stmtDoc->bind_param('i', $doc_id);
                        $stmtDoc->execute();
                        $saved_doc = $stmtDoc->get_result()->fetch_assoc();
                        $stmtDoc->close();

                        $db_file_path = $saved_doc['file_path'] ?? '';
                        $db_file_name = $saved_doc['original_name'] ?? basename($file['name']);
                        $db_file_size = (int)($saved_doc['file_size'] ?? $file['size']);

                        // Update certificate columns on requests record
                        $stmtReq = $conn->prepare("
                            UPDATE requests 
                            SET certificate_file_path = ?,
                                certificate_file_name = ?,
                                certificate_uploaded_by = ?,
                                certificate_uploaded_at = NOW(),
                                certificate_release_note = ?,
                                updated_at = NOW()
                            WHERE request_id = ?
                        ");
                        if ($stmtReq) {
                            $admin_user_id = (int)$_SESSION['user_id'];
                            $stmtReq->bind_param('ssisi', $db_file_path, $db_file_name, $admin_user_id, $release_note, $request_id);
                            $stmtReq->execute();
                            $stmtReq->close();
                        }

                        // Soft-delete older released certificate documents for this request
                        $stmtOld = $conn->prepare("
                            UPDATE request_documents 
                            SET deleted_at = NOW() 
                            WHERE request_id = ? 
                              AND document_type = 'released_certificate' 
                              AND document_id != ?
                        ");
                        if ($stmtOld) {
                            $stmtOld->bind_param('ii', $request_id, $doc_id);
                            $stmtOld->execute();
                            $stmtOld->close();
                        }

                        // Update in-memory request record
                        $request['certificate_file_path'] = $db_file_path;
                        $request['certificate_file_name'] = $db_file_name;
                        $request['certificate_uploaded_by'] = (int)$_SESSION['user_id'];
                        $request['certificate_uploaded_at'] = date('Y-m-d H:i:s');
                        $request['certificate_release_note'] = $release_note;

                        createAuditLog($conn, (int)$_SESSION['user_id'], 'UPLOAD_CERTIFICATE_RELEASE', 'requests', $request_id);

                        // If option to mark completed was chosen, complete request
                        if ($mark_completed) {
                            $stmtStatus = $conn->prepare("UPDATE requests SET status = 'completed', updated_at = NOW() WHERE request_id = ?");
                            if ($stmtStatus) {
                                $stmtStatus->bind_param('i', $request_id);
                                $stmtStatus->execute();
                                $stmtStatus->close();
                                $request['status'] = 'completed';
                                $new_status = 'completed';
                                createAuditLog($conn, (int)$_SESSION['user_id'], 'UPDATE_REQUEST_STATUS', 'requests', $request_id);
                                createRequestStatusNotification($conn, $request, 'completed', $release_note ?: 'Official certificate has been issued and is ready for download.');
                            }
                        }

                        // Notify parishioner that certificate is ready for download
                        createNotification(
                            $conn,
                            (int)$request['user_id'],
                            'Certificate Ready for Download',
                            'Your official certificate for request ' . ($request['reference_number'] ?? ('#' . $request_id)) . ' has been completed and is ready for online download.',
                            true,
                            'requests',
                            'request',
                            (int)$request_id,
                            'request.view'
                        );

                        if (function_exists('queueEmailNotification')) {
                            @queueEmailNotification(
                                $conn,
                                (int)$request['user_id'],
                                'Certificate Ready for Download - San Lorenzo Ruiz Parish',
                                'Hello ' . ($request['fullname'] ?? 'Parishioner') . ",\n\nYour certificate request (" . ($request['reference_number'] ?? '') . ") has been completed by the parish office. You may now download your official certificate online.\n\nSan Lorenzo Ruiz Parish"
                            );
                        }

                        // Explicitly trigger and await SMS delivery if phone exists
                        $smsResult = null;
                        $smsFeedback = '';
                        if (!empty($request['phone_number'])) {
                            $smsMsg = "TUGON Parish System: Your official certificate for request (" . ($request['reference_number'] ?? '') . ") has been completed and is ready for online download." . ($release_note ? " Note: " . $release_note : "");
                            $smsResult = sendTugonSms($conn, $request['phone_number'], $smsMsg, (int)$request['user_id'], 'certificate_ready_for_download');
                            if (!empty($smsResult['ok'])) {
                                $smsFeedback = " • SMS queued to {$smsResult['phone']}" . (!empty($smsResult['batch_id']) ? " (Batch: {$smsResult['batch_id']})" : "");
                            } elseif (!empty($smsResult['error'])) {
                                $smsFeedback = " • SMS notice: " . $smsResult['error'];
                            }
                        }

                        $success = 'Certificate file successfully uploaded and ready for online download.' . $smsFeedback;
                    }
                }
            }
        }

        $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (isset($_SERVER['HTTP_ACCEPT']) && str_contains(strtolower($_SERVER['HTTP_ACCEPT']), 'application/json'));

        if ($is_ajax) {
            header('Content-Type: application/json; charset=utf-8');
            if ($error) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
            } else {
                echo json_encode([
                    'success' => true,
                    'message' => $success,
                    'file_name' => $db_file_name,
                    'file_size' => formatFileSize($db_file_size),
                    'uploaded_at' => formatDate(date('Y-m-d H:i:s')),
                    'release_note' => $release_note,
                    'status' => $new_status,
                    'status_label' => ucfirst(str_replace('_', ' ', $new_status)),
                    'download_url' => '../users/download-certificate.php?request_id=' . intval($request_id) . '&download=1',
                    'view_url' => '../users/download-certificate.php?request_id=' . intval($request_id),
                    'sms' => $smsResult ?? null
                ]);
            }
            exit;
        } else {
            if ($error) {
                $_SESSION['flash_error'] = $error;
            } else {
                $_SESSION['flash_success'] = $success;
            }
            header('Location: request-workflow.php?id=' . intval($request_id));
            exit;
        }
    } elseif ($action === 'remove_certificate_release') {
        $stmtClear = $conn->prepare("
            UPDATE requests 
            SET certificate_file_path = NULL,
                certificate_file_name = NULL,
                certificate_uploaded_by = NULL,
                certificate_uploaded_at = NULL,
                certificate_release_note = NULL,
                updated_at = NOW()
            WHERE request_id = ?
        ");
        if ($stmtClear) {
            $stmtClear->bind_param('i', $request_id);
            $stmtClear->execute();
            $stmtClear->close();
        }

        $stmtDelDoc = $conn->prepare("
            UPDATE request_documents 
            SET deleted_at = NOW() 
            WHERE request_id = ? AND document_type = 'released_certificate'
        ");
        if ($stmtDelDoc) {
            $stmtDelDoc->bind_param('i', $request_id);
            $stmtDelDoc->execute();
            $stmtDelDoc->close();
        }

        $request['certificate_file_path'] = null;
        $request['certificate_file_name'] = null;
        $request['certificate_uploaded_by'] = null;
        $request['certificate_uploaded_at'] = null;
        $request['certificate_release_note'] = null;

        createAuditLog($conn, (int)$_SESSION['user_id'], 'REMOVE_CERTIFICATE_RELEASE', 'requests', $request_id);

        $success = 'Certificate file removed successfully.';

        $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (isset($_SERVER['HTTP_ACCEPT']) && str_contains(strtolower($_SERVER['HTTP_ACCEPT']), 'application/json'));

        if ($is_ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'message' => $success]);
            exit;
        } else {
            $_SESSION['flash_success'] = $success;
            header('Location: request-workflow.php?id=' . intval($request_id));
            exit;
        }
    } elseif ($action === 'send_test_sms') {
        $target_phone = trim((string)($_POST['phone_number'] ?? ''));
        $test_message = trim((string)($_POST['message'] ?? ''));

        if (empty($target_phone)) {
            $error = 'Please provide a valid recipient phone number.';
        } elseif (empty($test_message)) {
            $error = 'Please provide a message body to send.';
        } else {
            $smsResult = sendTugonSms($conn, $target_phone, $test_message, (int)($request['user_id'] ?? $_SESSION['user_id']), 'admin_test');
            if (!empty($smsResult['ok'])) {
                $success = "Test SMS queued successfully to {$smsResult['phone']}" . (!empty($smsResult['batch_id']) ? " (Batch: {$smsResult['batch_id']})" : "");
            } else {
                $error = "SMS failed: " . ($smsResult['error'] ?? 'Unknown gateway error');
            }
        }

        $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (isset($_SERVER['HTTP_ACCEPT']) && str_contains(strtolower($_SERVER['HTTP_ACCEPT']), 'application/json'));

        if ($is_ajax) {
            header('Content-Type: application/json; charset=utf-8');
            if ($error) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error, 'sms' => $smsResult ?? null]);
            } else {
                echo json_encode(['success' => true, 'message' => $success, 'sms' => $smsResult ?? null]);
            }
            exit;
        } else {
            if ($error) {
                $_SESSION['flash_error'] = $error;
            } else {
                $_SESSION['flash_success'] = $success;
            }
            header('Location: request-workflow.php?id=' . intval($request_id));
            exit;
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
            $prefDate = $kv['date of burial'] ?? $kv['preferred date'] ?? $kv['date of baptism'] ?? $kv['date of marriage'] ?? $kv['wedding date'] ?? $kv['date of patronal fiesta'] ?? null;
        }
        $displayDate = $prefDate ? formatDate($prefDate) : formatDate($request['date_requested']);

        $prefTime = $linked_reservation['event_time'] ?? null;
        if (!$prefTime) {
            $prefTime = $kv['preferred time'] ?? null;
        }
        $rawType = strtolower(trim((string)($request['request_type'] ?? '')));
        $isCommunionOrConfirmation = in_array($rawType, ['first_communion_service', 'first_communion', 'communion', 'confirmation_service', 'confirmation'], true);
        if ($isCommunionOrConfirmation && empty($prefTime)) {
            $displayTime = 'Time to be set';
        } else {
            $displayTime = $prefTime ? date('h:i A', strtotime($prefTime)) : 'Regular Parish Hours / TBA';
        }

        $assignedPriest = $request['assigned_staff_name'] ?? null;
        if (!$assignedPriest) {
            $assignedPriest = $kv['minister / officiant name'] ?? $kv['minister'] ?? $kv['officiant'] ?? $kv['assigned priest'] ?? $kv['priest'] ?? $kv['celebrant'] ?? 'Parish Priest / Assigned Minister';
        }

        $venue = $kv['location'] ?? $kv['venue'] ?? null;
        if (!$venue) {
            $rawType = strtolower(trim((string)($request['request_type'] ?? '')));
            $venue = str_contains($rawType, 'certif') ? 'San Lorenzo Ruiz Parish Office' : 'San Lorenzo Ruiz Parish Church / Chapel';
        }

        // Section 2: Applicant & Candidate Details
        $candidateName = !empty($request['record_holder_name']) ? $request['record_holder_name'] : null;
        $isMarriage = str_contains($rawType, 'marriage') || str_contains($rawType, 'wedding');
        $isFuneral = str_contains($rawType, 'funeral') || str_contains($rawType, 'burial');

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
        } elseif ($isFuneral) {
            $candidateName = $kv['deceased full name'] ?? $kv['deceased name'] ?? $kv['name of deceased'] ?? $request['record_holder_name'] ?? $request['fullname'];
            $fatherName = 'Civil Status: ' . ($kv['civil status'] ?? 'N/A');
            $motherName = 'Rites: ' . ($kv['type of funeral rites'] ?? $kv['funeral rites'] ?? 'Full Catholic Rites');
        } else {
            if (!$candidateName) {
                $candidateName = $kv['name of communicant'] ?? $kv['communicant'] ?? $kv['name of confirmed person'] ?? $kv['confirmed person'] ?? $kv['name of child'] ?? $kv['child\'s name'] ?? $kv['full name'] ?? $kv['full maiden name'] ?? $kv['record holder name'] ?? $request['fullname'];
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

        $reqType = strtolower(trim((string)($request['request_type'] ?? '')));
        $isCommunion = in_array($reqType, ['first_communion_service', 'first_communion', 'communion'], true);
        $isConfirmation = in_array($reqType, ['confirmation_service', 'confirmation'], true);

        $reqDocs = [];
        if ($isCommunion || $isConfirmation) {
            $expected = $isCommunion
                ? ['Baptismal Certificate']
                : ['Baptismal Certificate', 'First Communion Certificate'];

            $attachedRequirements = $documents_by_type['requirement'] ?? [];
            foreach ($expected as $expectedName) {
                $matchedDoc = null;
                foreach ($attachedRequirements as $idx => $doc) {
                    $dName = trim((string)($doc['requirement_name'] ?? ''));
                    if (strcasecmp($dName, $expectedName) === 0 || str_contains(strtolower($dName), strtolower(explode(' ', $expectedName)[0]))) {
                        $matchedDoc = $doc;
                        unset($attachedRequirements[$idx]);
                        break;
                    }
                }
                if (!$matchedDoc && !empty($attachedRequirements)) {
                    $matchedDoc = array_shift($attachedRequirements);
                }

                if ($matchedDoc) {
                    $reqDocs[] = [
                        'id'        => (int)$matchedDoc['document_id'],
                        'name'      => $expectedName,
                        'file_name' => $matchedDoc['original_name'],
                        'size'      => formatFileSize($matchedDoc['file_size']),
                        'mime'      => $matchedDoc['mime_type'] ?? '',
                        'status'    => 'uploaded'
                    ];
                } else {
                    $reqDocs[] = [
                        'id'        => 0,
                        'name'      => $expectedName,
                        'file_name' => '',
                        'size'      => '',
                        'mime'      => '',
                        'status'    => 'missing'
                    ];
                }
            }

            foreach ($attachedRequirements as $leftover) {
                $reqDocs[] = [
                    'id'        => (int)$leftover['document_id'],
                    'name'      => !empty($leftover['requirement_name']) ? $leftover['requirement_name'] : $leftover['original_name'],
                    'file_name' => $leftover['original_name'],
                    'size'      => formatFileSize($leftover['file_size']),
                    'mime'      => $leftover['mime_type'] ?? '',
                    'status'    => 'uploaded'
                ];
            }
        } else {
            foreach ($documents_by_type['requirement'] ?? [] as $doc) {
                $reqDocs[] = [
                    'id'        => (int)$doc['document_id'],
                    'name'      => !empty($doc['requirement_name']) ? $doc['requirement_name'] : $doc['original_name'],
                    'file_name' => $doc['original_name'],
                    'size'      => formatFileSize($doc['file_size']),
                    'mime'      => $doc['mime_type'] ?? '',
                    'status'    => 'uploaded'
                ];
            }
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

$raw_req_type = strtolower(trim((string)($request['request_type'] ?? '')));
$is_comm_or_conf = in_array($raw_req_type, ['first_communion_service', 'first_communion', 'communion', 'confirmation_service', 'confirmation'], true);
$formalDetails = extractFormalDocumentDetails($request, $linked_reservation, $documents_by_type);
$parsedSections = parseSubmittedApplicationForm((string)($request['description'] ?? ''));

if ($is_comm_or_conf && !empty($parsedSections)) {
    foreach ($parsedSections as $secName => &$secItems) {
        $secItems = array_values(array_filter($secItems, function($item) {
            if (($item['type'] ?? '') === 'field') {
                $lbl = strtolower(trim($item['label'] ?? ''));
                if (in_array($lbl, ['preferred date', 'preferred time', 'year', 'confirmation year', 'month and day', 'month & day'], true)) {
                    return false;
                }
            }
            return true;
        }));
    }
    unset($secItems);
    $parsedSections = array_filter($parsedSections, static fn($items) => !empty($items));
}

$existing_ceremony_date = $request['ceremony_date'] ?? '';
$existing_ceremony_time = $request['ceremony_time'] ?? '';
$existing_ceremony_minister = $request['ceremony_minister'] ?? '';

if ($is_comm_or_conf) {
    if (in_array($raw_req_type, ['first_communion_service', 'first_communion', 'communion'], true)) {
        $chkRec = $conn->prepare("SELECT communion_date, priest FROM first_communion_records WHERE request_id = ? LIMIT 1");
        if ($chkRec) {
            $chkRec->bind_param('i', $request_id);
            $chkRec->execute();
            $recRow = $chkRec->get_result()->fetch_assoc();
            $chkRec->close();
            if ($recRow) {
                if (empty($existing_ceremony_date)) $existing_ceremony_date = $recRow['communion_date'] ?? '';
                if (empty($existing_ceremony_minister)) $existing_ceremony_minister = $recRow['priest'] ?? '';
            }
        }
    } else {
        $chkRec = $conn->prepare("SELECT confirmation_date, bishop_priest FROM confirmation_records WHERE request_id = ? LIMIT 1");
        if ($chkRec) {
            $chkRec->bind_param('i', $request_id);
            $chkRec->execute();
            $recRow = $chkRec->get_result()->fetch_assoc();
            $chkRec->close();
            if ($recRow) {
                if (empty($existing_ceremony_date)) $existing_ceremony_date = $recRow['confirmation_date'] ?? '';
                if (empty($existing_ceremony_minister)) $existing_ceremony_minister = $recRow['bishop_priest'] ?? '';
            }
        }
    }
    if (empty($existing_ceremony_minister) && !str_contains($raw_req_type, 'communion')) {
        $existing_ceremony_minister = 'Bp. Angelito R. Lampon, O.M.I., D.D.';
    }
}

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

/* Standardized Dark Hero Header Banner & Card */
.rw-hero-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
    overflow: hidden;
}

.rw-hero-banner {
    background: #1e293b;
    color: #ffffff;
    padding: 14px 22px;
    border-radius: 12px 12px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.rw-hero-status {
    display: flex;
    align-items: center;
}

.rw-status-pill {
    display: inline-flex;
    align-items: center;
    padding: 5px 14px;
    border-radius: 9999px;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.03em;
    line-height: 1.3;
    text-transform: capitalize;
}

.rw-hero-tracking {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.rw-tracking-prefix {
    font-size: 0.82rem;
    font-weight: 500;
    color: #94a3b8;
    letter-spacing: 0.02em;
}

.rw-tracking-code {
    font-size: 0.95rem;
    font-weight: 700;
    color: #f8fafc;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    letter-spacing: 0.04em;
}

/* 4-Column Metadata Matrix */
.rw-metadata-body {
    padding: 22px 24px 20px;
    background: #ffffff;
}

.rw-metadata-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px 24px;
}

@media (max-width: 992px) {
    .rw-metadata-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 16px 20px;
    }
}

@media (max-width: 576px) {
    .rw-metadata-grid {
        grid-template-columns: 1fr;
        gap: 14px;
    }
}

.rw-meta-cell {
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    min-width: 0;
}

.rw-meta-label {
    font-size: 0.72rem !important;
    font-weight: 600 !important;
    color: #64748b !important;
    letter-spacing: 0.04em !important;
    text-transform: uppercase !important;
    margin-bottom: 6px !important;
    line-height: 1.2 !important;
}

.rw-meta-value {
    font-size: 0.88rem !important;
    font-weight: 600 !important;
    color: #0f172a !important;
    line-height: 1.4 !important;
    word-break: break-word;
}

.rw-meta-value.font-monospace {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace !important;
}

.rw-user-inline {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}

.rw-meta-avatar {
    width: 32px !important;
    height: 32px !important;
    min-width: 32px !important;
    min-height: 32px !important;
    border-radius: 50% !important;
    flex-shrink: 0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

.rw-meta-name {
    font-weight: 600;
    color: #0f172a;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.rw-phone-link {
    color: #0f172a;
    text-decoration: none;
    font-weight: 600;
    transition: color 0.15s ease;
}

.rw-phone-link:hover {
    color: #c89b3c;
    text-decoration: underline;
}

.rw-date-primary {
    font-weight: 600;
    color: #0f172a;
}

.rw-date-sub {
    font-size: 0.78rem;
    font-weight: 500;
    color: #64748b;
    margin-top: 2px;
}

/* Collapsible Application Drawer Toggle Bar */
.rw-app-drawer-toggle-bar {
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid #f1f5f9;
}

.rw-toggle-drawer-btn {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 18px;
    background: #fafaf9;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    color: #475569;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease-in-out;
}

.rw-toggle-drawer-btn:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
    color: #0f172a;
}

.rw-toggle-drawer-btn[aria-expanded="true"] {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #0f172a;
}

.rw-toggle-chevron {
    font-size: 0.85rem;
    font-weight: 700;
    display: inline-block;
    transition: transform 0.2s ease;
}

.rw-toggle-drawer-btn[aria-expanded="true"] .rw-toggle-chevron {
    transform: rotate(180deg);
}

/* Operational Review & Action Card */
.rw-review-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    margin-bottom: 24px;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
    overflow: hidden;
}

.rw-review-card-header {
    padding: 14px 20px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.rw-review-card-title {
    font-size: 0.82rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    color: #1e293b;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
    text-transform: uppercase;
}

.rw-review-card-body {
    padding: 20px 22px;
}

.rw-review-split-grid {
    display: grid;
    grid-template-columns: 4fr 6fr;
    gap: 20px;
    margin-bottom: 16px;
}

@media (max-width: 768px) {
    .rw-review-split-grid {
        grid-template-columns: 1fr;
        gap: 14px;
    }
}

.rw-control-select {
    height: 42px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 0.88rem;
    font-weight: 600;
    color: #1e293b;
    background-color: #ffffff;
    transition: all 0.15s ease;
}

.rw-control-select:focus {
    border-color: #c89b3c;
    box-shadow: 0 0 0 3px rgba(200, 155, 60, 0.15);
    outline: none;
}

.rw-control-textarea {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 0.88rem;
    color: #1e293b;
    background-color: #ffffff;
    min-height: 42px;
    transition: all 0.15s ease;
}

.rw-control-textarea:focus {
    border-color: #c89b3c;
    box-shadow: 0 0 0 3px rgba(200, 155, 60, 0.15);
    outline: none;
}

.rw-field-caption {
    font-size: 0.76rem;
    color: #64748b;
    margin-top: 6px;
    display: flex;
    align-items: center;
    gap: 5px;
}

.rw-action-toolbar {
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}

.rw-back-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border: 1px solid #cbd5e1 !important;
    color: #475569 !important;
    background: #ffffff !important;
    font-size: 0.85rem;
    font-weight: 600;
    padding: 8px 18px;
    border-radius: 8px;
    text-decoration: none;
    transition: all 0.15s ease;
}

.rw-back-btn:hover {
    background: #f1f5f9 !important;
    border-color: #94a3b8 !important;
    color: #0f172a !important;
}

.rw-update-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #c89b3c !important;
    border: 1px solid #b58930 !important;
    color: #ffffff !important;
    font-size: 0.88rem;
    font-weight: 700;
    padding: 8px 24px;
    border-radius: 8px;
    box-shadow: 0 2px 6px rgba(200, 155, 60, 0.25);
    transition: all 0.15s ease;
    cursor: pointer;
}

.rw-update-btn:hover {
    background: #b58930 !important;
    border-color: #9e7525 !important;
    box-shadow: 0 4px 10px rgba(200, 155, 60, 0.35);
    transform: translateY(-1px);
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

/* Release Certificate Dropzone */
.cert-dropzone-box {
    background: #fafaf9;
    border: 2px dashed #cbd5e1;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s ease-in-out;
}
.cert-dropzone-box:hover,
.cert-dropzone-box.dragover {
    background: #fffcf5;
    border-color: #8c6225;
    box-shadow: 0 0 0 3px rgba(140, 98, 37, 0.08);
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

        <!-- ========================================================= -->
        <!-- 1. STANDARDIZED REQUEST WORKFLOW HERO & METADATA CARD     -->
        <!-- ========================================================= -->
        <?php
        $status_raw = strtolower(trim((string)($disp_status ?? 'pending')));
        if ($status_raw === 'submitted') $status_raw = 'pending';

        // Soft muted fills with crisp typography
        $status_pill_styles = [
            'pending'          => 'background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;',
            'approved'         => 'background: #dcfce7; color: #15803d; border: 1px solid #86efac;',
            'processing'       => 'background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd;',
            'in_progress'      => 'background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd;',
            'completed'        => 'background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0;',
            'released'         => 'background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0;',
            'ready_for_pickup' => 'background: #fef9c3; color: #854d0e; border: 1px solid #fde047;',
            'rejected'         => 'background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;',
            'cancelled'        => 'background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;'
        ];
        $current_pill_style = $status_pill_styles[$status_raw] ?? 'background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1;';
        $status_display_label = ucfirst(str_replace('_', ' ', $status_raw));

        // Service naming & classification
        $service_name_display = ucfirst(str_replace(['_', '-'], ' ', (string)($request['request_type'] ?? 'General Request')));
        $service_type_display = $category_labels[$request_category] ?? 'Standard Service';
        $category_text_display = $category_labels[$request_category] ?? 'Parish Request';
        ?>

        <div class="rw-hero-card">
            <!-- A. Dark Hero Header Banner -->
            <div class="rw-hero-banner">
                <!-- Left Element: Single rounded soft-pill indicator (Strictly NO secondary chips/badges) -->
                <div class="rw-hero-status">
                    <span class="rw-status-pill" id="headerStatusBadge" style="<?php echo $current_pill_style; ?>" aria-label="Request Status: <?php echo e($status_display_label); ?>">
                        <?php echo e($status_display_label); ?>
                    </span>
                </div>

                <!-- Right Element: Tracking reference with high-contrast hierarchy -->
                <div class="rw-hero-tracking">
                    <span class="rw-tracking-prefix">Request ID: </span>
                    <span class="rw-tracking-code"><?php echo e($request['reference_number']); ?></span>
                </div>
            </div>

            <!-- B. Request Metadata Grid (4-Column Matrix) -->
            <div class="rw-metadata-body">
                <div class="rw-metadata-grid">
                    <!-- Row 1: Item 1 - Parishioner Name (32px circular avatar + inline name) -->
                    <div class="rw-meta-cell">
                        <span class="rw-meta-label">PARISHIONER NAME</span>
                        <div class="rw-user-inline">
                            <?php echo renderUserAvatar($request, 32, 'rw-meta-avatar'); ?>
                            <span class="rw-meta-name"><?php echo e($request['fullname']); ?></span>
                        </div>
                    </div>

                    <!-- Row 1: Item 2 - Tracking Reference -->
                    <div class="rw-meta-cell">
                        <span class="rw-meta-label">TRACKING REFERENCE</span>
                        <div class="rw-meta-value font-monospace"><?php echo e($request['reference_number']); ?></div>
                    </div>

                    <!-- Row 1: Item 3 - Email Address -->
                    <div class="rw-meta-cell">
                        <span class="rw-meta-label">EMAIL ADDRESS</span>
                        <div class="rw-meta-value text-truncate" title="<?php echo e($request['email']); ?>"><?php echo e($request['email']); ?></div>
                    </div>

                    <!-- Row 1: Item 4 - Contact Number -->
                    <div class="rw-meta-cell">
                        <span class="rw-meta-label">CONTACT NUMBER</span>
                        <div class="rw-meta-value d-flex align-items-center justify-content-between gap-2">
                            <?php if (!empty($request['phone_number'])): ?>
                                <a href="tel:<?php echo e($request['phone_number']); ?>" class="rw-phone-link text-truncate">
                                    <?php echo e($request['phone_number']); ?>
                                </a>
                                <button type="button" class="btn btn-xs btn-outline-primary py-0 px-2 rounded-pill shadow-none" style="font-size: 11px; white-space: nowrap;" onclick="openTestSmsModal('<?php echo e(addslashes($request['phone_number'])); ?>', '<?php echo e(addslashes($request['fullname'])); ?>')" title="Test TextBee SMS to this number">
                                    <i class="bi bi-chat-dots-fill me-1"></i>Test SMS
                                </button>
                            <?php else: ?>
                                <span class="text-muted fw-normal">None provided</span>
                                <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2 rounded-pill shadow-none" style="font-size: 11px; white-space: nowrap;" onclick="openTestSmsModal('', '<?php echo e(addslashes($request['fullname'])); ?>')" title="Send Test SMS">
                                    <i class="bi bi-chat-dots me-1"></i>Test SMS
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Row 2: Item 5 - Service Name -->
                    <div class="rw-meta-cell">
                        <span class="rw-meta-label">SERVICE NAME</span>
                        <div class="rw-meta-value fw-bold"><?php echo e($service_name_display); ?></div>
                    </div>

                    <!-- Row 2: Item 6 - Service Type -->
                    <div class="rw-meta-cell">
                        <span class="rw-meta-label">SERVICE TYPE</span>
                        <div class="rw-meta-value"><?php echo e($service_type_display); ?></div>
                    </div>

                    <!-- Row 2: Item 7 - Category (Standard clean label as plain text - strictly NO badges or pill backgrounds) -->
                    <div class="rw-meta-cell">
                        <span class="rw-meta-label">CATEGORY</span>
                        <div class="rw-meta-value"><?php echo e($category_text_display); ?></div>
                    </div>

                    <!-- Row 2: Item 8 - Date Requested (MMM DD, YYYY over hh:mm A) -->
                    <div class="rw-meta-cell">
                        <span class="rw-meta-label">DATE REQUESTED</span>
                        <div class="rw-meta-value">
                            <div class="rw-date-primary"><?php echo date('M d, Y', strtotime($request['date_requested'])); ?></div>
                            <div class="rw-date-sub"><?php echo date('h:i A', strtotime($request['date_requested'])); ?></div>
                        </div>
                    </div>
                </div>

                <!-- C. Collapsible Application Drawer Toggle Bar -->
                <script>
                function toggleSubmittedApplicationDrawer(e) {
                    if (e) {
                        if (typeof e.preventDefault === 'function') e.preventDefault();
                        if (typeof e.stopPropagation === 'function') e.stopPropagation();
                    }
                    var drawer = document.getElementById('submittedFormCollapse');
                    var btn = document.getElementById('toggleApplicationFormBtn');
                    var text = document.getElementById('toggleText');
                    var chevron = document.getElementById('toggleChevron');
                    if (!drawer) return false;

                    var isCurrentlyOpen = drawer.classList.contains('show') 
                        || drawer.classList.contains('is-open') 
                        || (drawer.style.display === 'block');

                    if (!isCurrentlyOpen) {
                        drawer.classList.remove('collapse');
                        drawer.classList.add('show', 'is-open');
                        drawer.style.setProperty('display', 'block', 'important');
                        drawer.style.setProperty('visibility', 'visible', 'important');
                        if (btn) btn.setAttribute('aria-expanded', 'true');
                        if (text) text.textContent = 'Hide Submitted Application Form';
                        if (chevron) chevron.style.transform = 'rotate(180deg)';
                    } else {
                        drawer.classList.remove('show', 'is-open');
                        drawer.classList.add('collapse');
                        drawer.style.setProperty('display', 'none', 'important');
                        if (btn) btn.setAttribute('aria-expanded', 'false');
                        if (text) text.textContent = 'View Submitted Application Form';
                        if (chevron) chevron.style.transform = 'none';
                    }
                    return false;
                }
                window.toggleSubmittedApplicationDrawer = toggleSubmittedApplicationDrawer;
                </script>
                <div class="rw-app-drawer-toggle-bar">
                    <button class="rw-toggle-drawer-btn" 
                            type="button" 
                            id="toggleApplicationFormBtn" 
                            onclick="toggleSubmittedApplicationDrawer(event)" 
                            aria-expanded="false" 
                            aria-controls="submittedFormCollapse">
                        <span>📄</span>
                        <span id="toggleText">View Submitted Application Form</span>
                        <span class="rw-toggle-chevron" id="toggleChevron">⌵</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- ========================================================= -->
        <!-- 2. COLLAPSIBLE FORMAL BOX: "SUBMITTED APPLICATION FORM"   -->
        <!-- (Hidden/collapsed by default until toggled open)          -->
        <!-- ========================================================= -->
        <div class="collapse mb-4" id="submittedFormCollapse" style="display: none;">
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

                    <?php 
                    $desc_trimmed = trim((string)($request['description'] ?? ''));
                    $has_form_data = ($desc_trimmed !== '') || !empty($linked_reservation);
                    ?>

                    <?php if (!$has_form_data): ?>
                    <div class="alert alert-light border py-4 text-center my-3 rounded-3" style="background: #fafaf8;">
                        <i class="fas fa-file-circle-question text-secondary fs-3 mb-2 d-block"></i>
                        <strong class="text-secondary d-block">No form data</strong>
                        <span class="text-muted small">No structured application form data or details were submitted with this request.</span>
                    </div>
                    <?php else: ?>

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
                            Section 1: Application Overview<?php echo !$is_comm_or_conf ? ' & Schedule' : ''; ?>
                        </div>
                        <div class="row g-3">
                            <?php if (!$is_comm_or_conf): ?>
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
                            <?php else: ?>
                            <div class="col-12 col-sm-6">
                                <span class="micro-label">Assigned Priest / Minister</span>
                                <div class="meta-value">
                                    <?php echo e($formalDetails['schedule']['assigned_priest']); ?>
                                </div>
                            </div>
                            <div class="col-12 col-sm-6">
                                <span class="micro-label">Venue / Location</span>
                                <div class="meta-value">
                                    <?php echo e($formalDetails['schedule']['venue']); ?>
                                </div>
                            </div>
                            <?php endif; ?>
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
                                            <?php if (($reqDoc['status'] ?? '') === 'missing'): ?>
                                                <div class="d-flex align-items-center justify-content-between p-2.5 rounded-3 border small h-100 shadow-none" style="background: #fef2f2; border-color: #fecaca !important;">
                                                    <div class="text-truncate me-2" title="<?php echo e($reqDoc['name']); ?>">
                                                        <i class="fas fa-file-circle-xmark text-danger me-1.5"></i>
                                                        <strong class="text-danger"><?php echo e($reqDoc['name']); ?></strong>
                                                    </div>
                                                    <span class="badge bg-danger text-white px-2 py-1 flex-shrink-0" style="font-size: 0.75rem;">
                                                        <i class="fas fa-circle-exclamation me-1"></i> Missing
                                                    </span>
                                                </div>
                                            <?php else: ?>
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
                                            <?php endif; ?>
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


                    <?php endif; // has_form_data ?>

                    <!-- Formal Document Footer Text -->
                    <div class="formal-doc-footer">
                        Submitted electronically via Parish Portal &bull; Reference: <?php echo e($request['reference_number']); ?> &bull; Date Requested: <?php echo formatDate($request['date_requested']); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========================================================= -->
        <!-- ALWAYS VISIBLE: "UPDATE REQUEST STATUS" CARD              -->
        <!-- ========================================================= -->
        <div class="rw-card mb-4" id="updateRequestStatusCard">
            <div class="rw-section-header">
                <h6 class="rw-section-title">
                    <i class="fas fa-arrows-rotate" style="color: #8c6225; font-size: 13px;"></i>
                    UPDATE REQUEST STATUS
                </h6>
                <span class="badge" id="cardStatusBadge" style="<?php echo $current_pill_style; ?> font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 12px;">
                    Current: <?php echo e($status_display_label); ?>
                </span>
            </div>
            <div class="rw-section-body">
                <form id="workflowStatusUpdateForm" method="POST" onsubmit="event.preventDefault(); handleWorkflowStatusSubmit(event); return false;">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="request_id" value="<?php echo intval($request_id); ?>">

                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label for="new_status_select" class="micro-label">Request Status <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="new_status_select" name="status" required style="height: 36px; font-size: 12px; font-weight: 600;">
                                <?php
                                $status_options = [
                                    'pending'    => 'Pending',
                                    'processing' => 'Processing',
                                    'completed'  => 'Completed',
                                    'rejected'   => 'Rejected'
                                ];
                                $active_status_val = strtolower($request['status'] ?? 'pending');
                                if ($active_status_val === 'ready_for_pickup' || $active_status_val === 'ready') $active_status_val = 'processing';
                                foreach ($status_options as $val => $lbl):
                                ?>
                                    <option value="<?php echo $val; ?>" <?php echo ($active_status_val === $val) ? 'selected' : ''; ?>>
                                        <?php echo $lbl; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="admin_remarks_input" class="micro-label">Admin Remarks (Optional)</label>
                            <input type="text" class="form-control form-control-sm" id="admin_remarks_input" name="admin_response" value="<?php echo e($request['admin_response'] ?? ''); ?>" placeholder="Add remarks, instructions, or pickup notes for parishioner..." style="height: 36px; font-size: 12px;">
                        </div>
                        <div class="col-md-3">
                            <button type="button" id="btnSubmitStatusUpdate" onclick="handleWorkflowStatusSubmit(event)" class="btn btn-sm btn-parish-gold w-100 d-inline-flex align-items-center justify-content-center gap-1.5" style="height: 36px;">
                                <i class="fas fa-check-circle" id="btnStatusUpdateIcon"></i>
                                <span id="btnStatusUpdateText">Update Status</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($is_certificate && isRequestOnlineRelease($request)): ?>
        <!-- ========================================================= -->
        <!-- RELEASE CERTIFICATE (ONLINE) CARD                         -->
        <!-- ========================================================= -->
        <?php
        $has_cert_file = !empty($request['certificate_file_path']) || !empty($documents_by_type['released_certificate']);
        $first_rel_doc = !empty($documents_by_type['released_certificate']) ? $documents_by_type['released_certificate'][0] : null;
        $rel_file_name = !empty($request['certificate_file_name']) 
            ? $request['certificate_file_name'] 
            : ($first_rel_doc['original_name'] ?? basename($request['certificate_file_path'] ?? ''));
        $rel_uploaded_at = !empty($request['certificate_uploaded_at']) 
            ? $request['certificate_uploaded_at'] 
            : ($first_rel_doc['uploaded_at'] ?? $request['updated_at']);
        $rel_file_size = $first_rel_doc ? formatFileSize($first_rel_doc['file_size']) : '';
        $rel_note = $request['certificate_release_note'] ?? '';
        $rel_ext = strtolower(pathinfo((string)$rel_file_name, PATHINFO_EXTENSION));
        $rel_icon = ($rel_ext === 'pdf') ? 'fa-file-pdf text-danger' : 'fa-file-image text-primary';
        $download_url = '../users/download-certificate.php?request_id=' . intval($request_id) . '&download=1';
        $view_url = '../users/download-certificate.php?request_id=' . intval($request_id);
        ?>
        <div class="rw-card mb-4" id="releaseCertificateCard" style="border-left: 4px solid #16a34a;">
            <div class="rw-section-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                <h6 class="rw-section-title" style="color: #166534;">
                    <i class="fas fa-file-circle-check" style="color: #16a34a; font-size: 14px;"></i>
                    RELEASE CERTIFICATE (ONLINE)
                </h6>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge" style="background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 12px;">
                        <i class="fas fa-globe me-1"></i> Online Release
                    </span>
                    <?php if ($has_cert_file): ?>
                        <span class="badge" id="certStatusBadge" style="background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 12px;">
                            <i class="fas fa-check me-1"></i> Certificate Available
                        </span>
                    <?php else: ?>
                        <span class="badge" id="certStatusBadge" style="background: #fef9c3; color: #854d0e; border: 1px solid #fde047; font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 12px;">
                            <i class="fas fa-clock me-1"></i> Awaiting Upload
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="rw-section-body">
                <!-- Released Certificate Details Box (Visible when a file has been uploaded) -->
                <div id="releasedFileSection" style="<?php echo $has_cert_file ? '' : 'display: none;'; ?>" class="mb-3">
                    <div class="p-3 rounded-3" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                            <div class="d-flex align-items-center gap-3 text-truncate me-2" style="max-width: 520px;">
                                <div class="d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 22px;">
                                    <i class="fas <?php echo $rel_icon; ?>" id="releasedFileIcon"></i>
                                </div>
                                <div class="text-truncate">
                                    <span class="fw-bold text-dark d-block text-truncate" id="releasedFileName" style="font-size: 13px;" title="<?php echo e($rel_file_name); ?>">
                                        <?php echo e($rel_file_name); ?>
                                    </span>
                                    <div class="text-muted" style="font-size: 11px;">
                                        <span id="releasedFileMeta">
                                            Uploaded: <?php echo formatDate($rel_uploaded_at); ?>
                                            <?php if ($rel_file_size): ?> &bull; <?php echo e($rel_file_size); ?><?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-1.5 flex-wrap">
                                <a href="<?php echo $view_url; ?>" id="btnViewCert" target="_blank" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1" style="font-size: 11.5px; height: 32px; border-radius: 6px;">
                                    <i class="fas fa-eye"></i> <span>View</span>
                                </a>
                                <a href="<?php echo $download_url; ?>" id="btnDownloadCert" download class="btn btn-sm btn-success d-inline-flex align-items-center gap-1" style="font-size: 11.5px; height: 32px; border-radius: 6px;">
                                    <i class="fas fa-download"></i> <span>Download</span>
                                </a>
                                <button type="button" id="btnToggleReplace" onclick="toggleReplaceCertificateZone()" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1" style="font-size: 11.5px; height: 32px; border-radius: 6px;">
                                    <i class="fas fa-arrows-rotate"></i> <span>Replace</span>
                                </button>
                                <button type="button" id="btnRemoveCert" onclick="handleRemoveCertificate(<?php echo $request_id; ?>)" class="btn btn-sm btn-outline-danger d-inline-flex align-items-center gap-1" style="font-size: 11.5px; height: 32px; border-radius: 6px;">
                                    <i class="fas fa-trash-alt"></i> <span>Remove</span>
                                </button>
                            </div>
                        </div>
                        <?php if ($rel_note): ?>
                            <div class="mt-2 pt-2 border-top text-secondary" id="releasedNoteWrap" style="font-size: 11.5px;">
                                <i class="fas fa-comment-dots text-primary me-1"></i> Note to Parishioner: <span class="fst-italic" id="releasedNoteText"><?php echo e($rel_note); ?></span>
                            </div>
                        <?php else: ?>
                            <div class="mt-2 pt-2 border-top text-secondary" id="releasedNoteWrap" style="font-size: 11.5px; display: none;">
                                <i class="fas fa-comment-dots text-primary me-1"></i> Note to Parishioner: <span class="fst-italic" id="releasedNoteText"></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Dropzone / Upload Form Area (Visible when no file, or when clicking Replace) -->
                <div id="certUploadDropzoneWrap" style="<?php echo $has_cert_file ? 'display: none;' : ''; ?>">
                    <form id="certUploadForm" method="POST" enctype="multipart/form-data" action="request-workflow.php?id=<?php echo intval($request_id); ?>" onsubmit="event.preventDefault(); handleUploadCertificateSubmit(event); return false;">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="action" value="upload_certificate_release">
                        <input type="hidden" name="request_id" value="<?php echo intval($request_id); ?>">

                        <!-- Drag and drop zone -->
                        <div class="cert-dropzone-box text-center p-4 rounded-3" id="certDropzone" onclick="document.getElementById('cert_file_input').click();">
                            <input type="file" id="cert_file_input" name="certificate_file" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" style="display: none;" onchange="handleCertFileSelect(this.files)">
                            <div class="mb-2">
                                <i class="fas fa-cloud-arrow-up" style="font-size: 32px; color: #8c6225;"></i>
                            </div>
                            <div class="fw-semibold text-dark" style="font-size: 13px;">
                                Drag and drop finished certificate here, or <span class="text-decoration-underline" style="color: #8c6225; cursor: pointer;">browse files</span>
                            </div>
                            <div class="text-muted mt-1" style="font-size: 11px;">
                                Accepted formats: PDF, JPG, PNG, WEBP &bull; Max 10 MB
                            </div>
                        </div>

                        <!-- Selected file preview -->
                        <div id="certFilePreview" class="alert alert-light border mt-2 py-2 px-3 align-items-center justify-content-between" style="display: none; border-color: #cbd5e1 !important;">
                            <div class="d-flex align-items-center gap-2 text-truncate me-2">
                                <i class="fas fa-file-lines text-primary" id="previewIcon" style="font-size: 18px;"></i>
                                <div class="text-truncate">
                                    <span class="fw-semibold text-dark d-block text-truncate" id="previewFileName" style="font-size: 12px;"></span>
                                    <span class="text-muted" id="previewFileSize" style="font-size: 10.5px;"></span>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-link text-danger p-0 text-decoration-none fw-semibold" style="font-size: 11.5px;" onclick="clearSelectedCertFile()">
                                <i class="fas fa-times me-0.5"></i> Clear
                            </button>
                        </div>
                        <div id="certFileError" class="text-danger small mt-1" style="display: none; font-size: 11px;"></div>

                        <!-- Optional Note to Parishioner & Set Completed -->
                        <div class="row g-2 mt-2 align-items-center">
                            <div class="col-12 col-md-8">
                                <label for="cert_note_input" class="micro-label">Optional Note to Parishioner</label>
                                <input type="text" class="form-control form-control-sm" id="cert_note_input" name="release_note" placeholder="e.g. Your official certificate is ready for download." value="<?php echo e($rel_note ?: 'Your certificate is ready for download.'); ?>" style="height: 36px; font-size: 12px;">
                            </div>
                            <div class="col-12 col-md-4">
                                <div class="pt-md-3">
                                    <div class="form-check m-0">
                                        <input class="form-check-input" type="checkbox" id="cert_mark_completed" name="mark_completed" value="1" <?php echo ($request['status'] === 'completed') ? '' : 'checked'; ?> style="width: 14px; height: 14px;">
                                        <label class="form-check-label" for="cert_mark_completed" style="font-size: 11.5px; color: #334155; cursor: pointer;">
                                            Set status to <strong>Completed</strong>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Submit Button Area -->
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-3 pt-2 border-top">
                            <div>
                                <?php if ($has_cert_file): ?>
                                    <button type="button" class="btn btn-sm btn-link text-secondary p-0 text-decoration-none" style="font-size: 11.5px;" onclick="toggleReplaceCertificateZone(false)">
                                        <i class="fas fa-arrow-left me-1"></i> Cancel Replace
                                    </button>
                                <?php endif; ?>
                            </div>
                            <button type="button" id="btnUploadCert" onclick="handleUploadCertificateSubmit(event)" class="btn btn-sm btn-success fw-semibold d-inline-flex align-items-center gap-1.5" style="height: 36px; font-size: 12px; padding: 0 16px; border-radius: 6px;">
                                <i class="fas fa-cloud-arrow-up" id="uploadCertIcon"></i>
                                <span id="uploadCertText"><?php echo $has_cert_file ? 'Upload & Replace Certificate' : 'Upload Certificate'; ?></span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

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



        <?php if (!$is_certificate): ?>
        <!-- 3. BOTTOM ACTION SECTION: "REVIEW STATUS & UPDATES" (For Sacramental & Service Requests) -->
        <div class="rw-review-card">
            <div class="rw-review-card-header">
                <h6 class="rw-review-card-title">
                    <span>📋</span> REVIEW STATUS &amp; UPDATES
                </h6>
            </div>

            <div class="rw-review-card-body">
                <form method="POST" id="reviewStatusForm">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="request_id" value="<?php echo intval($request_id); ?>">

                    <!-- 1. Input Grid (Side-by-Side Split: 40% / 60%) -->
                    <div class="rw-review-split-grid">
                        <!-- Current Status (Left Column, 40% width) -->
                        <div class="rw-review-col-status">
                            <label for="status" class="rw-meta-label">CURRENT STATUS</label>
                            <select class="form-select rw-control-select" id="status" name="status" required>
                                <option value="pending" <?php echo (strtolower($request['status'] ?? '') === 'pending') ? 'selected' : ''; ?>>Pending</option>
                                <option value="processing" <?php echo (strtolower($request['status'] ?? '') === 'processing') ? 'selected' : ''; ?>>Processing</option>
                                <option value="completed" <?php echo (strtolower($request['status'] ?? '') === 'completed') ? 'selected' : ''; ?>>Completed</option>
                                <option value="rejected" <?php echo (strtolower($request['status'] ?? '') === 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                            <div class="rw-field-caption">
                                <span>ⓘ</span> Changing this updates parishioner tracking status.
                            </div>
                        </div>

                        <!-- Admin Response / Remarks (Right Column, 60% width) -->
                        <div class="rw-review-col-remarks">
                            <label for="admin_response" class="rw-meta-label">ADMIN RESPONSE / REMARKS</label>
                            <textarea class="form-control rw-control-textarea" 
                                      id="admin_response" 
                                      name="admin_response" 
                                      rows="2" 
                                      placeholder="Add comments or instructions, or pickup/event reminders sent back to parishioner..."><?php echo e($request['admin_response'] ?? ''); ?></textarea>
                            <div class="rw-field-caption">
                                <span>🔔</span> Sent in parishioner email &amp; portal notification.
                            </div>
                        </div>
                    </div>

                    <?php if ($is_sacramental): 
                        $priest_roster = getParishPriestRoster($conn);
                        $default_parish_priest = getParishPriestName($conn);
                        $is_communion = in_array($raw_req_type, ['first_communion_service', 'first_communion', 'communion'], true);
                        $is_confirmation = in_array($raw_req_type, ['confirmation_service', 'confirmation'], true);
                    ?>
                        <?php if ($is_comm_or_conf): ?>
                            <div class="p-3 rounded-3 border mb-3" id="commConfCeremonyCard" style="background: #fafaf8;">
                                <div class="fw-bold text-dark small border-bottom pb-2 mb-3">
                                    <i class="fas fa-calendar-check text-primary me-1"></i> CEREMONY SCHEDULE &amp; MINISTER (REQUIRED FOR COMPLETION)
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="ceremony_date" class="rw-meta-label">CEREMONY DATE <span class="text-danger">*</span></label>
                                        <input type="date" 
                                               class="form-control border-secondary-subtle py-1.5 fw-semibold" 
                                               id="ceremony_date" 
                                               name="ceremony_date" 
                                               value="<?php echo htmlspecialchars($_POST['ceremony_date'] ?? $existing_ceremony_date ?? ''); ?>" 
                                               style="font-size: 0.88rem; height: 38px; border-radius: 8px;">
                                        <div class="rw-field-caption">Required ceremony date for official sacramental registry record.</div>
                                        <div class="invalid-feedback d-none text-danger small mt-1" id="ceremonyDateFeedback">
                                            <i class="fas fa-circle-exclamation me-1"></i>Please provide the Ceremony Date before completing this request.
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="ceremony_time" class="rw-meta-label">CEREMONY TIME <span class="text-danger">*</span></label>
                                        <select class="form-select border-secondary-subtle py-1.5 fw-semibold" id="ceremony_time" name="ceremony_time" style="font-size: 0.88rem; height: 38px; border-radius: 8px;">
                                            <option value="">-- Select Hourly Ceremony Time --</option>
                                            <?php
                                            $hourly_slots = [
                                                '06:00' => '06:00 AM', '07:00' => '07:00 AM', '08:00' => '08:00 AM',
                                                '09:00' => '09:00 AM', '10:00' => '10:00 AM', '11:00' => '11:00 AM',
                                                '12:00' => '12:00 PM', '13:00' => '01:00 PM', '14:00' => '02:00 PM',
                                                '15:00' => '03:00 PM', '16:00' => '04:00 PM', '17:00' => '05:00 PM',
                                                '18:00' => '06:00 PM', '19:00' => '07:00 PM'
                                            ];
                                            $curr_time = !empty($_POST['ceremony_time']) ? trim((string)$_POST['ceremony_time']) : (!empty($existing_ceremony_time) ? date('H:i', strtotime($existing_ceremony_time)) : '');
                                            foreach ($hourly_slots as $slot_val => $slot_lbl):
                                            ?>
                                                <option value="<?php echo $slot_val; ?>" <?php echo ($curr_time === $slot_val) ? 'selected' : ''; ?>>
                                                    <?php echo $slot_lbl; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="rw-field-caption">Required hourly time slot for the ceremony.</div>
                                        <div class="invalid-feedback d-none text-danger small mt-1" id="ceremonyTimeFeedback">
                                            <i class="fas fa-circle-exclamation me-1"></i>Please select the Ceremony Time before completing this request.
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="<?php echo $is_communion ? 'ceremony_minister_select' : 'ceremony_minister'; ?>" class="rw-meta-label">
                                            <?php echo $is_communion ? 'MINISTER' : 'OFFICIATING MINISTER / BISHOP'; ?> <span class="text-danger">*</span>
                                        </label>
                                        <?php if ($is_communion): ?>
                                            <?php
                                            $comm_opts = ['Rev. Fr. Alberto G. Cahilig, OMI', 'Rev. Fr. Alvin Vicente C. Barretto, OMI'];
                                            $cur_min = $_POST['ceremony_minister'] ?? $existing_ceremony_minister ?? '';
                                            $is_other = !empty($cur_min) && !in_array($cur_min, $comm_opts, true);
                                            ?>
                                            <select class="form-select border-secondary-subtle py-1.5 fw-semibold" id="ceremony_minister_select" name="ceremony_minister" style="font-size: 0.88rem; height: 38px; border-radius: 8px;">
                                                <option value="">-- Select Minister --</option>
                                                <?php foreach ($comm_opts as $c_opt): ?>
                                                    <option value="<?php echo htmlspecialchars($c_opt); ?>" <?php echo ($cur_min === $c_opt) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($c_opt); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                                <option value="Other" <?php echo ($is_other || $cur_min === 'Other') ? 'selected' : ''; ?>>Other</option>
                                            </select>
                                            <div id="ceremonyMinisterOtherWrap" class="mt-2 <?php echo ($is_other || $cur_min === 'Other') ? '' : 'd-none'; ?>">
                                                <input type="text" class="form-control border-secondary-subtle py-1.5 fw-semibold" id="ceremony_minister_other" name="ceremony_minister_other" value="<?php echo htmlspecialchars($is_other ? $cur_min : ($_POST['ceremony_minister_other'] ?? '')); ?>" placeholder="Enter Minister's Full Name" style="font-size: 0.88rem; height: 38px; border-radius: 8px;">
                                            </div>
                                        <?php else: ?>
                                            <input type="text" class="form-control border-secondary-subtle py-1.5 fw-semibold" id="ceremony_minister" name="ceremony_minister" value="<?php echo htmlspecialchars($_POST['ceremony_minister'] ?? $existing_ceremony_minister ?? 'Bp. Angelito R. Lampon, O.M.I., D.D.'); ?>" placeholder="Bp. Angelito R. Lampon, O.M.I., D.D." style="font-size: 0.88rem; height: 38px; border-radius: 8px;">
                                        <?php endif; ?>
                                        <div class="rw-field-caption">Required officiating minister for official registry.</div>
                                        <div class="invalid-feedback d-none text-danger small mt-1" id="ceremonyMinisterFeedback">
                                            <i class="fas fa-circle-exclamation me-1"></i>Please provide the Minister before completing this request.
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="workflow_parish_priest" class="rw-meta-label">PARISH PRIEST <span class="text-danger">*</span></label>
                                        <select class="form-select border-secondary-subtle py-1.5 fw-semibold" id="workflow_parish_priest" name="parish_priest" style="font-size: 0.88rem; height: 38px; border-radius: 8px;">
                                            <option value="">-- Select Parish Priest --</option>
                                            <?php foreach ($priest_roster as $p_opt): ?>
                                                <option value="<?php echo htmlspecialchars($p_opt); ?>" <?php echo ($p_opt === $default_parish_priest) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($p_opt); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="rw-field-caption">Confirmed canonical Parish Priest for official registry.</div>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="row g-3 mt-1 mb-2">
                                <div class="col-md-6">
                                    <label for="workflow_minister" class="rw-meta-label">MINISTER / OFFICIATING PRIEST <span class="text-danger">*</span></label>
                                    <select class="form-select border-secondary-subtle py-1.5 fw-semibold" id="workflow_minister" name="officiating_priest" style="font-size: 0.88rem; height: 38px; border-radius: 8px;">
                                        <option value="">-- Select Minister / Officiating Priest --</option>
                                        <?php foreach ($priest_roster as $p_opt): ?>
                                            <option value="<?php echo htmlspecialchars($p_opt); ?>" <?php echo (!empty($funeral_fields['minister']) && strcasecmp($funeral_fields['minister'], $p_opt) === 0) ? 'selected' : ''; ?>>
                                                 <?php echo htmlspecialchars($p_opt); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="rw-field-caption">Required when marking sacramental request completed.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="workflow_parish_priest" class="rw-meta-label">PARISH PRIEST <span class="text-danger">*</span></label>
                                    <select class="form-select border-secondary-subtle py-1.5 fw-semibold" id="workflow_parish_priest" name="parish_priest" style="font-size: 0.88rem; height: 38px; border-radius: 8px;">
                                        <option value="">-- Select Parish Priest --</option>
                                        <?php foreach ($priest_roster as $p_opt): ?>
                                            <option value="<?php echo htmlspecialchars($p_opt); ?>" <?php echo ($p_opt === $default_parish_priest) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($p_opt); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="rw-field-caption">Confirmed canonical Parish Priest for official registry.</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($is_funeral): ?>
                        <div class="mt-3 mb-2">
                            <div class="p-3 rounded-3 border" style="background: #fafaf8;">
                                <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-2 flex-wrap gap-2">
                                    <div class="fw-bold text-dark small">
                                        <i class="fas fa-cross text-secondary me-1"></i> FUNERAL INVESTIGATION SHEET &amp; PARISH RECORD DETAILS
                                    </div>
                                    <?php if ($linked_funeral_record): ?>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1" style="font-size: 11px;">
                                                <i class="fas fa-check-circle me-1"></i> Linked Record: <?php echo htmlspecialchars($linked_funeral_record['registry_no'] ?: ('#' . $linked_funeral_record['funeral_id'])); ?>
                                            </span>
                                            <a href="funeral-records.php?search=<?php echo urlencode($linked_funeral_record['deceased_name']); ?>" target="_blank" class="btn btn-sm btn-outline-success py-0 px-2" style="font-size: 11px;">
                                                View in Funeral Records <i class="fas fa-arrow-up-right-from-square ms-1"></i>
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1" style="font-size: 11px;">
                                            <i class="fas fa-info-circle me-1"></i> Auto-creates Funeral Record on Completion
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-muted mb-3" style="font-size: 11px;">
                                    Review and adjust the information below. Once this request is marked <strong>Completed</strong>, these exact fields are stored in the official <strong>Parish Records &gt; Funeral Records</strong> table. Any edits made after completion will update the official record.
                                </p>

                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label for="wf_deceased_name" class="rw-meta-label">Deceased Full Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control border-secondary-subtle py-1" id="wf_deceased_name" name="funeral_sheet[deceased_name]" value="<?php echo htmlspecialchars($funeral_fields['deceased_name'] ?? ''); ?>" style="font-size: 11.5px; height: 32px; border-radius: 6px;" placeholder="Deceased name">
                                    </div>
                                    <div class="col-md-3">
                                        <label for="wf_date_of_death" class="rw-meta-label">Date of Death <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control border-secondary-subtle py-1" id="wf_date_of_death" name="funeral_sheet[date_of_death]" value="<?php echo htmlspecialchars($funeral_fields['date_of_death'] ?? ''); ?>" style="font-size: 11.5px; height: 32px; border-radius: 6px;">
                                    </div>
                                    <div class="col-md-3">
                                        <label for="wf_date_of_burial" class="rw-meta-label">Date of Burial / Mass <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control border-secondary-subtle py-1" id="wf_date_of_burial" name="funeral_sheet[date_of_burial]" value="<?php echo htmlspecialchars($funeral_fields['date_of_burial'] ?? ''); ?>" style="font-size: 11.5px; height: 32px; border-radius: 6px;">
                                    </div>
                                    <div class="col-md-3">
                                        <label for="wf_civil_status" class="rw-meta-label">Civil Status <span class="text-danger">*</span></label>
                                        <select class="form-select border-secondary-subtle py-1 fw-semibold" id="wf_civil_status" name="funeral_sheet[civil_status]" style="font-size: 11.5px; height: 32px; border-radius: 6px;">
                                            <option value="">— Select —</option>
                                            <?php foreach (['Single', 'Married', 'Widowed', 'Separated', 'Annulled'] as $cs): ?>
                                                <option value="<?php echo $cs; ?>" <?php echo (strcasecmp($funeral_fields['civil_status'] ?? '', $cs) === 0) ? 'selected' : ''; ?>>
                                                    <?php echo $cs; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="wf_funeral_rites" class="rw-meta-label">Funeral Rites <span class="text-danger">*</span></label>
                                        <select class="form-select border-secondary-subtle py-1 fw-semibold" id="wf_funeral_rites" name="funeral_sheet[funeral_rites]" style="font-size: 11.5px; height: 32px; border-radius: 6px;">
                                            <option value="">— Select —</option>
                                            <?php foreach (['Full Catholic Rites', 'Simple Blessing', 'Graveside Service', 'Memorial Mass', 'Cremation Blessing', 'Other'] as $fr): ?>
                                                <option value="<?php echo $fr; ?>" <?php echo (strcasecmp($funeral_fields['funeral_rites'] ?? '', $fr) === 0) ? 'selected' : ''; ?>>
                                                    <?php echo $fr; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="wf_cause_of_death" class="rw-meta-label">Cause of Death</label>
                                        <input type="text" class="form-control border-secondary-subtle py-1" id="wf_cause_of_death" name="funeral_sheet[cause_of_death]" value="<?php echo htmlspecialchars($funeral_fields['cause_of_death'] ?? ''); ?>" style="font-size: 11.5px; height: 32px; border-radius: 6px;" placeholder="e.g. Natural causes">
                                    </div>
                                    <div class="col-md-3">
                                        <label for="wf_place_of_burial" class="rw-meta-label">Place of Burial <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control border-secondary-subtle py-1" id="wf_place_of_burial" name="funeral_sheet[place_of_burial]" value="<?php echo htmlspecialchars($funeral_fields['place_of_burial'] ?? ''); ?>" style="font-size: 11.5px; height: 32px; border-radius: 6px;" placeholder="e.g. Cemetery name">
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- 2. Action Toolbar & Button Hierarchy -->
                    <div class="rw-action-toolbar">
                        <a href="manage-requests.php" class="rw-back-btn">
                            <span>←</span> Back to Requests
                        </a>

                        <button type="submit" class="rw-update-btn">
                            <i class="fas fa-check-circle"></i> Update Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php else: ?>
        <div class="rw-action-toolbar mt-3 mb-4">
            <a href="manage-requests.php" class="rw-back-btn">
                <span>←</span> Back to Requests
            </a>
        </div>
        <?php endif; ?>

    </div>
</div>

<script>
// Global status badge style mappings
window.workflowStatusBadgeStyles = {
    'pending': 'background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;',
    'processing': 'background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd;',
    'completed': 'background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0;',
    'rejected': 'background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;'
};

function showStatusToast(message, type) {
    if (window.ParishNotify && typeof window.ParishNotify.show === 'function') {
        window.ParishNotify.show({
            type: type === 'success' ? 'success' : 'error',
            message: message,
            title: type === 'success' ? 'Status Updated' : 'Update Failed'
        });
        return;
    }

    let container = document.getElementById('parishToastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'parishToastContainer';
        container.className = 'parish-toast-container';
        container.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 99999;';
        document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    toast.className = 'alert alert-' + (type === 'success' ? 'success' : 'danger') + ' shadow-sm d-flex align-items-center gap-2 mb-2';
    toast.style.cssText = 'min-width: 280px; max-width: 420px; z-index: 99999; animation: fadeIn 0.2s ease-in;';
    toast.innerHTML = '<i class="fas ' + (type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle') + '"></i><span>' + message + '</span>';
    container.appendChild(toast);
    setTimeout(function() {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s ease';
        setTimeout(function() { toast.remove(); }, 300);
    }, 4000);
}
window.showStatusToast = showStatusToast;

async function handleWorkflowStatusSubmit(e) {
    if (e) {
        if (typeof e.preventDefault === 'function') e.preventDefault();
        if (typeof e.stopPropagation === 'function') e.stopPropagation();
    }
    const statusForm = document.getElementById('workflowStatusUpdateForm');
    if (!statusForm) return false;

    const btnSubmitStatus = document.getElementById('btnSubmitStatusUpdate');
    const btnIcon = document.getElementById('btnStatusUpdateIcon');
    const btnText = document.getElementById('btnStatusUpdateText');
    const headerBadge = document.getElementById('headerStatusBadge');
    const cardBadge = document.getElementById('cardStatusBadge');

    const submitBtn = btnSubmitStatus || statusForm.querySelector('button[type="submit"]') || statusForm.querySelector('button');
    const originalIconClass = btnIcon ? btnIcon.className : '';
    const originalText = btnText ? btnText.textContent : 'Update Status';

    if (submitBtn) submitBtn.disabled = true;
    if (btnIcon) btnIcon.className = 'fas fa-spinner fa-spin';
    if (btnText) btnText.textContent = 'Updating...';

    const formData = new FormData(statusForm);
    formData.append('ajax', '1');

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });

        const data = await response.json().catch(function() { return null; });
        if (!response.ok || !data || !data.success) {
            const errorMsg = (data && data.message) ? data.message : 'Failed to update request status. Please try again.';
            throw new Error(errorMsg);
        }

        if (submitBtn) submitBtn.disabled = false;
        if (btnIcon) btnIcon.className = originalIconClass || 'fas fa-check-circle';
        if (btnText) btnText.textContent = originalText;

        showStatusToast(data.message || 'Request status updated successfully!', 'success');

        const rawNewStatus = (data.status || '').toLowerCase();
        const newLabel = data.status_label || (rawNewStatus.charAt(0).toUpperCase() + rawNewStatus.slice(1));
        const newStyle = window.workflowStatusBadgeStyles[rawNewStatus] || window.workflowStatusBadgeStyles['pending'];

        if (headerBadge) {
            headerBadge.textContent = newLabel;
            headerBadge.setAttribute('style', newStyle);
            headerBadge.setAttribute('aria-label', 'Request Status: ' + newLabel);
        }
        if (cardBadge) {
            cardBadge.textContent = 'Current: ' + newLabel;
            cardBadge.setAttribute('style', newStyle + ' font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 12px;');
        }

        if (rawNewStatus === 'completed') {
            setTimeout(function() {
                window.location.reload();
            }, 1200);
        }
    } catch (err) {
        if (submitBtn) submitBtn.disabled = false;
        if (btnIcon) btnIcon.className = originalIconClass || 'fas fa-check-circle';
        if (btnText) btnText.textContent = originalText;
        showStatusToast(err.message, 'error');
    }
    return false;
}
window.handleWorkflowStatusSubmit = handleWorkflowStatusSubmit;

document.addEventListener('DOMContentLoaded', function () {

    const reviewStatusForm = document.getElementById('reviewStatusForm');
    const statusSelect = document.getElementById('status');
    const ceremonyDateInput = document.getElementById('ceremony_date');
    const ceremonyDateFeedback = document.getElementById('ceremonyDateFeedback');
    const ceremonyTimeSelect = document.getElementById('ceremony_time');
    const ceremonyTimeFeedback = document.getElementById('ceremonyTimeFeedback');
    const ceremonyMinisterSelect = document.getElementById('ceremony_minister_select');
    const ceremonyMinisterOtherWrap = document.getElementById('ceremonyMinisterOtherWrap');
    const ceremonyMinisterOtherInput = document.getElementById('ceremony_minister_other');
    const ceremonyMinisterTextInput = document.getElementById('ceremony_minister');
    const ceremonyMinisterFeedback = document.getElementById('ceremonyMinisterFeedback');

    if (ceremonyMinisterSelect && ceremonyMinisterOtherWrap) {
        ceremonyMinisterSelect.addEventListener('change', function () {
            if (this.value === 'Other') {
                ceremonyMinisterOtherWrap.classList.remove('d-none');
                if (ceremonyMinisterOtherInput) ceremonyMinisterOtherInput.focus();
            } else {
                ceremonyMinisterOtherWrap.classList.add('d-none');
            }
        });
    }

    if (reviewStatusForm) {
        reviewStatusForm.addEventListener('submit', function (e) {
            if (statusSelect && statusSelect.value === 'completed') {
                let hasError = false;
                let firstErrorEl = null;

                if (ceremonyDateInput && !ceremonyDateInput.value.trim()) {
                    hasError = true;
                    ceremonyDateInput.classList.add('is-invalid');
                    if (ceremonyDateFeedback) {
                        ceremonyDateFeedback.classList.remove('d-none');
                        ceremonyDateFeedback.style.display = 'block';
                    }
                    if (!firstErrorEl) firstErrorEl = ceremonyDateInput;
                }

                if (ceremonyTimeSelect && !ceremonyTimeSelect.value.trim()) {
                    hasError = true;
                    ceremonyTimeSelect.classList.add('is-invalid');
                    if (ceremonyTimeFeedback) {
                        ceremonyTimeFeedback.classList.remove('d-none');
                        ceremonyTimeFeedback.style.display = 'block';
                    }
                    if (!firstErrorEl) firstErrorEl = ceremonyTimeSelect;
                }

                if (ceremonyMinisterSelect) {
                    const selVal = ceremonyMinisterSelect.value.trim();
                    const otherVal = ceremonyMinisterOtherInput ? ceremonyMinisterOtherInput.value.trim() : '';
                    if (!selVal || (selVal === 'Other' && !otherVal)) {
                        hasError = true;
                        ceremonyMinisterSelect.classList.add('is-invalid');
                        if (selVal === 'Other' && ceremonyMinisterOtherInput) {
                            ceremonyMinisterOtherInput.classList.add('is-invalid');
                        }
                        if (ceremonyMinisterFeedback) {
                            ceremonyMinisterFeedback.classList.remove('d-none');
                            ceremonyMinisterFeedback.style.display = 'block';
                        }
                        if (!firstErrorEl) firstErrorEl = (selVal === 'Other' && ceremonyMinisterOtherInput) ? ceremonyMinisterOtherInput : ceremonyMinisterSelect;
                    }
                } else if (ceremonyMinisterTextInput && !ceremonyMinisterTextInput.value.trim()) {
                    hasError = true;
                    ceremonyMinisterTextInput.classList.add('is-invalid');
                    if (ceremonyMinisterFeedback) {
                        ceremonyMinisterFeedback.classList.remove('d-none');
                        ceremonyMinisterFeedback.style.display = 'block';
                    }
                    if (!firstErrorEl) firstErrorEl = ceremonyMinisterTextInput;
                }

                if (hasError) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (firstErrorEl) {
                        firstErrorEl.focus();
                        firstErrorEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                    return false;
                }
            }
        });

        if (ceremonyDateInput) {
            ceremonyDateInput.addEventListener('input', function () {
                if (ceremonyDateInput.value.trim()) {
                    ceremonyDateInput.classList.remove('is-invalid');
                    if (ceremonyDateFeedback) {
                        ceremonyDateFeedback.classList.add('d-none');
                        ceremonyDateFeedback.style.display = 'none';
                    }
                }
            });
        }

        if (ceremonyTimeSelect) {
            ceremonyTimeSelect.addEventListener('change', function () {
                if (ceremonyTimeSelect.value.trim()) {
                    ceremonyTimeSelect.classList.remove('is-invalid');
                    if (ceremonyTimeFeedback) {
                        ceremonyTimeFeedback.classList.add('d-none');
                        ceremonyTimeFeedback.style.display = 'none';
                    }
                }
            });
        }

        if (ceremonyMinisterSelect) {
            ceremonyMinisterSelect.addEventListener('change', function () {
                if (ceremonyMinisterSelect.value.trim() && ceremonyMinisterSelect.value !== 'Other') {
                    ceremonyMinisterSelect.classList.remove('is-invalid');
                    if (ceremonyMinisterFeedback) {
                        ceremonyMinisterFeedback.classList.add('d-none');
                        ceremonyMinisterFeedback.style.display = 'none';
                    }
                }
            });
        }

        if (ceremonyMinisterOtherInput) {
            ceremonyMinisterOtherInput.addEventListener('input', function () {
                if (ceremonyMinisterOtherInput.value.trim()) {
                    ceremonyMinisterOtherInput.classList.remove('is-invalid');
                    if (ceremonyMinisterSelect) ceremonyMinisterSelect.classList.remove('is-invalid');
                    if (ceremonyMinisterFeedback) {
                        ceremonyMinisterFeedback.classList.add('d-none');
                        ceremonyMinisterFeedback.style.display = 'none';
                    }
                }
            });
        }

        if (ceremonyMinisterTextInput) {
            ceremonyMinisterTextInput.addEventListener('input', function () {
                if (ceremonyMinisterTextInput.value.trim()) {
                    ceremonyMinisterTextInput.classList.remove('is-invalid');
                    if (ceremonyMinisterFeedback) {
                        ceremonyMinisterFeedback.classList.add('d-none');
                        ceremonyMinisterFeedback.style.display = 'none';
                    }
                }
            });
        }
    }

    // Initialize Certificate Release Dropzone
    const dropzone = document.getElementById('certDropzone');
    if (dropzone) {
        ['dragenter', 'dragover'].forEach(function(eventName) {
            dropzone.addEventListener(eventName, function(e) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('dragover');
            }, false);
        });
        ['dragleave', 'drop'].forEach(function(eventName) {
            dropzone.addEventListener(eventName, function(e) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('dragover');
            }, false);
        });
        dropzone.addEventListener('drop', function(e) {
            const dt = e.dataTransfer;
            if (dt && dt.files && dt.files.length) {
                const fileInput = document.getElementById('cert_file_input');
                if (fileInput) {
                    fileInput.files = dt.files;
                    handleCertFileSelect(dt.files);
                }
            }
        }, false);
    }
});

// Release Certificate (Online) Operations
function handleCertFileSelect(files) {
    if (!files || !files.length) return;
    const file = files[0];
    const previewWrap = document.getElementById('certFilePreview');
    const previewName = document.getElementById('previewFileName');
    const previewSize = document.getElementById('previewFileSize');
    const previewIcon = document.getElementById('previewIcon');
    const errorBox = document.getElementById('certFileError');
    const uploadBtn = document.getElementById('btnUploadCert');

    const maxBytes = 10 * 1024 * 1024; // 10 MB
    const ext = file.name.split('.').pop().toLowerCase();
    const allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    if (!allowedExts.includes(ext)) {
        if (errorBox) {
            errorBox.textContent = 'Invalid file format. Accepted formats: PDF, JPG, PNG, WEBP.';
            errorBox.style.display = 'block';
        }
        if (uploadBtn) uploadBtn.disabled = true;
        if (previewWrap) previewWrap.style.display = 'none';
        return;
    }

    if (file.size > maxBytes) {
        const sizeMb = (file.size / (1024 * 1024)).toFixed(2);
        if (errorBox) {
            errorBox.textContent = 'File size exceeds 10 MB limit (' + sizeMb + ' MB). Please choose a smaller file.';
            errorBox.style.display = 'block';
        }
        if (uploadBtn) uploadBtn.disabled = true;
        if (previewWrap) previewWrap.style.display = 'none';
        return;
    }

    if (errorBox) {
        errorBox.textContent = '';
        errorBox.style.display = 'none';
    }
    if (uploadBtn) uploadBtn.disabled = false;

    if (previewName) previewName.textContent = file.name;
    if (previewSize) previewSize.textContent = (file.size < 1048576) ? (Math.round(file.size / 1024) + ' KB') : ((file.size / (1024 * 1024)).toFixed(2) + ' MB');
    if (previewIcon) {
        previewIcon.className = (ext === 'pdf') ? 'fas fa-file-pdf text-danger' : 'fas fa-file-image text-primary';
    }
    if (previewWrap) previewWrap.style.display = 'flex';
}
window.handleCertFileSelect = handleCertFileSelect;

function clearSelectedCertFile() {
    const input = document.getElementById('cert_file_input');
    if (input) input.value = '';
    const previewWrap = document.getElementById('certFilePreview');
    if (previewWrap) previewWrap.style.display = 'none';
    const errorBox = document.getElementById('certFileError');
    if (errorBox) {
        errorBox.textContent = '';
        errorBox.style.display = 'none';
    }
    const uploadBtn = document.getElementById('btnUploadCert');
    if (uploadBtn) uploadBtn.disabled = false;
}
window.clearSelectedCertFile = clearSelectedCertFile;

function toggleReplaceCertificateZone(show) {
    const dropzoneWrap = document.getElementById('certUploadDropzoneWrap');
    const releasedSection = document.getElementById('releasedFileSection');
    if (!dropzoneWrap || !releasedSection) return;

    if (typeof show === 'boolean') {
        dropzoneWrap.style.display = show ? 'block' : 'none';
        releasedSection.style.display = show ? 'none' : 'block';
    } else {
        const isCurrentlyHidden = (dropzoneWrap.style.display === 'none' || getComputedStyle(dropzoneWrap).display === 'none');
        dropzoneWrap.style.display = isCurrentlyHidden ? 'block' : 'none';
        releasedSection.style.display = isCurrentlyHidden ? 'none' : 'block';
    }
}
window.toggleReplaceCertificateZone = toggleReplaceCertificateZone;

async function handleUploadCertificateSubmit(e) {
    if (e) {
        if (typeof e.preventDefault === 'function') e.preventDefault();
        if (typeof e.stopPropagation === 'function') e.stopPropagation();
    }
    const form = document.getElementById('certUploadForm');
    if (!form) return false;

    const fileInput = document.getElementById('cert_file_input');
    if (!fileInput || !fileInput.files || !fileInput.files.length) {
        showStatusToast('Please select or drop a certificate file to upload.', 'error');
        return false;
    }

    const file = fileInput.files[0];
    if (file.size > 10 * 1024 * 1024) {
        showStatusToast('File size exceeds the 10 MB limit.', 'error');
        return false;
    }

    const btn = document.getElementById('btnUploadCert');
    const icon = document.getElementById('uploadCertIcon');
    const text = document.getElementById('uploadCertText');
    const originalText = text ? text.textContent : 'Upload Certificate';
    const originalIconClass = icon ? icon.className : 'fas fa-cloud-arrow-up';

    if (btn) btn.disabled = true;
    if (icon) icon.className = 'fas fa-spinner fa-spin';
    if (text) text.textContent = 'Uploading Certificate...';

    const formData = new FormData(form);
    formData.append('ajax', '1');

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });

        const data = await response.json().catch(function() { return null; });
        if (!response.ok || !data || !data.success) {
            const errorMsg = (data && data.message) ? data.message : 'Upload failed. Please check the file and try again.';
            throw new Error(errorMsg);
        }

        showStatusToast(data.message || 'Certificate uploaded successfully!', 'success');

        // Update Released File Section DOM
        const releasedSection = document.getElementById('releasedFileSection');
        const dropzoneWrap = document.getElementById('certUploadDropzoneWrap');
        const fileNameEl = document.getElementById('releasedFileName');
        const fileMetaEl = document.getElementById('releasedFileMeta');
        const fileIconEl = document.getElementById('releasedFileIcon');
        const noteWrapEl = document.getElementById('releasedNoteWrap');
        const noteTextEl = document.getElementById('releasedNoteText');
        const certBadgeEl = document.getElementById('certStatusBadge');
        const btnView = document.getElementById('btnViewCert');
        const btnDownload = document.getElementById('btnDownloadCert');

        if (fileNameEl) {
            fileNameEl.textContent = data.file_name;
            fileNameEl.setAttribute('title', data.file_name);
        }
        if (fileMetaEl) {
            fileMetaEl.innerHTML = 'Uploaded: ' + (data.uploaded_at || 'Just now') + (data.file_size ? (' &bull; ' + data.file_size) : '');
        }
        if (fileIconEl) {
            const ext = (data.file_name || '').split('.').pop().toLowerCase();
            fileIconEl.className = 'fas ' + (ext === 'pdf' ? 'fa-file-pdf text-danger' : 'fa-file-image text-primary');
        }
        if (noteWrapEl && noteTextEl) {
            if (data.release_note && data.release_note.trim()) {
                noteTextEl.textContent = data.release_note;
                noteWrapEl.style.display = 'block';
            } else {
                noteWrapEl.style.display = 'none';
            }
        }
        if (btnView && data.view_url) btnView.href = data.view_url;
        if (btnDownload && data.download_url) btnDownload.href = data.download_url;

        if (certBadgeEl) {
            certBadgeEl.className = 'badge';
            certBadgeEl.style.cssText = 'background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 12px;';
            certBadgeEl.innerHTML = '<i class="fas fa-check me-1"></i> Certificate Available';
        }

        // If status changed to completed, update top badges & select
        if (data.status === 'completed') {
            const headerBadge = document.getElementById('headerStatusBadge');
            const cardBadge = document.getElementById('cardStatusBadge');
            const statusSelect = document.getElementById('new_status_select');
            const completedStyle = (window.workflowStatusBadgeStyles && window.workflowStatusBadgeStyles['completed']) || 'background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0;';

            if (headerBadge) {
                headerBadge.textContent = 'Completed';
                headerBadge.setAttribute('style', completedStyle);
                headerBadge.setAttribute('aria-label', 'Request Status: Completed');
            }
            if (cardBadge) {
                cardBadge.textContent = 'Current: Completed';
                cardBadge.setAttribute('style', completedStyle + ' font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 12px;');
            }
            if (statusSelect) {
                statusSelect.value = 'completed';
            }
        }

        // Toggle sections
        if (releasedSection) releasedSection.style.display = 'block';
        if (dropzoneWrap) dropzoneWrap.style.display = 'none';

        // Clear input & preview
        clearSelectedCertFile();

        if (btn) btn.disabled = false;
        if (icon) icon.className = originalIconClass;
        if (text) text.textContent = 'Upload & Replace Certificate';
    } catch (err) {
        if (btn) btn.disabled = false;
        if (icon) icon.className = originalIconClass;
        if (text) text.textContent = originalText;
        showStatusToast(err.message, 'error');
    }
    return false;
}
window.handleUploadCertificateSubmit = handleUploadCertificateSubmit;

async function handleRemoveCertificate(requestId) {
    if (!confirm('Are you sure you want to remove this certificate file? The parishioner will no longer be able to download it online.')) {
        return;
    }

    const btn = document.getElementById('btnRemoveCert');
    const originalContent = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Removing...';
    }

    const formData = new FormData();
    formData.append('action', 'remove_certificate_release');
    formData.append('request_id', requestId);
    const csrfTokenEl = document.querySelector('input[name="csrf_token"]');
    if (csrfTokenEl) formData.append('csrf_token', csrfTokenEl.value);
    formData.append('ajax', '1');

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });

        const data = await response.json().catch(function() { return null; });
        if (!response.ok || !data || !data.success) {
            throw new Error((data && data.message) ? data.message : 'Failed to remove certificate.');
        }

        showStatusToast(data.message || 'Certificate file removed successfully.', 'success');

        const releasedSection = document.getElementById('releasedFileSection');
        const dropzoneWrap = document.getElementById('certUploadDropzoneWrap');
        const certBadgeEl = document.getElementById('certStatusBadge');
        const text = document.getElementById('uploadCertText');

        if (releasedSection) releasedSection.style.display = 'none';
        if (dropzoneWrap) dropzoneWrap.style.display = 'block';
        if (text) text.textContent = 'Upload Certificate';

        if (certBadgeEl) {
            certBadgeEl.className = 'badge';
            certBadgeEl.style.cssText = 'background: #fef9c3; color: #854d0e; border: 1px solid #fde047; font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 12px;';
            certBadgeEl.innerHTML = '<i class="fas fa-clock me-1"></i> Awaiting Upload';
        }

        clearSelectedCertFile();
    } catch (err) {
        showStatusToast(err.message, 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalContent;
        }
    }
}
window.handleRemoveCertificate = handleRemoveCertificate;

// Test SMS Modal Functions
function openTestSmsModal(phone, name) {
    const modalEl = document.getElementById('testSmsModal');
    if (!modalEl) return;
    
    const phoneInput = document.getElementById('testSmsPhone');
    const msgInput = document.getElementById('testSmsMessage');
    const resultBox = document.getElementById('testSmsResult');
    
    if (phoneInput) phoneInput.value = phone || '';
    if (msgInput) {
        const refNum = '<?php echo addslashes($request['reference_number'] ?? ''); ?>';
        msgInput.value = `TUGON Parish System: Hello ${name || 'Parishioner'}, this is a test notification regarding your request (${refNum}).`;
    }
    if (resultBox) {
        resultBox.style.display = 'none';
        resultBox.innerHTML = '';
    }
    
    const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
    bsModal.show();
}
window.openTestSmsModal = openTestSmsModal;

async function submitTestSms() {
    const phoneInput = document.getElementById('testSmsPhone');
    const msgInput = document.getElementById('testSmsMessage');
    const sendBtn = document.getElementById('btnSendTestSms');
    const resultBox = document.getElementById('testSmsResult');
    
    const phone = phoneInput ? phoneInput.value.trim() : '';
    const message = msgInput ? msgInput.value.trim() : '';
    
    if (!phone) {
        alert('Please enter a valid mobile number (e.g. 09171234567 or +639171234567).');
        return;
    }
    if (!message) {
        alert('Please enter a message.');
        return;
    }
    
    const originalBtn = sendBtn ? sendBtn.innerHTML : '';
    if (sendBtn) {
        sendBtn.disabled = true;
        sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Sending via TextBee...';
    }
    if (resultBox) {
        resultBox.style.display = 'none';
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'send_test_sms');
        formData.append('request_id', '<?php echo (int)$request_id; ?>');
        formData.append('phone_number', phone);
        formData.append('message', message);
        const csrfTokenEl = document.querySelector('input[name="csrf_token"]');
        if (csrfTokenEl) formData.append('csrf_token', csrfTokenEl.value);
        
        const response = await fetch('api_send_test_sms.php', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: formData
        });
        
        const data = await response.json().catch(() => null);
        
        if (resultBox) {
            resultBox.style.display = 'block';
            if (response.ok && data && data.success) {
                const batch = data.data && data.data.batch_id ? `<br><small class="text-muted">Batch ID: <code>${data.data.batch_id}</code></small>` : '';
                const normPhone = data.data && data.data.formatted_phone ? data.data.formatted_phone : phone;
                resultBox.className = 'alert alert-success mt-3 mb-0 py-2 px-3 small';
                resultBox.innerHTML = `<strong><i class="bi bi-check-circle-fill me-1"></i> SMS Dispatched!</strong><br>Queued to <strong>${normPhone}</strong> via TextBee Android gateway.${batch}`;
                showStatusToast('SMS successfully sent to gateway!', 'success');
            } else {
                const errMsg = (data && data.message) ? data.message : 'Gateway request failed.';
                resultBox.className = 'alert alert-danger mt-3 mb-0 py-2 px-3 small';
                resultBox.innerHTML = `<strong><i class="bi bi-exclamation-triangle-fill me-1"></i> SMS Delivery Error:</strong><br>${errMsg}`;
                showStatusToast(errMsg, 'error');
            }
        }
    } catch (err) {
        if (resultBox) {
            resultBox.style.display = 'block';
            resultBox.className = 'alert alert-danger mt-3 mb-0 py-2 px-3 small';
            resultBox.innerHTML = `<strong><i class="bi bi-exclamation-triangle-fill me-1"></i> Network/API Error:</strong><br>${err.message}`;
        }
        showStatusToast(err.message, 'error');
    } finally {
        if (sendBtn) {
            sendBtn.disabled = false;
            sendBtn.innerHTML = originalBtn;
        }
    }
}
window.submitTestSms = submitTestSms;
</script>

<!-- Test SMS Modal -->
<div class="modal fade" id="testSmsModal" tabindex="-1" aria-labelledby="testSmsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; overflow: hidden;">
            <div class="modal-header bg-primary text-white py-3 px-4">
                <h5 class="modal-title fs-6 fw-bold" id="testSmsModalLabel">
                    <i class="bi bi-chat-left-dots-fill me-2"></i>Send Test SMS via TextBee
                </h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label for="testSmsPhone" class="form-label small fw-semibold text-secondary mb-1">Recipient Mobile Number</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-muted"><i class="bi bi-phone"></i></span>
                        <input type="text" class="form-control" id="testSmsPhone" placeholder="09XXXXXXXXX or +639XXXXXXXXX" autocomplete="off">
                    </div>
                    <div class="form-text small">Accepts local (09...) or international (+639...) formats. E.164 normalized before sending.</div>
                </div>
                <div class="mb-3">
                    <label for="testSmsMessage" class="form-label small fw-semibold text-secondary mb-1">Message Content</label>
                    <textarea class="form-control font-monospace" id="testSmsMessage" rows="4" style="font-size: 13px;" placeholder="Type your SMS notification text here..."></textarea>
                </div>
                <div id="testSmsResult" style="display: none;"></div>
            </div>
            <div class="modal-footer bg-light px-4 py-3 border-top-0 d-flex justify-content-between">
                <button type="button" class="btn btn-sm btn-outline-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary px-4 fw-semibold" id="btnSendTestSms" onclick="submitTestSms()">
                    <i class="bi bi-send-fill me-1"></i> Send SMS
                </button>
            </div>
        </div>
    </div>
</div>

<?php include '../templates/document-preview-modal.php'; ?>


<?php include '../templates/footer.php'; ?>
