<?php

require_once __DIR__ . '/authentication.php';

function passwordWasRecentlyUsed(mysqli $conn, int $userId, string $candidate): bool {
    try {
        $limit = max(1, (int) securitySetting($conn, 'security.password_history_count', 5));
        $statement = $conn->prepare(
            'SELECT password_hash FROM password_security_history WHERE user_id = ? AND password_hash IS NOT NULL ORDER BY created_at DESC, history_id DESC LIMIT ?'
        );
        if (!$statement) {
            return false;
        }
        $statement->bind_param('ii', $userId, $limit);
        $statement->execute();
        $result = $statement->get_result();
        while ($row = $result->fetch_assoc()) {
            if (!empty($row['password_hash']) && password_verify($candidate, $row['password_hash'])) {
                $statement->close();
                return true;
            }
        }
        $statement->close();
    } catch (Throwable $e) {
        error_log('Password history check warning: ' . $e->getMessage());
    }
    return false;
}

function updateAccountPassword(
    mysqli $conn,
    int $userId,
    string $newPassword,
    string $source = 'authenticated_change',
    ?int $actorUserId = null,
    string $eventType = 'password_changed'
): array {
    if (!isValidPassword($newPassword)) {
        return ['ok' => false, 'error' => passwordRequirementsMessage()];
    }

    try {
        $select = $conn->prepare('SELECT id, password, phone_number, email FROM users WHERE id = ? LIMIT 1');
        if (!$select) {
            return ['ok' => false, 'error' => 'Database error preparing verification.'];
        }
        $select->bind_param('i', $userId);
        $select->execute();
        $user = $select->get_result()->fetch_assoc();
        $select->close();

        if (!$user) {
            return ['ok' => false, 'error' => 'Account not found.'];
        }

        if (password_verify($newPassword, $user['password'])) {
            return ['ok' => false, 'error' => 'Your new password cannot be the same as your current password.'];
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        // Core Update: Save new password to database
        $update = $conn->prepare('UPDATE users SET password = ?, status = "active" WHERE id = ?');
        if (!$update) {
            return ['ok' => false, 'error' => 'Database error updating password.'];
        }
        $update->bind_param('si', $newHash, $userId);
        if (!$update->execute()) {
            $update->close();
            return ['ok' => false, 'error' => 'Failed to save new password to database.'];
        }
        $update->close();

        // Clear failed login attempts so user can log in immediately
        $conn->query("DELETE FROM login_attempts WHERE user_id = {$userId}");

        // Synchronize auth identifiers
        if (function_exists('synchronizeAuthenticationIdentifier')) {
            if (!empty($user['email'])) {
                synchronizeAuthenticationIdentifier($conn, $userId, 'email', strtolower($user['email']));
            }
            if (!empty($user['phone_number'])) {
                $storagePhone = normalizePhilippineMobileForStorage($user['phone_number']);
                synchronizeAuthenticationIdentifier($conn, $userId, 'mobile', $storagePhone);
            }
        }

        // Optional non-blocking history logging
        try {
            $ip = authenticationClientIp();
            $metadata = json_encode(['source' => $source]);
            $oldHash = (string) $user['password'];
            $history = $conn->prepare(
                'INSERT INTO password_security_history (user_id, password_hash, event_type, change_source, ip_address, metadata)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            if ($history) {
                $history->bind_param('isssss', $userId, $oldHash, $eventType, $source, $ip, $metadata);
                $history->execute();
                $history->close();
            }
        } catch (Throwable $histEx) {
            error_log('Non-critical password history recording note: ' . $histEx->getMessage());
        }

        if ((int) ($_SESSION['user_id'] ?? 0) === $userId) {
            $_SESSION['must_change_password'] = false;
            session_regenerate_id(true);
            $_SESSION['session_regenerated_at'] = time();
        }

        return ['ok' => true];
    } catch (Throwable $exception) {
        error_log('Password update error: ' . $exception->getMessage());
        return ['ok' => false, 'error' => 'Unable to update password. Please try again.'];
    }
}

function recordPasswordRecoveryEvent(mysqli $conn, int $userId, string $eventType, string $source): void {
    try {
        $ip = authenticationClientIp();
        $metadata = json_encode(['user_agent' => authenticationUserAgent()]);
        $statement = $conn->prepare(
            'INSERT INTO password_security_history (user_id, password_hash, event_type, change_source, actor_user_id, ip_address, metadata)
             VALUES (?, NULL, ?, ?, NULL, ?, ?)'
        );
        if ($statement) {
            $statement->bind_param('issss', $userId, $eventType, $source, $ip, $metadata);
            $statement->execute();
            $statement->close();
        }
    } catch (Throwable $e) {
        error_log('Password recovery recording note: ' . $e->getMessage());
    }
}

function resetPasswordUsingVerifiedTransaction(mysqli $conn, string $publicId, string $newPassword): array {
    if (!isValidPassword($newPassword)) {
        return ['ok' => false, 'error' => passwordRequirementsMessage()];
    }

    try {
        $transaction = otpTransactionByPublicId($conn, $publicId, true);
        if (!$transaction
            || $transaction['purpose'] !== 'password_reset'
            || !$transaction['verified_at']
            || $transaction['consumed_at']
            || $transaction['invalidated_at']) {
            return ['ok' => false, 'error' => 'Your password reset session expired. Please request a new OTP.'];
        }

        $userId = (int) $transaction['user_id'];
        $select = $conn->prepare('SELECT id, password, phone_number, email FROM users WHERE id = ? AND status = "active" LIMIT 1');
        if (!$select) {
            return ['ok' => false, 'error' => 'Database query preparation failed.'];
        }
        $select->bind_param('i', $userId);
        $select->execute();
        $user = $select->get_result()->fetch_assoc();
        $select->close();

        if (!$user) {
            return ['ok' => false, 'error' => 'Account not found or inactive.'];
        }

        if (password_verify($newPassword, $user['password'])) {
            return ['ok' => false, 'error' => 'Your new password cannot be the same as your old password.'];
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        // Core Update: Save new password to database
        $update = $conn->prepare('UPDATE users SET password = ?, status = "active" WHERE id = ?');
        if (!$update) {
            return ['ok' => false, 'error' => 'Database query preparation failed for update.'];
        }
        $update->bind_param('si', $newHash, $userId);
        if (!$update->execute()) {
            $update->close();
            return ['ok' => false, 'error' => 'Failed to save new password.'];
        }
        $update->close();

        // Mark OTP transaction as consumed
        $transactionId = (int) $transaction['otp_transaction_id'];
        $consume = $conn->prepare('UPDATE otp_transactions SET consumed_at = NOW() WHERE otp_transaction_id = ?');
        if ($consume) {
            $consume->bind_param('i', $transactionId);
            $consume->execute();
            $consume->close();
        }

        // Clear failed login attempts so user can log in immediately
        $conn->query("DELETE FROM login_attempts WHERE user_id = {$userId}");

        // Synchronize auth identifiers
        if (function_exists('synchronizeAuthenticationIdentifier')) {
            if (!empty($user['email'])) {
                synchronizeAuthenticationIdentifier($conn, $userId, 'email', strtolower($user['email']));
            }
            if (!empty($user['phone_number'])) {
                $storagePhone = normalizePhilippineMobileForStorage($user['phone_number']);
                synchronizeAuthenticationIdentifier($conn, $userId, 'mobile', $storagePhone);
            }
        }

        // Optional non-blocking history logging
        try {
            $ip = authenticationClientIp();
            $metadata = json_encode(['otp_transaction_id' => $transaction['otp_transaction_id']]);
            $oldHash = (string) $user['password'];
            $history = $conn->prepare(
                'INSERT INTO password_security_history (user_id, password_hash, event_type, change_source, ip_address, metadata)
                 VALUES (?, ?, "password_reset", "otp_recovery", ?, ?)'
            );
            if ($history) {
                $history->bind_param('isss', $userId, $oldHash, $ip, $metadata);
                $history->execute();
                $history->close();
            }
        } catch (Throwable $histEx) {
            error_log('Non-critical password history recording note: ' . $histEx->getMessage());
        }

        return ['ok' => true, 'user_id' => $userId, 'delivery_method' => $transaction['delivery_method']];
    } catch (Throwable $exception) {
        error_log('Password reset error: ' . $exception->getMessage());
        return ['ok' => false, 'error' => 'Your password reset session encountered an error. Please try again.'];
    }
}
