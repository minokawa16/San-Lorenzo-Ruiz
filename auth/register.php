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
            width: 36px !important;
            height: 36px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            background: transparent !important;
            border: none !important;
            border-radius: 8px !important;
            cursor: pointer !important;
            z-index: 6 !important;
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

                    <div class="field-group">
                        <label for="id_number" class="field-label">ID Number</label>
                        <div class="input-wrap">
                            <i class="fas fa-fingerprint field-icon"></i>
                            <input type="text" class="form-control" id="id_number" name="id_number" value="<?php echo e($form_data['id_number']); ?>" autocomplete="off" placeholder="Enter ID number" required>
                        </div>
                        <div class="form-hint">Enter the ID number shown on your valid ID.</div>
                        <div class="field-message" data-error-for="id_number"></div>
                    </div>

                    <div class="field-group">
                        <label for="password" class="field-label">Password</label>
                        <div class="input-wrap password">
                            <i class="fas fa-lock field-icon"></i>
                            <input type="password" class="form-control" id="password" name="password" autocomplete="new-password" minlength="<?php echo (int) PASSWORD_MIN_LENGTH; ?>" required>
                            <button type="button" class="password-toggle" data-toggle-password="password" aria-label="Show password">
                                <i class="fas fa-eye"></i>
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

                            <div class="face-match-status warning" id="faceMatchStatus">
                                <i class="fas fa-user-shield"></i>
                                <span>Capture your live face and valid ID to compare identity details.</span>
                            </div>

                            <div class="id-ocr-status warning" id="idOcrStatus">
                                <i class="fas fa-id-card-clip"></i>
                                <span>Front and back ID text will be scanned to auto-fill identity details.</span>
                            </div>
                        </div>
                        <input type="hidden" id="face_capture" name="face_capture">
                        <input type="hidden" id="valid_id_capture" name="valid_id_capture">
                        <input type="hidden" id="valid_id_back_capture" name="valid_id_back_capture">
                        <input type="hidden" id="face_match_status_input" name="face_match_status" value="pending">
                        <input type="hidden" id="id_ocr_status_input" name="id_ocr_status" value="pending">
                        <input type="hidden" id="registration_id" name="registration_id" value="<?php echo htmlspecialchars($registration_verification_id, ENT_QUOTES); ?>">
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
    <script>
        const registrationVerificationId = <?php echo json_encode($registration_verification_id); ?>;
        const csrfTokenName = <?php echo json_encode(csrfTokenName()); ?>;
        const form = document.getElementById('registrationForm');
        const submitButton = document.getElementById('registerSubmit');
        const submitText = submitButton.querySelector('.submit-text');
        let registrationSubmitInProgress = false;

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

        function currentCsrfField() {
            return form.querySelector('input[name="' + csrfTokenName + '"]');
        }

        async function refreshRegistrationCsrfToken() {
            try {
                const response = await fetch('../api/csrf-token.php?context=registration&registration_id=' + encodeURIComponent(registrationVerificationId) + '&t=' + Date.now(), {
                    method: 'GET',
                    cache: 'no-store',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                const data = await response.json().catch(() => ({}));
                if (response.ok && data.success && data.token) {
                    const csrfField = currentCsrfField();
                    if (csrfField) {
                        csrfField.value = data.token;
                    }
                    return data.token;
                }
            } catch (e) {
                // Non-blocking fallback
            }
            const existing = currentCsrfField();
            return existing && existing.value ? existing.value : '';
        }
        const verificationMethodInputs = Array.from(document.querySelectorAll('input[name="verification_method"]'));
        const registrationFieldGroups = Array.from(document.querySelectorAll('[data-registration-field]'));
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
        const faceMatchStatus = document.getElementById('faceMatchStatus');
        const idOcrStatusInput = document.getElementById('id_ocr_status_input');
        const idOcrStatus = document.getElementById('idOcrStatus');
        const strengthBars = Array.from(document.querySelectorAll('.password-strength span'));
        const strengthText = document.getElementById('passwordStrengthText');
        let cameraStream = null;
        let verificationMode = 'face';
        let activeIdSide = 'front';
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
            const phonePattern = /^(09\d{9}|\+639\d{9})$/;
            const registrationMethod = getRegistrationMethod();

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

            if (registrationMethod === 'mobile' && !phonePattern.test(fields.phone_number.value.trim())) {
                setFieldError('phone_number', 'Invalid mobile number. Please enter 09XXXXXXXXX or +639XXXXXXXXX.');
                isValid = false;
            }

            if (registrationMethod === 'email' && !emailPattern.test(fields.email.value.trim())) {
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

            if (fields.password.value.length < <?php echo (int) PASSWORD_MIN_LENGTH; ?>) {
                setFieldError('password', <?php echo json_encode(passwordRequirementsMessage()); ?>);
                isValid = false;
            }

            if (fields.confirm_password.value !== fields.password.value) {
                setFieldError('confirm_password', 'Passwords do not match.');
                isValid = false;
            }

            if (!fields.face_capture.value || !fields.valid_id_capture.value || !fields.valid_id_back_capture.value) {
                setFieldError('live_verification', 'Complete live face capture plus front and back ID images.');
                isValid = false;
            } else if (idOcrStatusInput.value === 'mismatch') {
                setFieldError('live_verification', 'Fix the fields flagged by the front ID scan before registration.');
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

        function getRegistrationMethod() {
            const selected = verificationMethodInputs.find((input) => input.checked);
            return selected ? selected.value : 'email';
        }

        function syncRegistrationMethod() {
            const method = getRegistrationMethod();
            registrationFieldGroups.forEach((group) => {
                const isActive = group.dataset.registrationField === method;
                group.hidden = !isActive;
                group.querySelectorAll('input').forEach((input) => {
                    input.required = isActive;
                    input.disabled = !isActive;
                    if (!isActive) {
                        input.classList.remove('is-invalid-field');
                    }
                });
            });
            setFieldError(method === 'email' ? 'phone_number' : 'email', '');
            setFieldError('verification_method', '');
        }

        function setFaceStatus(type, message, score = null) {
            faceMatchStatus.className = 'face-match-status ' + type;
            const icon = type === 'success' ? 'fa-circle-check' : (type === 'error' ? 'fa-circle-xmark' : 'fa-user-shield');
            const scoreText = score === null ? '' : ' <strong>(' + score + '% match)</strong>';
            faceMatchStatus.innerHTML = '<i class="fas ' + icon + '"></i><span>' + message + scoreText + '</span>';
            faceMatchStatusInput.value = type === 'success' ? 'matched' : (type === 'error' ? 'mismatch' : 'admin_review');
        }

        function setIdOcrStatus(type, message) {
            idOcrStatus.className = 'id-ocr-status ' + type;
            const icon = type === 'success' ? 'fa-circle-check' : (type === 'error' ? 'fa-circle-xmark' : 'fa-id-card-clip');
            idOcrStatus.innerHTML = '<i class="fas ' + icon + '"></i><span>' + message + '</span>';
            idOcrStatusInput.value = type === 'success' ? 'verified' : (type === 'error' ? 'mismatch' : 'pending');
        }

        function hasFilledIdDetails() {
            return Boolean(
                fields.first_name.value.trim() &&
                fields.surname.value.trim() &&
                fields.address.value.trim() &&
                fields.birthdate.value.trim() &&
                fields.birth_place.value.trim() &&
                fields.id_number.value.trim()
            );
        }

        function formatIsoDateForDisplay(value) {
            if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) {
                return '';
            }
            const parts = value.split('-').map(Number);
            const date = new Date(parts[0], parts[1] - 1, parts[2]);
            return date.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
        }

        function parseDisplayDate(value) {
            const raw = String(value || '').trim();
            if (!raw) {
                return new Date(NaN);
            }
            const parsed = new Date(raw);
            if (!Number.isNaN(parsed.getTime())) {
                return parsed;
            }
            const match = raw.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/);
            if (!match) {
                return new Date(NaN);
            }
            return new Date(Number(match[3]), Number(match[1]) - 1, Number(match[2]));
        }

        function inferBirthPlaceFromAddress(address) {
            const parts = String(address || '')
                .split(',')
                .map((part) => part.trim())
                .filter(Boolean);
            if (parts.length < 2) {
                return '';
            }
            return (parts[parts.length - 2] + ', ' + parts[parts.length - 1]).toUpperCase();
        }

        function extractFirstJsonObject(text) {
            const source = String(text || '');
            const start = source.indexOf('{');
            if (start < 0) {
                return source;
            }
            let depth = 0;
            let inString = false;
            let escaped = false;
            for (let i = start; i < source.length; i++) {
                const char = source[i];
                if (escaped) {
                    escaped = false;
                    continue;
                }
                if (char === '\\') {
                    escaped = inString;
                    continue;
                }
                if (char === '"') {
                    inString = !inString;
                    continue;
                }
                if (inString) {
                    continue;
                }
                if (char === '{') {
                    depth++;
                } else if (char === '}') {
                    depth--;
                    if (depth === 0) {
                        return source.slice(start, i + 1);
                    }
                }
            }
            return source.slice(start);
        }

        async function scanCapturedIdText() {
            if (!fields.valid_id_capture.value || !fields.valid_id_back_capture.value) {
                setIdOcrStatus('warning', 'Capture both the front and back ID before scanning registration details.');
                return;
            }

            let csrfToken = '';
            try {
                csrfToken = await refreshRegistrationCsrfToken();
            } catch (error) {
                const existing = currentCsrfField();
                csrfToken = existing && existing.value ? existing.value : '';
            }
            const fd = new FormData();
            fd.append('id_photo_data', fields.valid_id_capture.value);
            fd.append('id_back_photo_data', fields.valid_id_back_capture.value);
            fd.append('first_name', fields.first_name.value);
            fd.append('surname', fields.surname.value);
            fd.append('middle_initial', fields.middle_initial.value);
            fd.append('address', fields.address.value);
            fd.append('birthdate', fields.birthdate.value);
            fd.append('birth_place', fields.birth_place.value);
            fd.append('id_number', fields.id_number.value);
            fd.append('registration_id', registrationVerificationId || '');
            if (csrfToken) {
                fd.append(csrfTokenName, csrfToken);
            }

            try {
                setIdOcrStatus('warning', 'Scanning the front and back ID text for registration details...');
                const headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
                if (csrfToken) {
                    headers['X-CSRF-Token'] = csrfToken;
                }
                const res = await fetch('../ocr/api_process_id.php?t=' + Date.now(), {
                    method: 'POST',
                    body: fd,
                    cache: 'no-store',
                    credentials: 'same-origin',
                    headers: headers
                });
                const responseText = await res.text();
                let data;
                try {
                    data = JSON.parse(extractFirstJsonObject(responseText));
                } catch (parseError) {
                    throw new Error('The ID text could not be scanned clearly. Please retake the ID photo and try again.');
                }

                if (!res.ok || !data.success) {
                    throw new Error(data.error || 'The ID text could not be scanned.');
                }

                const idData = data.id_data || {};
                const fieldConfidence = idData.field_confidence || {};
                const confidenceThresholds = {
                    last_name: 0.67,
                    first_name: 0.67,
                    middle_name: 0.67,
                    address: 0.60,
                    date_of_birth: 0.67,
                    birth_place: 0.60,
                    id_number: 0.67
                };
                const isTrustedOcr = (key) => Number(fieldConfidence[key] || 0) >= (confidenceThresholds[key] || 0.67);
                if (!idData.birth_place) {
                    idData.birth_place = inferBirthPlaceFromAddress(idData.address);
                }
                const readableFields = {
                    last_name: 'last name',
                    first_name: 'first name',
                    middle_name: 'middle name',
                    address: 'address',
                    date_of_birth: 'birthdate',
                    birth_place: 'place of birth',
                    id_number: 'ID number'
                };
                const readLabels = Object.keys(readableFields)
                    .filter((key) => Boolean(idData[key]) && isTrustedOcr(key))
                    .map((key) => readableFields[key]);
                const uncertainLabels = Object.keys(readableFields)
                    .filter((key) => Boolean(idData[key]) && !isTrustedOcr(key))
                    .map((key) => readableFields[key]);
                let filledCount = 0;
                function fillFromOcr(fieldName, ocrKey, value, formatter = null) {
                    if (!value || !fields[fieldName] || !isTrustedOcr(ocrKey)) {
                        return false;
                    }
                    const nextValue = formatter ? formatter(value) : value;
                    if (!nextValue) {
                        return false;
                    }
                    fields[fieldName].value = nextValue;
                    setFieldError(fieldName, '');
                    return true;
                }

                if (fillFromOcr('surname', 'last_name', idData.last_name)) {
                    setFieldError('surname', '');
                    filledCount++;
                }
                if (fillFromOcr('first_name', 'first_name', idData.first_name)) {
                    setFieldError('first_name', '');
                    filledCount++;
                }
                if (fillFromOcr('middle_initial', 'middle_name', idData.middle_name, (value) => String(value).replace(/[^A-Za-z]/g, '').slice(0, 1).toUpperCase())) {
                    setFieldError('middle_initial', '');
                    filledCount++;
                }
                if (fillFromOcr('address', 'address', idData.address)) {
                    setFieldError('address', '');
                    filledCount++;
                }
                if (fillFromOcr('birth_place', 'birth_place', idData.birth_place)) {
                    setFieldError('birth_place', '');
                    filledCount++;
                }
                if (fillFromOcr('id_number', 'id_number', idData.id_number)) {
                    setFieldError('id_number', '');
                    filledCount++;
                }
                if (fillFromOcr('birthdate', 'date_of_birth', idData.date_of_birth, formatIsoDateForDisplay)) {
                    setFieldError('birthdate', '');
                    filledCount++;
                }

                const fieldMap = {
                    last_name: 'surname',
                    first_name: 'first_name',
                    middle_name: 'middle_initial',
                    address: 'address'
                };
                let hasMismatch = false;
                let correctedCount = 0;
                let readableCount = filledCount;

                Object.keys(fieldMap).forEach((ocrField) => {
                    const result = data.comparison && data.comparison[ocrField];
                    const inputName = fieldMap[ocrField];
                    if (!result || !fields[inputName] || !isTrustedOcr(ocrField)) {
                        return;
                    }

                    if (result.status === 'corrected') {
                        fields[inputName].value = inputName === 'middle_initial'
                            ? String(result.final_value || '').replace(/[^A-Za-z]/g, '').slice(0, 1).toUpperCase()
                            : result.final_value;
                        setFieldError(inputName, '');
                        correctedCount++;
                        readableCount++;
                    } else if (result.status === 'match' || result.status === 'id_field_not_found') {
                        setFieldError(inputName, '');
                        if (result.status === 'match') {
                            readableCount++;
                        }
                    } else if (result.status === 'mismatch') {
                        const ocrValue = idData[ocrField] || result.final_value;
                        if (ocrValue) {
                            fields[inputName].value = inputName === 'middle_initial'
                                ? String(ocrValue || '').replace(/[^A-Za-z]/g, '').slice(0, 1).toUpperCase()
                                : ocrValue;
                            setFieldError(inputName, '');
                            correctedCount++;
                            readableCount++;
                            return;
                        }
                        hasMismatch = true;
                        const similarity = result.similarity === null ? '' : ' Similarity: ' + result.similarity + '%.';
                        setFieldError(inputName, 'This does not match the ID scan.' + similarity);
                    }
                });

                if (hasMismatch) {
                    setIdOcrStatus('error', 'ID scan found field mismatches. Review the highlighted values before submitting.');
                    showToast('error', 'ID scan mismatch', 'Please correct the fields highlighted by the ID scan.');
                    return;
                }

                if (readableCount === 0) {
                    setIdOcrStatus('warning', 'OCR could not read the ID text. Retake the front and back ID photos upright, sharp, and filling the frame.');
                    return;
                }

                const changedTotal = correctedCount + filledCount;
                const readSummary = readLabels.length ? ' Read: ' + readLabels.join(', ') + '.' : '';
                if (uncertainLabels.length) {
                    setIdOcrStatus('warning', 'Some ID text needs your review: ' + uncertainLabels.join(', ') + '. Retake the ID closer and in bright, even light if a value is wrong.' + readSummary);
                    return;
                }
                setIdOcrStatus('success', changedTotal > 0
                    ? 'ID scanned successfully and filled the registration details.' + readSummary
                    : 'ID scanned successfully. Typed details match the readable ID fields.' + readSummary);
            } catch (error) {
                const rawMessage = error && error.message ? error.message : '';
                const message = rawMessage || 'The ID text could not be scanned.';
                setIdOcrStatus('warning', message);
            }
        }

        async function verifyCapturedFace() {
            if (!fields.face_capture.value || !fields.valid_id_capture.value || !fields.valid_id_back_capture.value) return;

            try {
                if (!window.FaceVerification || typeof window.FaceVerification.verifyLiveAgainstId !== 'function') {
                    throw new Error('Face verification module is not loaded.');
                }

                setFaceStatus('warning', 'Comparing the live face with the face printed on the ID...');
                const faceResult = await window.FaceVerification.verifyLiveAgainstId(facePreviewImage, idFrontPreviewImage);
                const faceScore = Number(faceResult.score ?? faceResult.matchScore ?? faceResult.distanceScore ?? 0);
                const faceIsMatch = Boolean(faceResult.is_match ?? faceResult.isMatch ?? faceResult.match);

                if (faceIsMatch) {
                    setFaceStatus('success', 'Face Verification Successful', Math.round(faceScore));
                    setFieldError('live_verification', '');
                } else {
                    setFaceStatus('warning', 'Face verification needs admin review. You can continue because the ID details will be checked manually.', Math.round(faceScore));
                    setFieldError('live_verification', '');
                }
            } catch (error) {
                const rawMessage = error && error.message ? error.message : '';
                const isModelJsonError = rawMessage.includes('Unexpected non-whitespace character after JSON');
                const message = isModelJsonError
                    ? 'Face verification needs admin review. You can continue because the ID details will be checked manually.'
                    : 'Face verification needs admin review. You can continue because the ID details will be checked manually.';
                setFaceStatus('warning', message);
                setFieldError('live_verification', '');
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
                    let value = field.value.replace(/[^\d+]/g, '');
                    if (value.indexOf('+') > 0) {
                        value = value.replace(/\+/g, '');
                    }
                    if (value.startsWith('+')) {
                        value = '+' + value.slice(1).replace(/\D/g, '').slice(0, 12);
                    } else {
                        value = value.replace(/\D/g, '').slice(0, 11);
                    }
                    field.value = value;
                }
                if (field === fields.password) {
                    updatePasswordStrength();
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
                syncRegistrationMethod();
            });
        });
        syncRegistrationMethod();

        // Set Camera Status Function - Documents this helper's role in the parish management workflow.
        function setCameraStatus(message, isError = false) {
            cameraStatus.textContent = message;
            cameraStatus.style.color = isError ? '#fecaca' : 'rgba(255, 248, 235, 0.78)';
        }

        async function startCamera(facingMode = 'user') {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                const reason = window.isSecureContext
                    ? 'This browser does not support camera access.'
                    : 'Camera access requires HTTPS. Open the secure https:// site and try again.';
                setCameraStatus(reason, true);
                setFieldError('live_verification', reason);
                return false;
            }

            if (cameraStream) {
                cameraStream.getTracks().forEach((track) => track.stop());
            }

            try {
                const preferred = {
                    video: { facingMode: { ideal: facingMode }, width: { ideal: 1920 }, height: { ideal: 1080 } },
                    audio: false
                };
                try {
                    cameraStream = await navigator.mediaDevices.getUserMedia(preferred);
                } catch (preferredError) {
                    if (preferredError && ['NotAllowedError', 'SecurityError'].includes(preferredError.name)) {
                        throw preferredError;
                    }
                    // Desktop webcams and older mobile browsers may reject facingMode.
                    cameraStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
                }
                video.srcObject = cameraStream;
                await new Promise((resolve) => {
                    if (video.readyState >= 1 && video.videoWidth > 0) {
                        resolve();
                        return;
                    }
                    video.addEventListener('loadedmetadata', resolve, { once: true });
                });
                await video.play();
                const track = cameraStream.getVideoTracks()[0];
                const capabilities = track && typeof track.getCapabilities === 'function' ? track.getCapabilities() : {};
                if (Array.isArray(capabilities.focusMode) && capabilities.focusMode.includes('continuous')) {
                    track.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }).catch(() => {});
                }
                cameraStage.classList.add('is-active');
                setFieldError('live_verification', '');
                return true;
            } catch (error) {
                const blocked = error && ['NotAllowedError', 'SecurityError'].includes(error.name);
                const unavailable = error && ['NotFoundError', 'DevicesNotFoundError'].includes(error.name);
                const message = blocked
                    ? 'Camera permission is blocked. Allow camera access for this site in your browser settings, then try again.'
                    : (unavailable
                        ? 'No usable camera was found on this device.'
                        : 'The camera could not start. Close other apps using it and try again.');
                setCameraStatus(message, true);
                setFieldError('live_verification', message);
                return false;
            }
        }

        // Capture Frame Function - Documents this helper's role in the parish management workflow.
        function captureFrame(mode = 'full') {
            if (!cameraStream || video.readyState < 2 || !video.videoWidth || !video.videoHeight) {
                throw new Error('The camera is not ready yet. Wait for the live preview, then capture again.');
            }
            const width = video.videoWidth;
            const height = video.videoHeight;
            const source = {
                x: 0,
                y: 0,
                width,
                height
            };

            if (mode === 'face') {
                source.x = Math.round(width * 0.24);
                source.y = Math.round(height * 0.04);
                source.width = Math.round(width * 0.52);
                source.height = Math.round(height * 0.82);
            } else if (mode === 'id') {
                // Crop to the physical ID-1 card ratio (85.60 x 53.98 mm).
                // This stays correct whether the camera preview is portrait or landscape.
                const cardRatio = 1.586;
                const maxWidth = width * 0.92;
                const maxHeight = height * 0.80;
                if (maxWidth / maxHeight > cardRatio) {
                    source.height = Math.round(maxHeight);
                    source.width = Math.round(source.height * cardRatio);
                } else {
                    source.width = Math.round(maxWidth);
                    source.height = Math.round(source.width / cardRatio);
                }
                source.x = Math.round((width - source.width) / 2);
                source.y = Math.round((height - source.height) / 2);
            }

            canvas.width = mode === 'id' ? 1800 : (mode === 'face' ? 720 : width);
            canvas.height = mode === 'id'
                ? Math.round(1800 * (source.height / source.width))
                : (mode === 'face' ? Math.round(720 * (source.height / source.width)) : height);
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
        async function switchToIdCapture(side = 'front') {
            verificationMode = 'id';
            activeIdSide = side;
            clearInterval(detectionTimer);
            cameraStage.classList.remove('is-face-mode');
            cameraStage.classList.add('is-id-mode');
            markStepDone(faceStep);
            idFrontStep.classList.toggle('is-current', side === 'front');
            idBackStep.classList.toggle('is-current', side === 'back');
            captureIdFrontBtn.disabled = true;
            captureIdBackBtn.disabled = true;
            setCameraStatus('Switching to the ID camera...');
            const started = await startCamera('environment');
            if (started) {
                captureIdFrontBtn.disabled = false;
                captureIdBackBtn.disabled = false;
                setCameraStatus('Capture or upload the ' + (side === 'front' ? 'front' : 'back') + ' side of the ID. Fill the yellow frame and keep text sharp.');
            }
        }

        async function detectFaceLoop() {
            if (verificationMode !== 'face') {
                return;
            }

            try {
                if (!video.videoWidth || video.readyState < 2) {
                    setCameraStatus('Camera is starting. Keep your face inside the guide.');
                    return;
                }

                if (window.FaceVerification && window.faceapi) {
                    await window.FaceVerification.loadModels();
                    const result = await faceapi.detectSingleFace(
                        video,
                        new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.25 })
                    );
                    if (result) {
                        const box = result.box || (result.detection && result.detection.box);
                        if (!box) {
                            setCameraStatus('Face detected. Hold still for automatic capture.');
                            faceDetectedSince = faceDetectedSince || Date.now();
                            if (Date.now() - faceDetectedSince > 900) {
                                fields.face_capture.value = captureFrame('face');
                                facePreviewImage.src = fields.face_capture.value;
                                switchToIdCapture();
                            }
                            return;
                        }
                        const centerX = box.x + box.width / 2;
                        const centerY = box.y + box.height / 2;
                        const aligned = centerX > video.videoWidth * 0.22 &&
                            centerX < video.videoWidth * 0.78 &&
                            centerY > video.videoHeight * 0.12 &&
                            centerY < video.videoHeight * 0.88 &&
                            box.width > video.videoWidth * 0.08;

                        if (aligned) {
                            faceDetectedSince = faceDetectedSince || Date.now();
                            setCameraStatus('Face detected. Hold still for automatic capture.');
                            if (Date.now() - faceDetectedSince > 900) {
                                fields.face_capture.value = captureFrame('face');
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
                setCameraStatus('Face detector is loading. Hold still inside the guide.');
                if (Date.now() - faceDetectedSince > 2200) {
                    fields.face_capture.value = captureFrame('face');
                    facePreviewImage.src = fields.face_capture.value;
                    switchToIdCapture();
                }
            } catch (error) {
                faceDetectedSince = faceDetectedSince || Date.now();
                setCameraStatus('Face detector is warming up. Hold still inside the guide.');
                if (Date.now() - faceDetectedSince > 2600) {
                    fields.face_capture.value = captureFrame('face');
                    facePreviewImage.src = fields.face_capture.value;
                    switchToIdCapture();
                }
            }
        }

        startCameraBtn.addEventListener('click', async () => {
            verificationMode = 'face';
            activeIdSide = 'front';
            fields.face_capture.value = '';
            fields.valid_id_capture.value = '';
            fields.valid_id_back_capture.value = '';
            faceMatchStatusInput.value = 'pending';
            idOcrStatusInput.value = 'pending';
            facePreviewImage.removeAttribute('src');
            idFrontPreviewImage.removeAttribute('src');
            idBackPreviewImage.removeAttribute('src');
            setFaceStatus('warning', 'Capture your live face and valid ID to compare identity details.');
            setIdOcrStatus('warning', 'Front and back ID text will be scanned to auto-fill identity details.');
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

            setFieldError('live_verification', '');

            if (fields.valid_id_capture.value && fields.valid_id_back_capture.value) {
                cameraStage.classList.remove('is-id-mode');
                setCameraStatus('Front and back ID images are ready. Scanning ID text and comparing the front ID face...');
                scanCapturedIdText();
                verifyCapturedFace();
            } else {
                const nextSide = fields.valid_id_capture.value ? 'back' : 'front';
                switchToIdCapture(nextSide);
            }
        }

        captureIdFrontBtn.addEventListener('click', () => {
            if (verificationMode !== 'id') return;
            try {
                activeIdSide = 'front';
                updateIdSide('front', captureFrame('id'));
            } catch (error) {
                setCameraStatus(error.message, true);
            }
        });

        captureIdBackBtn.addEventListener('click', () => {
            if (verificationMode !== 'id') return;
            try {
                activeIdSide = 'back';
                updateIdSide('back', captureFrame('id'));
            } catch (error) {
                setCameraStatus(error.message, true);
            }
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
                showToast('success', side === 'front' ? 'Front ID uploaded' : 'Back ID uploaded', 'The image is ready for verification.');
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

        form.addEventListener('submit', async (event) => {
            if (registrationSubmitInProgress) {
                return;
            }
            event.preventDefault();

            if (!validateForm()) {
                showToast('error', 'Check the form', 'Please correct the highlighted fields before creating your account.');
                return;
            }

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
    </script>
</body>
</html>
