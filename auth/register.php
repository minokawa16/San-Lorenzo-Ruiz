<?php
/**
 * User Registration Page
 * AI-Powered Parish Request and Sacramental Records Management System
 * Handles user registration with proper password hashing and validation
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include '../config/security.php';
include '../database/config.php';
include '../includes/helpers.php';

ensureUserVerificationSchema($conn);
ensureEmailNotificationSchema($conn);

if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        header("Location: ../admin/dashboard.php", true, 302);
    } else {
        header("Location: ../users/dashboard.php", true, 302);
    }
    exit();
}

$error = '';
$success = '';
$form_data = [
    'first_name' => '',
    'surname' => '',
    'middle_initial' => '',
    'phone_number' => '',
    'email' => '',
    'verification_method' => 'email',
    'chapel_district' => '',
    'address' => '',
    'birthdate' => '',
    'birth_place' => '',
    'id_number' => ''
];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requireValidCsrfToken();
    $first_name = isset($_POST['first_name']) ? sanitize($_POST['first_name']) : '';
    $surname = isset($_POST['surname']) ? sanitize($_POST['surname']) : '';
    $middle_initial = isset($_POST['middle_initial']) ? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', sanitize($_POST['middle_initial'])), 0, 1)) : '';
    $fullname = trim($first_name . ' ' . ($middle_initial !== '' ? $middle_initial . '. ' : '') . $surname);
    $phone_number = isset($_POST['phone_number']) ? sanitize($_POST['phone_number']) : '';
    $email = isset($_POST['email']) ? sanitize($_POST['email']) : '';
    $verification_method = $_POST['verification_method'] ?? 'email';
    if (!in_array($verification_method, ['email', 'mobile'], true)) {
        $verification_method = 'email';
    }
    $chapel_district = isset($_POST['chapel_district']) ? sanitize($_POST['chapel_district']) : '';
    $address = isset($_POST['address']) ? sanitize($_POST['address']) : '';
    $birthdate_input = isset($_POST['birthdate']) ? sanitize($_POST['birthdate']) : '';
    $birthdate_timestamp = $birthdate_input !== '' ? strtotime($birthdate_input) : false;
    $birthdate = $birthdate_timestamp ? date('Y-m-d', $birthdate_timestamp) : $birthdate_input;
    $birthdate_display = $birthdate_timestamp ? date('F d, Y', $birthdate_timestamp) : $birthdate_input;
    $birth_place = isset($_POST['birth_place']) ? sanitize($_POST['birth_place']) : '';
    $id_number = isset($_POST['id_number']) ? sanitize($_POST['id_number']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

    $form_data = [
        'first_name' => $first_name,
        'surname' => $surname,
        'middle_initial' => $middle_initial,
        'phone_number' => $phone_number,
        'email' => $email,
        'verification_method' => $verification_method,
        'chapel_district' => $chapel_district,
        'address' => $address,
        'birthdate' => $birthdate_display,
        'birth_place' => $birth_place,
        'id_number' => $id_number
    ];

    if (empty($first_name) || empty($surname) || empty($phone_number) || empty($email) || empty($chapel_district) || empty($address) || empty($birthdate_input) || empty($birth_place) || empty($password) || empty($confirm_password)) {
        $error = 'Please complete all required fields.';
    } elseif (!isValidEmail($email)) {
        $error = 'Please enter a valid email address.';
    } elseif (!preg_match('/@gmail\.com$/i', $email)) {
        $error = 'Please use a Gmail address ending in @gmail.com.';
    } elseif (!isValidPhilippineMobile($phone_number)) {
        $error = 'Invalid mobile number. Please enter a valid 11-digit Philippine mobile number.';
    } elseif (!$birthdate_timestamp || $birthdate_timestamp > strtotime('-13 years')) {
        $error = 'Please enter a valid birthdate in Month DD, YYYY format. Registrants must be at least 13 years old.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (empty($_POST['face_capture']) || empty($_POST['valid_id_capture']) || empty($_POST['valid_id_back_capture'])) {
        $error = 'Live face verification and front/back ID verification must be completed before registration.';
    } elseif (($_POST['face_match_status'] ?? '') !== 'matched') {
        $error = 'Face verification must be successfully matched before registration can proceed.';
    } else {
        $email_safe = $conn->real_escape_string($email);
        $sql = "SELECT id FROM users WHERE email = '$email_safe' LIMIT 1";
        $result = $conn->query($sql);

        if ($result && $result->num_rows > 0) {
            $error = 'This Gmail address is already registered.';
        } else {
            $face_capture = decodeCameraCapture($_POST['face_capture'], 10 * 1024 * 1024);
            $id_capture = decodeCameraCapture($_POST['valid_id_capture'], 10 * 1024 * 1024);
            $id_back_capture = decodeCameraCapture($_POST['valid_id_back_capture'], 10 * 1024 * 1024);

            if (!$face_capture['ok']) {
                $error = $face_capture['error'];
            } elseif (!$id_capture['ok']) {
                $error = $id_capture['error'];
            } elseif (!$id_back_capture['ok']) {
                $error = $id_back_capture['error'];
            } else {
                $id_upload_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'valid_ids';
                $face_upload_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'live_faces';
                $ocr_front_tmp = tempnam(sys_get_temp_dir(), 'tugon_id_front_');
                $ocr_back_tmp = tempnam(sys_get_temp_dir(), 'tugon_id_back_');
                file_put_contents($ocr_front_tmp, $id_capture['binary']);
                file_put_contents($ocr_back_tmp, $id_back_capture['binary']);

                $ocr_front_result = runValidIdOcr($ocr_front_tmp);
                $ocr_back_result = runValidIdOcr($ocr_back_tmp);
                $ocr_result = combineOcrResults([$ocr_front_result, $ocr_back_result]);
                $extracted_data = extractValidIdData($ocr_result['text']);
                $identity_match = compareIdentityData([
                    'fullname' => $fullname,
                    'first_name' => $first_name,
                    'surname' => $surname,
                    'middle_initial' => $middle_initial,
                    'birthdate' => $birthdate,
                    'birth_place' => $birth_place,
                    'address' => $address,
                    'id_number' => $extracted_data['id_number'] ?? ''
                ], $extracted_data, $ocr_result['text']);

                if ($ocr_result['available'] && trim($ocr_result['text']) === '') {
                    $identity_match['status'] = 'unreadable';
                } elseif (!$ocr_result['available']) {
                    $identity_match['status'] = 'ocr_unavailable';
                }

                $id_number = trim((string) ($extracted_data['id_number'] ?? ''));
                $required_ocr_fields = ['birthdate', 'birth_place', 'address', 'id_number'];
                $missing_ocr_fields = array_filter($required_ocr_fields, function ($field) use ($extracted_data) {
                    return trim((string) ($extracted_data[$field] ?? '')) === '';
                });
                if (trim((string) ($extracted_data['full_name'] ?? '')) === '' && (trim((string) ($extracted_data['first_name'] ?? '')) === '' || trim((string) ($extracted_data['surname'] ?? '')) === '')) {
                    $missing_ocr_fields[] = 'name';
                }
                $clear_mismatches = [];
                foreach (['name', 'birthdate', 'birth_place', 'address'] as $check_key) {
                    $field_key = $check_key === 'name' ? 'full_name' : $check_key;
                    $field_value = $check_key === 'name'
                        ? trim((string) (($extracted_data['full_name'] ?? '') ?: trim(($extracted_data['first_name'] ?? '') . ' ' . ($extracted_data['surname'] ?? ''))))
                        : trim((string) ($extracted_data[$field_key] ?? ''));
                    if ($field_value !== '' && empty($identity_match['checks'][$check_key])) {
                        $clear_mismatches[] = $check_key;
                    }
                }

                if ($id_number === '') {
                    @unlink($ocr_front_tmp);
                    @unlink($ocr_back_tmp);
                    $error = 'OCR could not read the ID number. Please retake the ID photo with better lighting and make sure the whole ID is visible.';
                } elseif (!empty($clear_mismatches)) {
                    @unlink($ocr_front_tmp);
                    @unlink($ocr_back_tmp);
                    $error = 'OCR identity verification failed because the scanned ID details do not match the registration form.';
                } else {
                    if (!empty($missing_ocr_fields) || intval($identity_match['score']) < 70) {
                        $identity_match['status'] = 'needs_review';
                    }
                    $id_number_hash = hashIdentityNumber($id_number);
                    $id_number_hash_safe = $conn->real_escape_string($id_number_hash);
                    $duplicate_id_result = $conn->query("SELECT id FROM users WHERE id_number_hash = '$id_number_hash_safe' LIMIT 1");
                    if ($duplicate_id_result && $duplicate_id_result->num_rows > 0) {
                        @unlink($ocr_front_tmp);
                        @unlink($ocr_back_tmp);
                        $error = 'This ID has already been registered in the system.';
                    }
                }

                if (!$error) {
                $id_saved = saveEncryptedCameraCapture($id_capture, $id_upload_dir, 'live-valid-id-front');
                $id_back_saved = saveEncryptedCameraCapture($id_back_capture, $id_upload_dir, 'live-valid-id-back');
                $face_saved = saveEncryptedCameraCapture($face_capture, $face_upload_dir, 'live-face');
                @unlink($ocr_front_tmp);
                @unlink($ocr_back_tmp);

                    if (!$id_saved || !$id_back_saved || !$face_saved) {
                        if ($id_saved && is_file($id_saved['path'])) {
                            unlink($id_saved['path']);
                        }
                        if ($id_back_saved && is_file($id_back_saved['path'])) {
                            unlink($id_back_saved['path']);
                        }
                        if ($face_saved && is_file($face_saved['path'])) {
                            unlink($face_saved['path']);
                        }
                        $error = 'Unable to save the live verification captures. Please try again.';
                    } else {
                        $db_path = 'uploads/valid_ids/' . $id_saved['filename'];
                        $back_db_path = 'uploads/valid_ids/' . $id_back_saved['filename'];
                        $face_db_path = 'uploads/live_faces/' . $face_saved['filename'];
                        $mime_type = $id_capture['mime_type'];
                        $face_mime_type = $face_capture['mime_type'];
                        $original_name = 'front-back-id-verification.' . $id_capture['extension'];
                    $hashed_password = hashPassword($password);
                    $id_number_encrypted = encryptSensitiveValue($id_number);
                    $ocr_text_encrypted = encryptSensitiveValue($ocr_result['text']);
                    $ocr_data_encrypted = encryptSensitiveValue(json_encode([
                        'extracted' => $extracted_data,
                        'checks' => $identity_match['checks'],
                        'confidence' => getOcrFieldConfidence($extracted_data, $identity_match['checks']),
                        'ocr_error' => $ocr_result['error'],
                        'back_id_path' => $back_db_path
                    ]));
                    $ocr_score = intval($identity_match['score']);
                    $ocr_status = $identity_match['status'];
                    $allowed_face_statuses = ['matched', 'mismatch', 'admin_review', 'pending'];
                    $face_status = $_POST['face_match_status'] ?? 'admin_review';
                    if (!in_array($face_status, $allowed_face_statuses, true) || $face_status === 'pending') {
                        $face_status = 'admin_review';
                    }

                    $stmt = $conn->prepare("INSERT INTO users (fullname, first_name, surname, middle_initial, phone_number, email, verification_method, chapel_district, address, birthdate, birth_place, id_number_hash, id_number_encrypted, password, role, status, valid_id_path, valid_id_original_name, valid_id_mime_type, valid_id_capture_method, face_image_path, face_image_mime_type, face_verification_status, face_verified_at, ocr_extracted_text_encrypted, ocr_extracted_data_encrypted, ocr_match_score, ocr_status, ocr_processed_at)
                                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'user', 'pending_verification', ?, ?, ?, 'live_camera', ?, ?, ?, NOW(), ?, ?, ?, ?, NOW())");

                    if ($stmt) {
                        $stmt->bind_param(
                            'ssssssssssssssssssssssis',
                            $fullname,
                            $first_name,
                            $surname,
                            $middle_initial,
                            $phone_number,
                            $email,
                            $verification_method,
                            $chapel_district,
                            $address,
                            $birthdate,
                            $birth_place,
                            $id_number_hash,
                            $id_number_encrypted,
                            $hashed_password,
                            $db_path,
                            $original_name,
                            $mime_type,
                            $face_db_path,
                            $face_mime_type,
                            $face_status,
                            $ocr_text_encrypted,
                            $ocr_data_encrypted,
                            $ocr_score,
                            $ocr_status
                        );
                    }

                    if ($stmt && $stmt->execute()) {
                        $success = $ocr_status === 'matched'
                            ? 'Your ID details were scanned successfully. Your registration is now under parish administrator review.'
                            : 'Your registration is under review. The ID scan needs administrator verification before approval.';
                        $form_data = [
                            'first_name' => '',
                            'surname' => '',
                            'middle_initial' => '',
                            'phone_number' => '',
                            'email' => '',
                            'verification_method' => 'email',
                            'chapel_district' => '',
                            'address' => '',
                            'birthdate' => '',
                            'birth_place' => '',
                            'id_number' => ''
                        ];

                        $new_user_id = $conn->insert_id;
                        if ($verification_method === 'mobile') {
                            sendOtpSms($conn, $new_user_id, $phone_number, 'registration');
                            $otp_contact = $phone_number;
                        } else {
                            sendEmailVerificationMessage($conn, $new_user_id, $email, $fullname);
                            sendOtpEmail($conn, $new_user_id, $email, 'registration');
                            $otp_contact = $email;
                        }
                        createAuditLog($conn, $new_user_id, 'REGISTRATION_OCR_PENDING_VERIFICATION', 'users', $new_user_id, null, [
                            'ocr_status' => $ocr_status,
                            'ocr_match_score' => $ocr_score,
                            'verification_method' => $verification_method
                        ]);
                        header('Location: verify-otp.php?purpose=registration&method=' . urlencode($verification_method) . '&user_id=' . urlencode($new_user_id) . '&contact=' . urlencode($otp_contact));
                        exit;
                    } else {
                        if (is_file($id_saved['path'])) {
                            unlink($id_saved['path']);
                        }
                        if (is_file($id_back_saved['path'])) {
                            unlink($id_back_saved['path']);
                        }
                        if (is_file($face_saved['path'])) {
                            unlink($face_saved['path']);
                        }
                        $error = 'Registration failed. Please try again later.';
                    }
                    if ($stmt) {
                        $stmt->close();
                    }
                }
            }
        }
    }
}
}

$chapel_options = [
    'District 1',
    'District 2',
    'District 3'
];

$logo_file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'san-lorenzo-logo.png';
$has_logo = is_file($logo_file);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Register | San Lorenzo Ruiz Mission Station</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/premium-parish.css">
    <style>
        :root {
            --register-navy: #0b1f3a;
            --register-navy-soft: #14365d;
            --register-gold: #d4af37;
            --register-gold-soft: #f4dc82;
            --register-gray: #eef3f7;
            --register-muted: #5d6d7f;
            --register-danger: #b42318;
            --register-success: #0f7a4f;
        }

        body.register-page {
            min-height: 100vh;
            margin: 0;
            color: #fff8eb;
            background: #14100d;
            overflow-x: hidden;
        }

        body.register-page::before {
            content: "";
            position: fixed;
            inset: 0;
            z-index: -3;
            background-image:
                linear-gradient(135deg, rgba(0, 0, 0, 0.18), rgba(11, 31, 58, 0.12)),
                url("../church%20image.png");
            background-size: cover;
            background-position: center center;
            background-repeat: no-repeat;
            filter: sepia(0.22) saturate(1.34) contrast(1.08) brightness(0.88);
            transform: none;
        }

        body.register-page::after {
            content: "";
            position: fixed;
            inset: 0;
            z-index: -2;
            background:
                radial-gradient(circle at 39% 30%, rgba(255, 214, 126, 0.26), transparent 25%),
                radial-gradient(circle at 50% 70%, rgba(255, 184, 64, 0.18), transparent 24%),
                linear-gradient(90deg, rgba(15, 10, 6, 0.62) 0%, rgba(15, 10, 6, 0.24) 44%, rgba(8, 11, 18, 0.78) 100%),
                linear-gradient(180deg, rgba(0, 0, 0, 0.22), rgba(0, 0, 0, 0.62));
        }

        .register-shell {
            position: relative;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            width: min(1180px, calc(100% - 32px));
            margin: 0 auto;
            padding: clamp(18px, 4vw, 42px) 0;
            overflow: hidden;
        }

        .register-shell::before {
            content: "";
            position: absolute;
            inset: 0;
            z-index: 0;
            background-image:
                radial-gradient(circle, rgba(255, 255, 255, 0.2) 1px, transparent 1.8px),
                radial-gradient(circle, rgba(212, 175, 55, 0.18) 1px, transparent 2px);
            background-position: 0 0, 38px 52px;
            background-size: 92px 92px, 126px 126px;
            opacity: 0.45;
            animation: particleDrift 18s linear infinite;
            pointer-events: none;
        }

        .register-shell::after {
            content: "\f684  \f654  \f2cd";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            inset: auto 4vw 4vh auto;
            z-index: 0;
            color: rgba(255, 255, 255, 0.08);
            font-size: clamp(2.4rem, 8vw, 6rem);
            letter-spacing: 24px;
            pointer-events: none;
        }

        .register-card {
            position: relative;
            z-index: 1;
            width: min(100%, 760px);
            max-height: calc(100vh - 42px);
            overflow-y: auto;
            background:
                linear-gradient(155deg, rgba(255, 248, 235, 0.22), rgba(255, 248, 235, 0.08)),
                rgba(20, 16, 13, 0.72);
            border: 1px solid rgba(255, 248, 235, 0.26);
            border-radius: 8px;
            box-shadow:
                0 34px 90px rgba(0, 0, 0, 0.5),
                0 0 56px rgba(216, 165, 58, 0.18),
                inset 0 1px 0 rgba(255, 255, 255, 0.16);
            padding: clamp(24px, 3vw, 34px);
            backdrop-filter: blur(28px) saturate(145%);
            -webkit-backdrop-filter: blur(28px) saturate(145%);
            animation: fadeUp 0.78s cubic-bezier(0.2, 0.8, 0.2, 1) both;
        }

        .register-card::before {
            content: "";
            position: absolute;
            inset: 12px;
            z-index: -1;
            border-radius: 8px;
            background:
                radial-gradient(circle at top left, rgba(246, 217, 139, 0.14), transparent 32%),
                linear-gradient(135deg, rgba(255, 248, 235, 0.08), transparent);
        }

        .register-card-header {
            text-align: center;
            margin-bottom: 22px;
        }

        .register-card-icon {
            width: 82px;
            height: 82px;
            margin: 0 auto 14px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--register-gold-soft), var(--register-gold));
            color: var(--register-navy);
            font-size: 2rem;
            box-shadow: 0 18px 40px rgba(212, 175, 55, 0.28);
            text-decoration: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .register-card-icon:hover {
            color: #0b1f3a;
            transform: translateY(-2px);
            box-shadow: 0 22px 46px rgba(212, 175, 55, 0.36);
        }

        .register-card h2 {
            margin: 0;
            font-weight: 850;
            color: #fff8eb;
            letter-spacing: 0;
            font-size: clamp(1.9rem, 4vw, 2.45rem);
        }

        .register-card-header p {
            color: rgba(255, 248, 235, 0.78);
            margin: 8px 0 0;
            line-height: 1.55;
        }

        .community-quote {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0 0 20px;
            padding: 12px 14px;
            border-radius: 18px;
            color: rgba(255, 248, 235, 0.86);
            background: rgba(255, 248, 235, 0.1);
            border: 1px solid rgba(246, 217, 139, 0.2);
            font-size: 0.9rem;
            line-height: 1.45;
        }

        .community-quote i {
            color: var(--register-gold);
        }

        .registration-form {
            display: grid;
            gap: 14px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .field-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .field-group.full {
            grid-column: 1 / -1;
        }

        .field-label {
            color: #fff8eb;
            font-weight: 800;
            font-size: 0.9rem;
        }

        .input-wrap {
            position: relative;
        }

        .input-wrap .field-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(246, 217, 139, 0.82);
            pointer-events: none;
            width: 18px;
            text-align: center;
        }

        .register-card .form-control,
        .register-card .form-select {
            width: 100%;
            min-height: 50px;
            border-radius: 8px;
            border: 1px solid rgba(255, 248, 235, 0.24);
            padding: 12px 16px 12px 46px;
            background: rgba(255, 248, 235, 0.13);
            color: #fff8eb;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease, background 0.2s ease;
        }

        .register-card .form-select {
            padding-right: 42px;
        }

        .register-card #chapel_district {
            background-color: #ffffff;
            color: #000000 !important;
            -webkit-text-fill-color: #000000;
        }

        .register-card .form-select option {
            background: #ffffff !important;
            color: #000000 !important;
            -webkit-text-fill-color: #000000;
        }

        .register-card .form-select option:checked {
            background: #d4af37;
            color: #000000 !important;
            font-weight: 700;
        }

        .register-card .form-control:focus,
        .register-card .form-select:focus {
            border-color: var(--register-gold);
            background: rgba(255, 248, 235, 0.16);
            color: #fff8eb;
            box-shadow:
                0 0 0 4px rgba(212, 175, 55, 0.2),
                0 12px 26px rgba(11, 31, 58, 0.12);
            transform: translateY(-1px);
        }

        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            width: 38px;
            height: 38px;
            border: none;
            border-radius: 8px;
            background: transparent;
            color: rgba(255, 248, 235, 0.68);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .password-toggle:hover {
            background: rgba(255, 248, 235, 0.12);
            color: #f6d98b;
        }

        .input-wrap.password .form-control {
            padding-right: 54px;
        }

        .field-message {
            min-height: 18px;
            color: var(--register-danger);
            font-size: 0.82rem;
            font-weight: 700;
        }

        .form-hint {
            color: rgba(255, 248, 235, 0.62);
            font-size: 0.82rem;
            margin-top: -2px;
        }

        .verification-options {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .verification-option {
            display: flex;
            align-items: center;
            gap: 10px;
            min-height: 54px;
            padding: 12px 14px;
            border: 1px solid rgba(255, 248, 235, 0.24);
            border-radius: 8px;
            background: rgba(255, 248, 235, 0.12);
            color: #fff8eb;
            cursor: pointer;
        }

        .verification-option input {
            width: 18px;
            height: 18px;
            accent-color: var(--register-gold);
        }

        .verification-option span {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            font-weight: 800;
        }

        .verification-notice {
            display: flex;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 18px;
            color: rgba(255, 248, 235, 0.86);
            background: rgba(255, 248, 235, 0.1);
            border: 1px solid rgba(246, 217, 139, 0.2);
            font-size: 0.88rem;
            line-height: 1.45;
        }

        .verification-notice i {
            color: var(--register-gold);
            margin-top: 2px;
        }

        .id-upload-box {
            position: relative;
            display: grid;
            place-items: center;
            min-height: 170px;
            padding: 18px;
            border: 2px dashed rgba(11, 31, 58, 0.28);
            border-radius: 8px;
            background: rgba(255, 248, 235, 0.1);
            cursor: pointer;
            text-align: center;
            transition: border-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
            overflow: hidden;
        }

        .id-upload-box:hover,
        .id-upload-box.is-dragover {
            border-color: var(--register-gold);
            background: rgba(255, 248, 235, 0.16);
            transform: translateY(-1px);
            box-shadow: 0 14px 30px rgba(11, 31, 58, 0.12);
        }

        .id-upload-box input {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
        }

        .id-upload-content {
            display: grid;
            justify-items: center;
            gap: 8px;
            color: rgba(255, 248, 235, 0.72);
        }

        .id-upload-content i {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #14100d;
            background: linear-gradient(135deg, var(--register-gold-soft), var(--register-gold));
            font-size: 1.25rem;
        }

        .id-upload-content strong {
            color: #fff8eb;
        }

        .id-preview {
            display: none;
            width: 100%;
            gap: 12px;
            align-items: center;
            text-align: left;
        }

        .id-preview img {
            width: 92px;
            height: 72px;
            object-fit: cover;
            border-radius: 14px;
            border: 1px solid rgba(11, 31, 58, 0.12);
        }

        .id-upload-box.has-preview .id-upload-content {
            display: none;
        }

        .id-upload-box.has-preview .id-preview {
            display: flex;
        }

        .live-verification {
            display: grid;
            gap: 12px;
            padding: 14px;
            border-radius: 8px;
            border: 1px solid rgba(255, 248, 235, 0.22);
            background: rgba(255, 248, 235, 0.1);
        }

        .camera-stage {
            position: relative;
            aspect-ratio: 16 / 9;
            min-height: 240px;
            overflow: hidden;
            border-radius: 8px;
            background: #111827;
            border: 1px solid rgba(255, 248, 235, 0.2);
        }

        .camera-stage video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: none;
        }

        .camera-stage.is-active video {
            display: block;
        }

        .camera-placeholder {
            position: absolute;
            inset: 0;
            display: grid;
            place-items: center;
            align-content: center;
            gap: 8px;
            padding: 20px;
            color: rgba(255, 248, 235, 0.8);
            text-align: center;
            background: linear-gradient(135deg, rgba(20, 16, 13, 0.88), rgba(11, 31, 58, 0.8));
        }

        .camera-placeholder i {
            color: var(--register-gold);
            font-size: 2rem;
        }

        .camera-stage.is-active .camera-placeholder {
            display: none;
        }

        .face-guide {
            position: absolute;
            left: 50%;
            top: 50%;
            width: min(42%, 220px);
            aspect-ratio: 0.76;
            transform: translate(-50%, -50%);
            border: 3px solid rgba(246, 217, 139, 0.92);
            border-radius: 50%;
            box-shadow: 0 0 0 999px rgba(0, 0, 0, 0.24);
            display: none;
            pointer-events: none;
        }

        .camera-stage.is-face-mode .face-guide {
            display: block;
        }

        .id-guide {
            position: absolute;
            left: 50%;
            top: 50%;
            width: min(86%, 560px);
            aspect-ratio: 1.58;
            transform: translate(-50%, -50%);
            border: 3px solid rgba(246, 217, 139, 0.94);
            border-radius: 14px;
            box-shadow: 0 0 0 999px rgba(0, 0, 0, 0.28);
            display: none;
            pointer-events: none;
        }

        .id-guide::before,
        .id-guide::after {
            content: "";
            position: absolute;
            width: 34px;
            height: 34px;
            border-color: #fff8eb;
            border-style: solid;
        }

        .id-guide::before {
            left: 10px;
            top: 10px;
            border-width: 3px 0 0 3px;
        }

        .id-guide::after {
            right: 10px;
            bottom: 10px;
            border-width: 0 3px 3px 0;
        }

        .id-guide span {
            position: absolute;
            left: 50%;
            bottom: -34px;
            transform: translateX(-50%);
            width: max-content;
            max-width: 92vw;
            padding: 6px 10px;
            border-radius: 999px;
            color: #14100d;
            background: linear-gradient(135deg, var(--register-gold-soft), var(--register-gold));
            font-size: 0.76rem;
            font-weight: 900;
            letter-spacing: 0.02em;
        }

        .camera-stage.is-id-mode .id-guide {
            display: block;
        }

        .verification-steps,
        .camera-actions,
        .capture-previews {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }

        .id-upload-actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .id-side-upload {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 42px;
            border: 1px dashed rgba(246, 217, 139, 0.54);
            border-radius: 8px;
            color: #fff8eb;
            background: rgba(255, 248, 235, 0.1);
            font-weight: 900;
            cursor: pointer;
            overflow: hidden;
        }

        .id-side-upload:hover {
            border-color: var(--register-gold);
            background: rgba(246, 217, 139, 0.16);
        }

        .id-side-upload input {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
        }

        .verification-step {
            display: flex;
            align-items: center;
            gap: 8px;
            min-height: 42px;
            padding: 10px;
            border-radius: 8px;
            color: rgba(255, 248, 235, 0.7);
            background: rgba(255, 248, 235, 0.08);
            border: 1px solid rgba(255, 248, 235, 0.16);
            font-weight: 800;
        }

        .verification-step.is-current {
            color: #14100d;
            background: linear-gradient(135deg, var(--register-gold-soft), var(--register-gold));
        }

        .verification-step.is-done {
            color: #dcfce7;
            border-color: rgba(74, 222, 128, 0.42);
            background: rgba(22, 101, 52, 0.42);
        }

        .camera-btn {
            min-height: 44px;
            border: 0;
            border-radius: 8px;
            color: #14100d;
            background: linear-gradient(135deg, var(--register-gold-soft), var(--register-gold));
            font-weight: 900;
        }

        .camera-btn.secondary {
            color: #fff8eb;
            background: rgba(255, 248, 235, 0.14);
            border: 1px solid rgba(255, 248, 235, 0.2);
        }

        .camera-btn:disabled {
            opacity: 0.48;
            cursor: not-allowed;
        }

        .camera-status {
            margin: 0;
            color: rgba(255, 248, 235, 0.78);
            font-size: 0.86rem;
            font-weight: 700;
        }

        .capture-preview {
            min-height: 112px;
            padding: 9px;
            border-radius: 8px;
            background: rgba(255, 248, 235, 0.08);
            border: 1px solid rgba(255, 248, 235, 0.16);
        }

        .capture-preview span {
            display: block;
            margin-bottom: 7px;
            color: rgba(255, 248, 235, 0.72);
            font-size: 0.78rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .capture-preview img {
            width: 100%;
            height: 82px;
            object-fit: cover;
            border-radius: 8px;
            background: rgba(255, 248, 235, 0.1);
            display: block;
        }

        .identity-match-panel {
            display: grid;
            gap: 14px;
            padding: 16px;
            border-radius: 8px;
            border: 1px solid rgba(255, 248, 235, 0.2);
            background: rgba(255, 248, 235, 0.1);
        }

        .identity-match-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .identity-match-header h3 {
            margin: 0;
            color: #fff8eb;
            font-size: 0.95rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .identity-score {
            display: inline-flex;
            align-items: center;
            min-height: 30px;
            padding: 5px 10px;
            border-radius: 999px;
            color: #14100d;
            background: linear-gradient(135deg, var(--register-gold-soft), var(--register-gold));
            font-size: 0.82rem;
            font-weight: 900;
        }

        .ocr-status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 30px;
            padding: 5px 10px;
            border-radius: 999px;
            color: #fff8eb;
            background: rgba(255, 248, 235, 0.14);
            border: 1px solid rgba(255, 248, 235, 0.22);
            font-size: 0.74rem;
            font-weight: 900;
            text-transform: uppercase;
        }

        .ocr-status-badge.processing {
            color: #14100d;
            background: linear-gradient(135deg, var(--register-gold-soft), var(--register-gold));
        }

        .ocr-status-badge.verified {
            color: #dcfce7;
            border-color: rgba(74, 222, 128, 0.42);
            background: rgba(22, 101, 52, 0.52);
        }

        .ocr-status-badge.failed {
            color: #fecaca;
            border-color: rgba(248, 113, 113, 0.42);
            background: rgba(127, 29, 29, 0.42);
        }

        .ocr-status-badge.review {
            color: #fef3c7;
            border-color: rgba(251, 191, 36, 0.38);
            background: rgba(120, 53, 15, 0.42);
        }

        .match-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 8px;
        }

        .match-chip {
            min-height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 8px;
            border-radius: 8px;
            color: #fecaca;
            background: rgba(127, 29, 29, 0.32);
            border: 1px solid rgba(248, 113, 113, 0.32);
            font-size: 0.78rem;
            font-weight: 900;
            text-align: center;
        }

        .match-chip.is-match {
            color: #dcfce7;
            background: rgba(22, 101, 52, 0.42);
            border-color: rgba(74, 222, 128, 0.42);
        }

        .match-chip.is-review {
            color: #fef3c7;
            background: rgba(120, 53, 15, 0.32);
            border-color: rgba(251, 191, 36, 0.34);
        }

        .extracted-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .extracted-item {
            padding: 10px;
            border-radius: 8px;
            background: rgba(255, 248, 235, 0.08);
            border: 1px solid rgba(255, 248, 235, 0.14);
        }

        .extracted-item span {
            display: block;
            color: rgba(255, 248, 235, 0.58);
            font-size: 0.73rem;
            font-weight: 900;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .extracted-item strong {
            display: block;
            min-height: 20px;
            color: #fff8eb;
            font-size: 0.88rem;
            overflow-wrap: anywhere;
        }

        .face-match-status {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px;
            border-radius: 8px;
            color: rgba(255, 248, 235, 0.8);
            background: rgba(255, 248, 235, 0.08);
            border: 1px solid rgba(255, 248, 235, 0.14);
            font-weight: 800;
        }

        .face-match-status.success {
            color: #dcfce7;
            background: rgba(22, 101, 52, 0.42);
            border-color: rgba(74, 222, 128, 0.42);
        }

        .face-match-status.error {
            color: #fecaca;
            background: rgba(127, 29, 29, 0.32);
            border-color: rgba(248, 113, 113, 0.32);
        }

        .face-match-status.warning {
            color: #fef3c7;
            background: rgba(120, 53, 15, 0.32);
            border-color: rgba(251, 191, 36, 0.34);
        }

        .is-invalid-field {
            border-color: #f04438 !important;
            box-shadow: 0 0 0 4px rgba(240, 68, 56, 0.12) !important;
        }

        .ocr-corrected-field {
            border-color: #10b981 !important;
            background: rgba(16, 185, 129, 0.16) !important;
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.14) !important;
        }

        .auth-alert {
            border: none;
            border-radius: 8px;
            box-shadow: none;
            font-weight: 700;
        }

        .auth-alert.alert-danger {
            color: var(--register-danger);
            background: #fff1f0;
        }

        .auth-alert.alert-success {
            color: var(--register-success);
            background: #ecfdf3;
        }

        .submit-btn {
            min-height: 54px;
            border: none;
            border-radius: 8px;
            background: linear-gradient(135deg, #fff8eb, #f6d98b 45%, #d8a53a);
            color: #14100d;
            font-weight: 850;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow:
                0 18px 42px rgba(216, 165, 58, 0.32);
            transition: transform 0.22s ease, box-shadow 0.22s ease, opacity 0.22s ease, filter 0.22s ease;
        }

        .submit-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            filter: brightness(1.04);
            box-shadow:
                0 24px 54px rgba(216, 165, 58, 0.36);
        }

        .submit-btn:disabled {
            cursor: not-allowed;
            opacity: 0.78;
        }

        .spinner {
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255, 255, 255, 0.45);
            border-top-color: #ffffff;
            border-radius: 50%;
            animation: spin 0.75s linear infinite;
            display: none;
        }

        .submit-btn.is-loading .spinner {
            display: inline-block;
        }

        .submit-btn.is-loading .submit-icon {
            display: none;
        }

        .login-link {
            text-align: center;
            color: rgba(255, 248, 235, 0.78);
            margin: 18px 0 0;
        }

        .login-link a {
            color: #f6d98b;
            font-weight: 850;
            text-decoration: none;
        }

        .login-link a:hover {
            color: #fff8eb;
            text-decoration: underline;
        }

        .register-social-divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 20px 0 12px;
            color: rgba(255, 248, 235, 0.62);
            font-size: 0.82rem;
            font-weight: 800;
        }

        .register-social-divider::before,
        .register-social-divider::after {
            content: "";
            height: 1px;
            flex: 1;
            background: rgba(255, 248, 235, 0.18);
        }

        .register-socials {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .register-social-btn {
            min-height: 42px;
            border-radius: 8px;
            border: 1px solid rgba(255, 248, 235, 0.2);
            color: #fff8eb;
            background: rgba(255, 248, 235, 0.09);
            font-weight: 850;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease, background 0.2s ease;
        }

        .register-social-btn:hover,
        .register-social-btn:focus-visible {
            transform: translateY(-2px);
            border-color: rgba(246, 217, 139, 0.48);
            background: rgba(255, 248, 235, 0.16);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.18);
        }

        .toast-stack {
            position: fixed;
            z-index: 20;
            top: 20px;
            right: 20px;
            display: grid;
            gap: 12px;
            width: min(360px, calc(100vw - 28px));
        }

        .auth-toast {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 16px;
            border-radius: 18px;
            color: #ffffff;
            background: rgba(11, 31, 58, 0.86);
            border: 1px solid rgba(255, 255, 255, 0.18);
            box-shadow: 0 18px 40px rgba(0, 0, 0, 0.24);
            backdrop-filter: blur(18px);
            animation: toastIn 0.35s ease both;
        }

        .auth-toast.success i {
            color: #87efac;
        }

        .auth-toast.error i {
            color: #fecaca;
        }

        .auth-toast strong {
            display: block;
            line-height: 1.2;
        }

        .auth-toast span {
            display: block;
            color: rgba(255, 255, 255, 0.78);
            font-size: 0.88rem;
            margin-top: 2px;
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(22px) scale(0.98);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        @keyframes toastIn {
            from {
                opacity: 0;
                transform: translateX(14px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes particleDrift {
            from {
                background-position: 0 0, 38px 52px;
            }
            to {
                background-position: 92px 92px, 164px 178px;
            }
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                scroll-behavior: auto !important;
            }
        }

        @media (max-width: 640px) {
            .register-shell {
                align-items: flex-start;
                justify-content: center;
                width: min(100% - 20px, 760px);
                padding: 16px 10px;
            }

            .register-card {
                max-height: none;
                border-radius: 24px;
                padding: 22px 16px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .verification-options {
                grid-template-columns: 1fr;
            }

            .register-socials {
                grid-template-columns: 1fr;
            }

            .verification-steps,
            .camera-actions,
            .id-upload-actions,
            .capture-previews,
            .match-grid,
            .extracted-grid {
                grid-template-columns: 1fr;
            }

            .camera-stage {
                min-height: 210px;
            }

            .toast-stack {
                top: 12px;
                right: 14px;
                left: 14px;
                width: auto;
            }
        }
    </style>
</head>
<body class="register-page">
    <div class="toast-stack" id="toastStack" aria-live="polite" aria-atomic="true">
        <?php if ($error): ?>
            <div class="auth-toast error" role="alert">
                <i class="fas fa-circle-exclamation"></i>
                <div>
                    <strong>Registration needs attention</strong>
                    <span><?php echo e($error); ?></span>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="auth-toast success" role="status">
                <i class="fas fa-circle-check"></i>
                <div>
                    <strong>Registration submitted</strong>
                    <span><?php echo e($success); ?></span>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <main class="register-shell">
        <section class="register-card" aria-label="Registration form">
            <div class="register-card-header">
                <a href="../index.php" class="register-card-icon" aria-label="Back to Parish System homepage">
                    <?php if ($has_logo): ?>
                        <img src="../assets/img/san-lorenzo-logo.png" alt="San Lorenzo Ruiz logo">
                    <?php else: ?>
                        <i class="fas fa-church"></i>
                    <?php endif; ?>
                </a>
                <h2>Create Your Parish Account</h2>
                <p>Register to access parish requests, sacramental records, schedules, and church services.</p>
            </div>

            <div class="community-quote">
                <i class="fas fa-cross"></i>
                    <span>Welcome to San Lorenzo Ruiz Mission Station. One community, one faith, one service portal.</span>
            </div>

            <div class="verification-notice">
                <i class="fas fa-shield-halved"></i>
                <span>This platform is exclusively for parishioners and residents of Aleosan, Cotabato. All registrations are subject to parish verification and approval.</span>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger auth-alert alert-dismissible fade show" role="alert">
                    <i class="fas fa-circle-exclamation"></i> <?php echo e($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success auth-alert alert-dismissible fade show" role="alert">
                    <i class="fas fa-circle-check"></i> <?php echo e($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="registration-form" id="registrationForm" novalidate>
                <?php echo csrfInput(); ?>
                <div class="form-grid">
                    <div class="field-group">
                        <label for="first_name" class="field-label">First Name</label>
                        <div class="input-wrap">
                            <i class="fas fa-user field-icon"></i>
                            <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo e($form_data['first_name']); ?>" autocomplete="given-name" required autofocus>
                        </div>
                        <div class="field-message" data-error-for="first_name"></div>
                    </div>

                    <div class="field-group">
                        <label for="surname" class="field-label">Surname</label>
                        <div class="input-wrap">
                            <i class="fas fa-user-tag field-icon"></i>
                            <input type="text" class="form-control" id="surname" name="surname" value="<?php echo e($form_data['surname']); ?>" autocomplete="family-name" required>
                        </div>
                        <div class="field-message" data-error-for="surname"></div>
                    </div>

                    <div class="field-group">
                        <label for="middle_initial" class="field-label">Middle Initial</label>
                        <div class="input-wrap">
                            <i class="fas fa-signature field-icon"></i>
                            <input type="text" class="form-control" id="middle_initial" name="middle_initial" value="<?php echo e($form_data['middle_initial']); ?>" autocomplete="additional-name" maxlength="1" placeholder="Optional">
                        </div>
                        <div class="field-message" data-error-for="middle_initial"></div>
                    </div>

                    <div class="field-group">
                        <label for="phone_number" class="field-label">Phone Number</label>
                        <div class="input-wrap">
                            <i class="fas fa-phone field-icon"></i>
                            <input type="tel" class="form-control" id="phone_number" name="phone_number" value="<?php echo e($form_data['phone_number']); ?>" autocomplete="tel" inputmode="numeric" pattern="09[0-9]{9}" maxlength="11" placeholder="09XXXXXXXXX" required>
                        </div>
                        <div class="form-hint">Use 11 digits only, starting with 09.</div>
                        <div class="field-message" data-error-for="phone_number"></div>
                    </div>

                    <div class="field-group">
                        <label for="email" class="field-label">Gmail Address</label>
                        <div class="input-wrap">
                            <i class="fas fa-envelope field-icon"></i>
                            <input type="email" class="form-control" id="email" name="email" value="<?php echo e($form_data['email']); ?>" autocomplete="email" placeholder="name@gmail.com" required>
                        </div>
                        <div class="field-message" data-error-for="email"></div>
                    </div>

                    <div class="field-group full">
                        <span class="field-label">Choose Verification Method</span>
                        <div class="verification-options">
                            <label class="verification-option">
                                <input type="radio" name="verification_method" value="email" <?php echo $form_data['verification_method'] === 'email' ? 'checked' : ''; ?>>
                                <span><i class="fas fa-envelope-circle-check"></i> Verify via Gmail</span>
                            </label>
                            <label class="verification-option">
                                <input type="radio" name="verification_method" value="mobile" <?php echo $form_data['verification_method'] === 'mobile' ? 'checked' : ''; ?>>
                                <span><i class="fas fa-mobile-screen-button"></i> Verify via Mobile Number</span>
                            </label>
                        </div>
                        <div class="field-message" data-error-for="verification_method"></div>
                    </div>

                    <div class="field-group">
                        <label for="chapel_district" class="field-label">Chapel/District</label>
                        <div class="input-wrap">
                            <i class="fas fa-location-dot field-icon"></i>
                            <select class="form-select" id="chapel_district" name="chapel_district" required>
                                <option value="">Select your Chapel/District</option>
                                <?php foreach ($chapel_options as $option): ?>
                                    <option value="<?php echo e($option); ?>" <?php echo $form_data['chapel_district'] === $option ? 'selected' : ''; ?>>
                                        <?php echo e($option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field-message" data-error-for="chapel_district"></div>
                    </div>

                    <div class="field-group">
                        <label for="role_selection" class="field-label">Role Selection</label>
                        <div class="input-wrap">
                            <i class="fas fa-user-shield field-icon"></i>
                            <select class="form-select" id="role_selection" name="role_selection" required>
                                <option value="user" selected>Parishioner</option>
                                <option value="volunteer">Volunteer / Ministry Member</option>
                            </select>
                        </div>
                        <div class="form-hint">Administrator access is granted only by parish staff after verification.</div>
                    </div>

                    <div class="field-group full">
                        <label for="address" class="field-label">Address</label>
                        <div class="input-wrap">
                            <i class="fas fa-map-location-dot field-icon"></i>
                            <input type="text" class="form-control" id="address" name="address" value="<?php echo e($form_data['address']); ?>" autocomplete="street-address" placeholder="Complete Aleosan address" required>
                        </div>
                        <div class="field-message" data-error-for="address"></div>
                    </div>

                    <div class="field-group">
                        <label for="birthdate" class="field-label">Birthdate</label>
                        <div class="input-wrap">
                            <i class="fas fa-calendar-day field-icon"></i>
                            <input type="text" class="form-control" id="birthdate" name="birthdate" value="<?php echo e($form_data['birthdate']); ?>" autocomplete="bday" placeholder="December 16, 2005" required>
                        </div>
                        <div class="field-message" data-error-for="birthdate"></div>
                    </div>

                    <div class="field-group">
                        <label for="birth_place" class="field-label">Place of Birth</label>
                        <div class="input-wrap">
                            <i class="fas fa-map-pin field-icon"></i>
                            <input type="text" class="form-control" id="birth_place" name="birth_place" value="<?php echo e($form_data['birth_place']); ?>" autocomplete="off" placeholder="City / Municipality / Province" required>
                        </div>
                        <div class="field-message" data-error-for="birth_place"></div>
                    </div>

                    <div class="field-group">
                        <label for="id_number" class="field-label">ID Number</label>
                        <div class="input-wrap">
                            <i class="fas fa-fingerprint field-icon"></i>
                            <input type="text" class="form-control" id="id_number" name="id_number" value="<?php echo e($form_data['id_number']); ?>" autocomplete="off" placeholder="Scanned automatically from ID" readonly required>
                        </div>
                        <div class="form-hint">This field is filled by OCR only and locked to prevent manual edits.</div>
                        <div class="field-message" data-error-for="id_number"></div>
                    </div>

                    <div class="field-group">
                        <label for="password" class="field-label">Password</label>
                        <div class="input-wrap password">
                            <i class="fas fa-lock field-icon"></i>
                            <input type="password" class="form-control" id="password" name="password" autocomplete="new-password" required>
                            <button type="button" class="password-toggle" data-toggle-password="password" aria-label="Show password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="form-hint">Use at least 8 characters.</div>
                        <div class="password-strength" aria-label="Password strength">
                            <span></span><span></span><span></span><span></span>
                        </div>
                        <div class="form-hint" id="passwordStrengthText">Password strength: waiting for input.</div>
                        <div class="field-message" data-error-for="password"></div>
                    </div>

                    <div class="field-group">
                        <label for="confirm_password" class="field-label">Confirm Password</label>
                        <div class="input-wrap password">
                            <i class="fas fa-key field-icon"></i>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
                            <button type="button" class="password-toggle" data-toggle-password="confirm_password" aria-label="Show confirm password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="field-message" data-error-for="confirm_password"></div>
                    </div>

                    <div class="field-group full">
                        <span class="field-label">Live Identity Verification</span>
                        <div class="live-verification" id="liveVerification">
                            <div class="camera-stage">
                                <video id="verificationVideo" playsinline muted></video>
                                <canvas id="captureCanvas" hidden></canvas>
                                <div class="face-guide" id="faceGuide" aria-hidden="true"></div>
                                <div class="id-guide" id="idGuide" aria-hidden="true">
                                    <span>Fill this frame with the ID</span>
                                </div>
                                <div class="camera-placeholder" id="cameraPlaceholder">
                                    <i class="fas fa-camera"></i>
                                    <strong>Camera verification required</strong>
                                    <small>No gallery uploads are allowed. Tugon will capture your live face and valid ID using the device camera.</small>
                                </div>
                            </div>

                            <div class="verification-steps">
                                <div class="verification-step is-current" id="faceStep">
                                    <i class="fas fa-user-check"></i>
                                    <span>Face verification</span>
                                </div>
                                <div class="verification-step" id="idFrontStep">
                                    <i class="fas fa-id-card"></i>
                                    <span>Front ID</span>
                                </div>
                                <div class="verification-step" id="idBackStep">
                                    <i class="fas fa-address-card"></i>
                                    <span>Back ID</span>
                                </div>
                            </div>

                            <div class="camera-actions">
                                <button type="button" class="camera-btn" id="startCameraBtn">
                                    <i class="fas fa-video"></i> Start Camera
                                </button>
                                <button type="button" class="camera-btn secondary" id="captureIdFrontBtn" disabled>
                                    <i class="fas fa-camera-retro"></i> Capture Front
                                </button>
                                <button type="button" class="camera-btn secondary" id="captureIdBackBtn" disabled>
                                    <i class="fas fa-camera"></i> Capture Back
                                </button>
                            </div>

                            <div class="id-upload-actions">
                                <label class="id-side-upload" for="frontIdUpload">
                                    <i class="fas fa-upload"></i>
                                    <span>Upload Front ID</span>
                                    <input type="file" id="frontIdUpload" accept="image/png,image/jpeg">
                                </label>
                                <label class="id-side-upload" for="backIdUpload">
                                    <i class="fas fa-upload"></i>
                                    <span>Upload Back ID</span>
                                    <input type="file" id="backIdUpload" accept="image/png,image/jpeg">
                                </label>
                            </div>

                            <p class="camera-status" id="cameraStatus">Start the camera and position your face inside the guide.</p>

                            <div class="capture-previews">
                                <div class="capture-preview">
                                    <span>Live Face</span>
                                    <img id="facePreviewImage" alt="Captured live face preview">
                                </div>
                                <div class="capture-preview">
                                    <span>Front ID</span>
                                    <img id="idFrontPreviewImage" alt="Captured front ID preview">
                                </div>
                                <div class="capture-preview">
                                    <span>Back ID</span>
                                    <img id="idBackPreviewImage" alt="Captured back ID preview">
                                </div>
                            </div>

                            <div class="identity-match-panel" id="identityMatchPanel">
                                <div class="identity-match-header">
                                    <h3><i class="fas fa-id-card-clip"></i> OCR Identity Match</h3>
                                    <div class="d-flex gap-2 flex-wrap justify-content-end">
                                        <span class="ocr-status-badge" id="ocrStatusBadge">Pending</span>
                                        <span class="identity-score" id="ocrScore">0%</span>
                                    </div>
                                </div>
                                <div class="match-grid">
                                    <div class="match-chip" data-match-chip="name"><i class="fas fa-xmark"></i> Name</div>
                                    <div class="match-chip" data-match-chip="birthdate"><i class="fas fa-xmark"></i> Birthdate</div>
                                    <div class="match-chip" data-match-chip="birth_place"><i class="fas fa-xmark"></i> Birth Place</div>
                                    <div class="match-chip" data-match-chip="address"><i class="fas fa-xmark"></i> Address</div>
                                    <div class="match-chip" data-match-chip="id_number"><i class="fas fa-xmark"></i> ID No.</div>
                                </div>
                                <div class="extracted-grid">
                                    <div class="extracted-item"><span>Name</span><strong id="extractedName">Waiting for ID scan</strong></div>
                                    <div class="extracted-item"><span>Birthdate</span><strong id="extractedBirthdate">Waiting for ID scan</strong></div>
                                    <div class="extracted-item"><span>Birth Place</span><strong id="extractedBirthPlace">Waiting for ID scan</strong></div>
                                    <div class="extracted-item"><span>ID No.</span><strong id="extractedIdNumber">Waiting for ID scan</strong></div>
                                    <div class="extracted-item"><span>Address</span><strong id="extractedAddress">Waiting for ID scan</strong></div>
                                </div>
                                <div class="face-match-status warning" id="faceMatchStatus">
                                    <i class="fas fa-user-shield"></i>
                                    <span>Capture your live face and valid ID to compare identity details.</span>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" id="face_capture" name="face_capture">
                        <input type="hidden" id="valid_id_capture" name="valid_id_capture">
                        <input type="hidden" id="valid_id_back_capture" name="valid_id_back_capture">
                        <input type="hidden" id="face_match_status_input" name="face_match_status" value="pending">
                        <div class="field-message" data-error-for="live_verification"></div>
                    </div>

                    <div class="field-group full">
                        <label class="auth-check terms-check" for="terms_check">
                            <input type="checkbox" id="terms_check" name="terms_check" required>
                            <span>I agree to the Terms & Conditions, parish verification policy, and responsible use of sacramental records.</span>
                        </label>
                        <div class="field-message" data-error-for="terms_check"></div>
                    </div>
                </div>

                <button type="submit" class="submit-btn" id="registerSubmit" <?php echo $success ? 'disabled' : ''; ?>>
                    <span class="spinner" aria-hidden="true"></span>
                    <i class="fas fa-user-check submit-icon"></i>
                    <span class="submit-text"><?php echo $success ? 'Registration Submitted' : 'Create Account'; ?></span>
                </button>
            </form>

            <p class="login-link">
                Already have an account? <a href="login.php">Login here</a>
            </p>

            <div class="register-socials" aria-label="Registration helpers">
                <button type="button" class="register-social-btn" title="Email verification placeholder">
                    <i class="fas fa-envelope-circle-check"></i> Verify Email
                </button>
            </div>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const form = document.getElementById('registrationForm');
        const submitButton = document.getElementById('registerSubmit');
        const submitText = submitButton.querySelector('.submit-text');

        const fields = {
            first_name: document.getElementById('first_name'),
            surname: document.getElementById('surname'),
            middle_initial: document.getElementById('middle_initial'),
            phone_number: document.getElementById('phone_number'),
            email: document.getElementById('email'),
            chapel_district: document.getElementById('chapel_district'),
            address: document.getElementById('address'),
            birthdate: document.getElementById('birthdate'),
            birth_place: document.getElementById('birth_place'),
            id_number: document.getElementById('id_number'),
            password: document.getElementById('password'),
            confirm_password: document.getElementById('confirm_password'),
            face_capture: document.getElementById('face_capture'),
            valid_id_capture: document.getElementById('valid_id_capture'),
            valid_id_back_capture: document.getElementById('valid_id_back_capture'),
            terms_check: document.getElementById('terms_check')
        };
        const verificationMethodInputs = Array.from(document.querySelectorAll('input[name="verification_method"]'));
        const cameraStage = document.querySelector('.camera-stage');
        const video = document.getElementById('verificationVideo');
        const canvas = document.getElementById('captureCanvas');
        const startCameraBtn = document.getElementById('startCameraBtn');
        const captureIdFrontBtn = document.getElementById('captureIdFrontBtn');
        const captureIdBackBtn = document.getElementById('captureIdBackBtn');
        const frontIdUpload = document.getElementById('frontIdUpload');
        const backIdUpload = document.getElementById('backIdUpload');
        const cameraStatus = document.getElementById('cameraStatus');
        const faceStep = document.getElementById('faceStep');
        const idFrontStep = document.getElementById('idFrontStep');
        const idBackStep = document.getElementById('idBackStep');
        const facePreviewImage = document.getElementById('facePreviewImage');
        const idFrontPreviewImage = document.getElementById('idFrontPreviewImage');
        const idBackPreviewImage = document.getElementById('idBackPreviewImage');
        const faceMatchStatusInput = document.getElementById('face_match_status_input');
        const ocrStatusBadge = document.getElementById('ocrStatusBadge');
        const ocrScore = document.getElementById('ocrScore');
        const extractedName = document.getElementById('extractedName');
        const extractedBirthdate = document.getElementById('extractedBirthdate');
        const extractedBirthPlace = document.getElementById('extractedBirthPlace');
        const extractedIdNumber = document.getElementById('extractedIdNumber');
        const extractedAddress = document.getElementById('extractedAddress');
        const faceMatchStatus = document.getElementById('faceMatchStatus');
        const matchChips = {
            name: document.querySelector('[data-match-chip="name"]'),
            birthdate: document.querySelector('[data-match-chip="birthdate"]'),
            birth_place: document.querySelector('[data-match-chip="birth_place"]'),
            address: document.querySelector('[data-match-chip="address"]'),
            id_number: document.querySelector('[data-match-chip="id_number"]')
        };
        const strengthBars = Array.from(document.querySelectorAll('.password-strength span'));
        const strengthText = document.getElementById('passwordStrengthText');
        let cameraStream = null;
        let verificationMode = 'face';
        let activeIdSide = 'front';
        let ocrVerified = false;
        let faceDetector = null;
        let faceDetectedSince = 0;
        let detectionTimer = null;

        // Set Field Error Function - Documents this helper's role in the parish management workflow.
        function setFieldError(fieldName, message) {
            const field = fields[fieldName];
            const messageTarget = document.querySelector('[data-error-for="' + fieldName + '"]');
            if (!messageTarget) {
                return;
            }

            if (field) {
                field.classList.toggle('is-invalid-field', Boolean(message));
            }
            messageTarget.textContent = message || '';
        }

        // Validate Form Function - Documents this helper's role in the parish management workflow.
        function validateForm() {
            let isValid = true;
            const emailPattern = /^[^\s@]+@gmail\.com$/i;
            const phonePattern = /^09\d{9}$/;

            Object.keys(fields).forEach((fieldName) => setFieldError(fieldName, ''));

            if (!fields.first_name.value.trim()) {
                setFieldError('first_name', 'First name is required.');
                isValid = false;
            }

            if (!fields.surname.value.trim()) {
                setFieldError('surname', 'Surname is required.');
                isValid = false;
            }

            if (fields.middle_initial.value.trim() && !/^[A-Za-z]$/.test(fields.middle_initial.value.trim())) {
                setFieldError('middle_initial', 'Use one letter only.');
                isValid = false;
            }

            if (!phonePattern.test(fields.phone_number.value.trim())) {
                setFieldError('phone_number', 'Invalid mobile number. Please enter a valid 11-digit Philippine mobile number.');
                isValid = false;
            }

            if (!emailPattern.test(fields.email.value.trim())) {
                setFieldError('email', 'Use a valid Gmail address.');
                isValid = false;
            }

            if (!verificationMethodInputs.some((input) => input.checked)) {
                setFieldError('verification_method', 'Please choose a verification method.');
                isValid = false;
            }

            if (!fields.chapel_district.value) {
                setFieldError('chapel_district', 'Please select a chapel or district.');
                isValid = false;
            }

            if (!fields.address.value.trim()) {
                setFieldError('address', 'Complete address is required.');
                isValid = false;
            }

            if (!fields.birthdate.value) {
                setFieldError('birthdate', 'Birthdate is required.');
                isValid = false;
            } else {
                const birthdate = parseDisplayDate(fields.birthdate.value);
                const minimumAgeDate = new Date();
                minimumAgeDate.setFullYear(minimumAgeDate.getFullYear() - 13);
                if (Number.isNaN(birthdate.getTime()) || birthdate > minimumAgeDate) {
                    setFieldError('birthdate', 'Use Month DD, YYYY format and make sure the registrant is at least 13 years old.');
                    isValid = false;
                }
            }

            if (!fields.birth_place.value.trim()) {
                setFieldError('birth_place', 'Place of birth is required.');
                isValid = false;
            }

            if (!fields.id_number.value.trim()) {
                setFieldError('id_number', 'ID number must be scanned from your valid ID.');
                isValid = false;
            }

            if (fields.password.value.length < 8) {
                setFieldError('password', 'Password must be at least 8 characters.');
                isValid = false;
            }

            if (fields.confirm_password.value !== fields.password.value) {
                setFieldError('confirm_password', 'Passwords do not match.');
                isValid = false;
            }

            if (!fields.face_capture.value || !fields.valid_id_capture.value || !fields.valid_id_back_capture.value) {
                setFieldError('live_verification', 'Complete live face verification plus front and back ID images.');
                isValid = false;
            } else if (!ocrVerified) {
                setFieldError('live_verification', 'OCR identity verification must be successful before registration.');
                isValid = false;
            } else if (faceMatchStatusInput.value !== 'matched') {
                setFieldError('live_verification', 'Face Matched status is required before registration.');
                isValid = false;
            }

            if (!fields.terms_check.checked) {
                setFieldError('terms_check', 'Please accept the Terms & Conditions.');
                isValid = false;
            }

            return isValid;
        }

        // Update Password Strength Function - Documents this helper's role in the parish management workflow.
        function updatePasswordStrength() {
            const value = fields.password.value;
            let score = 0;
            if (value.length >= 8) score++;
            if (/[A-Z]/.test(value) && /[a-z]/.test(value)) score++;
            if (/\d/.test(value)) score++;
            if (/[^A-Za-z0-9]/.test(value)) score++;

            strengthBars.forEach((bar, index) => {
                bar.classList.toggle('active', index < score);
            });

            const labels = ['waiting for input', 'weak', 'fair', 'good', 'strong'];
            strengthText.textContent = 'Password strength: ' + labels[score] + '.';
        }

        // Show Toast Function - Documents this helper's role in the parish management workflow.
        function showToast(type, title, message) {
            const toastStack = document.getElementById('toastStack');
            const toast = document.createElement('div');
            toast.className = 'auth-toast ' + type;
            toast.setAttribute('role', type === 'success' ? 'status' : 'alert');
            toast.innerHTML = '<i class="fas ' + (type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation') + '"></i><div><strong>' + title + '</strong><span>' + message + '</span></div>';
            toastStack.appendChild(toast);
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(14px)';
                setTimeout(() => toast.remove(), 280);
            }, 4200);
        }

        function setMatchChip(key, matched) {
            const chip = matchChips[key];
            if (!chip) return;
            const label = {
                name: 'Name',
                birthdate: 'Birthdate',
                birth_place: 'Birth Place',
                address: 'Address',
                id_number: 'ID No.'
            }[key] || key;
            chip.classList.remove('is-match', 'is-review');
            if (matched === null) {
                chip.classList.add('is-review');
                chip.innerHTML = '<i class="fas fa-triangle-exclamation"></i> ' + label + ' Review';
                return;
            }
            chip.classList.toggle('is-match', Boolean(matched));
            chip.innerHTML = '<i class="fas ' + (matched ? 'fa-check' : 'fa-xmark') + '"></i> ' + label;
        }

        function setFaceStatus(type, message, score = null) {
            faceMatchStatus.className = 'face-match-status ' + type;
            const icon = type === 'success' ? 'fa-circle-check' : (type === 'error' ? 'fa-circle-xmark' : 'fa-user-shield');
            const scoreText = score === null ? '' : ' <strong>(' + score + '% match)</strong>';
            faceMatchStatus.innerHTML = '<i class="fas ' + icon + '"></i><span>' + message + scoreText + '</span>';
            faceMatchStatusInput.value = type === 'success' ? 'matched' : (type === 'error' ? 'mismatch' : 'admin_review');
        }

        function setOcrStatus(status) {
            ocrStatusBadge.className = 'ocr-status-badge ' + status;
            ocrStatusBadge.textContent = status === 'processing' ? 'Processing' : (status === 'verified' ? 'Verified' : (status === 'review' ? 'Review' : (status === 'failed' ? 'Failed' : 'Pending')));
        }

        function parseDisplayDate(value) {
            const raw = String(value || '').trim();
            if (!raw) return new Date(NaN);
            if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
                return new Date(raw + 'T00:00:00');
            }
            return new Date(raw);
        }

        function formatDisplayDate(value) {
            const date = parseDisplayDate(value);
            if (Number.isNaN(date.getTime())) {
                return value || '';
            }
            return date.toLocaleDateString('en-US', {
                month: 'long',
                day: '2-digit',
                year: 'numeric'
            });
        }

        function normalizeOcrCompare(value) {
            return String(value || '')
                .toLowerCase()
                .replace(/[^a-z0-9]/g, '')
                .trim();
        }

        function markOcrCorrected(field) {
            if (!field) return;
            field.classList.add('ocr-corrected-field');
            window.setTimeout(() => field.classList.remove('ocr-corrected-field'), 6500);
        }

        function applyOcrCorrection(field, value, options = {}) {
            if (!field || !String(value || '').trim()) return false;
            const nextValue = options.uppercase ? String(value).trim().toUpperCase() : String(value).trim();
            const currentComparable = options.date ? normalizeOcrCompare(formatDisplayDate(field.value)) : normalizeOcrCompare(field.value);
            const nextComparable = options.date ? normalizeOcrCompare(formatDisplayDate(nextValue)) : normalizeOcrCompare(nextValue);
            if (currentComparable === nextComparable) return false;
            field.value = options.date ? formatDisplayDate(nextValue) : nextValue;
            markOcrCorrected(field);
            setFieldError(field.id, '');
            return true;
        }

        function updateExtractedPanel(data) {
            const extracted = data.extracted || {};
            const checks = data.checks || {};
            const confidence = data.confidence || {};
            function setExtractedValue(target, value, field) {
                const percent = Number(confidence[field] || 0);
                const displayValue = field === 'birthdate' && value ? formatDisplayDate(value) : value;
                target.textContent = (displayValue || 'Needs Review') + (percent ? ' (' + percent + '%)' : '');
            }
            const extractedFullName = extracted.full_name || [extracted.first_name, extracted.middle_initial, extracted.surname].filter(Boolean).join(' ');
            setExtractedValue(extractedName, extractedFullName, 'full_name');
            setExtractedValue(extractedBirthdate, extracted.birthdate, 'birthdate');
            setExtractedValue(extractedBirthPlace, extracted.birth_place, 'birth_place');
            setExtractedValue(extractedIdNumber, extracted.id_number, 'id_number');
            setExtractedValue(extractedAddress, extracted.address, 'address');
            ocrScore.textContent = (data.score || 0) + '%';
            const needsReview = ['ocr_unavailable', 'unreadable', 'manual_review'].includes(data.status) && !(data.text || '').trim();
            setMatchChip('name', needsReview || !extractedFullName ? null : checks.name);
            setMatchChip('birthdate', needsReview || !extracted.birthdate ? null : checks.birthdate);
            setMatchChip('birth_place', needsReview || !extracted.birth_place ? null : checks.birth_place);
            setMatchChip('address', needsReview || !extracted.address ? null : checks.address);
            setMatchChip('id_number', needsReview || !extracted.id_number ? null : checks.id_number);

            let corrected = false;
            const correctedKeys = new Set();
            const surnameCorrected = applyOcrCorrection(fields.surname, extracted.surname, { uppercase: true });
            const firstNameCorrected = applyOcrCorrection(fields.first_name, extracted.first_name, { uppercase: true });
            const middleCorrected = applyOcrCorrection(fields.middle_initial, extracted.middle_initial, { uppercase: true });
            const birthdateCorrected = applyOcrCorrection(fields.birthdate, extracted.birthdate, { date: true });
            corrected = surnameCorrected || firstNameCorrected || middleCorrected || birthdateCorrected;
            if (surnameCorrected || firstNameCorrected || middleCorrected) correctedKeys.add('name');
            if (birthdateCorrected) correctedKeys.add('birthdate');
            if (!extracted.first_name && !extracted.surname && extracted.full_name) {
                const nameParts = extracted.full_name.replace(/\s+/g, ' ').trim().split(' ');
                if (nameParts.length > 1) {
                    const fallbackSurnameCorrected = applyOcrCorrection(fields.surname, nameParts[nameParts.length - 1], { uppercase: true });
                    const fallbackFirstCorrected = applyOcrCorrection(fields.first_name, nameParts.slice(0, -1).join(' '), { uppercase: true });
                    corrected = fallbackSurnameCorrected || fallbackFirstCorrected || corrected;
                    if (fallbackSurnameCorrected || fallbackFirstCorrected) correctedKeys.add('name');
                }
            }
            if (extracted.birth_place && !fields.birth_place.value.trim()) {
                fields.birth_place.value = extracted.birth_place;
                markOcrCorrected(fields.birth_place);
                correctedKeys.add('birth_place');
            }
            if (extracted.address && !fields.address.value.trim()) {
                fields.address.value = extracted.address;
                markOcrCorrected(fields.address);
                correctedKeys.add('address');
            }
            if (extracted.id_number) {
                fields.id_number.value = extracted.id_number;
                fields.id_number.readOnly = true;
                markOcrCorrected(fields.id_number);
            }
            if (corrected) {
                showToast('success', 'Information updated based on the verified government-issued ID.', 'Registration details were automatically corrected using verified ID information.');
            }
            correctedKeys.forEach((key) => setMatchChip(key, true));

            const hasExtractedValue = Boolean(extractedFullName || extracted.birthdate || extracted.birth_place || extracted.id_number || extracted.address);
            const hasCriticalOcrValue = Boolean(extracted.id_number);
            const clearMismatch = ['name', 'birthdate', 'birth_place', 'address'].some((key) => {
                const field = key === 'name' ? 'full_name' : key;
                const value = key === 'name' ? extractedFullName : extracted[field];
                return Boolean(value) && checks[key] === false && !correctedKeys.has(key);
            });
            ocrVerified = hasCriticalOcrValue && !clearMismatch && Boolean(data.ocr_available || data.ocr_available === undefined);
            if (data.duplicate_id) {
                ocrVerified = false;
                setOcrStatus('failed');
                setFieldError('id_number', 'This ID has already been registered in the system.');
                showToast('error', 'Duplicate ID detected', 'This ID has already been registered in the system.');
                setCameraStatus('This ID has already been registered in the system.', true);
            } else if (!hasExtractedValue && (data.ocr_available || data.ocr_available === undefined)) {
                setOcrStatus('failed');
                showToast('error', 'ID text was not readable', 'Please upload or capture a clearer front and back ID image.');
                setCameraStatus('OCR could not read the ID text. Recapture or upload clearer ID images before continuing.', true);
                captureIdFrontBtn.disabled = false;
                captureIdBackBtn.disabled = false;
                idFrontStep.classList.toggle('is-current', !fields.valid_id_capture.value);
                idBackStep.classList.toggle('is-current', !fields.valid_id_back_capture.value);
                cameraStage.classList.add('is-id-mode');
            } else if (ocrVerified) {
                setOcrStatus(data.status === 'needs_review' ? 'review' : 'verified');
                showToast(data.status === 'needs_review' ? 'error' : 'success', data.status === 'needs_review' ? 'Some ID details need review' : 'OCR extraction completed', data.status === 'needs_review' ? 'Some ID details could not be read clearly. The administrator will review them.' : 'Name, birthdate, birth place, address, and ID number were detected.');
                setFieldError('live_verification', '');
            } else {
                setOcrStatus('failed');
                setCameraStatus('Some ID details could not be read clearly. Please retake the ID photo with better lighting and make sure the whole ID is visible.', true);
            }
        }

        async function scanCapturedId() {
            if (!fields.valid_id_capture.value && !fields.valid_id_back_capture.value) return;
            ocrVerified = false;
            setOcrStatus('processing');
            ocrScore.textContent = 'Scanning...';
            extractedName.textContent = 'Scanning ID image...';
            extractedBirthdate.textContent = 'Scanning ID image...';
            extractedBirthPlace.textContent = 'Scanning ID image...';
            extractedIdNumber.textContent = 'Scanning ID image...';
            extractedAddress.textContent = 'Scanning ID image...';

            const payload = new URLSearchParams();
            payload.set('id_front_image', fields.valid_id_capture.value);
            payload.set('id_back_image', fields.valid_id_back_capture.value);
            payload.set('id_image', fields.valid_id_capture.value || fields.valid_id_back_capture.value);
            payload.set('first_name', fields.first_name.value.trim());
            payload.set('surname', fields.surname.value.trim());
            payload.set('middle_initial', fields.middle_initial.value.trim());
            payload.set('birthdate', fields.birthdate.value);
            payload.set('birth_place', fields.birth_place.value.trim());
            payload.set('address', fields.address.value.trim());
            payload.set('id_number', fields.id_number.value.trim());

            try {
                const response = await fetch('../api/ocr-identity.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: payload.toString()
                });
                const data = await response.json();
                if (!data.ok) {
                    throw new Error(data.error || 'OCR scan failed.');
                }
                updateExtractedPanel(data);
                if (!data.ocr_available) {
                    showToast('error', 'OCR unavailable', 'The ID was captured, but OCR needs Tesseract installed on the server.');
                }
            } catch (error) {
                ocrScore.textContent = '0%';
                setOcrStatus('failed');
                extractedName.textContent = 'OCR unavailable';
                extractedBirthdate.textContent = 'OCR unavailable';
                extractedBirthPlace.textContent = 'OCR unavailable';
                extractedIdNumber.textContent = 'OCR unavailable';
                extractedAddress.textContent = 'OCR unavailable';
                showToast('error', 'OCR scan failed', error.message || 'Please recapture the ID clearly.');
            }
        }

        function loadImage(src) {
            return new Promise((resolve, reject) => {
                const image = new Image();
                image.onload = () => resolve(image);
                image.onerror = reject;
                image.src = src;
            });
        }

        function cropFaceSignature(image, box) {
            const sampleCanvas = document.createElement('canvas');
            const size = 32;
            sampleCanvas.width = size;
            sampleCanvas.height = size;
            const ctx = sampleCanvas.getContext('2d');
            ctx.drawImage(image, box.x, box.y, box.width, box.height, 0, 0, size, size);
            const pixels = ctx.getImageData(0, 0, size, size).data;
            const signature = [];
            for (let i = 0; i < pixels.length; i += 4) {
                signature.push(Math.round((pixels[i] + pixels[i + 1] + pixels[i + 2]) / 3));
            }
            return signature;
        }

        function signatureSimilarity(a, b) {
            if (!a.length || a.length !== b.length) return 0;
            let diff = 0;
            for (let i = 0; i < a.length; i++) {
                diff += Math.abs(a[i] - b[i]);
            }
            return Math.max(0, 1 - (diff / (a.length * 255)));
        }

        async function compareCapturedFaces() {
            if (!fields.face_capture.value || !fields.valid_id_capture.value) return;
            if (!('FaceDetector' in window)) {
                setFaceStatus('error', 'Face verification cannot continue because this browser does not support local face detection.');
                return;
            }

            try {
                setFaceStatus('warning', 'Comparing the live face with the face printed on the ID...');
                const detector = new FaceDetector({ fastMode: false, maxDetectedFaces: 1 });
                const liveImage = await loadImage(fields.face_capture.value);
                const idImage = await loadImage(fields.valid_id_capture.value);
                const liveFaces = await detector.detect(liveImage);
                const idFaces = await detector.detect(idImage);

                if (liveFaces.length !== 1 || idFaces.length !== 1) {
                    setFaceStatus('error', 'Face Verification Failed: The uploaded ID photo does not match the captured selfie.');
                    return;
                }

                const liveSignature = cropFaceSignature(liveImage, liveFaces[0].boundingBox);
                const idSignature = cropFaceSignature(idImage, idFaces[0].boundingBox);
                const similarity = signatureSimilarity(liveSignature, idSignature);

                const matchPercent = Math.round(similarity * 100);

                if (similarity >= 0.58) {
                    setFaceStatus('success', 'Face Verification Successful', matchPercent);
                } else {
                    setFaceStatus('error', 'Face Verification Failed: The uploaded ID photo does not match the captured selfie.', matchPercent);
                }
            } catch (error) {
                setFaceStatus('error', 'Face verification failed. Please make sure both the live face and front ID image are clear.');
            }
        }

        Object.values(fields).forEach((field) => {
            if (!field) {
                return;
            }
            field.addEventListener('input', () => {
                if (field === fields.middle_initial) {
                    field.value = field.value.replace(/[^A-Za-z]/g, '').slice(0, 1).toUpperCase();
                }
                if (field === fields.phone_number) {
                    field.value = field.value.replace(/\D/g, '').slice(0, 11);
                }
                if (field === fields.password) {
                    updatePasswordStrength();
                }
                if ([fields.first_name, fields.surname, fields.middle_initial, fields.birthdate, fields.birth_place, fields.address].includes(field) && (fields.valid_id_capture.value || fields.valid_id_back_capture.value)) {
                    window.clearTimeout(field._ocrTimer);
                    field._ocrTimer = window.setTimeout(scanCapturedId, 500);
                }
                if (field.classList.contains('is-invalid-field')) {
                    validateForm();
                }
            });
            field.addEventListener('change', () => {
                if (field.classList.contains('is-invalid-field')) {
                    validateForm();
                }
            });
        });

        verificationMethodInputs.forEach((input) => {
            input.addEventListener('change', () => {
                setFieldError('verification_method', '');
            });
        });

        // Set Camera Status Function - Documents this helper's role in the parish management workflow.
        function setCameraStatus(message, isError = false) {
            cameraStatus.textContent = message;
            cameraStatus.style.color = isError ? '#fecaca' : 'rgba(255, 248, 235, 0.78)';
        }

        async function startCamera(facingMode = 'user') {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                setFieldError('live_verification', 'This browser does not support camera access.');
                return false;
            }

            if (cameraStream) {
                cameraStream.getTracks().forEach((track) => track.stop());
            }

            try {
                cameraStream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: { ideal: facingMode }, width: { ideal: 1280 }, height: { ideal: 720 } },
                    audio: false
                });
                video.srcObject = cameraStream;
                await video.play();
                cameraStage.classList.add('is-active');
                setFieldError('live_verification', '');
                return true;
            } catch (error) {
                setCameraStatus('Camera is blocked or unavailable. Please allow camera access and try again.', true);
                setFieldError('live_verification', 'Camera access is required for registration.');
                return false;
            }
        }

        // Capture Frame Function - Documents this helper's role in the parish management workflow.
        function captureFrame(mode = 'full') {
            const width = video.videoWidth || 1280;
            const height = video.videoHeight || 720;
            const source = {
                x: 0,
                y: 0,
                width,
                height
            };

            if (mode === 'id') {
                source.x = Math.round(width * 0.07);
                source.y = Math.round(height * 0.17);
                source.width = Math.round(width * 0.86);
                source.height = Math.round(height * 0.66);
            }

            canvas.width = mode === 'id' ? 1800 : width;
            canvas.height = mode === 'id' ? Math.round(1800 * (source.height / source.width)) : height;
            canvas.getContext('2d').drawImage(
                video,
                source.x,
                source.y,
                source.width,
                source.height,
                0,
                0,
                canvas.width,
                canvas.height
            );
            return canvas.toDataURL('image/jpeg', mode === 'id' ? 0.95 : 0.88);
        }

        // Mark Step Done Function - Documents this helper's role in the parish management workflow.
        function markStepDone(step) {
            step.classList.remove('is-current');
            step.classList.add('is-done');
        }

        // Switch To ID Capture Function - Documents this helper's role in the parish management workflow.
        function switchToIdCapture(side = 'front') {
            verificationMode = 'id';
            activeIdSide = side;
            clearInterval(detectionTimer);
            cameraStage.classList.remove('is-face-mode');
            cameraStage.classList.add('is-id-mode');
            markStepDone(faceStep);
            idFrontStep.classList.toggle('is-current', side === 'front');
            idBackStep.classList.toggle('is-current', side === 'back');
            captureIdFrontBtn.disabled = false;
            captureIdBackBtn.disabled = false;
            setCameraStatus('Capture or upload the ' + (side === 'front' ? 'front' : 'back') + ' side of the ID. Fill the yellow frame and keep text sharp.');
            startCamera('environment');
        }

        async function detectFaceLoop() {
            if (verificationMode !== 'face') {
                return;
            }

            if ('FaceDetector' in window && !faceDetector) {
                faceDetector = new FaceDetector({ fastMode: true, maxDetectedFaces: 1 });
            }

            try {
                if (faceDetector) {
                    const faces = await faceDetector.detect(video);
                    if (faces.length === 1) {
                        const box = faces[0].boundingBox;
                        const centerX = box.x + box.width / 2;
                        const centerY = box.y + box.height / 2;
                        const aligned = centerX > video.videoWidth * 0.32 &&
                            centerX < video.videoWidth * 0.68 &&
                            centerY > video.videoHeight * 0.22 &&
                            centerY < video.videoHeight * 0.78 &&
                            box.width > video.videoWidth * 0.16;

                        if (aligned) {
                            faceDetectedSince = faceDetectedSince || Date.now();
                            setCameraStatus('Face detected. Hold still for automatic capture.');
                            if (Date.now() - faceDetectedSince > 900) {
                                fields.face_capture.value = captureFrame();
                                facePreviewImage.src = fields.face_capture.value;
                                switchToIdCapture();
                            }
                            return;
                        }
                    }
                    faceDetectedSince = 0;
                    setCameraStatus('Position your face clearly inside the guide.');
                    return;
                }

                faceDetectedSince = faceDetectedSince || Date.now();
                setCameraStatus('Native face detection is unavailable. Hold still inside the guide for live capture.');
                if (Date.now() - faceDetectedSince > 2200) {
                    fields.face_capture.value = captureFrame();
                    facePreviewImage.src = fields.face_capture.value;
                    switchToIdCapture();
                }
            } catch (error) {
                faceDetectedSince = 0;
                setCameraStatus('Face detection failed. Improve lighting and keep your face clear.', true);
            }
        }

        startCameraBtn.addEventListener('click', async () => {
            verificationMode = 'face';
            activeIdSide = 'front';
            ocrVerified = false;
            fields.face_capture.value = '';
            fields.valid_id_capture.value = '';
            fields.valid_id_back_capture.value = '';
            fields.id_number.value = '';
            fields.id_number.readOnly = true;
            faceMatchStatusInput.value = 'pending';
            facePreviewImage.removeAttribute('src');
            idFrontPreviewImage.removeAttribute('src');
            idBackPreviewImage.removeAttribute('src');
            setOcrStatus('pending');
            ocrScore.textContent = '0%';
            extractedName.textContent = 'Waiting for ID scan';
            extractedBirthdate.textContent = 'Waiting for ID scan';
            extractedBirthPlace.textContent = 'Waiting for ID scan';
            extractedIdNumber.textContent = 'Waiting for ID scan';
            extractedAddress.textContent = 'Waiting for ID scan';
            Object.keys(matchChips).forEach((key) => setMatchChip(key, false));
            setFaceStatus('warning', 'Capture your live face and valid ID to compare identity details.');
            faceStep.className = 'verification-step is-current';
            idFrontStep.className = 'verification-step';
            idBackStep.className = 'verification-step';
            captureIdFrontBtn.disabled = true;
            captureIdBackBtn.disabled = true;
            cameraStage.classList.remove('is-id-mode');
            cameraStage.classList.add('is-face-mode');
            const started = await startCamera('user');
            if (started) {
                setCameraStatus('Position your face clearly inside the guide.');
                clearInterval(detectionTimer);
                detectionTimer = setInterval(detectFaceLoop, 350);
            }
        });

        function updateIdSide(side, dataUrl) {
            if (side === 'back') {
                fields.valid_id_back_capture.value = dataUrl;
                idBackPreviewImage.src = dataUrl;
                markStepDone(idBackStep);
            } else {
                fields.valid_id_capture.value = dataUrl;
                idFrontPreviewImage.src = dataUrl;
                markStepDone(idFrontStep);
            }

            ocrVerified = false;
            fields.id_number.value = '';
            fields.id_number.readOnly = true;
            setFieldError('live_verification', '');

            if (fields.valid_id_capture.value && fields.valid_id_back_capture.value) {
                cameraStage.classList.remove('is-id-mode');
                setCameraStatus('Front and back ID images are ready. Scanning OCR and comparing the front ID face...');
                scanCapturedId();
                compareCapturedFaces();
            } else {
                const nextSide = fields.valid_id_capture.value ? 'back' : 'front';
                switchToIdCapture(nextSide);
            }
        }

        captureIdFrontBtn.addEventListener('click', () => {
            if (verificationMode !== 'id') return;
            activeIdSide = 'front';
            updateIdSide('front', captureFrame('id'));
        });

        captureIdBackBtn.addEventListener('click', () => {
            if (verificationMode !== 'id') return;
            activeIdSide = 'back';
            updateIdSide('back', captureFrame('id'));
        });

        function readImageUpload(file) {
            return new Promise((resolve, reject) => {
                if (!file || !/^image\/(jpeg|png)$/i.test(file.type)) {
                    reject(new Error('Please upload a JPG or PNG image.'));
                    return;
                }
                const reader = new FileReader();
                reader.onload = () => resolve(reader.result);
                reader.onerror = () => reject(new Error('The selected image could not be read.'));
                reader.readAsDataURL(file);
            });
        }

        async function handleIdUpload(side, input) {
            try {
                const dataUrl = await readImageUpload(input.files[0]);
                updateIdSide(side, dataUrl);
                showToast('success', side === 'front' ? 'Front ID uploaded' : 'Back ID uploaded', 'The image is ready for OCR scanning.');
            } catch (error) {
                showToast('error', 'Upload failed', error.message);
            } finally {
                input.value = '';
            }
        }

        frontIdUpload.addEventListener('change', () => handleIdUpload('front', frontIdUpload));
        backIdUpload.addEventListener('change', () => handleIdUpload('back', backIdUpload));

        document.querySelectorAll('[data-toggle-password]').forEach((toggle) => {
            toggle.addEventListener('click', () => {
                const target = document.getElementById(toggle.dataset.togglePassword);
                const icon = toggle.querySelector('i');
                const shouldShow = target.type === 'password';
                target.type = shouldShow ? 'text' : 'password';
                icon.classList.toggle('fa-eye', !shouldShow);
                icon.classList.toggle('fa-eye-slash', shouldShow);
                toggle.setAttribute('aria-label', shouldShow ? 'Hide password' : 'Show password');
            });
        });

        form.addEventListener('submit', (event) => {
            if (!validateForm()) {
                event.preventDefault();
                showToast('error', 'Check the form', 'Please correct the highlighted fields before creating your account.');
                return;
            }

            submitButton.disabled = true;
            submitButton.classList.add('is-loading');
            submitText.textContent = 'Creating account...';
        });
    </script>
</body>
</html>
