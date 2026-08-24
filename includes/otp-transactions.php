<?php

require_once __DIR__ . '/authentication.php';
require_once __DIR__ . '/audit.php';

function otpTransactionByPublicId(mysqli $conn, string $publicId, bool $forUpdate = false): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/', $publicId)) {
        return null;
    }
    $sql = 'SELECT ot.*, u.fullname, u.email, u.phone_number, u.status, u.must_change_password
            FROM otp_transactions ot JOIN users u ON u.id = ot.user_id
            WHERE ot.public_id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
    $statement = $conn->prepare($sql);
    if (!$statement) {
        return null;
    }
    $statement->bind_param('s', $publicId);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc() ?: null;
    $statement->close();
    return $row;
}

function otpRateLimitAllows(mysqli $conn, int $userId, string $ip): bool {
    $accountLimit = max(1, (int) securitySetting($conn, 'otp.account_hourly_limit', 10));
    $ipLimit = max($accountLimit, (int) securitySetting($conn, 'otp.ip_hourly_limit', 30));
    $windowStart = date('Y-m-d H:i:s', time() - 3600);
    $statement = $conn->prepare(
        'SELECT SUM(user_id = ?) AS account_total, SUM(request_ip = ?) AS ip_total FROM otp_transactions WHERE created_at >= ?'
    );
    if (!$statement) {
        return false;
    }
    $statement->bind_param('iss', $userId, $ip, $windowStart);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    return (int) ($row['account_total'] ?? 0) < $accountLimit && (int) ($row['ip_total'] ?? 0) < $ipLimit;
}

function deliverTransactionOtp(mysqli $conn, array $user, string $method, string $destination, string $purpose, string $otp): bool {
    $purposeLabel = [
        'login' => 'secure sign-in',
        'password_reset' => 'password reset',
        'registration' => 'registration verification',
        'resubmission' => 'registration resubmission',
    ][$purpose] ?? 'account verification';
    $delivered = false;
    if ($method === 'mobile') {
        if (function_exists('sendTugonSms')) {
            $message = "TUGON code: {$otp}. Use it for {$purposeLabel}. It expires in 5 minutes. Do not share this code.";
            $result = sendTugonSms($conn, $destination, $message, (int) ($user['id'] ?? 0), 'otp_' . $purpose);
            $delivered = !empty($result['ok']);
        }
    } else {
        if (function_exists('sendTugonEmail') && function_exists('tugonEmailTemplate')) {
            $body = '<p>Hello ' . e($user['fullname'] ?? 'Parishioner') . ',</p>'
                . '<p>Your TUGON code for ' . e($purposeLabel) . ' is:</p>'
                . '<p style="font-size:28px;font-weight:800;letter-spacing:6px">' . e($otp) . '</p>'
                . '<p>This code expires in 5 minutes and can be used only once.</p>';
            $result = sendTugonEmail(
                $conn,
                $destination,
                'Your TUGON Security Code',
                tugonEmailTemplate('Security Verification', $body),
                '',
                (int) ($user['id'] ?? 0),
                'otp_' . $purpose
            );
            $delivered = !empty($result['ok']);
        }
    }

    if (!$delivered && (!defined('APP_ENVIRONMENT') || APP_ENVIRONMENT !== 'production')) {
        error_log("TUGON DEV OTP ({$method}) for [{$destination}]: {$otp}");
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['last_dev_otp'] = $otp;
            $_SESSION['last_dev_otp_destination'] = $destination;
        }
        return true;
    }

    return $delivered;
}

function createOtpTransaction(mysqli $conn, int $userId, string $purpose, string $method): array {
    $allowedPurposes = ['login', 'password_reset', 'registration', 'resubmission'];
    if (!in_array($purpose, $allowedPurposes, true) || !in_array($method, ['email', 'mobile'], true)) {
        return ['ok' => false, 'error' => 'Unable to create a secure verification transaction.'];
    }
    $statement = $conn->prepare(
        'SELECT u.id, u.fullname, u.status, i.normalized_value AS destination, i.verified_at AS destination_verified_at
         FROM users u
         JOIN user_auth_identifiers i ON i.user_id = u.id AND i.identifier_type = ?
         WHERE u.id = ? LIMIT 1'
    );
    if (!$statement) {
        return ['ok' => false, 'error' => 'Unable to create a secure verification transaction.'];
    }
    $statement->bind_param('si', $method, $userId);
    $statement->execute();
    $user = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!$user) {
        return ['ok' => false, 'error' => 'Unable to create a secure verification transaction.'];
    }
    $destination = (string) ($user['destination'] ?? '');
    if (($method === 'mobile' && !isValidPhilippineMobile($destination))
        || ($method === 'email' && !isValidEmail($destination))) {
        return ['ok' => false, 'error' => 'No valid delivery method is available.'];
    }
    if (!in_array($purpose, ['registration', 'resubmission', 'password_reset'], true) && empty($user['destination_verified_at'])) {
        return ['ok' => false, 'error' => 'No verified delivery method is available.'];
    }
    $ip = authenticationClientIp();
    if (!otpRateLimitAllows($conn, $userId, $ip)) {
        return ['ok' => false, 'error' => 'Too many verification requests. Please wait and try again.'];
    }

    $publicId = bin2hex(random_bytes(32));
    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otpHash = password_hash($otp, PASSWORD_DEFAULT);
    $ttl = max(60, (int) securitySetting($conn, 'otp.ttl_seconds', 300));
    $maxAttempts = max(3, (int) securitySetting($conn, 'otp.max_attempts', 5));
    $expires = date('Y-m-d H:i:s', time() + $ttl);
    $now = date('Y-m-d H:i:s');
    $agent = authenticationUserAgent();

    $conn->begin_transaction();
    try {
        $invalidate = $conn->prepare(
            'UPDATE otp_transactions SET invalidated_at = NOW() WHERE user_id = ? AND purpose = ? AND verified_at IS NULL AND invalidated_at IS NULL'
        );
        $invalidate->bind_param('is', $userId, $purpose);
        $invalidate->execute();
        $invalidate->close();

        $insert = $conn->prepare(
            'INSERT INTO otp_transactions (public_id, user_id, purpose, delivery_method, destination, otp_hash, expires_at, max_attempts, last_sent_at, request_ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$insert) {
            throw new RuntimeException('Unable to prepare the OTP transaction.');
        }
        $insert->bind_param('sisssssisss', $publicId, $userId, $purpose, $method, $destination, $otpHash, $expires, $maxAttempts, $now, $ip, $agent);
        if (!$insert->execute()) {
            throw new RuntimeException('Unable to store the OTP transaction.');
        }
        $insert->close();
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        return ['ok' => false, 'error' => 'Unable to create a secure verification transaction.'];
    }

    if (!deliverTransactionOtp($conn, $user, $method, $destination, $purpose, $otp)) {
        $invalidate = $conn->prepare('UPDATE otp_transactions SET invalidated_at = NOW() WHERE public_id = ?');
        $invalidate->bind_param('s', $publicId);
        $invalidate->execute();
        $invalidate->close();
        writeAuditLog($conn,$userId,'OTP_REQUEST_FAILURE','otp_transactions',null,null,['purpose'=>$purpose,'method'=>$method],'authentication','otp.request.failed');
        return ['ok' => false, 'error' => 'Unable to deliver the verification code.'];
    }
    writeAuditLog($conn,$userId,'OTP_REQUESTED','otp_transactions',null,null,['purpose'=>$purpose,'method'=>$method],'authentication','otp.requested');
    return ['ok' => true, 'transaction_id' => $publicId, 'expires_at' => $expires, 'method' => $method];
}

function resendOtpTransaction(mysqli $conn, string $publicId): array {
    $conn->begin_transaction();
    try {
        $transaction = otpTransactionByPublicId($conn, $publicId, true);
        if (!$transaction || $transaction['verified_at'] || $transaction['consumed_at'] || $transaction['invalidated_at']) {
            throw new RuntimeException('invalid');
        }
        $cooldown = max(10, (int) securitySetting($conn, 'otp.resend_cooldown_seconds', 60));
        $maximumResends = max(1, (int) securitySetting($conn, 'otp.max_resends', 4));
        if ((int) $transaction['resend_count'] >= $maximumResends) {
            $conn->rollback();
            return ['ok' => false, 'error' => 'The resend limit has been reached. Start again later.'];
        }
        $retryAfter = strtotime($transaction['last_sent_at']) + $cooldown - time();
        if ($retryAfter > 0) {
            $conn->rollback();
            return ['ok' => false, 'error' => 'Please wait before requesting another code.', 'retry_after' => $retryAfter];
        }
        if (!otpRateLimitAllows($conn, (int) $transaction['user_id'], authenticationClientIp())) {
            $conn->rollback();
            return ['ok' => false, 'error' => 'Too many verification requests. Please wait and try again.'];
        }
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = password_hash($otp, PASSWORD_DEFAULT);
        $ttl = max(60, (int) securitySetting($conn, 'otp.ttl_seconds', 300));
        $expires = date('Y-m-d H:i:s', time() + $ttl);
        $update = $conn->prepare(
            'UPDATE otp_transactions SET otp_hash = ?, expires_at = ?, attempt_count = 0, resend_count = resend_count + 1, last_sent_at = NOW() WHERE otp_transaction_id = ?'
        );
        $transactionId = (int) $transaction['otp_transaction_id'];
        $update->bind_param('ssi', $hash, $expires, $transactionId);
        $update->execute();
        $update->close();
        $conn->commit();

        $delivered = deliverTransactionOtp(
            $conn,
            ['id' => $transaction['user_id'], 'fullname' => $transaction['fullname']],
            $transaction['delivery_method'],
            $transaction['destination'],
            $transaction['purpose'],
            $otp
        );
        if (!$delivered) {
            $invalidate = $conn->prepare('UPDATE otp_transactions SET invalidated_at = NOW() WHERE otp_transaction_id = ?');
            $invalidate->bind_param('i', $transactionId);
            $invalidate->execute();
            $invalidate->close();
            return ['ok' => false, 'error' => 'Unable to deliver the verification code.'];
        }
        return ['ok' => true, 'expires_at' => $expires];
    } catch (Throwable $exception) {
        if ($conn->errno === 0) {
            $conn->rollback();
        }
        return ['ok' => false, 'error' => 'The verification transaction is invalid or expired.'];
    }
}

function verifyOtpTransaction(mysqli $conn, string $publicId, string $otp, ?string $expectedPurpose = null, bool $consume = true): array {
    if (!preg_match('/^\d{6}$/', $otp)) {
        return ['ok' => false, 'error' => 'The verification code is invalid.'];
    }
    $conn->begin_transaction();
    try {
        $transaction = otpTransactionByPublicId($conn, $publicId, true);
        if (!$transaction
            || ($expectedPurpose !== null && $transaction['purpose'] !== $expectedPurpose)
            || $transaction['verified_at']
            || $transaction['consumed_at']
            || $transaction['invalidated_at']) {
            throw new RuntimeException('invalid');
        }
        if (strtotime($transaction['expires_at']) < time()) {
            $invalidate = $conn->prepare('UPDATE otp_transactions SET invalidated_at = NOW() WHERE otp_transaction_id = ?');
            $id = (int) $transaction['otp_transaction_id'];
            $invalidate->bind_param('i', $id);
            $invalidate->execute();
            $invalidate->close();
            $conn->commit();
            return ['ok' => false, 'error' => 'The verification code has expired.'];
        }
        if ((int) $transaction['attempt_count'] >= (int) $transaction['max_attempts']) {
            $conn->rollback();
            return ['ok' => false, 'error' => 'The verification attempt limit has been reached.'];
        }
        $id = (int) $transaction['otp_transaction_id'];
        $increment = $conn->prepare('UPDATE otp_transactions SET attempt_count = attempt_count + 1 WHERE otp_transaction_id = ?');
        $increment->bind_param('i', $id);
        $increment->execute();
        $increment->close();
        if (!password_verify($otp, $transaction['otp_hash'])) {
            if ((int) $transaction['attempt_count'] + 1 >= (int) $transaction['max_attempts']) {
                $invalidate = $conn->prepare('UPDATE otp_transactions SET invalidated_at = NOW() WHERE otp_transaction_id = ?');
                $invalidate->bind_param('i', $id);
                $invalidate->execute();
                $invalidate->close();
            }
            $conn->commit();
            writeAuditLog($conn,(int)$transaction['user_id'],'OTP_VERIFICATION_FAILURE','otp_transactions',$id,null,['purpose'=>$transaction['purpose']],'authentication','otp.verify.failed');
            return ['ok' => false, 'error' => 'The verification code is invalid.'];
        }
        $sql = $consume
            ? 'UPDATE otp_transactions SET verified_at = NOW(), consumed_at = NOW() WHERE otp_transaction_id = ?'
            : 'UPDATE otp_transactions SET verified_at = NOW() WHERE otp_transaction_id = ?';
        $verified = $conn->prepare($sql);
        $verified->bind_param('i', $id);
        $verified->execute();
        $verified->close();
        $conn->commit();
        writeAuditLog($conn,(int)$transaction['user_id'],'OTP_VERIFICATION_SUCCESS','otp_transactions',$id,null,['purpose'=>$transaction['purpose']],'authentication','otp.verify.success');
        return ['ok' => true, 'transaction' => $transaction];
    } catch (Throwable $exception) {
        $conn->rollback();
        return ['ok' => false, 'error' => 'The verification transaction is invalid or expired.'];
    }
}

function consumeVerifiedOtpTransaction(mysqli $conn, string $publicId, string $purpose): ?array {
    $conn->begin_transaction();
    try {
        $transaction = otpTransactionByPublicId($conn, $publicId, true);
        if (!$transaction || $transaction['purpose'] !== $purpose || !$transaction['verified_at'] || $transaction['consumed_at'] || $transaction['invalidated_at']) {
            throw new RuntimeException('invalid');
        }
        $id = (int) $transaction['otp_transaction_id'];
        $statement = $conn->prepare('UPDATE otp_transactions SET consumed_at = NOW() WHERE otp_transaction_id = ?');
        $statement->bind_param('i', $id);
        $statement->execute();
        $statement->close();
        $conn->commit();
        return $transaction;
    } catch (Throwable $exception) {
        $conn->rollback();
        return null;
    }
}
