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
    } elseif (empty($_POST['terms_check'])) {
        $error = 'You must read and agree to the Terms & Conditions and parish verification policy.';
    } else {
        $identifier_type = $verification_method === 'mobile' ? 'mobile' : 'email';
        $identifier_value = $verification_method === 'mobile' ? $phone_number : $email;
        if (!authenticationIdentifierAvailable($conn, $identifier_type, $identifier_value)) {
            $error = 'Unable to complete registration with the information provided. Use account recovery or contact the parish office if you already applied.';
        }

        if (!$error) {
            $id_upload_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'valid_ids';
            $face_upload_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'live_faces';
            $id_number_hash = hashIdentityNumber($id_number);
            $id_number_hash_safe = $conn->real_escape_string($id_number_hash);
            $duplicate_id_result = $conn->query("SELECT id FROM users WHERE id_number_hash = '$id_number_hash_safe' LIMIT 1");
            if ($duplicate_id_result && $duplicate_id_result->num_rows > 0) {
                $error = 'This ID number has already been registered in the system.';
            }

            if (!$error) {
                $fullname = trim($first_name . ' ' . ($middle_initial !== '' ? $middle_initial . '. ' : '') . $surname);
                $id_saved = null;
                $id_back_saved = null;
                $face_saved = null;
                $db_path = null;
                $back_db_path = null;
                $face_db_path = null;
                $mime_type = null;
                $back_mime_type = null;
                $face_mime_type = null;
                $original_name = null;

                if (!empty($_POST['valid_id_capture'])) {
                    $id_capture = decodeCameraCapture($_POST['valid_id_capture'], 10 * 1024 * 1024);
                    if ($id_capture['ok']) {
                        $id_saved = saveEncryptedCameraCapture($id_capture, $id_upload_dir, 'live-valid-id-front');
                        if ($id_saved) {
                            $db_path = 'uploads/valid_ids/' . $id_saved['filename'];
                            $mime_type = $id_capture['mime_type'];
                            $original_name = 'philsys-id-front.' . $id_capture['extension'];
                        }
                    }
                }

                if (!empty($_POST['valid_id_back_capture'])) {
                    $id_back_capture = decodeCameraCapture($_POST['valid_id_back_capture'], 10 * 1024 * 1024);
                    if ($id_back_capture['ok']) {
                        $id_back_saved = saveEncryptedCameraCapture($id_back_capture, $id_upload_dir, 'live-valid-id-back');
                        if ($id_back_saved) {
                            $back_db_path = 'uploads/valid_ids/' . $id_back_saved['filename'];
                            $back_mime_type = $id_back_capture['mime_type'];
                        }
                    }
                }

                if (!empty($_POST['face_capture'])) {
                    $face_capture = decodeCameraCapture($_POST['face_capture'], 10 * 1024 * 1024);
                    if ($face_capture['ok']) {
                        $face_saved = saveEncryptedCameraCapture($face_capture, $face_upload_dir, 'live-face');
                        if ($face_saved) {
                            $face_db_path = 'uploads/live_faces/' . $face_saved['filename'];
                            $face_mime_type = $face_capture['mime_type'];
                        }
                    }
                }

                $hashed_password = hashPassword($password);
                $id_number_encrypted = encryptSensitiveValue($id_number);
                $face_status = $face_saved ? 'admin_review' : 'pending';

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

        .ocr-autofilled {
            background-color: #f0fdf4 !important;
            border-color: #86efac !important;
            transition: all 0.3s ease;
        }

        .is-low-confidence {
            background-color: #fffbeb !important;
            border-color: #fcd34d !important;
        }

        .ocr-confidence-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.73rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            margin-top: 4px;
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .id-scan-consent-wrap {
            background: rgba(212, 169, 78, 0.1) !important;
            border: 1px solid rgba(212, 169, 78, 0.35) !important;
            color: var(--register-text) !important;
            transition: border-color 0.2s ease, background 0.2s ease;
        }

        .id-scan-consent-wrap.is-consented {
            background: rgba(34, 197, 94, 0.08) !important;
            border-color: rgba(34, 197, 94, 0.35) !important;
        }

        .id-quality-notice {
            background: rgba(8, 115, 154, 0.08) !important;
            border: 1px solid rgba(8, 115, 154, 0.25) !important;
            color: var(--register-text) !important;
        }

        .id-mode-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
        }

        .id-mode-tab {
            flex: 1;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid var(--register-border);
            background: #ffffff;
            color: var(--register-text);
            font-size: 0.85rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .id-mode-tab.active {
            background: linear-gradient(135deg, var(--register-gold-soft), var(--register-gold));
            border-color: var(--register-gold);
            color: #14100d;
        }

        .capture-preview-card {
            position: relative;
        }

        .capture-preview-card .retake-btn {
            position: absolute;
            top: 6px;
            right: 6px;
            padding: 2px 8px;
            font-size: 0.72rem;
            font-weight: 700;
            border-radius: 6px;
            background: rgba(0,0,0,0.72);
            color: #ffffff;
            border: none;
            cursor: pointer;
        }

        .capture-preview-card .retake-btn:hover {
            background: #b91c1c;
        }

        .pcn-wrap {
            position: relative;
        }

        .pcn-toggle-btn {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: var(--register-muted);
            font-size: 0.9rem;
            cursor: pointer;
            padding: 4px 8px;
            z-index: 7;
        }

        .pcn-toggle-btn:hover {
            color: var(--register-link);
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
                            <strong><i class="fas fa-id-card" style="margin-right:5px;opacity:.7"></i>Philippine National ID Verification &amp; Scan</strong>
                            <span>Scan or upload your Philippine National ID (PhilSys ID) — OCR will auto-fill your personal details below.</span>
                        </div>
                        <div class="reg-step-status">
                            <span class="step-pill pending" id="step1Pill">Pending</span>
                        </div>
                    </div>

                    <!-- Mandatory Privacy & Security Consent Checkbox -->
                    <div class="id-scan-consent-wrap p-3 rounded-3 mb-3" id="idConsentBox">
                        <label class="d-flex align-items-start gap-2 mb-0" style="cursor: pointer;">
                            <input class="form-check-input mt-1" type="checkbox" id="idScanConsent" style="width:19px;height:19px;flex-shrink:0;cursor:pointer;">
                            <span style="font-size: 0.88rem; font-weight: 600; line-height: 1.45;">
                                I consent to my ID being scanned and my information being used to complete this registration.
                                <span class="d-block text-muted fw-normal mt-1" style="font-size: 0.78rem;">Your ID photo is processed securely in real time and raw temporary images are not permanently stored.</span>
                            </span>
                        </label>
                    </div>

                    <!-- Quality Guidance Tips -->
                    <div class="id-quality-notice p-3 rounded-3 mb-3">
                        <div class="d-flex align-items-center gap-2 mb-1" style="font-weight: 700; color: var(--register-ocean);">
                            <i class="fas fa-lightbulb text-warning"></i> Guidelines for High-Accuracy ID Scanning:
                        </div>
                        <ul class="mb-0 ps-3" style="font-size: 0.82rem; line-height: 1.45;">
                            <li>Make sure the ID is well-lit, completely flat, and fully visible inside the frame.</li>
                            <li>Avoid glare, flash reflections, finger shadows, or tilted/blurry angles.</li>
                            <li>Supports JPG, PNG, and WEBP formats (max 8MB).</li>
                        </ul>
                    </div>

                    <!-- Capture Mode Tabs -->
                    <div class="id-mode-tabs" id="idModeTabs">
                        <button type="button" class="id-mode-tab active" id="tabCameraMode">
                            <i class="fas fa-camera"></i> Live Camera Scan
                        </button>
                        <button type="button" class="id-mode-tab" id="tabUploadMode">
                            <i class="fas fa-cloud-arrow-up"></i> Upload ID Image
                        </button>
                    </div>

                    <div class="live-verification" id="liveVerification">
                        <!-- Camera Viewport Container -->
                        <div id="cameraSection">
                            <div class="camera-stage">
                                <video id="verificationVideo" playsinline muted></video>
                                <canvas id="captureCanvas" hidden></canvas>
                                <div class="face-guide" id="faceGuide" aria-hidden="true"></div>
                                <div class="id-guide" id="idGuide" aria-hidden="true">
                                    <span>Position PhilSys ID inside this frame</span>
                                </div>
                                <div class="camera-placeholder" id="cameraPlaceholder">
                                    <i class="fas fa-camera"></i>
                                    <strong>Camera Verification</strong>
                                    <small>Check the consent box above, then click Start Camera or Open Scanner to capture your PhilSys ID.</small>
                                </div>
                            </div>

                            <div class="verification-steps">
                                <div class="verification-step is-current" id="faceStep">
                                    <i class="fas fa-user-check"></i>
                                    <span>Face</span>
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
                                <button type="button" class="camera-btn" id="startCameraBtn" disabled>
                                    <i class="fas fa-video"></i> Start Camera
                                </button>
                                <button type="button" class="camera-btn secondary" id="captureIdFrontBtn" disabled>
                                    <i class="fas fa-camera-retro"></i> Scan Front ID
                                </button>
                                <button type="button" class="camera-btn secondary" id="captureIdBackBtn" disabled>
                                    <i class="fas fa-camera"></i> Scan Back ID
                                </button>
                            </div>
                        </div>

                        <!-- File Upload Container -->
                        <div id="uploadSection" style="display: none;">
                            <div class="id-upload-actions mb-3">
                                <label class="id-side-upload" for="frontIdUpload" id="frontIdUploadLabel">
                                    <i class="fas fa-upload"></i>
                                    <span>Upload Front PhilSys ID</span>
                                    <input type="file" id="frontIdUpload" accept="image/png,image/jpeg,image/webp" disabled>
                                </label>
                                <label class="id-side-upload" for="backIdUpload" id="backIdUploadLabel">
                                    <i class="fas fa-upload"></i>
                                    <span>Upload Back ID (Optional)</span>
                                    <input type="file" id="backIdUpload" accept="image/png,image/jpeg,image/webp" disabled>
                                </label>
                            </div>
                        </div>

                        <p class="camera-status" id="cameraStatus">Please check the consent box above to enable camera or file upload.</p>

                        <!-- Previews with Retake Buttons -->
                        <div class="capture-previews">
                            <div class="capture-preview capture-preview-card">
                                <span>Live Face</span>
                                <button type="button" class="retake-btn" id="retakeFaceBtn" style="display: none;" title="Retake face">Retake</button>
                                <img id="facePreviewImage" alt="Captured live face preview">
                            </div>
                            <div class="capture-preview capture-preview-card">
                                <span>Front PhilSys ID</span>
                                <button type="button" class="retake-btn" id="retakeFrontBtn" style="display: none;" title="Retake or re-upload Front ID">Retake</button>
                                <img id="idFrontPreviewImage" alt="Captured front ID preview">
                            </div>
                            <div class="capture-preview capture-preview-card">
                                <span>Back ID</span>
                                <button type="button" class="retake-btn" id="retakeBackBtn" style="display: none;" title="Retake or re-upload Back ID">Retake</button>
                                <img id="idBackPreviewImage" alt="Captured back ID preview">
                            </div>
                        </div>

                        <div class="face-match-status warning" id="faceMatchStatus">
                            <i class="fas fa-user-shield"></i>
                            <span>Capture your live face and PhilSys ID to compare identity details.</span>
                        </div>

                        <div class="id-ocr-status warning" id="idOcrStatus">
                            <i class="fas fa-id-card-clip"></i>
                            <span>PhilSys ID text will be scanned to auto-fill your personal information below.</span>
                        </div>

                        <!-- Always-Visible Manual Entry Fallback -->
                        <div class="d-flex justify-content-between align-items-center mt-3 pt-2" style="border-top: 1px dashed rgba(20,16,13,0.18);">
                            <span style="font-size: 0.84rem; color: var(--register-muted);"><i class="fas fa-circle-info me-1"></i> Prefer typing or scanner not available?</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="skipToManualBtn" style="font-weight: 700; border-radius: 8px;">
                                <i class="fas fa-pen-to-square me-1"></i> Enter details manually instead
                            </button>
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
                        <span id="regFieldsBannerText">Scan or upload your PhilSys ID above — OCR will auto-fill your personal details below.</span>
                    </div>

                    <!-- OCR Auto-fill Confirmation Banner -->
                    <div class="ocr-success-confirmation mb-3" id="ocrSuccessBanner" style="display: none;">
                        <div class="alert alert-success d-flex align-items-start gap-2 mb-0" style="border-radius: 10px; background: rgba(34, 197, 94, 0.12); border: 1px solid rgba(34, 197, 94, 0.35); color: #15803d;">
                            <i class="fas fa-circle-check fs-5 mt-1" style="color: #16a34a;"></i>
                            <div>
                                <strong>We've filled in your details from your ID.</strong>
                                <div style="font-size: 0.86rem; color: #166534;">Please review before continuing. All fields are editable so you can make any corrections.</div>
                            </div>
                        </div>
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
                            <div class="ocr-field-flag" id="flag_first_name"></div>
                            <div class="field-message" data-error-for="first_name"></div>
                        </div>

                        <div class="field-group">
                            <label for="surname" class="field-label">Surname</label>
                            <div class="input-wrap">
                                <i class="fas fa-user-tag field-icon"></i>
                                <input type="text" class="form-control" id="surname" name="surname" value="<?php echo e($form_data['surname']); ?>" autocomplete="family-name" required>
                            </div>
                            <div class="ocr-field-flag" id="flag_surname"></div>
                            <div class="field-message" data-error-for="surname"></div>
                        </div>

                        <div class="field-group">
                            <label for="middle_initial" class="field-label">Middle Initial</label>
                            <div class="input-wrap">
                                <i class="fas fa-signature field-icon"></i>
                                <input type="text" class="form-control" id="middle_initial" name="middle_initial" value="<?php echo e($form_data['middle_initial']); ?>" autocomplete="additional-name" maxlength="1" placeholder="Optional">
                            </div>
                            <div class="ocr-field-flag" id="flag_middle_initial"></div>
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
                            <div class="ocr-field-flag" id="flag_address"></div>
                            <div class="field-message" data-error-for="address"></div>
                        </div>

                        <div class="field-group">
                            <label for="birthdate" class="field-label">Birthdate</label>
                            <div class="input-wrap">
                                <i class="fas fa-calendar-day field-icon"></i>
                                <input type="text" class="form-control" id="birthdate" name="birthdate" value="<?php echo e($form_data['birthdate']); ?>" autocomplete="bday" placeholder="December 16, 2005" required>
                            </div>
                            <div class="ocr-field-flag" id="flag_birthdate"></div>
                            <div class="field-message" data-error-for="birthdate"></div>
                        </div>

                        <div class="field-group">
                            <label for="birth_place" class="field-label">Place of Birth</label>
                            <div class="input-wrap">
                                <i class="fas fa-map-pin field-icon"></i>
                                <input type="text" class="form-control" id="birth_place" name="birth_place" value="<?php echo e($form_data['birth_place']); ?>" autocomplete="off" placeholder="City / Municipality / Province" required>
                            </div>
                            <div class="ocr-field-flag" id="flag_birth_place"></div>
                            <div class="field-message" data-error-for="birth_place"></div>
                        </div>

                        <div class="field-group">
                            <label for="sex" class="field-label">Sex</label>
                            <div class="input-wrap">
                                <i class="fas fa-venus-mars field-icon"></i>
                                <select class="form-select" id="sex" name="sex">
                                    <option value="">Select Sex (Optional)</option>
                                    <option value="Male" <?php echo ($form_data['sex'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo ($form_data['sex'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                                </select>
                            </div>
                            <div class="ocr-field-flag" id="flag_sex"></div>
                            <div class="field-message" data-error-for="sex"></div>
                        </div>

                        <div class="field-group full">
                            <label for="id_number" class="field-label d-flex justify-content-between align-items-center">
                                <span>PhilSys Card Number (PCN) / ID Number</span>
                                <span id="pcnValidationBadge" class="badge" style="display:none; font-size:0.75rem;"></span>
                            </label>
                            <div class="input-wrap pcn-wrap">
                                <i class="fas fa-fingerprint field-icon"></i>
                                <input type="text" class="form-control" id="id_number" name="id_number" value="<?php echo e($form_data['id_number']); ?>" autocomplete="off" placeholder="XXXX-XXXX-XXXX-XXXX" required style="padding-right: 48px !important;">
                                <button type="button" class="pcn-toggle-btn" id="pcnToggleBtn" aria-label="Toggle ID number visibility" title="Show/Hide ID Number">
                                    <i class="fas fa-eye-slash" id="pcnToggleIcon"></i>
                                </button>
                            </div>
                            <div class="form-hint" id="pcnHint">16-digit PhilSys Card Number (XXXX-XXXX-XXXX-XXXX). Auto-filled fields remain editable.</div>
                            <div class="ocr-field-flag" id="flag_id_number"></div>
                            <div class="field-message" data-error-for="id_number"></div>
                        </div>
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
            sex: document.getElementById('sex'),
            id_number: document.getElementById('id_number'),
            password: document.getElementById('password'),
            confirm_password: document.getElementById('confirm_password'),
            face_capture: document.getElementById('face_capture'),
            valid_id_capture: document.getElementById('valid_id_capture'),
            valid_id_back_capture: document.getElementById('valid_id_back_capture'),
            terms_check: document.getElementById('terms_check')
        };

        const idScanConsent = document.getElementById('idScanConsent');
        const tabCameraMode = document.getElementById('tabCameraMode');
        const tabUploadMode = document.getElementById('tabUploadMode');
        const cameraSection = document.getElementById('cameraSection');
        const uploadSection = document.getElementById('uploadSection');
        const skipToManualBtn = document.getElementById('skipToManualBtn');
        const retakeFrontBtn = document.getElementById('retakeFrontBtn');
        const retakeBackBtn = document.getElementById('retakeBackBtn');
        const retakeFaceBtn = document.getElementById('retakeFaceBtn');
        const ocrSuccessBanner = document.getElementById('ocrSuccessBanner');
        const pcnToggleBtn = document.getElementById('pcnToggleBtn');
        const pcnToggleIcon = document.getElementById('pcnToggleIcon');
        const pcnValidationBadge = document.getElementById('pcnValidationBadge');

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

        function isConsentGiven() {
            return Boolean(idScanConsent && idScanConsent.checked);
        }

        // Set Field Error Function
        function setFieldError(fieldName, message) {
            const field = fields[fieldName] || document.getElementById(fieldName);
            const messageTarget = document.querySelector('[data-error-for="' + fieldName + '"]');
            if (field) {
                field.classList.toggle('is-invalid-field', Boolean(message));
            }
            if (messageTarget) {
                messageTarget.textContent = message || '';
            }
        }

        // Validate Form Function — Supports graceful fallback to manual entry
        function validateForm() {
            let isValid = true;
            const emailPattern = /^[^\s@]+@gmail\.com$/i;
            const phonePattern = /^(09\d{9}|\+639\d{9})$/;
            const registrationMethod = getRegistrationMethod();
            const isManualMode = idOcrStatusInput.value === 'manual';

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
                    setFieldError('birthdate', 'Use Month DD, YYYY format and make sure registrant is at least 13 years old.');
                    isValid = false;
                }
            }

            if (!fields.birth_place.value.trim()) {
                setFieldError('birth_place', 'Place of birth is required.');
                isValid = false;
            }

            if (!fields.id_number.value.trim()) {
                setFieldError('id_number', 'PhilSys ID number is required.');
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

            // Only require ID capture if not in explicit manual mode
            if (!isManualMode && !fields.valid_id_capture.value) {
                setFieldError('live_verification', 'Please scan or upload your PhilSys ID above, or click "Enter details manually instead".');
                isValid = false;
            }

            if (!fields.terms_check.checked) {
                setFieldError('terms_check', 'Please accept the Terms & Conditions.');
                isValid = false;
            }

            return isValid;
        }

        // Update Password Strength Function
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

        // Show Toast Function
        function showToast(type, title, message) {
            const toastStack = document.getElementById('toastStack');
            if (!toastStack) return;
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

        function setIdOcrStatus(type, message, aiEnhanced) {
            idOcrStatus.className = 'id-ocr-status ' + type;
            const icon = type === 'success' ? 'fa-circle-check' : (type === 'error' ? 'fa-circle-xmark' : (type === 'info' ? 'fa-circle-info' : 'fa-id-card-clip'));
            const aiBadge = aiEnhanced
                ? ' <span style="display:inline-flex;align-items:center;gap:4px;background:linear-gradient(135deg,#D4A94E,#B07D2A);color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:12px;letter-spacing:.4px;vertical-align:middle">✦ AI Enhanced</span>'
                : '';
            idOcrStatus.innerHTML = '<i class="fas ' + icon + '"></i><span>' + message + aiBadge + '</span>';
            if (idOcrStatusInput.value !== 'manual') {
                idOcrStatusInput.value = type === 'success' ? 'verified' : (type === 'error' ? 'mismatch' : 'pending');
            }
            _updateStepBadges(type);
        }

        function _updateStepBadges(ocrType) {
            const step1Pill  = document.getElementById('step1Pill');
            const step2Pill  = document.getElementById('step2Pill');
            const step1El    = document.getElementById('regStep1');
            const banner     = document.getElementById('regFieldsBanner');
            const bannerTxt  = document.getElementById('regFieldsBannerText');

            if (ocrType === 'success') {
                if (step1Pill)  { step1Pill.textContent = '\u2713 Scanned'; step1Pill.className = 'step-pill done'; }
                if (step2Pill)  { step2Pill.textContent = 'Auto-filled';   step2Pill.className = 'step-pill done'; }
                if (step1El)    step1El.classList.add('step-complete');
                if (banner)     banner.classList.add('is-unlocked');
                if (bannerTxt)  bannerTxt.textContent = '\u2726 PhilSys ID scanned \u2014 fields auto-filled below. Review and edit if needed.';
            } else if (ocrType === 'info') {
                if (step1Pill)  { step1Pill.textContent = 'Manual Entry'; step1Pill.className = 'step-pill manual'; }
                if (step1El)    step1El.classList.add('step-complete');
                if (banner)     banner.classList.add('is-unlocked');
                if (bannerTxt)  bannerTxt.textContent = 'Manual entry mode active \u2014 please fill in your details accurately.';
            } else if (ocrType === 'warning') {
                if (step1Pill)  { step1Pill.textContent = '\u26a0 Review'; step1Pill.className = 'step-pill error'; }
                if (banner)     banner.classList.add('is-unlocked');
                if (bannerTxt)  bannerTxt.textContent = 'Some details could not be read clearly. You can fill or correct them manually below.';
            } else if (ocrType === 'error') {
                if (step1Pill)  { step1Pill.textContent = '\u2715 Scan Failed'; step1Pill.className = 'step-pill error'; }
                if (banner)     banner.classList.add('is-unlocked');
                if (bannerTxt)  bannerTxt.textContent = 'ID scan could not read the text. Please enter your details manually below.';
            }
            updateSubmitGate();
        }

        function initTermsScrollGate() {
            const termsBox = document.getElementById('termsScrollBox');
            const termsNotice = document.getElementById('termsScrollNotice');
            const termsCheck = document.getElementById('terms_check');
            const step3Pill = document.getElementById('step3Pill');
            const regStep3 = document.getElementById('regStep3');
            const endMarker = document.getElementById('termsScrollEndMarker');

            if (!termsBox || !termsCheck) return;

            let unlocked = !termsCheck.disabled;

            function unlockTerms() {
                if (unlocked) return;
                unlocked = true;
                termsCheck.disabled = false;
                if (termsNotice) {
                    termsNotice.classList.add('is-done');
                    termsNotice.innerHTML = '<i class="fas fa-circle-check"></i><span>You have reached the end of the terms. You may now check the agreement box below.</span>';
                }
                if (!termsCheck.checked && step3Pill) {
                    step3Pill.textContent = 'Ready to Agree';
                    step3Pill.className = 'step-pill pending';
                }
                updateSubmitGate();
            }

            if (termsCheck.checked) {
                unlocked = true;
                termsCheck.disabled = false;
                if (termsNotice) {
                    termsNotice.classList.add('is-done');
                    termsNotice.innerHTML = '<i class="fas fa-circle-check"></i><span>Terms &amp; Conditions agreed.</span>';
                }
                if (step3Pill) {
                    step3Pill.textContent = '\u2713 Agreed';
                    step3Pill.className = 'step-pill done';
                }
                if (regStep3) regStep3.classList.add('step-complete');
            }

            termsBox.addEventListener('scroll', () => {
                const atBottom = termsBox.scrollHeight - termsBox.scrollTop - termsBox.clientHeight <= 25;
                if (atBottom) {
                    unlockTerms();
                }
            }, { passive: true });

            if ('IntersectionObserver' in window && endMarker) {
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            unlockTerms();
                        }
                    });
                }, { root: termsBox, threshold: 0.1 });
                observer.observe(endMarker);
            }

            if (termsBox.scrollHeight <= termsBox.clientHeight + 15) {
                unlockTerms();
            }

            termsCheck.addEventListener('change', () => {
                if (termsCheck.checked) {
                    if (step3Pill) {
                        step3Pill.textContent = '\u2713 Agreed';
                        step3Pill.className = 'step-pill done';
                    }
                    if (regStep3) regStep3.classList.add('step-complete');
                    setFieldError('terms_check', '');
                } else {
                    if (step3Pill) {
                        step3Pill.textContent = 'Ready to Agree';
                        step3Pill.className = 'step-pill pending';
                    }
                    if (regStep3) regStep3.classList.remove('step-complete');
                }
                updateSubmitGate();
            });
        }

        function updateSubmitGate() {
            const submitBtn = document.getElementById('registerSubmit');
            const gateNotice = document.getElementById('submitGateNotice');
            const step1Pill = document.getElementById('step1Pill');
            const step2Pill = document.getElementById('step2Pill');
            const step4Pill = document.getElementById('step4Pill');
            const regStep1 = document.getElementById('regStep1');
            const regStep2 = document.getElementById('regStep2');
            const regStep4 = document.getElementById('regStep4');
            const minPasswordLength = <?php echo (int) PASSWORD_MIN_LENGTH; ?>;

            if (!submitBtn) return;

            // Step 1: ID scanned, uploaded, or manual entry chosen
            const isManual = idOcrStatusInput.value === 'manual';
            const hasFront = Boolean(fields.valid_id_capture && fields.valid_id_capture.value);
            const step1Complete = isManual || hasFront;

            if (step1Complete) {
                if (regStep1) regStep1.classList.add('step-complete');
            } else {
                if (regStep1) regStep1.classList.remove('step-complete');
            }

            // Step 2: Personal information
            const method = getRegistrationMethod();
            const contactValid = method === 'email'
                ? /^[^\s@]+@gmail\.com$/i.test(fields.email.value.trim())
                : /^(09\d{9}|\+639\d{9})$/.test(fields.phone_number.value.trim());

            const personalFilled = Boolean(
                fields.first_name.value.trim() &&
                fields.surname.value.trim() &&
                fields.chapel_district.value &&
                fields.address.value.trim() &&
                fields.birthdate.value.trim() &&
                fields.birth_place.value.trim() &&
                fields.id_number.value.trim() &&
                contactValid
            );

            if (personalFilled) {
                if (step2Pill && !step2Pill.classList.contains('done')) {
                    step2Pill.textContent = 'Completed';
                    step2Pill.className = 'step-pill done';
                }
                if (regStep2) regStep2.classList.add('step-complete');
            } else {
                if (regStep2) regStep2.classList.remove('step-complete');
            }

            // Step 3: Terms agreed
            const step3Complete = Boolean(fields.terms_check && fields.terms_check.checked);

            // Step 4: Password valid & matched
            const passVal = fields.password ? fields.password.value : '';
            const confirmVal = fields.confirm_password ? fields.confirm_password.value : '';
            const step4Complete = passVal.length >= minPasswordLength && passVal === confirmVal;

            if (step4Pill) {
                if (step4Complete) {
                    step4Pill.textContent = '\u2713 Secured';
                    step4Pill.className = 'step-pill done';
                    if (regStep4) regStep4.classList.add('step-complete');
                } else {
                    step4Pill.textContent = 'Incomplete';
                    step4Pill.className = 'step-pill pending';
                    if (regStep4) regStep4.classList.remove('step-complete');
                }
            }

            const allComplete = step1Complete && personalFilled && step3Complete && step4Complete;

            if (allComplete) {
                submitBtn.disabled = false;
                if (gateNotice) {
                    gateNotice.classList.add('is-ready');
                    gateNotice.innerHTML = '<i class="fas fa-circle-check"></i><span>All requirements completed! Click <strong>Create Account</strong> to submit your registration.</span>';
                }
            } else {
                submitBtn.disabled = true;
                if (gateNotice) {
                    gateNotice.classList.remove('is-ready');
                    let hint = 'Complete all steps above to enable account creation.';
                    if (!step1Complete) {
                        hint = 'Step 1: Scan or upload your PhilSys ID, or click "Enter details manually instead".';
                    } else if (!personalFilled) {
                        hint = 'Step 2: Fill in all required personal information fields.';
                    } else if (!step3Complete) {
                        hint = 'Step 3: Scroll to the end of the terms and check the agreement box.';
                    } else if (!step4Complete) {
                        if (passVal.length < minPasswordLength) {
                            hint = 'Step 4: Password must be at least ' + minPasswordLength + ' characters.';
                        } else if (passVal !== confirmVal) {
                            hint = 'Step 4: Passwords do not match.';
                        }
                    }
                    gateNotice.innerHTML = '<i class="fas fa-circle-info"></i><span>' + hint + '</span>';
                }
            }
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

        // Apply OCR extracted field with confidence check and visual tagging
        function applyOcrField(fieldName, value, confidence, isTrusted) {
            const field = fields[fieldName] || document.getElementById(fieldName);
            const flagTarget = document.getElementById('flag_' + fieldName);
            if (!field || !value) return false;

            field.value = value;
            field.classList.add('ocr-autofilled');
            setFieldError(fieldName, '');

            if (!isTrusted) {
                field.classList.add('is-low-confidence');
                if (flagTarget) {
                    const scorePct = Math.round((confidence || 0) * 100);
                    flagTarget.innerHTML = '<span class="ocr-confidence-badge"><i class="fas fa-triangle-exclamation"></i> Low OCR confidence (' + scorePct + '%) \u2014 please verify</span>';
                }
            } else {
                field.classList.remove('is-low-confidence');
                if (flagTarget) {
                    flagTarget.innerHTML = '';
                }
            }
            return true;
        }

        // Main OCR Scanning Function
        async function scanCapturedIdText() {
            if (!fields.valid_id_capture.value) {
                setIdOcrStatus('warning', 'Please capture or upload the front of your PhilSys ID.');
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
            if (fields.valid_id_back_capture.value) {
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
            if (csrfToken) {
                fd.append(csrfTokenName, csrfToken);
            }

            try {
                setIdOcrStatus('warning', 'Reading PhilSys ID text and extracting personal information...');
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
                    throw new Error('The ID text could not be parsed clearly. You can still enter your details manually.');
                }

                if (!res.ok || !data.success) {
                    throw new Error(data.error || 'The ID text could not be scanned.');
                }

                const idData = data.id_data || {};
                const fieldConfidence = idData.field_confidence || {};
                const confThreshold = 0.65;
                const isTrustedOcr = (key) => Number(fieldConfidence[key] || 0) >= confThreshold;

                let filledCount = 0;

                // 1. Surname (Apelyido)
                if (idData.last_name) {
                    if (applyOcrField('surname', idData.last_name, fieldConfidence['last_name'], isTrustedOcr('last_name'))) {
                        filledCount++;
                    }
                }

                // 2. Given Names (Mga Pangalan)
                if (idData.first_name) {
                    if (applyOcrField('first_name', idData.first_name, fieldConfidence['first_name'], isTrustedOcr('first_name'))) {
                        filledCount++;
                    }
                }

                // 3. Middle Initial (Gitnang Apelyido)
                if (idData.middle_name) {
                    const mi = String(idData.middle_name).replace(/[^A-Za-z]/g, '').slice(0, 1).toUpperCase();
                    if (applyOcrField('middle_initial', mi, fieldConfidence['middle_name'], isTrustedOcr('middle_name'))) {
                        filledCount++;
                    }
                }

                // 4. Date of Birth (Petsa ng Kapanganakan)
                const dobDisplay = idData.date_of_birth_display || formatIsoDateForDisplay(idData.date_of_birth) || idData.date_of_birth;
                if (dobDisplay) {
                    if (applyOcrField('birthdate', dobDisplay, fieldConfidence['date_of_birth'], isTrustedOcr('date_of_birth'))) {
                        filledCount++;
                    }
                }

                // 5. Address (Tirahan)
                if (idData.address) {
                    if (applyOcrField('address', idData.address, fieldConfidence['address'], isTrustedOcr('address'))) {
                        filledCount++;
                    }
                }

                // 6. Place of Birth
                const birthPlaceVal = idData.birth_place || inferBirthPlaceFromAddress(idData.address);
                if (birthPlaceVal) {
                    if (applyOcrField('birth_place', birthPlaceVal, fieldConfidence['birth_place'] || 0.70, true)) {
                        filledCount++;
                    }
                }

                // 7. Sex (if present)
                if (idData.sex && fields.sex) {
                    const parsedSex = String(idData.sex).toLowerCase().startsWith('f') ? 'Female' : 'Male';
                    fields.sex.value = parsedSex;
                    fields.sex.classList.add('ocr-autofilled');
                }

                // 8. PhilSys Card Number (PCN)
                if (idData.id_number) {
                    const rawPcn = String(idData.id_number).replace(/\D/g, '');
                    const maskedPcn = idData.id_number_masked || (rawPcn.length === 16 ? '\u2022\u2022\u2022\u2022-\u2022\u2022\u2022\u2022-\u2022\u2022\u2022\u2022-' + rawPcn.slice(12) : rawPcn);
                    fields.id_number.dataset.rawPcn = rawPcn;
                    fields.id_number.dataset.maskedPcn = maskedPcn;
                    fields.id_number.value = maskedPcn;
                    fields.id_number.classList.add('ocr-autofilled');
                    setFieldError('id_number', '');

                    if (pcnValidationBadge) {
                        if (idData.id_number_valid) {
                            pcnValidationBadge.className = 'badge bg-success';
                            pcnValidationBadge.textContent = '\u2713 Valid 16-Digit PCN';
                            pcnValidationBadge.style.display = 'inline-block';
                        } else {
                            pcnValidationBadge.className = 'badge bg-warning text-dark';
                            pcnValidationBadge.textContent = 'Check PCN Format';
                            pcnValidationBadge.style.display = 'inline-block';
                        }
                    }
                    filledCount++;
                }

                // Show confirmation banner
                if (ocrSuccessBanner) {
                    ocrSuccessBanner.style.display = 'block';
                }

                // Unlock Step 2
                const banner = document.getElementById('regFieldsBanner');
                if (banner) banner.classList.add('is-unlocked');

                const isAiEnhanced = Boolean(data.ai_enhanced);
                const idTypeLabel = data.id_type_detected ? ' (' + data.id_type_detected + ')' : '';

                if (filledCount > 0) {
                    setIdOcrStatus('success', 'PhilSys ID scanned successfully' + idTypeLabel + '. Details auto-filled below \u2014 please review and edit if needed.', isAiEnhanced);
                    showToast('success', 'PhilSys ID Scanned', 'Registration fields auto-filled! Please review your details before continuing.');
                    // Smoothly scroll to Step 2
                    const regStep2 = document.getElementById('regStep2');
                    if (regStep2) {
                        regStep2.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                } else {
                    setIdOcrStatus('warning', 'OCR could not read the ID text clearly. Please enter your details manually below.');
                }
            } catch (error) {
                const message = (error && error.message) ? error.message : 'The ID text could not be scanned.';
                setIdOcrStatus('warning', message + ' You may fill in your details manually below.');
                const banner = document.getElementById('regFieldsBanner');
                if (banner) banner.classList.add('is-unlocked');
            }
        }

        async function verifyCapturedFace() {
            if (!fields.face_capture.value || !fields.valid_id_capture.value) return;

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
                    setFaceStatus('warning', 'Face verification will be reviewed by admin. You can continue registration.', Math.round(faceScore));
                    setFieldError('live_verification', '');
                }
            } catch (error) {
                setFaceStatus('warning', 'Face verification will be reviewed by admin. You can continue registration.');
                setFieldError('live_verification', '');
            }
        }

        // Clear low-confidence flags when parishioner edits any field
        ['first_name', 'surname', 'middle_initial', 'address', 'birthdate', 'birth_place', 'id_number', 'sex'].forEach((name) => {
            const el = fields[name] || document.getElementById(name);
            if (el) {
                el.addEventListener('input', () => {
                    el.classList.remove('is-low-confidence');
                    const flag = document.getElementById('flag_' + name);
                    if (flag) flag.innerHTML = '';
                    if (name === 'id_number') {
                        el.dataset.rawPcn = el.value;
                    }
                });
            }
        });

        Object.values(fields).forEach((field) => {
            if (!field) return;
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
                updateSubmitGate();
            });
            field.addEventListener('change', () => {
                if (field.classList.contains('is-invalid-field')) {
                    validateForm();
                }
                updateSubmitGate();
            });
        });

        verificationMethodInputs.forEach((input) => {
            input.addEventListener('change', () => {
                syncRegistrationMethod();
                updateSubmitGate();
            });
        });
        syncRegistrationMethod();

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

        function markStepDone(step) {
            if (!step) return;
            step.classList.remove('is-current');
            step.classList.add('is-done');
        }

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
                setCameraStatus('Capture or upload the ' + (side === 'front' ? 'front' : 'back') + ' side of your PhilSys ID. Align within the frame.');
            }
            updateSubmitGate();
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
                                if (retakeFaceBtn) retakeFaceBtn.style.display = 'inline-flex';
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
                                if (retakeFaceBtn) retakeFaceBtn.style.display = 'inline-flex';
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
                    if (retakeFaceBtn) retakeFaceBtn.style.display = 'inline-flex';
                    switchToIdCapture();
                }
            } catch (error) {
                faceDetectedSince = faceDetectedSince || Date.now();
                setCameraStatus('Face detector is warming up. Hold still inside the guide.');
                if (Date.now() - faceDetectedSince > 2600) {
                    fields.face_capture.value = captureFrame('face');
                    facePreviewImage.src = fields.face_capture.value;
                    if (retakeFaceBtn) retakeFaceBtn.style.display = 'inline-flex';
                    switchToIdCapture();
                }
            }
        }

        // Start Camera Button with Consent Guard
        startCameraBtn.addEventListener('click', async () => {
            if (!isConsentGiven()) {
                showToast('warning', 'Consent Required', 'Please check the consent box above before scanning or capturing your ID.');
                if (idScanConsent) {
                    idScanConsent.focus();
                    idScanConsent.parentElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return;
            }

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
            if (retakeFaceBtn) retakeFaceBtn.style.display = 'none';
            if (retakeFrontBtn) retakeFrontBtn.style.display = 'none';
            if (retakeBackBtn) retakeBackBtn.style.display = 'none';

            setFaceStatus('warning', 'Capture your live face and PhilSys ID to auto-fill registration details.');
            setIdOcrStatus('warning', 'PhilSys ID will be scanned to auto-fill registration details.');
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
            updateSubmitGate();
        });

        // Update ID Side Image (from Camera or File Upload)
        function updateIdSide(side, dataUrl) {
            if (side === 'back') {
                fields.valid_id_back_capture.value = dataUrl;
                idBackPreviewImage.src = dataUrl;
                markStepDone(idBackStep);
                if (retakeBackBtn) retakeBackBtn.style.display = 'inline-flex';
            } else {
                fields.valid_id_capture.value = dataUrl;
                idFrontPreviewImage.src = dataUrl;
                markStepDone(idFrontStep);
                if (retakeFrontBtn) retakeFrontBtn.style.display = 'inline-flex';
            }

            setFieldError('live_verification', '');

            // Trigger ID OCR scanning immediately whenever front ID is supplied!
            if (fields.valid_id_capture.value) {
                setCameraStatus('PhilSys ID front is ready. Scanning text to auto-fill registration details...');
                scanCapturedIdText();
            }

            if (fields.valid_id_capture.value && fields.valid_id_back_capture.value && fields.face_capture.value) {
                cameraStage.classList.remove('is-id-mode');
                verifyCapturedFace();
            } else if (side === 'front' && !fields.valid_id_back_capture.value) {
                switchToIdCapture('back');
            }
            updateSubmitGate();
        }

        // Live Capture Front Button
        captureIdFrontBtn.addEventListener('click', async () => {
            if (!isConsentGiven()) {
                showToast('warning', 'Consent Required', 'Please check the consent box above before capturing your ID.');
                return;
            }
            if (verificationMode !== 'id') return;
            try {
                if (window.IDScanner) {
                    setCameraStatus('Opening ID scanner…');
                    try {
                        const dataUrl = await window.IDScanner.openModal('front');
                        updateIdSide('front', dataUrl);
                    } catch (scanErr) {
                        if (scanErr.message !== 'Scanner cancelled.') {
                            activeIdSide = 'front';
                            updateIdSide('front', captureFrame('id'));
                        } else {
                            setCameraStatus('Scanner closed. Capture or upload the front of your PhilSys ID.');
                        }
                    }
                } else {
                    activeIdSide = 'front';
                    updateIdSide('front', captureFrame('id'));
                }
            } catch (error) {
                setCameraStatus(error.message, true);
            }
        });

        // Live Capture Back Button
        captureIdBackBtn.addEventListener('click', async () => {
            if (!isConsentGiven()) {
                showToast('warning', 'Consent Required', 'Please check the consent box above before capturing your ID.');
                return;
            }
            if (verificationMode !== 'id') return;
            try {
                if (window.IDScanner) {
                    setCameraStatus('Opening ID scanner…');
                    try {
                        const dataUrl = await window.IDScanner.openModal('back');
                        updateIdSide('back', dataUrl);
                    } catch (scanErr) {
                        if (scanErr.message !== 'Scanner cancelled.') {
                            activeIdSide = 'back';
                            updateIdSide('back', captureFrame('id'));
                        } else {
                            setCameraStatus('Scanner closed. Capture or upload the back of your PhilSys ID.');
                        }
                    }
                } else {
                    activeIdSide = 'back';
                    updateIdSide('back', captureFrame('id'));
                }
            } catch (error) {
                setCameraStatus(error.message, true);
            }
        });

        // Read image upload supporting JPG, PNG, and WEBP formats up to 8MB
        function readImageUpload(file) {
            return new Promise((resolve, reject) => {
                if (!file) {
                    reject(new Error('Please select an image file.'));
                    return;
                }
                if (!/^image\/(jpeg|png|webp)$/i.test(file.type)) {
                    reject(new Error('Please upload a JPG, PNG, or WEBP image.'));
                    return;
                }
                if (file.size > 8 * 1024 * 1024) {
                    reject(new Error('Image file size exceeds the 8MB limit.'));
                    return;
                }
                const reader = new FileReader();
                reader.onload = () => resolve(reader.result);
                reader.onerror = () => reject(new Error('The selected image could not be read.'));
                reader.readAsDataURL(file);
            });
        }

        async function handleIdUpload(side, input) {
            if (!isConsentGiven()) {
                showToast('warning', 'Consent Required', 'Please check the consent box above before uploading your ID.');
                input.value = '';
                if (idScanConsent) {
                    idScanConsent.focus();
                    idScanConsent.parentElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return;
            }

            try {
                const dataUrl = await readImageUpload(input.files[0]);
                updateIdSide(side, dataUrl);
                showToast('success', side === 'front' ? 'Front ID uploaded' : 'Back ID uploaded', 'The image is ready and being scanned.');
            } catch (error) {
                showToast('error', 'Upload failed', error.message);
            } finally {
                input.value = '';
            }
        }

        if (frontIdUpload) frontIdUpload.addEventListener('change', () => handleIdUpload('front', frontIdUpload));
        if (backIdUpload) backIdUpload.addEventListener('change', () => handleIdUpload('back', backIdUpload));

        // Retake Button Handlers
        if (retakeFrontBtn) {
            retakeFrontBtn.addEventListener('click', () => {
                fields.valid_id_capture.value = '';
                idFrontPreviewImage.removeAttribute('src');
                retakeFrontBtn.style.display = 'none';
                idFrontStep.className = 'verification-step is-current';
                captureIdFrontBtn.disabled = false;
                setCameraStatus('Front ID cleared. Ready to capture or upload again.');
                updateSubmitGate();
            });
        }

        if (retakeBackBtn) {
            retakeBackBtn.addEventListener('click', () => {
                fields.valid_id_back_capture.value = '';
                idBackPreviewImage.removeAttribute('src');
                retakeBackBtn.style.display = 'none';
                idBackStep.className = 'verification-step is-current';
                captureIdBackBtn.disabled = false;
                setCameraStatus('Back ID cleared. Ready to capture or upload again.');
                updateSubmitGate();
            });
        }

        if (retakeFaceBtn) {
            retakeFaceBtn.addEventListener('click', () => {
                fields.face_capture.value = '';
                facePreviewImage.removeAttribute('src');
                retakeFaceBtn.style.display = 'none';
                faceStep.className = 'verification-step is-current';
                setCameraStatus('Face capture cleared. Click Start Camera to capture your face.');
                updateSubmitGate();
            });
        }

        // Tab Switching: Live Camera vs Upload Mode
        if (tabCameraMode && tabUploadMode) {
            tabCameraMode.addEventListener('click', () => {
                tabCameraMode.classList.add('active');
                tabUploadMode.classList.remove('active');
                if (cameraSection) cameraSection.style.display = 'block';
                if (uploadSection) uploadSection.style.display = 'none';
            });

            tabUploadMode.addEventListener('click', () => {
                tabUploadMode.classList.add('active');
                tabCameraMode.classList.remove('active');
                if (cameraSection) cameraSection.style.display = 'none';
                if (uploadSection) uploadSection.style.display = 'block';
                // Stop camera stream when switching to upload mode
                if (cameraStream) {
                    cameraStream.getTracks().forEach((track) => track.stop());
                    cameraStream = null;
                    cameraStage.classList.remove('is-active', 'is-face-mode', 'is-id-mode');
                    setCameraStatus('Camera closed. Select your ID files using the upload boxes below.');
                }
            });
        }

        // Fallback: Skip to Manual Entry
        if (skipToManualBtn) {
            skipToManualBtn.addEventListener('click', (e) => {
                e.preventDefault();
                if (cameraStream) {
                    cameraStream.getTracks().forEach((track) => track.stop());
                    cameraStream = null;
                    cameraStage.classList.remove('is-active', 'is-face-mode', 'is-id-mode');
                }
                idOcrStatusInput.value = 'manual';
                setIdOcrStatus('info', 'Manual entry selected. Please type your details in the fields below.');

                const banner = document.getElementById('regFieldsBanner');
                const bannerTxt = document.getElementById('regFieldsBannerText');
                if (banner) banner.classList.add('is-unlocked');
                if (bannerTxt) bannerTxt.textContent = 'Manual entry mode active \u2014 please enter your information accurately.';

                const step1Pill = document.getElementById('step1Pill');
                if (step1Pill) {
                    step1Pill.textContent = 'Manual Entry';
                    step1Pill.className = 'step-pill manual';
                }
                const regStep1 = document.getElementById('regStep1');
                if (regStep1) regStep1.classList.add('step-complete');

                const regStep2 = document.getElementById('regStep2');
                if (regStep2) {
                    regStep2.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
                if (fields.first_name) {
                    fields.first_name.focus();
                }
                showToast('info', 'Manual Entry', 'You can now fill in all registration fields manually.');
                updateSubmitGate();
            });
        }

        // PCN Masking & Eye Toggle
        if (pcnToggleBtn && fields.id_number) {
            pcnToggleBtn.addEventListener('click', (e) => {
                e.preventDefault();
                const raw = fields.id_number.dataset.rawPcn;
                const masked = fields.id_number.dataset.maskedPcn;
                if (!raw) return;

                if (fields.id_number.value === masked) {
                    fields.id_number.value = raw;
                    if (pcnToggleIcon) {
                        pcnToggleIcon.classList.remove('fa-eye-slash');
                        pcnToggleIcon.classList.add('fa-eye');
                    }
                } else {
                    fields.id_number.value = masked;
                    if (pcnToggleIcon) {
                        pcnToggleIcon.classList.remove('fa-eye');
                        pcnToggleIcon.classList.add('fa-eye-slash');
                    }
                }
            });
        }

        // Password Show/Hide Toggle
        document.querySelectorAll('[data-toggle-password]').forEach((toggle) => {
            toggle.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const targetId = toggle.dataset.togglePassword || toggle.getAttribute('data-toggle-password');
                const target = document.getElementById(targetId);
                if (!target) return;
                const icon = toggle.querySelector('i');
                const isPassword = target.type === 'password';
                target.type = isPassword ? 'text' : 'password';
                if (icon) {
                    icon.classList.remove(isPassword ? 'fa-eye' : 'fa-eye-slash');
                    icon.classList.add(isPassword ? 'fa-eye-slash' : 'fa-eye');
                }
                const fieldName = targetId === 'confirm_password' ? 'confirm password' : 'password';
                const newLabel = isPassword ? `Hide ${fieldName}` : `Show ${fieldName}`;
                toggle.setAttribute('aria-label', newLabel);
                toggle.setAttribute('title', newLabel);
            });
        });

        // Form Submit Handler
        form.addEventListener('submit', async (event) => {
            if (registrationSubmitInProgress) {
                return;
            }
            event.preventDefault();

            // Restore raw unmasked PCN before form submission if currently masked
            if (fields.id_number && fields.id_number.dataset.rawPcn && fields.id_number.value.includes('\u2022')) {
                fields.id_number.value = fields.id_number.dataset.rawPcn;
            }

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

        // Initialize Terms scroll-gate and submit button gating
        initTermsScrollGate();
        updateSubmitGate();
    </script>
</body>
</html>
