<?php
/**
 * User Registration Page
 * AI-Powered Parish Request and Sacramental Records Management System
 * Handles user registration with proper password hashing and validation
 */

require_once '../includes/session.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

include '../config/security.php';
include '../database/config.php';
include '../includes/helpers.php';

ensureUserVerificationSchema($conn);
ensureEmailNotificationSchema($conn);

if (empty($_SESSION['registration_verification_id'])) {
    $_SESSION['registration_verification_id'] = bin2hex(random_bytes(16));
}
$registration_verification_id = $_SESSION['registration_verification_id'];

if (isLoggedIn()) {
    header('Location: ' . getUserDashboardURL(), true, 302);
    exit;
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
    'id_number' => '',
    'sex' => '',
    'nationality' => ''
];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requireValidCsrfToken();
    $first_name = isset($_POST['first_name']) ? sanitize($_POST['first_name']) : '';
    $surname = isset($_POST['surname']) ? sanitize($_POST['surname']) : '';
    $middle_initial = isset($_POST['middle_initial']) ? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', sanitize($_POST['middle_initial'])), 0, 1)) : '';
    $fullname = trim($first_name . ' ' . ($middle_initial !== '' ? $middle_initial . '. ' : '') . $surname);
    $phone_number = isset($_POST['phone_number']) ? normalizePhilippineMobileForStorage(sanitize($_POST['phone_number'])) : '';
    $email = isset($_POST['email']) ? strtolower(sanitize($_POST['email'])) : '';
    $verification_method = $_POST['verification_method'] ?? 'email';
    if (!in_array($verification_method, ['email', 'mobile'], true)) {
        $verification_method = 'email';
    }
    if ($verification_method === 'email') {
        $phone_number = '';
    } else {
        $email = '';
    }
    $chapel_district = isset($_POST['chapel_district']) ? sanitize($_POST['chapel_district']) : '';
    $address = isset($_POST['address']) ? sanitize($_POST['address']) : '';
    $birthdate_input = isset($_POST['birthdate']) ? sanitize($_POST['birthdate']) : '';
    $birthdate_timestamp = $birthdate_input !== '' ? strtotime($birthdate_input) : false;
    $birthdate = $birthdate_timestamp ? date('Y-m-d', $birthdate_timestamp) : $birthdate_input;
    $birthdate_display = $birthdate_timestamp ? date('F d, Y', $birthdate_timestamp) : $birthdate_input;
    $birth_place = isset($_POST['birth_place']) ? sanitize($_POST['birth_place']) : '';
    $id_number = isset($_POST['id_number']) ? sanitize($_POST['id_number']) : '';
    $sex = isset($_POST['sex']) ? sanitize($_POST['sex']) : '';
    $nationality = isset($_POST['nationality']) ? sanitize($_POST['nationality']) : '';
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
        'id_number' => $id_number,
        'sex' => $sex,
        'nationality' => $nationality
    ];

    if (empty($first_name) || empty($surname) || empty($chapel_district) || empty($address) || empty($birthdate_input) || empty($birth_place) || empty($password) || empty($confirm_password)) {
        $error = 'Please complete all required fields.';
    } elseif ($verification_method === 'email' && $email === '') {
        $error = 'Please enter your Gmail address.';
    } elseif ($verification_method === 'mobile' && $phone_number === '') {
        $error = 'Please enter your mobile number.';
    } elseif ($verification_method === 'email' && !isValidEmail($email)) {
        $error = 'Please enter a valid email address.';
    } elseif ($verification_method === 'mobile' && !isValidPhilippineMobile($phone_number)) {
        $error = 'Invalid mobile number. Please enter a valid Philippine mobile number.';
    } elseif (!$birthdate_timestamp || $birthdate_timestamp > strtotime('-13 years')) {
        $error = 'Please enter a valid birthdate in Month DD, YYYY format. Registrants must be at least 13 years old.';
    } elseif (!isValidPassword($password)) {
        $error = passwordRequirementsMessage();
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (empty($id_number)) {
        $error = 'ID number is required.';
    } elseif (empty($_POST['face_capture']) || empty($_POST['valid_id_capture']) || empty($_POST['valid_id_back_capture'])) {
        $error = 'Live face capture and front/back ID verification must be completed before registration.';
    } elseif (empty($_POST['terms_check'])) {
        $error = 'You must read and agree to the Terms & Conditions and parish verification policy.';
    } elseif (($_POST['id_ocr_status'] ?? 'pending') === 'mismatch') {
        $error = 'Please correct the fields flagged by the front ID scan before registration.';
    } else {
        $identifier_type = $verification_method === 'mobile' ? 'mobile' : 'email';
        $identifier_value = $verification_method === 'mobile' ? $phone_number : $email;
        if (!authenticationIdentifierAvailable($conn, $identifier_type, $identifier_value)) {
            $error = 'Unable to complete registration with the information provided. Use account recovery or contact the parish office if you already applied.';
        }

        if (!$error) {
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
                $id_number_hash = hashIdentityNumber($id_number);
                $id_number_hash_safe = $conn->real_escape_string($id_number_hash);
                $duplicate_id_result = $conn->query("SELECT id FROM users WHERE id_number_hash = '$id_number_hash_safe' LIMIT 1");
                if ($duplicate_id_result && $duplicate_id_result->num_rows > 0) {
                    $error = 'This ID has already been registered in the system.';
                }

                if (!$error) {
                    $fullname = trim($first_name . ' ' . ($middle_initial !== '' ? $middle_initial . '. ' : '') . $surname);
                    $id_saved = saveEncryptedCameraCapture($id_capture, $id_upload_dir, 'live-valid-id-front');
                    $id_back_saved = saveEncryptedCameraCapture($id_back_capture, $id_upload_dir, 'live-valid-id-back');
                    $face_saved = saveEncryptedCameraCapture($face_capture, $face_upload_dir, 'live-face');

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
                        $back_mime_type = $id_back_capture['mime_type'];
                        $face_mime_type = $face_capture['mime_type'];
                        $original_name = 'front-back-id-verification.' . $id_capture['extension'];
                    $hashed_password = hashPassword($password);
                    $id_number_encrypted = encryptSensitiveValue($id_number);
                    // Browser-side face matching is a usability signal only. A hidden
                    // form value must never establish server-side identity assurance.
                    // Every testing/production registration remains pending until an
                    // authorized parish reviewer compares the protected captures.
                    $face_status = 'admin_review';

                    $phone_number_db = $phone_number !== '' ? $phone_number : null;
                    $email_db = $email !== '' ? $email : null;

                    $stmt = $conn->prepare("INSERT INTO users (fullname, first_name, surname, middle_initial, phone_number, email, verification_method, chapel_district, address, birthdate, birth_place, sex, nationality, id_number_hash, id_number_encrypted, password, role, status, valid_id_path, valid_id_original_name, valid_id_mime_type, valid_id_back_path, valid_id_back_mime_type, valid_id_capture_method, face_image_path, face_image_mime_type, face_verification_status, face_verified_at)
                                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'user', 'pending_verification', ?, ?, ?, ?, ?, 'live_camera', ?, ?, ?, NULL)");

                    if ($stmt) {
                        $stmt->bind_param(
                            'ssssssssssssssssssssssss',
                            $fullname,
                            $first_name,
                            $surname,
                            $middle_initial,
                            $phone_number_db,
                            $email_db,
                            $verification_method,
                            $chapel_district,
                            $address,
                            $birthdate,
                            $birth_place,
                            $sex,
                            $nationality,
                            $id_number_hash,
                            $id_number_encrypted,
                            $hashed_password,
                            $db_path,
                            $original_name,
                            $mime_type,
                            $back_db_path,
                            $back_mime_type,
                            $face_db_path,
                            $face_mime_type,
                            $face_status
                        );
                    }

                    if ($stmt && $stmt->execute()) {
                        $new_user_id = $conn->insert_id;
                        $security_provisioned = synchronizeAuthenticationIdentifier(
                            $conn,
                            $new_user_id,
                            $identifier_type,
                            $identifier_value,
                            null
                        ) && assignUserRole($conn, $new_user_id, 'parishioner', null)
                            && recordAccountStatusChange($conn, $new_user_id, null, 'pending_verification', 'submitted', null, $new_user_id)
                            && recordRegistrationReview($conn, $new_user_id, 'submitted', null, 'pending_verification', null, $new_user_id);
                        $otp_sent = $security_provisioned
                            ? createOtpTransaction($conn, $new_user_id, 'registration', $verification_method)
                            : ['ok' => false, 'error' => 'Unable to provision account security.'];
                        if (empty($otp_sent['ok'])) {
                            $error = 'Unable to complete secure registration. Please try again later.';
                            createAuditLog($conn, $new_user_id, 'REGISTRATION_OTP_SEND_FAILED', 'users', $new_user_id);
                            foreach ([$id_saved['path'], $id_back_saved['path'], $face_saved['path']] as $capture_path) {
                                if (is_file($capture_path)) {
                                    @unlink($capture_path);
                                }
                            }
                            $delete = $conn->prepare("DELETE FROM users WHERE id = ? AND status = 'pending_verification'");
                            $delete->bind_param('i', $new_user_id);
                            $delete->execute();
                            $delete->close();
                        }
                        if (!$error) {
                            $success = 'Your registration is now under parish administrator review.';
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
                            createAuditLog($conn, $new_user_id, 'REGISTRATION_PENDING_VERIFICATION', 'users', $new_user_id, null, [
                                'verification_method' => $verification_method
                            ]);
                            $_SESSION['pending_registration_transaction'] = $otp_sent['transaction_id'];
                            header('Location: verify-otp.php?transaction=' . urlencode($otp_sent['transaction_id']), true, 303);
                            exit;
                        }
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
    <?php
    $style_version = file_exists(__DIR__ . '/../assets/css/style.css') ? filemtime(__DIR__ . '/../assets/css/style.css') : time();
    $premium_style_version = file_exists(__DIR__ . '/../assets/css/premium-parish.css') ? filemtime(__DIR__ . '/../assets/css/premium-parish.css') : time();
    $theme_style_version = file_exists(__DIR__ . '/../assets/css/theme.css') ? filemtime(__DIR__ . '/../assets/css/theme.css') : time();
    ?>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo $style_version; ?>">
    <link rel="stylesheet" href="../assets/css/premium-parish.css?v=<?php echo $premium_style_version; ?>">
    <link rel="stylesheet" href="../assets/css/theme.css?v=<?php echo $theme_style_version; ?>">
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

        /* Hide native browser password reveal button in Edge/IE/WebKit */
        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear,
        input::-ms-reveal,
        input::-ms-clear {
            display: none !important;
            width: 0 !important;
            height: 0 !important;
            pointer-events: none !important;
        }


        .password-toggle {
            position: absolute !important;
            right: 8px !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
            width: 38px !important;
            height: 38px !important;
            border: none !important;
            border-radius: 8px !important;
            background: transparent !important;
            color: #77736b !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            cursor: pointer !important;
            z-index: 6 !important;
            transition: color 0.18s ease, background 0.18s ease !important;
        }

        .password-toggle:hover {
            color: #a97f24 !important;
            background: rgba(200, 155, 60, 0.12) !important;
        }

        .password-toggle:focus-visible {
            outline: 2px solid #c89b3c !important;
            outline-offset: 2px !important;
        }

        .password-toggle i {
            position: static !important;
            width: auto !important;
            height: auto !important;
            color: inherit !important;
            transform: none !important;
            pointer-events: none !important;
            font-size: 15px !important;
        }

        .input-wrap.password .form-control {
            padding-right: 48px !important;
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

        .id-ocr-status {
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

        .id-ocr-status.success {
            color: #dcfce7;
            background: rgba(22, 101, 52, 0.42);
            border-color: rgba(74, 222, 128, 0.42);
        }

        .id-ocr-status.error {
            color: #fecaca;
            background: rgba(127, 29, 29, 0.32);
            border-color: rgba(248, 113, 113, 0.32);
        }

        .id-ocr-status.warning {
            color: #fef3c7;
            background: rgba(120, 53, 15, 0.32);
            border-color: rgba(251, 191, 36, 0.34);
        }

        .is-invalid-field {
            border-color: #f04438 !important;
            box-shadow: 0 0 0 4px rgba(240, 68, 56, 0.12) !important;
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
            .capture-previews {
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

        .auth-register-screen {
            width: min(1380px, calc(100% - 32px));
            justify-content: center;
            gap: 0;
        }

        .auth-register-screen .auth-login-side {
            display: flex;
            min-height: 760px;
            width: min(38vw, 500px);
        }

        .auth-register-card.register-card {
            align-self: stretch;
            width: min(62vw, 760px);
            min-height: 760px;
            max-height: min(92vh, 900px);
            overflow-y: auto;
            padding: clamp(34px, 4vw, 52px);
            display: block;
            border-radius: 0 8px 8px 0;
            background: rgba(255, 248, 235, 0.96);
            border: 1px solid rgba(20, 16, 13, 0.08);
            color: #14100d;
            box-shadow: 0 34px 90px rgba(0, 0, 0, 0.32);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        .auth-register-card.register-card::before,
        .auth-register-card .register-card-icon,
        .auth-register-card + .register-card-icon {
            display: none;
        }

        .auth-register-card .register-card-header {
            width: min(100%, 620px);
            margin: 0 auto 28px;
            text-align: left;
        }

        .auth-register-card .register-card-header h2 {
            color: #14100d;
            font-family: Georgia, "Times New Roman", serif;
            font-size: clamp(2.1rem, 3.6vw, 2.8rem);
            font-weight: 900;
            line-height: 1.05;
        }

        .auth-register-card .register-card-header p,
        .auth-register-card .form-hint,
        .auth-register-card .login-link,
        .auth-register-card .auth-switch {
            color: rgba(20, 16, 13, 0.62);
        }

        .auth-register-card .registration-form,
        .auth-register-card .community-quote,
        .auth-register-card .verification-notice,
        .auth-register-card .login-link,
        .auth-register-card .register-socials {
            width: min(100%, 620px);
            margin-left: auto;
            margin-right: auto;
        }

        .auth-register-card .community-quote,
        .auth-register-card .verification-notice {
            color: rgba(20, 16, 13, 0.74);
            background: #ffffff;
            border: 1px solid rgba(20, 16, 13, 0.14);
            border-radius: 8px;
        }

        .auth-register-card .community-quote i,
        .auth-register-card .verification-notice i,
        .auth-register-card .input-wrap .field-icon {
            color: rgba(216, 165, 58, 0.82);
        }

        .auth-register-card .field-label {
            color: #14100d;
        }

        .auth-register-card .form-control,
        .auth-register-card .form-select,
        .auth-register-card #chapel_district {
            color: #14100d !important;
            -webkit-text-fill-color: #14100d;
            background: #ffffff;
            border: 1px solid rgba(20, 16, 13, 0.88);
            box-shadow: none;
        }

        .auth-register-card .form-control::placeholder {
            color: rgba(20, 16, 13, 0.42);
            -webkit-text-fill-color: rgba(20, 16, 13, 0.42);
        }

        .auth-register-card .form-control:focus,
        .auth-register-card .form-select:focus,
        .auth-register-card #chapel_district:focus {
            color: #14100d !important;
            -webkit-text-fill-color: #14100d;
            background: #ffffff;
            border-color: rgba(216, 165, 58, 0.84);
            box-shadow: 0 0 0 4px rgba(216, 165, 58, 0.18);
            transform: none;
        }

        .auth-register-card .verification-option,
        .auth-register-card .live-verification,
        .auth-register-card .verification-step,
        .auth-register-card .capture-preview,
        .auth-register-card .face-match-status,
        .auth-register-card .id-ocr-status,
        .auth-register-card .id-side-upload {
            color: #14100d;
            background: #ffffff;
            border: 1px solid rgba(20, 16, 13, 0.16);
            border-radius: 8px;
        }

        .auth-register-card .verification-option span,
        .auth-register-card .capture-preview span,
        .auth-register-card .terms-check {
            color: #14100d;
        }

        .auth-register-card .camera-status {
            color: rgba(20, 16, 13, 0.66);
        }

        .auth-register-card .submit-btn {
            width: min(100%, 620px);
            margin: 6px auto 0;
            min-height: 50px;
            border-radius: 8px;
            color: #14100d;
            background: #c39b2a;
            border: 1px solid #b48c1e;
            box-shadow: none;
        }

        .auth-register-card .submit-btn:hover:not(:disabled),
        .auth-register-card .submit-btn:focus-visible {
            transform: none;
            background: #b98f20;
            box-shadow: 0 12px 28px rgba(184, 143, 32, 0.22);
        }

        .auth-register-card .login-link a {
            color: #b98414;
        }

        .auth-register-card .login-link a:hover {
            color: #14100d;
        }

        .auth-register-card .register-social-btn {
            min-height: 46px;
            color: #14100d;
            background: #ffffff;
            border: 1px solid rgba(20, 16, 13, 0.88);
            border-radius: 8px;
            box-shadow: none;
        }

        @media (max-width: 900px) {
            .auth-register-screen {
                display: grid;
                width: min(100% - 24px, 760px);
                padding: 18px 0;
            }

            .auth-register-screen .auth-login-side,
            .auth-register-card.register-card {
                width: 100%;
                min-height: auto;
                max-height: none;
                border-radius: 8px;
            }

            .auth-register-screen .auth-login-side {
                padding: 28px;
                border-right: 1px solid rgba(246, 217, 139, 0.16);
                gap: 28px;
            }

            .auth-register-card.register-card {
                padding: 34px 24px;
            }
        }

        :root {
            --register-navy: #203238;
            --register-navy-soft: #08739A;
            --register-gold: #149BB5;
            --register-gold-soft: #91C2B9;
            --register-gray: #EEF6F5;
            --register-muted: #52686B;
            --register-danger: #b42318;
            --register-success: #08739A;
            --register-ocean: #08739A;
            --register-link: #149BB5;
            --register-teal: #2AA6AF;
            --register-aqua: #91C2B9;
            --register-stone: #E3E0D8;
            --register-border: #D2D8D3;
            --register-surface: #FFFFFF;
            --register-text: #203238;
        }

        .auth-register-card.register-card {
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.96), rgba(238, 246, 245, 0.9)) !important;
            border: 1px solid var(--register-border) !important;
            color: var(--register-text) !important;
            box-shadow: 0 34px 90px rgba(8, 115, 154, 0.24) !important;
        }

        .auth-register-card.register-card::before {
            border-color: rgba(42, 166, 175, 0.28) !important;
        }

        .auth-register-card .register-card-icon,
        .auth-register-card .input-wrap .field-icon {
            background: rgba(145, 194, 185, 0.34) !important;
            color: var(--register-ocean) !important;
            border-color: var(--register-border) !important;
        }

        .auth-register-card .register-card-header h2 {
            color: var(--register-text) !important;
            font-size: clamp(28px, 3vw, 36px) !important;
            line-height: 1.25 !important;
            font-weight: 700 !important;
        }

        .auth-register-card .register-card-header p,
        .auth-register-card .form-hint,
        .auth-register-card .login-link,
        .auth-register-card .auth-switch,
        .auth-register-card .community-quote,
        .auth-register-card .verification-notice {
            color: var(--register-muted) !important;
            font-size: 16px !important;
            line-height: 1.6 !important;
        }

        .auth-register-card .field-label,
        .auth-register-card .form-label,
        .auth-register-card label {
            color: var(--register-text) !important;
            font-size: 15px !important;
            line-height: 1.45 !important;
            font-weight: 600 !important;
        }

        .auth-register-card .form-control,
        .auth-register-card .form-select,
        .auth-register-card #chapel_district {
            background: #FFFFFF !important;
            border-color: var(--register-border) !important;
            color: var(--register-text) !important;
            font-size: 16px !important;
            line-height: 1.5 !important;
            min-height: 44px !important;
        }

        .auth-register-card .form-control:focus,
        .auth-register-card .form-select:focus,
        .auth-register-card #chapel_district:focus {
            border-color: var(--register-link) !important;
            box-shadow: 0 0 0 4px rgba(20, 155, 181, 0.18) !important;
        }

        .auth-register-card .verification-option,
        .auth-register-card .live-verification,
        .auth-register-card .verification-step,
        .auth-register-card .capture-preview,
        .auth-register-card .face-match-status,
        .auth-register-card .id-ocr-status,
        .auth-register-card .id-side-upload,
        .auth-register-card .community-quote,
        .auth-register-card .verification-notice {
            background: rgba(145, 194, 185, 0.18) !important;
            border-color: var(--register-border) !important;
            color: var(--register-text) !important;
        }

        .auth-register-card .verification-option span,
        .auth-register-card .capture-preview span,
        .auth-register-card .terms-check,
        .auth-register-card .camera-status {
            color: var(--register-text) !important;
            font-size: 15px !important;
            line-height: 1.5 !important;
        }

        .auth-register-card .submit-btn {
            background: var(--register-link) !important;
            border-color: var(--register-link) !important;
            color: #FFFFFF !important;
            min-height: 44px !important;
            font-size: 16px !important;
            font-weight: 600 !important;
        }

        .auth-register-card .submit-btn:hover:not(:disabled),
        .auth-register-card .submit-btn:focus-visible {
            background: var(--register-ocean) !important;
            border-color: var(--register-ocean) !important;
        }

        .auth-register-card .login-link a,
        .auth-register-card .register-social-btn {
            color: var(--register-link) !important;
            font-size: 15px !important;
            font-weight: 600 !important;
        }

        .auth-register-card .register-social-btn {
            background: #FFFFFF !important;
            border-color: var(--register-border) !important;
        }

        /* Final ocean registration pass: UI only, keeps all form behavior intact. */
        body.auth-cinematic-page {
            background:
                radial-gradient(circle at 12% 8%, rgba(145, 194, 185, 0.48), transparent 28%),
                radial-gradient(circle at 86% 20%, rgba(42, 166, 175, 0.2), transparent 30%),
                linear-gradient(180deg, #F7FBFA 0%, #EEF6F5 46%, #DDECE9 100%) !important;
            color: var(--register-text) !important;
            font-family: "Inter", "Segoe UI", Arial, sans-serif !important;
        }

        body.auth-cinematic-page::before,
        body.auth-cinematic-page::after {
            opacity: 0 !important;
            display: none !important;
        }

        .auth-register-screen {
            width: min(1380px, calc(100% - 32px)) !important;
            align-items: stretch !important;
            border-radius: 18px !important;
            overflow: hidden !important;
            background: #FFFFFF !important;
            border: 1px solid var(--register-border) !important;
            box-shadow: 0 28px 70px rgba(8, 115, 154, 0.18) !important;
        }

        .auth-register-screen .auth-login-side {
            background:
                linear-gradient(180deg, rgba(255, 255, 255, 0.12), rgba(255, 255, 255, 0)),
                var(--register-ocean) !important;
            color: #FFFFFF !important;
            border-right: 0 !important;
            box-shadow: none !important;
        }

        .auth-register-screen .auth-login-side *,
        .auth-register-screen .auth-login-side p,
        .auth-register-screen .auth-login-side span,
        .auth-register-screen .auth-login-side strong {
            color: #FFFFFF !important;
        }

        .auth-register-screen .auth-brand-logo {
            background: #FFFFFF !important;
            border: 1px solid rgba(255, 255, 255, 0.42) !important;
            box-shadow: 0 14px 34px rgba(0, 0, 0, 0.14) !important;
        }

        .auth-register-screen .auth-feature-pill {
            background: rgba(255, 255, 255, 0.14) !important;
            border-color: rgba(255, 255, 255, 0.28) !important;
            color: #FFFFFF !important;
            min-height: 44px !important;
            font-size: 14px !important;
        }

        .auth-register-card.register-card {
            width: min(62vw, 780px) !important;
            background:
                radial-gradient(circle at 96% 6%, rgba(145, 194, 185, 0.22), transparent 26%),
                #FFFFFF !important;
            border: 0 !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            color: var(--register-text) !important;
            scrollbar-color: var(--register-aqua) #EEF6F5;
        }

        .auth-register-card .register-card-header h2 {
            font-family: "Inter", "Segoe UI", Arial, sans-serif !important;
            color: var(--register-text) !important;
            letter-spacing: 0 !important;
        }

        .auth-register-card .community-quote,
        .auth-register-card .verification-notice {
            background: rgba(145, 194, 185, 0.16) !important;
            border: 1px solid rgba(8, 115, 154, 0.18) !important;
            color: var(--register-text) !important;
            padding: 14px 16px !important;
        }

        .auth-register-card .community-quote i,
        .auth-register-card .verification-notice i,
        .auth-register-card .field-icon,
        .auth-register-card .input-wrap .field-icon {
            color: var(--register-teal) !important;
        }

        .auth-register-card .verification-options {
            gap: 14px !important;
        }

        .auth-register-card .verification-option {
            background: #FFFFFF !important;
            border: 1px solid var(--register-border) !important;
            color: var(--register-text) !important;
            min-height: 54px !important;
            font-size: 15px !important;
        }

        .auth-register-card .verification-option:hover,
        .auth-register-card .verification-option:focus-within {
            border-color: var(--register-link) !important;
            box-shadow: 0 0 0 4px rgba(20, 155, 181, 0.12) !important;
        }

        .auth-register-card .verification-option input:checked + span,
        .auth-register-card .verification-option:has(input:checked) {
            background: rgba(145, 194, 185, 0.22) !important;
            border-color: var(--register-link) !important;
        }

        .auth-register-card .form-control,
        .auth-register-card .form-select,
        .auth-register-card #chapel_district {
            border-radius: 8px !important;
            border: 1px solid var(--register-border) !important;
            background: #FFFFFF !important;
            color: var(--register-text) !important;
            -webkit-text-fill-color: var(--register-text) !important;
            min-height: 48px !important;
            font-size: 16px !important;
            box-shadow: none !important;
        }

        .auth-register-card .form-control::placeholder {
            color: #667575 !important;
            -webkit-text-fill-color: #667575 !important;
            opacity: 1 !important;
        }

        .auth-register-card .form-control:focus,
        .auth-register-card .form-select:focus,
        .auth-register-card #chapel_district:focus {
            border-color: var(--register-link) !important;
            box-shadow: 0 0 0 4px rgba(20, 155, 181, 0.18) !important;
        }

        .auth-register-card .live-verification,
        .auth-register-card .verification-step,
        .auth-register-card .capture-preview,
        .auth-register-card .face-match-status,
        .auth-register-card .id-ocr-status,
        .auth-register-card .id-side-upload {
            background: rgba(238, 246, 245, 0.82) !important;
            border: 1px solid var(--register-border) !important;
            color: var(--register-text) !important;
        }

        .auth-register-card .verification-step.is-current,
        .auth-register-card .verification-step.is-done {
            border-color: var(--register-link) !important;
            background: rgba(145, 194, 185, 0.26) !important;
        }

        .auth-register-card .camera-btn,
        .auth-register-card .submit-btn {
            background: var(--register-link) !important;
            border: 1px solid var(--register-link) !important;
            color: #FFFFFF !important;
            border-radius: 999px !important;
            min-height: 48px !important;
            font-size: 16px !important;
            font-weight: 600 !important;
            box-shadow: 0 12px 28px rgba(20, 155, 181, 0.22) !important;
        }

        .auth-register-card .camera-btn.secondary,
        .auth-register-card .register-social-btn {
            background: #FFFFFF !important;
            border: 1px solid var(--register-border) !important;
            color: var(--register-ocean) !important;
            box-shadow: none !important;
        }

        .auth-register-card .camera-btn:hover:not(:disabled),
        .auth-register-card .submit-btn:hover:not(:disabled),
        .auth-register-card .submit-btn:focus-visible {
            background: var(--register-ocean) !important;
            border-color: var(--register-ocean) !important;
            transform: translateY(-1px) !important;
        }

        .auth-register-card .login-link a {
            color: var(--register-link) !important;
            text-decoration: none !important;
        }

        .auth-register-card .login-link a:hover {
            color: var(--register-ocean) !important;
            text-decoration: underline !important;
        }

        .auth-toast {
            background: #FFFFFF !important;
            border: 1px solid var(--register-border) !important;
            color: var(--register-text) !important;
            box-shadow: 0 18px 44px rgba(8, 115, 154, 0.18) !important;
        }

        @media (max-width: 900px) {
            .auth-register-screen {
                display: grid !important;
                width: min(100% - 24px, 760px) !important;
                overflow: visible !important;
                border-radius: 18px !important;
            }

            .auth-register-screen .auth-login-side,
            .auth-register-card.register-card {
                width: 100% !important;
                border-radius: 0 !important;
            }

            .auth-register-screen .auth-login-side {
                min-height: auto !important;
            }
        }

        /* Warm cream/gold registration restoration. */
        :root {
            --register-navy: #1C1B18;
            --register-navy-soft: #27231D;
            --register-gold: #D4A94E;
            --register-gold-soft: #F6DF9F;
            --register-gray: #FAF6EE;
            --register-muted: #6F675A;
            --register-success: #2F6D3B;
            --register-ocean: #1C1B18;
            --register-link: #B88A22;
            --register-teal: #D4A94E;
            --register-aqua: #F6DF9F;
            --register-stone: #DFCFAA;
            --register-border: #DFCFAA;
            --register-surface: #FFFFFF;
            --register-text: #1C1B18;
        }

        body.auth-cinematic-page {
            background:
                linear-gradient(90deg, rgba(15, 10, 6, 0.62) 0%, rgba(15, 10, 6, 0.24) 44%, rgba(8, 11, 18, 0.78) 100%),
                linear-gradient(180deg, rgba(0, 0, 0, 0.22), rgba(0, 0, 0, 0.62)),
                url("../church%20image.png") center center / cover no-repeat fixed !important;
            color: var(--register-text) !important;
        }

        body.auth-cinematic-page::before {
            display: block !important;
            opacity: 1 !important;
            background-image: url("../church%20image.png") !important;
            filter: sepia(0.22) saturate(1.18) contrast(1.05) brightness(0.82) !important;
        }

        body.auth-cinematic-page::after {
            display: block !important;
            opacity: 1 !important;
            background:
                radial-gradient(circle at 39% 30%, rgba(255, 214, 126, 0.22), transparent 25%),
                radial-gradient(circle at 50% 70%, rgba(255, 184, 64, 0.16), transparent 24%),
                linear-gradient(90deg, rgba(15, 10, 6, 0.62) 0%, rgba(15, 10, 6, 0.24) 44%, rgba(8, 11, 18, 0.78) 100%) !important;
        }

        .auth-register-screen {
            background: #FFF8EB !important;
            border: 1px solid rgba(255, 248, 235, 0.68) !important;
            box-shadow: 0 34px 90px rgba(0, 0, 0, 0.32) !important;
        }

        .auth-register-screen .auth-login-side {
            background:
                radial-gradient(circle at 88% 12%, rgba(212, 169, 78, 0.2), transparent 28%),
                linear-gradient(135deg, rgba(28, 27, 24, 0.96), rgba(39, 35, 29, 0.94)) !important;
        }

        .auth-register-card.register-card {
            background:
                radial-gradient(circle at 96% 8%, rgba(246, 223, 159, 0.28), transparent 28%),
                #FFF8EB !important;
            color: var(--register-text) !important;
        }

        .auth-register-card .community-quote,
        .auth-register-card .verification-notice,
        .auth-register-card .verification-option,
        .auth-register-card .live-verification,
        .auth-register-card .verification-step,
        .auth-register-card .capture-preview,
        .auth-register-card .face-match-status,
        .auth-register-card .id-ocr-status,
        .auth-register-card .id-side-upload {
            background: rgba(246, 223, 159, 0.22) !important;
            border-color: var(--register-border) !important;
            color: var(--register-text) !important;
        }

        .auth-register-card .form-control,
        .auth-register-card .form-select,
        .auth-register-card #chapel_district {
            background: #FFFFFF !important;
            border-color: var(--register-border) !important;
            color: var(--register-text) !important;
        }

        .auth-register-card .form-control:focus,
        .auth-register-card .form-select:focus,
        .auth-register-card #chapel_district:focus {
            border-color: var(--register-gold) !important;
            box-shadow: 0 0 0 4px rgba(212, 169, 78, 0.18) !important;
        }

        .auth-register-card .register-card-icon,
        .auth-register-card .input-wrap .field-icon,
        .auth-register-card .community-quote i,
        .auth-register-card .verification-notice i {
            color: var(--register-link) !important;
        }

        .auth-register-card .camera-btn,
        .auth-register-card .submit-btn {
            background: linear-gradient(135deg, var(--register-gold), #B88A22) !important;
            border-color: #B88A22 !important;
            color: var(--register-text) !important;
        }

        .auth-register-card .camera-btn *,
        .auth-register-card .submit-btn * {
            color: var(--register-text) !important;
        }

        .auth-register-card .camera-btn.secondary,
        .auth-register-card .register-social-btn {
            background: #FFFFFF !important;
            border-color: var(--register-border) !important;
            color: var(--register-link) !important;
        }

        .auth-register-card .login-link a {
            color: var(--register-link) !important;
        }

        /* --- GLOBAL INPUT & PREFIX ICON ALIGNMENT STANDARDIZATION --- */
        .input-wrap,
        .auth-register-card .input-wrap {
            position: relative !important;
            display: block !important;
            width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            box-sizing: border-box !important;
        }

        .input-wrap .field-icon,
        .input-wrap > i:first-child,
        .auth-register-card .input-wrap .field-icon,
        .auth-register-card .field-icon {
            position: absolute !important;
            left: 14px !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
            width: 20px !important;
            height: 20px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            text-align: center !important;
            pointer-events: none !important;
            z-index: 5 !important;
            font-size: 15px !important;
            margin: 0 !important;
            padding: 0 !important;
            border: none !important;
            background: transparent !important;
        }

        .register-card .form-control,
        .register-card .form-select,
        .auth-register-card .form-control,
        .auth-register-card .form-select,
        .auth-register-card #chapel_district,
        .input-wrap .form-control,
        .input-wrap .form-select {
            display: block !important;
            width: 100% !important;
            min-height: 48px !important;
            padding: 10px 16px 10px 44px !important; /* CRITICAL: Clears prefix icon cleanly */
            box-sizing: border-box !important;
            line-height: 1.5 !important;
        }

        .register-card .form-select,
        .auth-register-card .form-select,
        .auth-register-card #chapel_district,
        .input-wrap .form-select {
            padding-right: 40px !important;
            background-position: right 14px center !important;
        }

        .input-wrap.password .form-control,
        .auth-register-card .input-wrap.password .form-control {
            padding-right: 48px !important;
        }

        .password-toggle,
        .auth-register-card .password-toggle,
        .input-wrap .password-toggle {
            position: absolute !important;
            right: 8px !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
            width: 38px !important;
            height: 38px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            background: transparent !important;
            border: none !important;
            border-radius: 8px !important;
            color: #77736b !important;
            cursor: pointer !important;
            z-index: 6 !important;
            transition: color 0.18s ease, background 0.18s ease !important;
        }

        .password-toggle:hover,
        .auth-register-card .password-toggle:hover,
        .input-wrap .password-toggle:hover {
            color: #a97f24 !important;
            background: rgba(200, 155, 60, 0.12) !important;
        }

        .password-toggle:focus-visible,
        .auth-register-card .password-toggle:focus-visible,
        .input-wrap .password-toggle:focus-visible {
            outline: 2px solid #c89b3c !important;
            outline-offset: 2px !important;
        }

        .password-toggle i,
        .auth-register-card .password-toggle i,
        .input-wrap .password-toggle i {
            position: static !important;
            width: auto !important;
            height: auto !important;
            color: inherit !important;
            transform: none !important;
            pointer-events: none !important;
            font-size: 15px !important;
        }

        /* --- FORM GRID & FIELD ALIGNMENT --- */
        .form-grid,
        .auth-register-card .form-grid {
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 14px !important;
            align-items: start !important;
        }

        @media (max-width: 680px) {
            .form-grid,
            .auth-register-card .form-grid {
                grid-template-columns: 1fr !important;
            }
        }

        /* --- VERIFICATION METHOD RADIO SELECTORS --- */
        .verification-options,
        .auth-register-card .verification-options {
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 12px !important;
            width: 100% !important;
        }

        @media (max-width: 680px) {
            .verification-options,
            .auth-register-card .verification-options {
                grid-template-columns: 1fr !important;
            }
        }

        .verification-option,
        .auth-register-card .verification-option {
            display: flex !important;
            flex-direction: row !important;
            align-items: center !important;
            gap: 12px !important;
            min-height: 52px !important;
            padding: 10px 16px !important;
            border-radius: 10px !important;
            cursor: pointer !important;
            box-sizing: border-box !important;
        }

        .verification-option input[type="radio"],
        .auth-register-card .verification-option input[type="radio"] {
            width: 18px !important;
            height: 18px !important;
            flex: 0 0 18px !important;
            margin: 0 !important;
            cursor: pointer !important;
        }

        .verification-option span,
        .auth-register-card .verification-option span {
            display: inline-flex !important;
            align-items: center !important;
            gap: 10px !important;
            font-weight: 600 !important;
            font-size: 0.92rem !important;
            line-height: 1.3 !important;
            min-width: 0 !important;
            flex: 1 1 auto !important;
        }

        .verification-option span i,
        .auth-register-card .verification-option span i {
            font-size: 1.05rem !important;
            flex: 0 0 auto !important;
        }

        /* ===== 3-TIER REGISTRATION STEP LAYOUT ===== */
        .registration-form {
            display: grid !important;
            gap: 0 !important;
        }

        .reg-step {
            position: relative;
            display: grid;
            gap: 14px;
            padding: 20px;
            border-radius: 14px;
            border: 1px solid var(--register-border);
            background: rgba(246, 223, 159, 0.06);
            margin-bottom: 0;
            transition: border-color 0.3s ease, background 0.3s ease;
        }

        .auth-register-card .reg-step {
            background: rgba(246, 223, 159, 0.07) !important;
            border-color: var(--register-border) !important;
        }

        .reg-step.step-complete {
            border-color: rgba(34, 197, 94, 0.38) !important;
            background: rgba(34, 197, 94, 0.04) !important;
        }

        .reg-step-header {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .reg-step-badge-num {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            min-width: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--register-gold-soft), var(--register-gold));
            color: #1C1B18;
            font-weight: 900;
            font-size: 1rem;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(212, 169, 78, 0.26);
            transition: background 0.3s ease, box-shadow 0.3s ease;
        }

        .reg-step.step-complete .reg-step-badge-num {
            background: linear-gradient(135deg, #86efac, #22c55e);
            color: #14532d;
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.24);
        }

        .reg-step-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
            flex: 1;
            min-width: 0;
        }

        .reg-step-info strong {
            font-weight: 800;
            font-size: 0.98rem;
            color: var(--register-text);
            display: block;
        }

        .reg-step-info span {
            font-size: 0.81rem;
            color: var(--register-muted);
            line-height: 1.4;
            display: block;
        }

        .reg-step-status {
            flex-shrink: 0;
            margin-left: auto;
        }

        .step-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 11px;
            border-radius: 999px;
            font-size: 0.73rem;
            font-weight: 800;
            letter-spacing: 0.02em;
            white-space: nowrap;
            border: 1px solid transparent;
        }

        .step-pill.pending {
            background: rgba(246, 223, 159, 0.26);
            color: #6F675A;
            border-color: var(--register-border);
        }

        .step-pill.scanning {
            background: rgba(251, 191, 36, 0.16);
            color: #92400e;
            border-color: rgba(251, 191, 36, 0.32);
            animation: pill-pulse 1.6s ease infinite;
        }

        .step-pill.done {
            background: rgba(34, 197, 94, 0.13);
            color: #15803d;
            border-color: rgba(34, 197, 94, 0.28);
        }

        .step-pill.error {
            background: rgba(239, 68, 68, 0.11);
            color: #b91c1c;
            border-color: rgba(239, 68, 68, 0.26);
        }

        @keyframes pill-pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }

        .reg-step-divider {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 22px;
            position: relative;
            margin: 0;
        }

        .reg-step-divider::before {
            content: "";
            position: absolute;
            left: 38px;
            top: 0;
            width: 2px;
            height: 100%;
            background: linear-gradient(180deg, var(--register-border) 60%, transparent);
        }

        .reg-fields-lock-banner {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 10px;
            background: rgba(246, 223, 159, 0.16);
            border: 1px dashed rgba(212, 169, 78, 0.4);
            color: var(--register-muted);
            font-size: 0.84rem;
            font-weight: 700;
            transition: background 0.4s ease, border-color 0.4s ease, color 0.4s ease;
        }

        .reg-fields-lock-banner i {
            color: var(--register-gold);
            flex-shrink: 0;
            font-size: 1rem;
        }

        .reg-fields-lock-banner.is-unlocked {
            background: rgba(34, 197, 94, 0.08);
            border-color: rgba(34, 197, 94, 0.3);
            border-style: solid;
            color: #15803d;
        }

        .reg-fields-lock-banner.is-unlocked i {
            color: #22c55e;
        }

        .form-control.ocr-autofilled {
            border-color: rgba(34, 197, 94, 0.52) !important;
            background: rgba(34, 197, 94, 0.04) !important;
        }

        /* ===== SCROLLABLE TERMS BOX ===== */
        .terms-scroll-box {
            height: 260px;
            overflow-y: auto;
            padding: 18px 20px;
            border-radius: 10px;
            border: 1px solid var(--register-border);
            background: #FFFFFF;
            color: var(--register-text);
            font-size: 0.85rem;
            line-height: 1.65;
            scroll-behavior: smooth;
            scrollbar-width: thin;
            scrollbar-color: var(--register-gold) #f5f0e8;
        }

        .terms-scroll-box::-webkit-scrollbar {
            width: 6px;
        }

        .terms-scroll-box::-webkit-scrollbar-track {
            background: #f5f0e8;
            border-radius: 3px;
        }

        .terms-scroll-box::-webkit-scrollbar-thumb {
            background: var(--register-gold);
            border-radius: 3px;
        }

        .terms-scroll-box h4 {
            font-size: 0.95rem;
            font-weight: 800;
            margin: 0 0 4px;
            color: var(--register-text);
        }

        .terms-scroll-box h5 {
            font-size: 0.84rem;
            font-weight: 700;
            margin: 0 0 14px;
            color: var(--register-muted);
            border-bottom: 1px solid var(--register-border);
            padding-bottom: 10px;
        }

        .terms-scroll-box h6 {
            font-size: 0.84rem;
            font-weight: 800;
            margin: 14px 0 4px;
            color: var(--register-text);
        }

        .terms-scroll-box p, .terms-scroll-box ul {
            margin: 0 0 10px;
            color: rgba(28, 27, 24, 0.82);
        }

        .terms-scroll-box ul {
            padding-left: 18px;
        }

        .terms-scroll-box ul li {
            margin-bottom: 4px;
        }

        .terms-scroll-end-marker {
            height: 1px;
        }

        .terms-scroll-notice {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 10px 14px;
            border-radius: 10px;
            background: rgba(251, 191, 36, 0.12);
            border: 1px solid rgba(251, 191, 36, 0.32);
            color: #92400e;
            font-size: 0.83rem;
            font-weight: 700;
            transition: opacity 0.4s ease, height 0.4s ease;
        }

        .terms-scroll-notice i {
            color: #d97706;
            animation: bounce-down 1.4s ease infinite;
            flex-shrink: 0;
        }

        .terms-scroll-notice.is-done {
            background: rgba(34, 197, 94, 0.08);
            border-color: rgba(34, 197, 94, 0.3);
            color: #15803d;
        }

        .terms-scroll-notice.is-done i {
            animation: none;
            color: #22c55e;
        }

        @keyframes bounce-down {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(4px); }
        }

        /* Terms checkbox disabled state */
        #terms_check:disabled + span {
            opacity: 0.52;
            cursor: not-allowed;
        }

        label.terms-check:has(#terms_check:disabled) {
            cursor: not-allowed;
        }

        /* Submit gate notice */
        .submit-gate-notice {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 10px 14px;
            border-radius: 10px;
            background: rgba(246, 223, 159, 0.16);
            border: 1px dashed rgba(212, 169, 78, 0.4);
            color: var(--register-muted);
            font-size: 0.83rem;
            font-weight: 700;
            transition: all 0.35s ease;
        }

        .submit-gate-notice i {
            color: var(--register-gold);
            flex-shrink: 0;
        }

        .submit-gate-notice.is-ready {
            background: rgba(34, 197, 94, 0.08);
            border-color: rgba(34, 197, 94, 0.3);
            border-style: solid;
            color: #15803d;
        }

        .submit-gate-notice.is-ready i {
            color: #22c55e;
        }

        .auth-register-card .terms-scroll-box {
            background: #FFFFFF !important;
            border-color: var(--register-border) !important;
            color: var(--register-text) !important;
        }

        @media (max-width: 640px) {
            .reg-step { padding: 14px 12px; gap: 12px; }
            .reg-step-header { gap: 10px; }
            .reg-step-badge-num { width: 32px; height: 32px; min-width: 32px; font-size: 0.9rem; }
            .reg-step-divider::before { left: 30px; }
            .step-pill { font-size: 0.68rem; padding: 3px 9px; }
            .reg-step-info strong { font-size: 0.9rem; }
            .terms-scroll-box { height: 210px; }
        }

        /* ═════════════════════════════════════════════════════════════
           ID SCANNER & CAMERA VERIFICATION COMPONENT
        ═════════════════════════════════════════════════════════════ */
        .id-scanner-wrap {
            display: flex;
            flex-direction: column;
            gap: 12px;
            width: 100%;
        }

        /* 1. Camera Stage */
        .id-camera-stage {
            position: relative;
            width: 100%;
            aspect-ratio: 16 / 9.6;
            min-height: 250px;
            max-height: 380px;
            background: #0d121c;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid rgba(212, 169, 78, 0.28);
            box-shadow: inset 0 2px 12px rgba(0, 0, 0, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .id-camera-stage video {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: none;
            z-index: 1;
        }

        .id-camera-stage.is-active video {
            display: block;
        }

        /* Mirror video for face verification */
        .id-camera-stage.is-face-mode video {
            transform: scaleX(-1);
        }

        /* Non-mirror for ID scanning so text isn't flipped */
        .id-camera-stage.is-id-mode video {
            transform: none;
        }

        /* Placeholder State */
        .id-camera-placeholder {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px 20px;
            text-align: center;
            background: linear-gradient(180deg, #131b2e 0%, #0a0e17 100%);
            z-index: 2;
        }

        .id-camera-stage.is-active .id-camera-placeholder {
            display: none !important;
        }

        .id-placeholder-icon {
            font-size: 34px;
            color: #D4A94E;
            margin-bottom: 10px;
        }

        .id-placeholder-title {
            color: #ffffff;
            font-size: 15.5px;
            font-weight: 700;
            margin: 0 0 6px 0;
            letter-spacing: -0.2px;
        }

        .id-placeholder-desc {
            color: rgba(255, 255, 255, 0.7);
            font-size: 12px;
            max-width: 440px;
            margin: 0;
            line-height: 1.45;
        }

        .id-camera-error-msg {
            color: #fca5a5;
            background: rgba(239, 68, 68, 0.16);
            border: 1px solid rgba(239, 68, 68, 0.35);
            border-radius: 8px;
            padding: 8px 14px;
            font-size: 12px;
            margin-top: 12px;
            max-width: 460px;
            line-height: 1.4;
        }

        /* Guide Overlays */
        .id-face-guide {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            z-index: 3;
        }

        .id-face-oval {
            width: min(44%, 220px);
            height: min(76%, 250px);
            border-radius: 50%;
            border: 3px solid rgba(212, 169, 78, 0.95);
            box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.38);
        }

        .id-card-guide {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            z-index: 3;
        }

        .id-card-frame {
            width: min(84%, 460px);
            aspect-ratio: 1.58;
            border-radius: 12px;
            border: 2.5px solid rgba(212, 169, 78, 0.92);
            box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.42);
            position: relative;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            padding-bottom: 8px;
        }

        .id-card-guide-label {
            background: rgba(0, 0, 0, 0.72);
            color: #ffffff;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 12px;
            border-radius: 20px;
            letter-spacing: 0.3px;
        }

        .id-corner {
            position: absolute;
            width: 18px;
            height: 18px;
            border-color: #ffd875;
            border-style: solid;
        }
        .id-corner-tl { top: -2px; left: -2px; border-width: 3px 0 0 3px; border-radius: 8px 0 0 0; }
        .id-corner-tr { top: -2px; right: -2px; border-width: 3px 3px 0 0; border-radius: 0 8px 0 0; }
        .id-corner-bl { bottom: -2px; left: -2px; border-width: 0 0 3px 3px; border-radius: 0 0 0 8px; }
        .id-corner-br { bottom: -2px; right: -2px; border-width: 0 3px 3px 0; border-radius: 0 0 8px 0; }

        /* 2. Three Tabs */
        .id-scanner-tabs {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }

        .id-scanner-tab {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 10px 10px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            background: #F4EFE6;
            border: 1px solid #E2D9C8;
            color: #4A4235;
            transition: all 0.18s ease;
        }

        .id-scanner-tab:hover {
            background: #ECE5D8;
            border-color: #D4C7B0;
        }

        .id-scanner-tab.active {
            background: #E8EFE0;
            border: 1.5px solid #9FB890;
            color: #24381C;
            font-weight: 800;
            box-shadow: 0 2px 6px rgba(159, 184, 144, 0.25);
        }

        /* 3. Action Buttons */
        .id-action-buttons {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }

        .id-act-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 11px 10px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            transition: all 0.18s ease;
            text-decoration: none;
        }

        .id-act-btn.primary {
            background: linear-gradient(135deg, #CCA34B, #B58A35);
            color: #1E1B18;
            box-shadow: 0 3px 10px rgba(181, 138, 53, 0.28);
        }

        .id-act-btn.primary:hover:not(:disabled) {
            background: linear-gradient(135deg, #D9B159, #C1943D);
            transform: translateY(-1px);
        }

        .id-act-btn.secondary {
            background: #FDFCF9;
            border: 1px solid #EAE3D5;
            color: #A89F91;
            cursor: not-allowed;
        }

        .id-act-btn.secondary.is-highlighted {
            background: linear-gradient(135deg, #CCA34B, #B58A35);
            border: none;
            color: #1E1B18;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 3px 10px rgba(181, 138, 53, 0.28);
        }

        .id-act-btn.secondary.is-highlighted:hover {
            background: linear-gradient(135deg, #D9B159, #C1943D);
            transform: translateY(-1px);
        }

        .id-act-btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
            pointer-events: none;
        }

        /* 4. Upload Fallback Row (Front / Back only) */
        .id-upload-fallback-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }

        .id-fallback-upload-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            background: #FDFCF9;
            border: 1px solid #E2D9C8;
            color: #4A4235;
            margin: 0;
            transition: all 0.18s ease;
        }

        .id-fallback-upload-btn:hover {
            background: #F5EFE4;
            border-color: #CCA34B;
            color: #8A6822;
            transform: translateY(-1px);
        }

        .id-fallback-upload-btn.is-disabled {
            opacity: 0.45;
            cursor: not-allowed;
            pointer-events: none;
        }

        /* Instruction Line */
        .id-instruction-line {
            margin: 4px 0 2px;
            font-size: 13px;
            font-weight: 700;
            color: #3A3226;
            text-align: left;
        }

        /* 5. Three Preview Thumbnails */
        .id-preview-thumbnails {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }

        .id-thumb-card {
            background: #FBF8F0;
            border: 1px solid #EAE2D2;
            border-radius: 10px;
            padding: 10px 10px 8px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .id-thumb-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-height: 20px;
        }

        .id-thumb-title {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.6px;
            color: #4A4235;
            text-transform: uppercase;
        }

        .id-thumb-retake {
            background: rgba(220, 38, 38, 0.12);
            color: #b91c1c;
            border: 1px solid rgba(220, 38, 38, 0.28);
            border-radius: 5px;
            font-size: 10.5px;
            font-weight: 700;
            padding: 2px 7px;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .id-thumb-retake:hover {
            background: rgba(220, 38, 38, 0.22);
        }

        .id-thumb-preview {
            width: 100%;
            height: 76px;
            border-radius: 6px;
            overflow: hidden;
            background: #ECE5D8;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .id-thumb-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: none;
        }

        .id-thumb-placeholder {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            color: #8F8473;
            text-align: center;
            padding: 6px;
            font-size: 11px;
            font-weight: 500;
            line-height: 1.3;
        }

        .id-thumb-placeholder i {
            font-size: 16px;
            opacity: 0.7;
        }

        /* 6. Two Helper Text Lines */
        .id-helper-lines {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 4px;
        }

        .id-helper-line {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 10px;
            background: #F6F1E7;
            border: 1px solid #EAE1D1;
            font-size: 12.5px;
            font-weight: 600;
            color: #4A4235;
            line-height: 1.4;
        }

        .id-helper-line i {
            font-size: 14px;
            color: #8F8473;
            flex-shrink: 0;
        }

        /* Manual Entry Button */
        .id-manual-btn {
            background: transparent;
            border: 1px solid #D4C9B6;
            color: #6E6352;
            border-radius: 8px;
            padding: 8px 18px;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.18s ease;
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        .id-manual-btn:hover {
            border-color: #CCA34B;
            color: #3A3226;
            background: rgba(204, 163, 75, 0.08);
        }

        /* Mobile adjustments for ID Scanner */
        @media (max-width: 600px) {
            .id-scanner-tab {
                padding: 9px 4px;
                font-size: 11.5px;
                gap: 5px;
            }
            .id-act-btn {
                padding: 10px 4px;
                font-size: 11.5px;
                gap: 5px;
            }
            .id-fallback-upload-btn {
                padding: 9px 8px;
                font-size: 12px;
            }
            .id-thumb-title {
                font-size: 10px;
            }
            .id-thumb-placeholder {
                font-size: 10px;
            }
            .id-thumb-placeholder span {
                font-size: 9.5px;
            }
            .id-helper-line {
                font-size: 11.5px;
                padding: 8px 10px;
            }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/auth-mobile.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/auth-mobile.css'); ?>">
</head>
<body class="auth-cinematic-page">
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

    <div class="auth-ambient" aria-hidden="true"></div>
    <main class="auth-screen auth-login-screen auth-register-screen">
        <aside class="auth-copy auth-login-side" aria-label="System introduction">
            <a href="../index.php" class="auth-side-brand" aria-label="Back to TUGON homepage">
                <span class="auth-side-logo">
                    <?php if ($has_logo): ?>
                        <img src="../assets/img/san-lorenzo-logo.png" alt="San Lorenzo Ruiz logo">
                    <?php else: ?>
                        <i class="fas fa-church"></i>
                    <?php endif; ?>
                </span>
                <span>
                    <strong>San Lorenzo Ruiz</strong>
                    <small>Mission Station</small>
                </span>
            </a>
            <blockquote>"Where faith, community, and service meet in harmony."</blockquote>
            <p>Register for TUGON to access parish requests, sacramental records, reservations, and announcements.</p>
            <div class="auth-copy-list" aria-label="Platform features">
                <span><i class="fas fa-check"></i> Secure identity verification</span>
                <span><i class="fas fa-check"></i> Parishioner services</span>
                <span><i class="fas fa-check"></i> Registration review</span>
            </div>
        </aside>

        <section class="auth-glass-card auth-login-card register-card auth-register-card" aria-label="Registration form">
            <a href="login.php" class="mobile-auth-back"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back</a>
            <div class="register-card-header">
                <a href="../index.php" class="register-card-icon" aria-label="Back to Parish System homepage">
                    <?php if ($has_logo): ?>
                        <img src="../assets/img/san-lorenzo-logo.png" alt="San Lorenzo Ruiz logo">
                    <?php else: ?>
                        <i class="fas fa-church"></i>
                    <?php endif; ?>
                </a>
                <h2><span class="desktop-auth-only">Create Your Parish Account</span><span class="mobile-auth-only">Create an Account</span></h2>
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

                <!-- ═══════════════════════════════════════════
                     STEP 1 — Identity Verification & ID Scan
                ═══════════════════════════════════════════ -->
                <div class="reg-step" id="regStep1">
                    <div class="reg-step-header">
                        <div class="reg-step-badge-num" id="step1Num">1</div>
                        <div class="reg-step-info">
                            <strong><i class="fas fa-id-card" style="margin-right:5px;opacity:.7"></i>Identity Verification &amp; ID Scan</strong>
                            <span>Upload your Face Photo, Front ID, and Back ID — OCR will auto-fill your details.</span>
                        </div>
                        <div class="reg-step-status">
                            <span class="step-pill pending" id="step1Pill">Pending</span>
                        </div>
                    </div>

                    <!-- Privacy consent -->
                    <div class="id-scan-consent-wrap" id="idScanConsentWrap">
                        <label class="id-scan-consent-label">
                            <input type="checkbox" id="idScanConsent">
                            <span>I consent to having my ID photo scanned by OCR for the purpose of auto-filling my registration details. I understand no ID image is permanently stored.</span>
                        </label>
                    </div>

                    <!-- Guidelines -->
                    <div class="id-scan-guidelines" id="idScanGuidelines">
                        <strong><i class="fas fa-circle-info"></i> Tips for Accurate ID Scanning</strong>
                        <ul>
                            <li>Use a well-lit environment — avoid glare and shadows on the ID.</li>
                            <li>Keep the ID flat and fully visible — no fingers covering text.</li>
                            <li>JPG, PNG, or WEBP only. Maximum 8 MB per image.</li>
                            <li>The ID must be a Philippine government-issued ID (PhilSys, UMID, Driver's License, etc.)</li>
                        </ul>
                    </div>

                    <!-- ID Verification & Scanner Container -->
                    <div class="id-scanner-wrap" id="idScannerWrap">

                        <!-- 1. CAMERA PREVIEW BOX -->
                        <div class="id-camera-stage" id="idCameraStage">
                            <video id="verificationVideo" autoplay playsinline muted></video>
                            <canvas id="captureCanvas" style="display:none"></canvas>

                            <!-- Guide overlays -->
                            <div class="id-face-guide" id="idFaceGuide" aria-hidden="true">
                                <div class="id-face-oval"></div>
                            </div>
                            <div class="id-card-guide" id="idCardGuide" aria-hidden="true" style="display:none">
                                <div class="id-card-frame">
                                    <div class="id-corner id-corner-tl"></div>
                                    <div class="id-corner id-corner-tr"></div>
                                    <div class="id-corner id-corner-bl"></div>
                                    <div class="id-corner id-corner-br"></div>
                                    <span class="id-card-guide-label" id="idCardGuideLabel">Position Front ID inside frame</span>
                                </div>
                            </div>

                            <!-- Placeholder State -->
                            <div class="id-camera-placeholder" id="idCameraPlaceholder">
                                <div class="id-placeholder-icon">
                                    <i class="fas fa-camera"></i>
                                </div>
                                <h4 class="id-placeholder-title">Camera verification required</h4>
                                <p class="id-placeholder-desc">No gallery uploads are allowed. You must capture your live face and valid ID using the device camera.</p>
                                <div class="id-camera-error-msg" id="idCameraErrorMsg" style="display:none"></div>
                            </div>
                        </div>

                        <!-- 2. THREE TABS -->
                        <div class="id-scanner-tabs" id="idScannerTabs" role="tablist">
                            <button type="button" class="id-scanner-tab active" id="tabFace" data-tab="face" role="tab" aria-selected="true">
                                <i class="fas fa-user"></i> <span>Face verification</span>
                            </button>
                            <button type="button" class="id-scanner-tab" id="tabFront" data-tab="front" role="tab" aria-selected="false">
                                <i class="fas fa-id-card"></i> <span>Front ID</span>
                            </button>
                            <button type="button" class="id-scanner-tab" id="tabBack" data-tab="back" role="tab" aria-selected="false">
                                <i class="fas fa-id-card"></i> <span>Back ID</span>
                            </button>
                        </div>

                        <!-- 3. ACTION BUTTONS -->
                        <div class="id-action-buttons" id="idActionButtons">
                            <button type="button" class="id-act-btn primary" id="startCameraBtn" disabled>
                                <i class="fas fa-video" id="startCameraIcon"></i>
                                <span id="startCameraText">Start Camera</span>
                            </button>
                            <button type="button" class="id-act-btn secondary" id="captureFrontBtn" disabled>
                                <i class="fas fa-camera"></i>
                                <span>Capture Front</span>
                            </button>
                            <button type="button" class="id-act-btn secondary" id="captureBackBtn" disabled>
                                <i class="fas fa-camera"></i>
                                <span>Capture Back</span>
                            </button>
                        </div>

                        <!-- 4. UPLOAD FALLBACK BUTTONS (Front/Back ID only, NO Face upload) -->
                        <div class="id-upload-fallback-row" id="idUploadFallbackRow">
                            <label class="id-fallback-upload-btn is-disabled" for="frontIdUploadFile" id="frontIdUploadLabel">
                                <i class="fas fa-upload"></i>
                                <span>Upload Front ID</span>
                                <input type="file" id="frontIdUploadFile" accept="image/jpeg,image/png,image/webp" disabled style="display:none">
                            </label>
                            <label class="id-fallback-upload-btn is-disabled" for="backIdUploadFile" id="backIdUploadLabel">
                                <i class="fas fa-upload"></i>
                                <span>Upload Back ID</span>
                                <input type="file" id="backIdUploadFile" accept="image/jpeg,image/png,image/webp" disabled style="display:none">
                            </label>
                        </div>

                        <!-- Instruction line -->
                        <div class="id-instruction-line" id="idInstructionLine">
                            <span id="idInstructionText">Start the camera and position your face inside the guide.</span>
                        </div>

                        <!-- 5. THREE PREVIEW THUMBNAILS -->
                        <div class="id-preview-thumbnails" id="idPreviewThumbnails">
                            <!-- Live Face Card -->
                            <div class="id-thumb-card" id="faceThumbCard">
                                <div class="id-thumb-header">
                                    <span class="id-thumb-title">LIVE FACE</span>
                                    <button type="button" class="id-thumb-retake" id="retakeFaceBtn" style="display:none" title="Retake live face">Retake</button>
                                </div>
                                <div class="id-thumb-preview" id="faceThumbPreview">
                                    <img id="facePreviewImage" alt="Captured live face preview" style="display:none">
                                    <div class="id-thumb-placeholder" id="faceThumbPlaceholder">
                                        <i class="fas fa-user-circle"></i>
                                        <span>Captured live face preview</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Front ID Card -->
                            <div class="id-thumb-card" id="frontThumbCard">
                                <div class="id-thumb-header">
                                    <span class="id-thumb-title">FRONT ID</span>
                                    <button type="button" class="id-thumb-retake" id="retakeFrontBtn" style="display:none" title="Retake front ID">Retake</button>
                                </div>
                                <div class="id-thumb-preview" id="frontThumbPreview">
                                    <img id="idFrontPreviewImage" alt="Captured front ID preview" style="display:none">
                                    <div class="id-thumb-placeholder" id="frontThumbPlaceholder">
                                        <i class="fas fa-id-card"></i>
                                        <span>Captured front ID preview</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Back ID Card -->
                            <div class="id-thumb-card" id="backThumbCard">
                                <div class="id-thumb-header">
                                    <span class="id-thumb-title">BACK ID</span>
                                    <button type="button" class="id-thumb-retake" id="retakeBackBtn" style="display:none" title="Retake back ID">Retake</button>
                                </div>
                                <div class="id-thumb-preview" id="backThumbPreview">
                                    <img id="idBackPreviewImage" alt="Captured back ID preview" style="display:none">
                                    <div class="id-thumb-placeholder" id="backThumbPlaceholder">
                                        <i class="fas fa-id-card"></i>
                                        <span>Captured back ID preview</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 6. TWO HELPER TEXT LINES -->
                        <div class="id-helper-lines">
                            <div class="id-helper-line">
                                <i class="fas fa-user"></i>
                                <span>Capture your live face and valid ID to compare identity details.</span>
                            </div>
                            <div class="id-helper-line">
                                <i class="fas fa-id-card"></i>
                                <span>Front and back ID text will be scanned to auto-fill identity details.</span>
                            </div>
                        </div>

                        <!-- OCR status bar -->
                        <div class="id-ocr-status warning" id="idOcrStatus" style="display:none;margin-top:12px">
                            <i class="fas fa-id-card-clip"></i>
                            <span>Upload your Front ID and Back ID to auto-fill registration details via OCR.</span>
                        </div>

                        <!-- Face match status -->
                        <div class="face-match-status warning" id="faceMatchStatus" style="display:none;margin-top:8px">
                            <i class="fas fa-user-shield"></i>
                            <span>Upload your Face Photo and Front ID to verify your identity.</span>
                        </div>
                    </div><!-- /.id-scanner-wrap -->

                    <input type="hidden" id="face_capture" name="face_capture">
                    <input type="hidden" id="valid_id_capture" name="valid_id_capture">
                    <input type="hidden" id="valid_id_back_capture" name="valid_id_back_capture">
                    <input type="hidden" id="face_match_status_input" name="face_match_status" value="pending">
                    <input type="hidden" id="id_ocr_status_input" name="id_ocr_status" value="pending">
                    <input type="hidden" id="registration_id" name="registration_id" value="<?php echo htmlspecialchars($registration_verification_id, ENT_QUOTES); ?>">
                    <div class="field-message" data-error-for="live_verification"></div>
                </div>

                <div class="reg-step-divider" aria-hidden="true"></div>

                <!-- ═══════════════════════════════════════════
                     STEP 2 — Personal Information
                ═══════════════════════════════════════════ -->
                <div class="reg-step" id="regStep2">
                    <div class="reg-step-header">
                        <div class="reg-step-badge-num" id="step2Num">2</div>
                        <div class="reg-step-info">
                            <strong><i class="fas fa-user-pen" style="margin-right:5px;opacity:.7"></i>Personal Information</strong>
                            <span>Review and complete your details — OCR auto-fills highlighted fields from your ID.</span>
                        </div>
                        <div class="reg-step-status">
                            <span class="step-pill pending" id="step2Pill">Pending ID Scan</span>
                        </div>
                    </div>

                    <div class="reg-fields-lock-banner" id="regFieldsBanner">
                        <i class="fas fa-wand-magic-sparkles"></i>
                        <span id="regFieldsBannerText">Complete Step 1 to scan your ID — OCR will auto-fill most fields below.</span>
                    </div>

                    <div class="field-group full">
                        <span class="field-label">Registration Method</span>
                        <div class="verification-options">
                            <label class="verification-option">
                                <input type="radio" name="verification_method" value="email" <?php echo $form_data['verification_method'] === 'email' ? 'checked' : ''; ?>>
                                <span><i class="fas fa-envelope-circle-check"></i> Register using Email Address</span>
                            </label>
                            <label class="verification-option">
                                <input type="radio" name="verification_method" value="mobile" <?php echo $form_data['verification_method'] === 'mobile' ? 'checked' : ''; ?>>
                                <span><i class="fas fa-mobile-screen-button"></i> Register using Mobile Number</span>
                            </label>
                        </div>
                        <div class="field-message" data-error-for="verification_method"></div>
                    </div>

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

                        <div class="field-group" data-registration-field="mobile">
                            <label for="phone_number" class="field-label">Phone Number</label>
                            <div class="input-wrap">
                                <i class="fas fa-phone field-icon"></i>
                                <input type="tel" class="form-control" id="phone_number" name="phone_number" value="<?php echo e($form_data['phone_number']); ?>" autocomplete="tel" inputmode="tel" pattern="(09[0-9]{9}|\+639[0-9]{9})" maxlength="13" placeholder="09XXXXXXXXX or +639XXXXXXXXX">
                            </div>
                            <div class="form-hint">Use 09XXXXXXXXX or +639XXXXXXXXX.</div>
                            <div class="field-message" data-error-for="phone_number"></div>
                        </div>

                        <div class="field-group" data-registration-field="email">
                            <label for="email" class="field-label">Gmail Address</label>
                            <div class="input-wrap">
                                <i class="fas fa-envelope field-icon"></i>
                                <input type="email" class="form-control" id="email" name="email" value="<?php echo e($form_data['email']); ?>" autocomplete="email" placeholder="name@gmail.com">
                            </div>
                            <div class="field-message" data-error-for="email"></div>
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

                        <div class="field-group full">
                            <label for="id_number" class="field-label">ID Number</label>
                            <div class="input-wrap">
                                <i class="fas fa-fingerprint field-icon"></i>
                                <input type="text" class="form-control" id="id_number" name="id_number" value="<?php echo e($form_data['id_number']); ?>" autocomplete="off" placeholder="Enter ID number" required>
                            </div>
                            <div class="form-hint">Enter the ID number shown on your valid ID.</div>
                            <div class="field-message" data-error-for="id_number"></div>
                        </div>
                    </div>
                </div>

                <div class="reg-step-divider" aria-hidden="true"></div>

                <!-- ═══════════════════════════════════════════
                     STEP 3 — Terms & Verification Agreement
                ═══════════════════════════════════════════ -->
                <div class="reg-step" id="regStep3">
                    <div class="reg-step-header">
                        <div class="reg-step-badge-num" id="step3Num">3</div>
                        <div class="reg-step-info">
                            <strong><i class="fas fa-file-contract" style="margin-right:5px;opacity:.7"></i>Terms &amp; Verification Agreement</strong>
                            <span>Read the full terms carefully — you must scroll to the bottom before agreeing.</span>
                        </div>
                        <div class="reg-step-status">
                            <span class="step-pill pending" id="step3Pill">Unread</span>
                        </div>
                    </div>

                    <div class="terms-scroll-box" id="termsScrollBox" tabindex="0" role="region" aria-label="Terms and Conditions">
                        <h4><i class="fas fa-church"></i> San Lorenzo Ruiz Mission Station &mdash; TUGON Parish System</h4>
                        <h5>Terms &amp; Conditions, Parish Verification Policy, and Responsible Use of Sacramental Records</h5>
                        <p><strong>Effective Date:</strong> Upon account registration and approval by the Parish Office.</p>

                        <p>By completing your registration in the TUGON Parish Management System, you agree to the following terms governing the collection, use, and protection of your personal and sacramental information. Please read each section carefully.</p>

                        <h6>1. Purpose of Registration</h6>
                        <p>This system is exclusively for verified parishioners and residents of Aleosan, Cotabato who are affiliated with the San Lorenzo Ruiz Mission Station. Registration grants access to parish services including sacramental records, event schedules, requests, and parish announcements.</p>

                        <h6>2. Accuracy of Information</h6>
                        <p>You affirm that all information submitted during registration &mdash; including your name, address, birthdate, contact details, and valid government ID &mdash; is truthful, accurate, and complete. Submission of false, misleading, or fraudulent information constitutes grounds for immediate account suspension and may be reported to the appropriate authorities.</p>

                        <h6>3. Identity Verification</h6>
                        <p>Registration requires live face capture and front-and-back images of a valid government-issued ID. These biometric captures are used strictly for identity verification purposes. Your captures are encrypted, stored securely, and reviewed only by authorized Parish Office personnel. No biometric data is shared with third parties.</p>

                        <h6>4. Parish Verification &amp; Approval Policy</h6>
                        <p>All registrations are subject to manual review and approval by the Parish Office. Your account will remain in a <em>Pending Verification</em> status until a parish administrator confirms your identity. The Parish Office reserves the right to approve, defer, or reject any registration at its sole discretion, particularly if identity cannot be sufficiently verified.</p>

                        <h6>5. Responsible Use of Sacramental Records</h6>
                        <p>Sacramental records accessible through this system (Baptism, Confirmation, Marriage, etc.) are sacred and confidential ecclesiastical documents. You agree to:</p>
                        <ul>
                            <li>Access only records belonging to yourself or your immediate family members.</li>
                            <li>Never reproduce, distribute, or commercially exploit any record retrieved from this system.</li>
                            <li>Respect the dignity and privacy of all individuals named in sacramental records.</li>
                            <li>Use records solely for lawful personal, spiritual, or administrative purposes.</li>
                        </ul>

                        <h6>6. Data Privacy &amp; Protection</h6>
                        <p>The Parish System complies with the Philippine Data Privacy Act of 2012 (Republic Act No. 10173). Your personal data is collected with your consent, used only for parish administration, and protected by technical security measures. You have the right to access, correct, or request deletion of your personal data by contacting the Parish Office directly.</p>

                        <h6>7. Account Responsibilities</h6>
                        <p>You are solely responsible for maintaining the confidentiality of your login credentials. You must not share your account access with any other person. Any unauthorized use of your account must be reported to the Parish Office immediately. The parish is not liable for any damages resulting from your failure to secure your credentials.</p>

                        <h6>8. System Use &amp; Conduct</h6>
                        <p>Use of this system is limited to lawful, good-faith purposes aligned with the mission and values of the Catholic Church. Prohibited activities include, but are not limited to: unauthorized data access, system manipulation, harassment of parish staff or members, and any activity contrary to moral law or civil law.</p>

                        <h6>9. Amendments</h6>
                        <p>The Parish Office reserves the right to amend these Terms at any time. Registered parishioners will be notified of material changes. Continued use of the system after notification constitutes acceptance of the revised Terms.</p>

                        <h6>10. Governing Authority</h6>
                        <p>This system operates under the authority of the San Lorenzo Ruiz Mission Station Parish Office, Aleosan, Cotabato, Philippines. Disputes shall be resolved under the jurisdiction of applicable Philippine civil and canon law.</p>

                        <div class="terms-scroll-end-marker" id="termsScrollEndMarker" aria-hidden="true"></div>
                    </div>

                    <div class="terms-scroll-notice" id="termsScrollNotice">
                        <i class="fas fa-arrow-down"></i>
                        <span>Scroll to the end of the Terms &amp; Conditions to enable the agreement checkbox.</span>
                    </div>

                    <div class="field-group full">
                        <label class="auth-check terms-check" for="terms_check" id="termsCheckLabel">
                            <input type="checkbox" id="terms_check" name="terms_check" required <?php echo !empty($_POST['terms_check']) ? 'checked' : 'disabled'; ?>>
                            <span>I agree to the Terms &amp; Conditions, parish verification policy, and responsible use of sacramental records.</span>
                        </label>
                        <div class="field-message" data-error-for="terms_check"></div>
                    </div>
                </div>

                <div class="reg-step-divider" aria-hidden="true"></div>

                <!-- ═══════════════════════════════════════════
                     STEP 4 — Account Security & Create Account
                ═══════════════════════════════════════════ -->
                <div class="reg-step" id="regStep4">
                    <div class="reg-step-header">
                        <div class="reg-step-badge-num" id="step4Num">4</div>
                        <div class="reg-step-info">
                            <strong><i class="fas fa-shield-halved" style="margin-right:5px;opacity:.7"></i>Account Security</strong>
                            <span>Set a strong password to secure your parish account.</span>
                        </div>
                        <div class="reg-step-status">
                            <span class="step-pill pending" id="step4Pill">Incomplete</span>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="field-group">
                            <label for="password" class="field-label">Password</label>
                            <div class="input-wrap password">
                                <i class="fas fa-lock field-icon"></i>
                                <input type="password" class="form-control" id="password" name="password" autocomplete="new-password" minlength="<?php echo (int) PASSWORD_MIN_LENGTH; ?>" required>
                                <button type="button" class="password-toggle" data-toggle-password="password" aria-label="Show password" title="Show password">
                                    <i class="fas fa-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                            <div class="form-hint"><?php echo e(passwordRequirementsMessage()); ?></div>
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
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" autocomplete="new-password" minlength="<?php echo (int) PASSWORD_MIN_LENGTH; ?>" required>
                                <button type="button" class="password-toggle" data-toggle-password="confirm_password" aria-label="Show confirm password" title="Show confirm password">
                                    <i class="fas fa-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                            <div class="field-message" data-error-for="confirm_password"></div>
                        </div>
                    </div>

                    <div class="submit-gate-notice" id="submitGateNotice">
                        <i class="fas fa-circle-info"></i>
                        <span id="submitGateText">Complete all steps above — agree to the terms and fill all required fields — to enable account creation.</span>
                    </div>

                    <button type="submit" class="submit-btn" id="registerSubmit" <?php echo $success ? 'disabled' : ''; ?> disabled>
                        <span class="spinner" aria-hidden="true"></span>
                        <i class="fas fa-user-check submit-icon"></i>
                        <span class="submit-text"><?php echo $success ? 'Registration Submitted' : 'Create Account'; ?></span>
                    </button>
                </div>
            </form>

            <p class="login-link">
                Already have an account? <a href="login.php">Login here</a>
            </p>

            <div class="auth-verification-actions register-socials" aria-label="Registration helpers">
                <a href="../index.php" class="auth-social-btn register-social-btn">
                    <i class="fas fa-arrow-left"></i> Back to Home
                </a>
                <a href="login.php" class="auth-social-btn register-social-btn">
                    <i class="fas fa-right-to-bracket"></i> Sign In
                </a>
            </div>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
    <script defer src="<?php echo e(BASE_URL); ?>assets/js/face-verification.js?v=20260822-deploy"></script>
    <script defer src="<?php echo e(BASE_URL); ?>assets/js/id-scanner.js?v=20260826"></script>
    <script>
        const registrationVerificationId = <?php echo json_encode($registration_verification_id); ?>;
        const csrfTokenName = <?php echo json_encode(csrfTokenName()); ?>;
        const form = document.getElementById('registrationForm');
        const submitButton = document.getElementById('registerSubmit');
        const submitText = submitButton.querySelector('.submit-text');
        let registrationSubmitInProgress = false;

        // ── Field references ─────────────────────────────────────────────────
        const fields = {
            first_name:            document.getElementById('first_name'),
            surname:               document.getElementById('surname'),
            middle_initial:        document.getElementById('middle_initial'),
            phone_number:          document.getElementById('phone_number'),
            email:                 document.getElementById('email'),
            chapel_district:       document.getElementById('chapel_district'),
            address:               document.getElementById('address'),
            birthdate:             document.getElementById('birthdate'),
            birth_place:           document.getElementById('birth_place'),
            id_number:             document.getElementById('id_number'),
            password:              document.getElementById('password'),
            confirm_password:      document.getElementById('confirm_password'),
            face_capture:          document.getElementById('face_capture'),
            valid_id_capture:      document.getElementById('valid_id_capture'),
            valid_id_back_capture: document.getElementById('valid_id_back_capture'),
            terms_check:           document.getElementById('terms_check'),
            sex:                   document.getElementById('sex'),
        };

        // ── ID Scanner Element References ────────────────────────────────────
        const idScannerWrap          = document.getElementById('idScannerWrap');
        const idCameraStage          = document.getElementById('idCameraStage');
        const video                  = document.getElementById('verificationVideo');
        const captureCanvas          = document.getElementById('captureCanvas');
        const idFaceGuide            = document.getElementById('idFaceGuide');
        const idCardGuide            = document.getElementById('idCardGuide');
        const idCardGuideLabel       = document.getElementById('idCardGuideLabel');
        const idCameraPlaceholder    = document.getElementById('idCameraPlaceholder');
        const idCameraErrorMsg       = document.getElementById('idCameraErrorMsg');
        const tabFace                = document.getElementById('tabFace');
        const tabFront               = document.getElementById('tabFront');
        const tabBack                = document.getElementById('tabBack');
        const startCameraBtn         = document.getElementById('startCameraBtn');
        const startCameraIcon        = document.getElementById('startCameraIcon');
        const startCameraText        = document.getElementById('startCameraText');
        const captureFrontBtn        = document.getElementById('captureFrontBtn');
        const captureBackBtn         = document.getElementById('captureBackBtn');
        const frontIdUploadFile      = document.getElementById('frontIdUploadFile');
        const backIdUploadFile       = document.getElementById('backIdUploadFile');
        const frontIdUploadLabel     = document.getElementById('frontIdUploadLabel');
        const backIdUploadLabel      = document.getElementById('backIdUploadLabel');
        const idInstructionText      = document.getElementById('idInstructionText');
        const facePreviewImage       = document.getElementById('facePreviewImage');
        const idFrontPreviewImage    = document.getElementById('idFrontPreviewImage');
        const idBackPreviewImage     = document.getElementById('idBackPreviewImage');
        const faceThumbPlaceholder   = document.getElementById('faceThumbPlaceholder');
        const frontThumbPlaceholder  = document.getElementById('frontThumbPlaceholder');
        const backThumbPlaceholder   = document.getElementById('backThumbPlaceholder');
        const retakeFaceBtn          = document.getElementById('retakeFaceBtn');
        const retakeFrontBtn         = document.getElementById('retakeFrontBtn');
        const retakeBackBtn          = document.getElementById('retakeBackBtn');
        const idOcrStatus            = document.getElementById('idOcrStatus');
        const faceMatchStatus        = document.getElementById('faceMatchStatus');
        const faceMatchStatusInput   = document.getElementById('face_match_status_input');
        const idOcrStatusInput       = document.getElementById('id_ocr_status_input');
        const idScanConsent          = document.getElementById('idScanConsent');
        const manualEntryBtn         = document.getElementById('manualEntryBtn');
        const verificationMethodInputs = Array.from(document.querySelectorAll('input[name="verification_method"]'));
        const registrationFieldGroups  = Array.from(document.querySelectorAll('[data-registration-field]'));
        const strengthBars  = Array.from(document.querySelectorAll('.password-strength span'));
        const strengthText  = document.getElementById('passwordStrengthText');

        // ── Camera & Scanner State ───────────────────────────────────────────
        let activeCameraTab = 'face'; // 'face' | 'front' | 'back'
        let cameraStream    = null;
        let cameraRunning   = false;

        function isConsentGiven() {
            return Boolean(idScanConsent && idScanConsent.checked);
        }

        function showCameraError(message) {
            if (idCameraErrorMsg) {
                idCameraErrorMsg.textContent = message;
                idCameraErrorMsg.style.display = 'block';
            }
        }

        function hideCameraError() {
            if (idCameraErrorMsg) {
                idCameraErrorMsg.textContent = '';
                idCameraErrorMsg.style.display = 'none';
            }
        }

        // ── Button and Tab State Synchronizer ─────────────────────────────────
        function updateActionButtonsState() {
            const consent = isConsentGiven();

            // When consent is not given, all capture and camera buttons stay disabled
            if (!consent) {
                startCameraBtn.disabled = true;
                captureFrontBtn.disabled = true;
                captureBackBtn.disabled = true;
                captureFrontBtn.classList.remove('is-highlighted');
                captureBackBtn.classList.remove('is-highlighted');
                if (frontIdUploadFile) frontIdUploadFile.disabled = true;
                if (backIdUploadFile) backIdUploadFile.disabled = true;
                if (frontIdUploadLabel) frontIdUploadLabel.classList.add('is-disabled');
                if (backIdUploadLabel) backIdUploadLabel.classList.add('is-disabled');
                return;
            }

            // Fallback uploads are unlocked once consent is checked
            if (frontIdUploadFile) frontIdUploadFile.disabled = false;
            if (backIdUploadFile) backIdUploadFile.disabled = false;
            if (frontIdUploadLabel) frontIdUploadLabel.classList.remove('is-disabled');
            if (backIdUploadLabel) backIdUploadLabel.classList.remove('is-disabled');

            if (!cameraRunning) {
                // Camera stopped: "Start Camera" is primary and ready
                startCameraBtn.disabled = false;
                startCameraBtn.className = 'id-act-btn primary';
                if (startCameraIcon) startCameraIcon.className = 'fas fa-video';
                if (startCameraText) startCameraText.textContent = 'Start Camera';

                captureFrontBtn.disabled = true;
                captureFrontBtn.className = 'id-act-btn secondary';
                captureFrontBtn.classList.remove('is-highlighted');

                captureBackBtn.disabled = true;
                captureBackBtn.className = 'id-act-btn secondary';
                captureBackBtn.classList.remove('is-highlighted');
            } else {
                // Camera actively running
                if (activeCameraTab === 'face') {
                    // Face tab: primary action is "Capture Face"
                    startCameraBtn.disabled = false;
                    startCameraBtn.className = 'id-act-btn primary';
                    if (startCameraIcon) startCameraIcon.className = 'fas fa-camera';
                    if (startCameraText) startCameraText.textContent = 'Capture Face';

                    captureFrontBtn.disabled = true;
                    captureFrontBtn.className = 'id-act-btn secondary';
                    captureFrontBtn.classList.remove('is-highlighted');

                    captureBackBtn.disabled = true;
                    captureBackBtn.className = 'id-act-btn secondary';
                    captureBackBtn.classList.remove('is-highlighted');
                } else if (activeCameraTab === 'front') {
                    // Front ID tab: "Capture Front" is highlighted primary; Button 1 allows "Stop Camera"
                    startCameraBtn.disabled = false;
                    startCameraBtn.className = 'id-act-btn secondary';
                    if (startCameraIcon) startCameraIcon.className = 'fas fa-video-slash';
                    if (startCameraText) startCameraText.textContent = 'Stop Camera';

                    captureFrontBtn.disabled = false;
                    captureFrontBtn.className = 'id-act-btn secondary is-highlighted';

                    captureBackBtn.disabled = true;
                    captureBackBtn.className = 'id-act-btn secondary';
                    captureBackBtn.classList.remove('is-highlighted');
                } else if (activeCameraTab === 'back') {
                    // Back ID tab: "Capture Back" is highlighted primary; Button 1 allows "Stop Camera"
                    startCameraBtn.disabled = false;
                    startCameraBtn.className = 'id-act-btn secondary';
                    if (startCameraIcon) startCameraIcon.className = 'fas fa-video-slash';
                    if (startCameraText) startCameraText.textContent = 'Stop Camera';

                    captureFrontBtn.disabled = true;
                    captureFrontBtn.className = 'id-act-btn secondary';
                    captureFrontBtn.classList.remove('is-highlighted');

                    captureBackBtn.disabled = false;
                    captureBackBtn.className = 'id-act-btn secondary is-highlighted';
                }
            }
        }

        // ── Tab Switching (Preserves stream if camera is running) ─────────────
        function switchScannerTab(targetTab) {
            activeCameraTab = targetTab;

            if (tabFace) {
                tabFace.classList.toggle('active', targetTab === 'face');
                tabFace.setAttribute('aria-selected', targetTab === 'face' ? 'true' : 'false');
            }
            if (tabFront) {
                tabFront.classList.toggle('active', targetTab === 'front');
                tabFront.setAttribute('aria-selected', targetTab === 'front' ? 'true' : 'false');
            }
            if (tabBack) {
                tabBack.classList.toggle('active', targetTab === 'back');
                tabBack.setAttribute('aria-selected', targetTab === 'back' ? 'true' : 'false');
            }

            if (targetTab === 'face') {
                if (idCameraStage) {
                    idCameraStage.classList.add('is-face-mode');
                    idCameraStage.classList.remove('is-id-mode');
                }
                if (idFaceGuide) idFaceGuide.style.display = 'flex';
                if (idCardGuide) idCardGuide.style.display = 'none';
                if (idInstructionText) {
                    idInstructionText.textContent = cameraRunning
                        ? 'Position your face clearly inside the oval guide and click Capture Face.'
                        : 'Start the camera and position your face inside the guide.';
                }
            } else if (targetTab === 'front') {
                if (idCameraStage) {
                    idCameraStage.classList.remove('is-face-mode');
                    idCameraStage.classList.add('is-id-mode');
                }
                if (idFaceGuide) idFaceGuide.style.display = 'none';
                if (idCardGuide) idCardGuide.style.display = 'flex';
                if (idCardGuideLabel) idCardGuideLabel.textContent = 'Position Front ID inside frame';
                if (idInstructionText) {
                    idInstructionText.textContent = cameraRunning
                        ? 'Align the front of your ID within the frame and click Capture Front.'
                        : 'Align the front of your ID within the frame and capture.';
                }
            } else if (targetTab === 'back') {
                if (idCameraStage) {
                    idCameraStage.classList.remove('is-face-mode');
                    idCameraStage.classList.add('is-id-mode');
                }
                if (idFaceGuide) idFaceGuide.style.display = 'none';
                if (idCardGuide) idCardGuide.style.display = 'flex';
                if (idCardGuideLabel) idCardGuideLabel.textContent = 'Position Back ID inside frame';
                if (idInstructionText) {
                    idInstructionText.textContent = cameraRunning
                        ? 'Align the back of your ID within the frame and click Capture Back.'
                        : 'Align the back of your ID within the frame and capture.';
                }
            }

            updateActionButtonsState();
        }

        if (tabFace)  tabFace.addEventListener('click', () => switchScannerTab('face'));
        if (tabFront) tabFront.addEventListener('click', () => switchScannerTab('front'));
        if (tabBack)  tabBack.addEventListener('click', () => switchScannerTab('back'));

        // ── Camera Control (getUserMedia) ────────────────────────────────────
        async function startCamera(preferredFacing = null) {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                const msg = window.isSecureContext
                    ? 'Camera access is not supported by your browser.'
                    : 'Camera access requires a secure origin (HTTPS or localhost).';
                showCameraError(msg);
                showToast('error', 'Camera Error', msg);
                return false;
            }

            if (!isConsentGiven()) {
                showToast('warning', 'Consent Required', 'Please check the consent box above before starting the camera.');
                if (idScanConsent) {
                    idScanConsent.focus();
                    idScanConsent.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return false;
            }

            hideCameraError();

            if (cameraStream) {
                try { cameraStream.getTracks().forEach(t => t.stop()); } catch (e) {}
                cameraStream = null;
            }

            const facing = preferredFacing || (activeCameraTab === 'face' ? 'user' : 'environment');

            try {
                try {
                    cameraStream = await navigator.mediaDevices.getUserMedia({
                        video: {
                            facingMode: { ideal: facing },
                            width: { ideal: 1280 },
                            height: { ideal: 720 }
                        },
                        audio: false
                    });
                } catch (constraintErr) {
                    if (constraintErr && ['NotAllowedError', 'SecurityError'].includes(constraintErr.name)) {
                        throw constraintErr;
                    }
                    cameraStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
                }

                if (video) {
                    video.srcObject = cameraStream;
                    await new Promise((resolve) => {
                        if (video.readyState >= 1 && video.videoWidth > 0) return resolve();
                        video.addEventListener('loadedmetadata', resolve, { once: true });
                    });
                    await video.play();
                }

                cameraRunning = true;
                if (idCameraStage) idCameraStage.classList.add('is-active');
                switchScannerTab(activeCameraTab);
                setFieldError('live_verification', '');
                return true;
            } catch (err) {
                cameraRunning = false;
                if (idCameraStage) idCameraStage.classList.remove('is-active');
                const blocked = err && ['NotAllowedError', 'SecurityError'].includes(err.name);
                const notFound = err && ['NotFoundError', 'DevicesNotFoundError'].includes(err.name);
                const msg = blocked
                    ? 'Camera permission denied. Allow camera access for this site in your browser settings, then try again.'
                    : (notFound
                        ? 'No usable camera was found on this device.'
                        : 'Could not access device camera: ' + (err && err.message ? err.message : 'Unknown error'));
                showCameraError(msg);
                showToast('error', 'Camera Error', msg);
                updateActionButtonsState();
                return false;
            }
        }

        function stopCamera() {
            if (cameraStream) {
                try { cameraStream.getTracks().forEach(t => t.stop()); } catch (e) {}
                cameraStream = null;
            }
            if (video) { video.srcObject = null; }
            cameraRunning = false;
            if (idCameraStage) idCameraStage.classList.remove('is-active');
            switchScannerTab(activeCameraTab);
        }

        // ── Frame Snapshot from Video to Base64 ───────────────────────────────
        function captureFrame() {
            if (!video || !video.videoWidth || !video.videoHeight) {
                showToast('error', 'Camera Not Ready', 'The camera video is still initializing. Please wait a moment.');
                return null;
            }
            const canvas = captureCanvas || document.createElement('canvas');
            canvas.width  = video.videoWidth;
            canvas.height = video.videoHeight;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            return canvas.toDataURL('image/jpeg', 0.92);
        }

        // ── Data & Thumbnail Updaters ─────────────────────────────────────────
        function setFaceData(dataUrl) {
            fields.face_capture.value = dataUrl;
            if (facePreviewImage) {
                facePreviewImage.src = dataUrl;
                facePreviewImage.style.display = 'block';
            }
            if (faceThumbPlaceholder) faceThumbPlaceholder.style.display = 'none';
            if (retakeFaceBtn) retakeFaceBtn.style.display = 'inline-block';
            setFaceStatus('warning', 'Live face photo captured. Scan your Front ID to verify your identity.');
            setFieldError('live_verification', '');
            updateSubmitGate();

            // Run face verification if Front ID already present
            if (fields.valid_id_capture.value && fields.valid_id_capture.value !== 'manual' && window.FaceVerification && typeof window.FaceVerification.verifyLiveAgainstId === 'function') {
                setFaceStatus('warning', 'Comparing face photo with ID…');
                window.FaceVerification.verifyLiveAgainstId(facePreviewImage, idFrontPreviewImage)
                    .then(res => {
                        const score = Math.round(Number(res.score ?? res.matchScore ?? 0));
                        const isMatch = Boolean(res.is_match ?? res.isMatch ?? res.match);
                        if (isMatch) setFaceStatus('success', 'Face verified', score);
                        else setFaceStatus('warning', 'Face needs admin review. You can continue — ID details will be checked manually.', score);
                    })
                    .catch(() => setFaceStatus('warning', 'Face verification will be reviewed by the Parish Office.'));
            }
        }

        function setFrontIdData(dataUrl) {
            fields.valid_id_capture.value = dataUrl;
            if (idFrontPreviewImage) {
                idFrontPreviewImage.src = dataUrl;
                idFrontPreviewImage.style.display = 'block';
            }
            if (frontThumbPlaceholder) frontThumbPlaceholder.style.display = 'none';
            if (retakeFrontBtn) retakeFrontBtn.style.display = 'inline-block';
            setIdOcrStatus('warning', 'Front ID ready' + (fields.valid_id_back_capture.value && fields.valid_id_back_capture.value !== 'manual' ? ' — scanning text…' : ' — capture or upload Back ID to start OCR.'));
            setFieldError('live_verification', '');
            updateSubmitGate();
            maybeTriggerOcr();

            // Run face verification if Face capture already present
            if (fields.face_capture.value && fields.face_capture.value !== 'manual' && window.FaceVerification && typeof window.FaceVerification.verifyLiveAgainstId === 'function') {
                setFaceStatus('warning', 'Comparing face photo with ID…');
                window.FaceVerification.verifyLiveAgainstId(facePreviewImage, idFrontPreviewImage)
                    .then(res => {
                        const score = Math.round(Number(res.score ?? res.matchScore ?? 0));
                        const isMatch = Boolean(res.is_match ?? res.isMatch ?? res.match);
                        if (isMatch) setFaceStatus('success', 'Face verified', score);
                        else setFaceStatus('warning', 'Face needs admin review. You can continue — ID details will be checked manually.', score);
                    })
                    .catch(() => setFaceStatus('warning', 'Face verification will be reviewed by the Parish Office.'));
            }
        }

        function setBackIdData(dataUrl) {
            fields.valid_id_back_capture.value = dataUrl;
            if (idBackPreviewImage) {
                idBackPreviewImage.src = dataUrl;
                idBackPreviewImage.style.display = 'block';
            }
            if (backThumbPlaceholder) backThumbPlaceholder.style.display = 'none';
            if (retakeBackBtn) retakeBackBtn.style.display = 'inline-block';
            setIdOcrStatus('warning', 'Back ID ready' + (fields.valid_id_capture.value && fields.valid_id_capture.value !== 'manual' ? ' — scanning text…' : ' — capture or upload Front ID too.'));
            setFieldError('live_verification', '');
            updateSubmitGate();
            maybeTriggerOcr();
        }

        // ── Action Buttons Click Handlers ────────────────────────────────────
        if (startCameraBtn) {
            startCameraBtn.addEventListener('click', async () => {
                if (!cameraRunning) {
                    await startCamera();
                } else {
                    if (activeCameraTab === 'face') {
                        const dataUrl = captureFrame();
                        if (dataUrl) {
                            setFaceData(dataUrl);
                            showToast('success', 'Face Captured', 'Live face photo saved. You may now switch to Front ID.');
                            switchScannerTab('front');
                        }
                    } else {
                        // User clicked Stop Camera while on Front or Back ID tab
                        stopCamera();
                    }
                }
            });
        }

        if (captureFrontBtn) {
            captureFrontBtn.addEventListener('click', () => {
                if (!cameraRunning) return;
                const dataUrl = captureFrame();
                if (dataUrl) {
                    setFrontIdData(dataUrl);
                    showToast('success', 'Front ID Captured', 'Front ID saved. Now capture or upload the Back ID.');
                    switchScannerTab('back');
                }
            });
        }

        if (captureBackBtn) {
            captureBackBtn.addEventListener('click', () => {
                if (!cameraRunning) return;
                const dataUrl = captureFrame();
                if (dataUrl) {
                    setBackIdData(dataUrl);
                    showToast('success', 'Back ID Captured', 'Back ID saved. Starting OCR text scan.');
                }
            });
        }

        // ── File Upload Fallback Handlers (Front and Back only, NO Face upload) ─
        function readImageFile(file) {
            return new Promise((resolve, reject) => {
                const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];
                if (!file) { reject(new Error('No file selected.')); return; }
                if (!ALLOWED.includes(file.type)) { reject(new Error('Please upload a JPG, PNG, or WEBP image.')); return; }
                if (file.size > 8 * 1024 * 1024) { reject(new Error('Image exceeds the 8 MB limit. Please compress it first.')); return; }
                const reader = new FileReader();
                reader.onload = () => resolve(reader.result);
                reader.onerror = () => reject(new Error('Could not read the file. Try again.'));
                reader.readAsDataURL(file);
            });
        }

        if (frontIdUploadFile) {
            frontIdUploadFile.addEventListener('change', async () => {
                if (frontIdUploadFile.files && frontIdUploadFile.files[0]) {
                    try {
                        const dataUrl = await readImageFile(frontIdUploadFile.files[0]);
                        setFrontIdData(dataUrl);
                        showToast('success', 'Front ID Uploaded', 'Front ID image successfully loaded.');
                    } catch (err) {
                        showToast('error', 'Upload Failed', err.message);
                    }
                    frontIdUploadFile.value = '';
                }
            });
        }

        if (backIdUploadFile) {
            backIdUploadFile.addEventListener('change', async () => {
                if (backIdUploadFile.files && backIdUploadFile.files[0]) {
                    try {
                        const dataUrl = await readImageFile(backIdUploadFile.files[0]);
                        setBackIdData(dataUrl);
                        showToast('success', 'Back ID Uploaded', 'Back ID image successfully loaded.');
                    } catch (err) {
                        showToast('error', 'Upload Failed', err.message);
                    }
                    backIdUploadFile.value = '';
                }
            });
        }

        // ── Retake Buttons ───────────────────────────────────────────────────
        if (retakeFaceBtn) {
            retakeFaceBtn.addEventListener('click', () => {
                fields.face_capture.value = '';
                if (facePreviewImage) {
                    facePreviewImage.removeAttribute('src');
                    facePreviewImage.style.display = 'none';
                }
                if (faceThumbPlaceholder) faceThumbPlaceholder.style.display = 'flex';
                retakeFaceBtn.style.display = 'none';
                setFaceStatus('warning', 'Capture your live face and valid ID to verify your identity.');
                faceMatchStatusInput.value = 'pending';
                switchScannerTab('face');
                updateSubmitGate();
            });
        }

        if (retakeFrontBtn) {
            retakeFrontBtn.addEventListener('click', () => {
                fields.valid_id_capture.value = '';
                if (idFrontPreviewImage) {
                    idFrontPreviewImage.removeAttribute('src');
                    idFrontPreviewImage.style.display = 'none';
                }
                if (frontThumbPlaceholder) frontThumbPlaceholder.style.display = 'flex';
                retakeFrontBtn.style.display = 'none';
                setIdOcrStatus('warning', 'Capture your Front ID and Back ID to auto-fill registration details via OCR.');
                idOcrStatusInput.value = 'pending';
                ['surname','first_name','middle_initial','address','birth_place','id_number','birthdate'].forEach(n => {
                    if (fields[n]) fields[n].classList.remove('ocr-autofilled');
                });
                switchScannerTab('front');
                updateSubmitGate();
            });
        }

        if (retakeBackBtn) {
            retakeBackBtn.addEventListener('click', () => {
                fields.valid_id_back_capture.value = '';
                if (idBackPreviewImage) {
                    idBackPreviewImage.removeAttribute('src');
                    idBackPreviewImage.style.display = 'none';
                }
                if (backThumbPlaceholder) backThumbPlaceholder.style.display = 'flex';
                retakeBackBtn.style.display = 'none';
                setIdOcrStatus('warning', 'Capture your Front ID and Back ID to auto-fill registration details via OCR.');
                idOcrStatusInput.value = 'pending';
                switchScannerTab('back');
                updateSubmitGate();
            });
        }

        // ── Consent Checkbox Gate ─────────────────────────────────────────────
        if (idScanConsent) {
            idScanConsent.addEventListener('change', () => {
                updateActionButtonsState();
            });
        }
        updateActionButtonsState(); // initialized with current checkbox state

        // ── Manual Entry Fallback ─────────────────────────────────────────────
        if (manualEntryBtn) {
            manualEntryBtn.addEventListener('click', () => {
                fields.face_capture.value = 'manual';
                fields.valid_id_capture.value = 'manual';
                fields.valid_id_back_capture.value = 'manual';
                setIdOcrStatus('warning', 'Manual entry mode — fill in your details below.');
                setFaceStatus('warning', 'Identity will be verified manually by the Parish Office.');
                faceMatchStatusInput.value = 'admin_review';
                idOcrStatusInput.value = 'pending';
                _updateStepBadges('error');
                updateSubmitGate();
                showToast('info', 'Manual Mode', 'Fill in all personal details in Step 2 manually.');
                const step2 = document.getElementById('regStep2');
                if (step2) step2.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        }

        // ── CSRF Helpers ─────────────────────────────────────────────────────
        function currentCsrfField() {
            return form.querySelector('input[name="' + csrfTokenName + '"]');
        }
        async function refreshRegistrationCsrfToken() {
            try {
                const response = await fetch('../api/csrf-token.php?context=registration&registration_id=' + encodeURIComponent(registrationVerificationId) + '&t=' + Date.now(), {
                    method: 'GET', cache: 'no-store', credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await response.json().catch(() => ({}));
                if (response.ok && data.success && data.token) {
                    const csrfField = currentCsrfField();
                    if (csrfField) csrfField.value = data.token;
                    return data.token;
                }
            } catch (e) { /* non-blocking */ }
            const existing = currentCsrfField();
            return existing && existing.value ? existing.value : '';
        }

        // ── OCR trigger & state ──────────────────────────────────────────────
        let ocrScanInProgress = false;
        let pendingOcrScan    = false;

        function maybeTriggerOcr() {
            const front = fields.valid_id_capture.value;
            if (front && front !== 'manual') {
                scanCapturedIdText();
            }
        }

        // ── Status helpers ───────────────────────────────────────────────────
        function setFaceStatus(type, message, score = null) {
            if (!faceMatchStatus) return;
            faceMatchStatus.className = 'face-match-status ' + type;
            const icon = type === 'success' ? 'fa-circle-check' : (type === 'error' ? 'fa-circle-xmark' : 'fa-user-shield');
            const scoreText = score === null ? '' : ' <strong>(' + score + '% match)</strong>';
            faceMatchStatus.innerHTML = '<i class="fas ' + icon + '"></i><span>' + message + scoreText + '</span>';
            if (faceMatchStatusInput) faceMatchStatusInput.value = type === 'success' ? 'matched' : (type === 'error' ? 'mismatch' : 'admin_review');
        }

        function setIdOcrStatus(type, message, aiEnhanced) {
            if (!idOcrStatus) return;
            idOcrStatus.style.display = 'block';
            idOcrStatus.className = 'id-ocr-status ' + (type === 'scanning' ? 'warning' : (type === 'review' ? 'warning' : type));
            const icon = type === 'success'
                ? 'fa-circle-check'
                : (type === 'error'
                    ? 'fa-circle-xmark'
                    : (type === 'scanning' ? 'fa-spinner fa-spin' : 'fa-id-card-clip'));
            const aiBadge = aiEnhanced
                ? ' <span style="display:inline-flex;align-items:center;gap:4px;background:linear-gradient(135deg,#D4A94E,#B07D2A);color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:12px;letter-spacing:.4px;vertical-align:middle">✦ AI Enhanced</span>'
                : '';
            idOcrStatus.innerHTML = '<i class="fas ' + icon + '"></i><span>' + message + aiBadge + '</span>';
            if (idOcrStatusInput) idOcrStatusInput.value = type === 'success' ? 'verified' : (type === 'error' ? 'mismatch' : 'pending');
            _updateStepBadges(type, message);
        }

        function _updateStepBadges(ocrType, customBannerMsg) {
            const step1Pill  = document.getElementById('step1Pill');
            const step2Pill  = document.getElementById('step2Pill');
            const step1El    = document.getElementById('regStep1');
            const banner     = document.getElementById('regFieldsBanner');
            const bannerTxt  = document.getElementById('regFieldsBannerText');
            const hasFront   = fields.valid_id_capture && fields.valid_id_capture.value && fields.valid_id_capture.value !== 'manual';
            const hasBack    = fields.valid_id_back_capture && fields.valid_id_back_capture.value && fields.valid_id_back_capture.value !== 'manual';

            if (ocrType === 'scanning') {
                if (step1Pill) { step1Pill.textContent = 'Scanning…'; step1Pill.className = 'step-pill pending'; }
                if (step2Pill) { step2Pill.textContent = 'Scanning…'; step2Pill.className = 'step-pill pending'; }
                if (banner) banner.classList.add('is-unlocked');
                if (bannerTxt) bannerTxt.textContent = customBannerMsg || 'Scanning ID… OCR is extracting your details.';
            } else if (ocrType === 'success') {
                if (step1Pill) { step1Pill.textContent = '✓ Scanned'; step1Pill.className = 'step-pill done'; }
                if (step2Pill) { step2Pill.textContent = 'ID Scanned ✓'; step2Pill.className = 'step-pill done'; }
                if (step1El) step1El.classList.add('step-complete');
                if (banner) banner.classList.add('is-unlocked');
                if (bannerTxt) bannerTxt.textContent = customBannerMsg || '✦ OCR complete — fields auto-filled from your ID. Review and edit if needed.';
                ['first_name','surname','middle_initial','address','birth_place','id_number','birthdate'].forEach(n => {
                    const f = fields[n]; if (f && f.value.trim()) f.classList.add('ocr-autofilled');
                });
            } else if (ocrType === 'review') {
                if (step1Pill) { step1Pill.textContent = '⚠ Review'; step1Pill.className = 'step-pill pending'; }
                if (step2Pill) { step2Pill.textContent = 'Needs Review'; step2Pill.className = 'step-pill pending'; }
                if (banner) banner.classList.add('is-unlocked');
                if (bannerTxt) bannerTxt.textContent = customBannerMsg || 'Some fields need your review — check and correct the highlighted fields below.';
                ['first_name','surname','middle_initial','address','birth_place','id_number','birthdate'].forEach(n => {
                    const f = fields[n]; if (f && f.value.trim()) f.classList.add('ocr-autofilled');
                });
            } else if (ocrType === 'error') {
                if (step1Pill) { step1Pill.textContent = '✕ Scan Failed'; step1Pill.className = 'step-pill error'; }
                if (step2Pill) { step2Pill.textContent = 'Scan Failed'; step2Pill.className = 'step-pill error'; }
                if (banner) banner.classList.add('is-unlocked');
                if (bannerTxt) bannerTxt.textContent = customBannerMsg || 'OCR scan failed — please fill in your details manually below.';
            } else if (ocrType === 'warning' && (hasFront || hasBack)) {
                if (step1Pill) { step1Pill.textContent = '⚠ Review'; step1Pill.className = 'step-pill error'; }
                if (step2Pill) { step2Pill.textContent = 'Needs Review'; step2Pill.className = 'step-pill pending'; }
                if (banner) banner.classList.add('is-unlocked');
                if (bannerTxt) bannerTxt.textContent = customBannerMsg || 'Some fields need your review — check and correct the highlighted fields below.';
            }
            updateSubmitGate();
        }

        // ── Set/clear field errors ───────────────────────────────────────────
        function setFieldError(fieldName, message) {
            const field = fields[fieldName];
            const messageTarget = document.querySelector('[data-error-for="' + fieldName + '"]');
            if (!messageTarget) return;
            if (field) field.classList.toggle('is-invalid-field', Boolean(message));
            messageTarget.textContent = message || '';
        }

        // ── Date helpers ─────────────────────────────────────────────────────
        function formatIsoDateForDisplay(value) {
            if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return value || '';
            const parts = value.split('-').map(Number);
            return new Date(parts[0], parts[1] - 1, parts[2]).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
        }
        function parseDisplayDate(value) {
            const raw = String(value || '').trim();
            if (!raw) return new Date(NaN);
            const parsed = new Date(raw);
            if (!Number.isNaN(parsed.getTime())) return parsed;
            const match = raw.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/);
            if (!match) return new Date(NaN);
            return new Date(Number(match[3]), Number(match[1]) - 1, Number(match[2]));
        }
        function inferBirthPlaceFromAddress(address) {
            const parts = String(address || '').split(',').map(p => p.trim()).filter(Boolean);
            if (parts.length < 2) return '';
            return (parts[parts.length - 2] + ', ' + parts[parts.length - 1]).toUpperCase();
        }
        function extractFirstJsonObject(text) {
            const source = String(text || '');
            const start = source.indexOf('{');
            if (start < 0) return source;
            let depth = 0, inString = false, escaped = false;
            for (let i = start; i < source.length; i++) {
                const char = source[i];
                if (escaped) { escaped = false; continue; }
                if (char === '\\') { escaped = inString; continue; }
                if (char === '"') { inString = !inString; continue; }
                if (inString) continue;
                if (char === '{') depth++;
                else if (char === '}') { depth--; if (depth === 0) return source.slice(start, i + 1); }
            }
            return source.slice(start);
        }

        // ── Toast ────────────────────────────────────────────────────────────
        function showToast(type, title, message) {
            const toastStack = document.getElementById('toastStack');
            if (!toastStack) return;
            const toast = document.createElement('div');
            toast.className = 'auth-toast ' + type;
            toast.setAttribute('role', type === 'success' ? 'status' : 'alert');
            toast.innerHTML = '<i class="fas ' + (type === 'success' ? 'fa-circle-check' : (type === 'info' ? 'fa-circle-info' : 'fa-circle-exclamation')) + '"></i><div><strong>' + title + '</strong><span>' + message + '</span></div>';
            toastStack.appendChild(toast);
            setTimeout(() => { toast.style.opacity = '0'; toast.style.transform = 'translateX(14px)'; setTimeout(() => toast.remove(), 280); }, 4200);
        }

        // ── OCR scan ─────────────────────────────────────────────────────────
        async function scanCapturedIdText() {
            if (!fields.valid_id_capture.value) {
                setIdOcrStatus('warning', 'Please capture or upload your Front ID before scanning.');
                return;
            }
            if (fields.valid_id_capture.value === 'manual') return;

            if (ocrScanInProgress) {
                pendingOcrScan = true;
                return;
            }
            ocrScanInProgress = true;

            setIdOcrStatus('scanning', 'Scanning ID text… extracting your personal details.');

            try {
                let csrfToken = '';
                try { csrfToken = await refreshRegistrationCsrfToken(); } catch (e) {
                    const existing = currentCsrfField();
                    csrfToken = existing && existing.value ? existing.value : '';
                }
                const fd = new FormData();
                fd.append('id_photo_data', fields.valid_id_capture.value);
                if (fields.valid_id_back_capture.value && fields.valid_id_back_capture.value !== 'manual') {
                    fd.append('id_back_photo_data', fields.valid_id_back_capture.value);
                }
                fd.append('first_name', fields.first_name.value);
                fd.append('surname', fields.surname.value);
                fd.append('middle_initial', fields.middle_initial.value);
                fd.append('address', fields.address.value);
                fd.append('birthdate', fields.birthdate.value);
                fd.append('birth_place', fields.birth_place.value);
                fd.append('id_number', fields.id_number.value);
                fd.append('registration_id', registrationVerificationId || '');
                if (csrfToken) fd.append(csrfTokenName, csrfToken);

                const headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
                if (csrfToken) headers['X-CSRF-Token'] = csrfToken;

                const res = await fetch('../ocr/api_process_id.php?t=' + Date.now(), {
                    method: 'POST', body: fd, cache: 'no-store', credentials: 'same-origin', headers
                });
                const responseText = await res.text();
                let data;
                try {
                    data = JSON.parse(extractFirstJsonObject(responseText));
                } catch (parseError) {
                    throw new Error('The ID text could not be scanned clearly. Retake the ID photo and try again.');
                }

                if (!res.ok || !data.success) {
                    throw new Error(data.error || 'The ID text could not be scanned.');
                }

                const idData = data.id_data || {};

                function fillFromOcr(fieldName, value, fmt) {
                    if (!value || !fields[fieldName]) return false;
                    const next = fmt ? fmt(value) : value;
                    if (!next || String(next).trim() === '') return false;
                    fields[fieldName].value = next;
                    fields[fieldName].classList.add('ocr-autofilled');
                    setFieldError(fieldName, '');
                    try {
                        fields[fieldName].dispatchEvent(new Event('input', { bubbles: true }));
                        fields[fieldName].dispatchEvent(new Event('change', { bubbles: true }));
                    } catch (e) {}
                    return true;
                }

                let filledCount = 0;
                if (fillFromOcr('surname', idData.last_name)) filledCount++;
                if (fillFromOcr('first_name', idData.first_name)) filledCount++;
                if (fillFromOcr('middle_initial', idData.middle_name, v => String(v).replace(/[^A-Za-z]/g, '').slice(0, 1).toUpperCase())) filledCount++;
                if (fillFromOcr('address', idData.address)) filledCount++;
                if (fillFromOcr('birth_place', idData.birth_place)) filledCount++;
                if (fillFromOcr('id_number', idData.id_number_formatted || idData.id_number)) filledCount++;
                if (fillFromOcr('birthdate', idData.date_of_birth_display || idData.date_of_birth, v => /^\d{4}-\d{2}-\d{2}$/.test(v) ? formatIsoDateForDisplay(v) : v)) filledCount++;

                // Sex field (if present)
                if (idData.sex && fields.sex) {
                    const s = String(idData.sex).trim().toUpperCase();
                    if (s === 'MALE' || s === 'M') fields.sex.value = 'male';
                    else if (s === 'FEMALE' || s === 'F') fields.sex.value = 'female';
                    try { fields.sex.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
                }

                const isAiEnhanced = Boolean(data.ai_enhanced);
                const idTypeLabel  = data.id_type_detected ? ' (' + data.id_type_detected + ')' : '';
                const readLabels   = ['last_name', 'first_name', 'middle_name', 'address', 'date_of_birth', 'birth_place', 'id_number']
                    .filter(k => Boolean(idData[k]))
                    .map(k => k.replace(/_/g, ' '));
                const readSummary  = readLabels.length ? ' Read: ' + readLabels.join(', ') + '.' : '';

                if (filledCount === 0) {
                    setIdOcrStatus('error', 'OCR could not read text from the ID. You can enter your details manually below.');
                    return;
                }

                const hasCore = Boolean(idData.first_name || idData.last_name);
                if (hasCore) {
                    setIdOcrStatus('success', 'ID scanned successfully' + idTypeLabel + ' and auto-filled registration details.' + readSummary, isAiEnhanced);
                } else {
                    setIdOcrStatus('review', 'Some fields need your review — check and correct the highlighted fields below.' + readSummary, isAiEnhanced);
                }

                // Face validation (non-blocking)
                if (fields.face_capture.value && fields.face_capture.value !== 'manual' && window.FaceVerification && typeof window.FaceVerification.verifyLiveAgainstId === 'function') {
                    try {
                        setFaceStatus('warning', 'Comparing face photo with ID…');
                        const faceResult = await window.FaceVerification.verifyLiveAgainstId(facePreviewImage, idFrontPreviewImage);
                        const faceScore  = Number(faceResult.score ?? faceResult.matchScore ?? 0);
                        const faceIsMatch = Boolean(faceResult.is_match ?? faceResult.isMatch ?? faceResult.match);
                        if (faceIsMatch) setFaceStatus('success', 'Face verified', Math.round(faceScore));
                        else setFaceStatus('warning', 'Face needs admin review. You can continue — ID details will be checked manually.', Math.round(faceScore));
                    } catch (e) {
                        setFaceStatus('warning', 'Face verification needs admin review. You can continue.');
                    }
                } else if (fields.face_capture.value && fields.face_capture.value !== 'manual') {
                    setFaceStatus('warning', 'Face photo uploaded. Identity will be reviewed by the Parish Office.');
                }

            } catch (error) {
                console.error('[ID OCR]', error);
                setIdOcrStatus('error', error && error.message ? error.message : 'The ID text could not be scanned. Please fill in details manually.');
            } finally {
                ocrScanInProgress = false;
                if (pendingOcrScan) {
                    pendingOcrScan = false;
                    scanCapturedIdText();
                }
            }
        }

        // ── Registration method toggle ────────────────────────────────────────
        function getRegistrationMethod() {
            const sel = verificationMethodInputs.find(i => i.checked);
            return sel ? sel.value : 'email';
        }
        function syncRegistrationMethod() {
            const method = getRegistrationMethod();
            registrationFieldGroups.forEach(group => {
                const isActive = group.dataset.registrationField === method;
                group.hidden = !isActive;
                group.querySelectorAll('input').forEach(input => {
                    input.required = isActive;
                    input.disabled = !isActive;
                    if (!isActive) input.classList.remove('is-invalid-field');
                });
            });
            setFieldError(method === 'email' ? 'phone_number' : 'email', '');
            setFieldError('verification_method', '');
        }
        verificationMethodInputs.forEach(i => i.addEventListener('change', () => { syncRegistrationMethod(); updateSubmitGate(); }));
        syncRegistrationMethod();

        // ── Password strength ────────────────────────────────────────────────
        function updatePasswordStrength() {
            const value = fields.password ? fields.password.value : '';
            let score = 0;
            if (value.length >= 8) score++;
            if (/[A-Z]/.test(value) && /[a-z]/.test(value)) score++;
            if (/\d/.test(value)) score++;
            if (/[^A-Za-z0-9]/.test(value)) score++;
            strengthBars.forEach((bar, index) => bar.classList.toggle('active', index < score));
            const labels = ['waiting for input','weak','fair','good','strong'];
            if (strengthText) strengthText.textContent = 'Password strength: ' + labels[score] + '.';
        }

        // ── Validation ───────────────────────────────────────────────────────
        function validateForm() {
            let isValid = true;
            const emailPattern = /^[^\s@]+@gmail\.com$/i;
            const phonePattern = /^(09\d{9}|\+639\d{9})$/;
            const registrationMethod = getRegistrationMethod();
            Object.keys(fields).forEach(fieldName => setFieldError(fieldName, ''));

            if (!fields.first_name.value.trim())   { setFieldError('first_name', 'First name is required.'); isValid = false; }
            if (!fields.surname.value.trim())       { setFieldError('surname', 'Surname is required.'); isValid = false; }
            if (fields.middle_initial.value.trim() && !/^[A-Za-z]$/.test(fields.middle_initial.value.trim())) { setFieldError('middle_initial', 'Use one letter only.'); isValid = false; }
            if (registrationMethod === 'mobile' && !phonePattern.test(fields.phone_number.value.trim())) { setFieldError('phone_number', 'Invalid mobile number. Please enter 09XXXXXXXXX or +639XXXXXXXXX.'); isValid = false; }
            if (registrationMethod === 'email'  && !emailPattern.test(fields.email.value.trim())) { setFieldError('email', 'Use a valid Gmail address.'); isValid = false; }
            if (!verificationMethodInputs.some(i => i.checked)) { setFieldError('verification_method', 'Please choose a verification method.'); isValid = false; }
            if (!fields.chapel_district.value) { setFieldError('chapel_district', 'Please select a chapel or district.'); isValid = false; }
            if (!fields.address.value.trim())  { setFieldError('address', 'Complete address is required.'); isValid = false; }
            if (!fields.birthdate.value) {
                setFieldError('birthdate', 'Birthdate is required.'); isValid = false;
            } else {
                const birthdate = parseDisplayDate(fields.birthdate.value);
                const minAge = new Date(); minAge.setFullYear(minAge.getFullYear() - 13);
                if (Number.isNaN(birthdate.getTime()) || birthdate > minAge) { setFieldError('birthdate', 'Use Month DD, YYYY format and make sure the registrant is at least 13 years old.'); isValid = false; }
            }
            if (!fields.birth_place.value.trim()) { setFieldError('birth_place', 'Place of birth is required.'); isValid = false; }
            if (!fields.id_number.value.trim())   { setFieldError('id_number', 'ID number must be scanned from your valid ID.'); isValid = false; }
            if (fields.password.value.length < <?php echo (int) PASSWORD_MIN_LENGTH; ?>) { setFieldError('password', <?php echo json_encode(passwordRequirementsMessage()); ?>); isValid = false; }
            if (fields.confirm_password.value !== fields.password.value) { setFieldError('confirm_password', 'Passwords do not match.'); isValid = false; }
            if (!fields.face_capture.value || !fields.valid_id_capture.value || !fields.valid_id_back_capture.value) {
                setFieldError('live_verification', 'Upload your Face Photo, Front ID, and Back ID to complete Step 1.');
                isValid = false;
            }
            if (!fields.terms_check.checked) { setFieldError('terms_check', 'Please accept the Terms & Conditions.'); isValid = false; }
            return isValid;
        }

        // ── Submit gate ───────────────────────────────────────────────────────
        function updateSubmitGate() {
            const submitBtn   = document.getElementById('registerSubmit');
            const gateNotice  = document.getElementById('submitGateNotice');
            const step1Pill   = document.getElementById('step1Pill');
            const step2Pill   = document.getElementById('step2Pill');
            const step4Pill   = document.getElementById('step4Pill');
            const regStep1    = document.getElementById('regStep1');
            const regStep2    = document.getElementById('regStep2');
            const regStep4    = document.getElementById('regStep4');
            const minPasswordLength = <?php echo (int) PASSWORD_MIN_LENGTH; ?>;
            if (!submitBtn) return;

            const hasFace  = Boolean(fields.face_capture && fields.face_capture.value);
            const hasFront = Boolean(fields.valid_id_capture && fields.valid_id_capture.value);
            const hasBack  = Boolean(fields.valid_id_back_capture && fields.valid_id_back_capture.value);
            const step1Complete = hasFace && hasFront && hasBack;
            if (regStep1) regStep1.classList.toggle('step-complete', step1Complete);
            if (step1Pill && !step1Complete) { step1Pill.textContent = 'Pending'; step1Pill.className = 'step-pill pending'; }

            const method = getRegistrationMethod();
            const contactValid = method === 'email'
                ? /^[^\s@]+@gmail\.com$/i.test(fields.email.value.trim())
                : /^(09\d{9}|\+639\d{9})$/.test(fields.phone_number.value.trim());
            const personalFilled = Boolean(
                fields.first_name.value.trim() && fields.surname.value.trim() &&
                fields.chapel_district.value && fields.address.value.trim() &&
                fields.birthdate.value.trim() && fields.birth_place.value.trim() &&
                fields.id_number.value.trim() && contactValid
            );
            if (personalFilled) {
                if (step2Pill && !step2Pill.classList.contains('done')) { step2Pill.textContent = 'Completed'; step2Pill.className = 'step-pill done'; }
                if (regStep2) regStep2.classList.add('step-complete');
            } else {
                if (regStep2) regStep2.classList.remove('step-complete');
            }

            const step3Complete = Boolean(fields.terms_check && fields.terms_check.checked);
            const passVal    = fields.password ? fields.password.value : '';
            const confirmVal = fields.confirm_password ? fields.confirm_password.value : '';
            const step4Complete = passVal.length >= minPasswordLength && passVal === confirmVal;
            if (step4Pill) {
                step4Pill.textContent = step4Complete ? '\u2713 Secured' : 'Incomplete';
                step4Pill.className   = 'step-pill ' + (step4Complete ? 'done' : 'pending');
                if (regStep4) regStep4.classList.toggle('step-complete', step4Complete);
            }

            const allComplete = step1Complete && personalFilled && step3Complete && step4Complete;
            submitBtn.disabled = !allComplete;
            if (gateNotice) {
                gateNotice.classList.toggle('is-ready', allComplete);
                if (allComplete) {
                    gateNotice.innerHTML = '<i class="fas fa-circle-check"></i><span>All requirements completed! Click <strong>Create Account</strong> to submit your registration.</span>';
                } else {
                    let hint = 'Complete all 4 steps above to enable account creation.';
                    if (!step1Complete)    hint = 'Step 1: Upload your Face Photo, Front ID, and Back ID.';
                    else if (!personalFilled) hint = 'Step 2: Fill in all required personal information fields.';
                    else if (!step3Complete)  hint = 'Step 3: Scroll to the end of the terms and check the agreement box.';
                    else if (!step4Complete)  hint = passVal.length < minPasswordLength
                        ? 'Step 4: Password must be at least ' + minPasswordLength + ' characters.'
                        : 'Step 4: Passwords do not match.';
                    gateNotice.innerHTML = '<i class="fas fa-circle-info"></i><span>' + hint + '</span>';
                }
            }
        }

        // ── Terms scroll gate ────────────────────────────────────────────────
        function initTermsScrollGate() {
            const termsBox    = document.getElementById('termsScrollBox');
            const termsNotice = document.getElementById('termsScrollNotice');
            const termsCheck  = document.getElementById('terms_check');
            const step3Pill   = document.getElementById('step3Pill');
            const regStep3    = document.getElementById('regStep3');
            const endMarker   = document.getElementById('termsScrollEndMarker');
            if (!termsBox || !termsCheck) return;
            let unlocked = !termsCheck.disabled;
            function unlockTerms() {
                if (unlocked) return;
                unlocked = true;
                termsCheck.disabled = false;
                if (termsNotice) { termsNotice.classList.add('is-done'); termsNotice.innerHTML = '<i class="fas fa-circle-check"></i><span>You have reached the end of the terms. You may now check the agreement box below.</span>'; }
                if (!termsCheck.checked && step3Pill) { step3Pill.textContent = 'Ready to Agree'; step3Pill.className = 'step-pill pending'; }
                updateSubmitGate();
            }
            if (termsCheck.checked) {
                unlocked = true; termsCheck.disabled = false;
                if (termsNotice) { termsNotice.classList.add('is-done'); termsNotice.innerHTML = '<i class="fas fa-circle-check"></i><span>Terms &amp; Conditions agreed.</span>'; }
                if (step3Pill)  { step3Pill.textContent = '\u2713 Agreed'; step3Pill.className = 'step-pill done'; }
                if (regStep3) regStep3.classList.add('step-complete');
            }
            termsBox.addEventListener('scroll', () => { if (termsBox.scrollHeight - termsBox.scrollTop - termsBox.clientHeight <= 25) unlockTerms(); }, { passive: true });
            if ('IntersectionObserver' in window && endMarker) {
                const obs = new IntersectionObserver(entries => { entries.forEach(e => { if (e.isIntersecting) unlockTerms(); }); }, { root: termsBox, threshold: 0.1 });
                obs.observe(endMarker);
            }
            if (termsBox.scrollHeight <= termsBox.clientHeight + 15) unlockTerms();
            termsCheck.addEventListener('change', () => {
                if (termsCheck.checked) {
                    if (step3Pill)  { step3Pill.textContent = '\u2713 Agreed'; step3Pill.className = 'step-pill done'; }
                    if (regStep3)   regStep3.classList.add('step-complete');
                    setFieldError('terms_check', '');
                } else {
                    if (step3Pill)  { step3Pill.textContent = 'Ready to Agree'; step3Pill.className = 'step-pill pending'; }
                    if (regStep3)   regStep3.classList.remove('step-complete');
                }
                updateSubmitGate();
            });
        }

        // ── Input event listeners for live gating ────────────────────────────
        Object.values(fields).forEach(field => {
            if (!field) return;
            field.addEventListener('input', () => {
                if (field === fields.middle_initial) field.value = field.value.replace(/[^A-Za-z]/g,'').slice(0,1).toUpperCase();
                if (field === fields.phone_number) {
                    let v = field.value.replace(/[^\d+]/g,'');
                    if (v.indexOf('+') > 0) v = v.replace(/\+/g,'');
                    field.value = v.startsWith('+') ? '+' + v.slice(1).replace(/\D/g,'').slice(0,12) : v.replace(/\D/g,'').slice(0,11);
                }
                if (field === fields.password) updatePasswordStrength();
                if (field.classList.contains('is-invalid-field')) validateForm();
                updateSubmitGate();
            });
            field.addEventListener('change', () => {
                if (field.classList.contains('is-invalid-field')) validateForm();
                updateSubmitGate();
            });
        });

        // ── Password toggle ───────────────────────────────────────────────────
        document.querySelectorAll('[data-toggle-password]').forEach(toggle => {
            toggle.addEventListener('click', e => {
                e.preventDefault(); e.stopPropagation();
                const targetId = toggle.dataset.togglePassword || toggle.getAttribute('data-toggle-password');
                const target = document.getElementById(targetId);
                if (!target) return;
                const icon = toggle.querySelector('i');
                const isPassword = target.type === 'password';
                target.type = isPassword ? 'text' : 'password';
                if (icon) { icon.classList.remove(isPassword ? 'fa-eye' : 'fa-eye-slash'); icon.classList.add(isPassword ? 'fa-eye-slash' : 'fa-eye'); }
                const fieldName = targetId === 'confirm_password' ? 'confirm password' : 'password';
                const newLabel  = isPassword ? 'Hide ' + fieldName : 'Show ' + fieldName;
                toggle.setAttribute('aria-label', newLabel); toggle.setAttribute('title', newLabel);
            });
        });

        // ── Form submit ───────────────────────────────────────────────────────
        form.addEventListener('submit', async event => {
            if (registrationSubmitInProgress) return;
            event.preventDefault();
            if (!validateForm()) { showToast('error', 'Check the form', 'Please correct the highlighted fields before creating your account.'); return; }
            submitButton.disabled = true;
            submitButton.classList.add('is-loading');
            submitText.textContent = 'Creating account...';
            try {
                await refreshRegistrationCsrfToken();
                registrationSubmitInProgress = true;
                form.submit();
            } catch (error) {
                submitButton.disabled = false;
                submitButton.classList.remove('is-loading');
                submitText.textContent = 'Create Account';
                showToast('error', 'Session token refresh failed', error.message || 'Please refresh the registration page and try again.');
            }
        });


        // ── Initialize ─────────────────────────────────────────────────────────
        initTermsScrollGate();
        updateSubmitGate();
    </script>
</body>
</html>
