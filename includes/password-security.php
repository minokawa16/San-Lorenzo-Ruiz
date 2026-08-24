<?php

require_once __DIR__ . '/authentication.php';

function passwordWasRecentlyUsed(mysqli $conn, int $userId, string $candidate): bool {
    $limit = max(1, (int) securitySetting($conn, 'security.password_history_count', 5));
    $statement = $conn->prepare(
        'SELECT password_hash FROM password_security_history WHERE user_id = ? AND password_hash IS NOT NULL ORDER BY created_at DESC, history_id DESC LIMIT ?'
    );
    $statement->bind_param('ii', $userId, $limit);
    $statement->execute();
    $result = $statement->get_result();
    while ($row = $result->fetch_assoc()) {
        if (password_verify($candidate, $row['password_hash'])) {
            $statement->close();
            return true;
        }
    }
    $statement->close();
    return false;
}

function updateAccountPassword(
    mysqli $conn,
    int $userId,
    string $newPassword,
    string $source,
    ?int $actorUserId = null,
    string $eventType = 'password_changed'
): array {
    if (!isValidPassword($newPassword)) {
        return ['ok' => false, 'error' => passwordRequirementsMessage()];
    }
    $conn->begin_transaction();
    try {
        $select = $conn->prepare('SELECT password FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
        $select->bind_param('i', $userId);
        $select->execute();
        $user = $select->get_result()->fetch_assoc();
        $select->close();
        if (!$user) {
            throw new RuntimeException('Account not found.');
        }
        if (password_verify($newPassword, $user['password']) || passwordWasRecentlyUsed($conn, $userId, $newPassword)) {
            $conn->rollback();
            return ['ok' => false, 'error' => 'Choose a password that has not been used recently.'];
        }
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT, ['cost' => 12]);
        $update = $conn->prepare(
            'UPDATE users SET password = ?, must_change_password = 0, password_changed_at = NOW() WHERE id = ?'
        );
        $update->bind_param('si', $newHash, $userId);
        if (!$update->execute()) {
            throw new RuntimeException('Unable to update password.');
        }
        $update->close();

        $ip = authenticationClientIp();
        $metadata = json_encode(['temporary_password_replaced' => !empty($_SESSION['must_change_password'])]);
        $history = $conn->prepare(
            'INSERT INTO password_security_history (user_id, password_hash, event_type, change_source, actor_user_id, ip_address, metadata)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $oldHash = $user['password'];
        $history->bind_param('isssiss', $userId, $oldHash, $eventType, $source, $actorUserId, $ip, $metadata);
        if (!$history->execute()) {
            throw new RuntimeException('Unable to record password history.');
        }
        $history->close();
        $conn->commit();
        if ((int) ($_SESSION['user_id'] ?? 0) === $userId) {
            $_SESSION['must_change_password'] = false;
            session_regenerate_id(true);
            $_SESSION['session_regenerated_at'] = time();
        }
        return ['ok' => true];
    } catch (Throwable $exception) {
        $conn->rollback();
        return ['ok' => false, 'error' => 'Unable to update the password securely.'];
    }
}

function recordPasswordRecoveryEvent(mysqli $conn, int $userId, string $eventType, string $source): void {
    $ip = authenticationClientIp();
    $metadata = json_encode(['user_agent' => authenticationUserAgent()]);
    $statement = $conn->prepare(
        'INSERT INTO password_security_history (user_id, password_hash, event_type, change_source, actor_user_id, ip_address, metadata)
         VALUES (?, NULL, ?, ?, NULL, ?, ?)'
    );
    $statement->bind_param('issss', $userId, $eventType, $source, $ip, $metadata);
    $statement->execute();
    $statement->close();
}

function resetPasswordUsingVerifiedTransaction(mysqli $conn, string $publicId, string $newPassword): array {
    if (!isValidPassword($newPassword)) {
        return ['ok' => false, 'error' => passwordRequirementsMessage()];
    }
    $conn->begin_transaction();
    try {
        $transaction = otpTransactionByPublicId($conn, $publicId, true);
        if (!$transaction
            || $transaction['purpose'] !== 'password_reset'
            || !$transaction['verified_at']
            || $transaction['consumed_at']
            || $transaction['invalidated_at']) {
            throw new RuntimeException('invalid_transaction');
        }
        $userId = (int) $transaction['user_id'];
        $select = $conn->prepare('SELECT password FROM users WHERE id = ? AND status = "active" LIMIT 1 FOR UPDATE');
        $select->bind_param('i', $userId);
        $select->execute();
        $user = $select->get_result()->fetch_assoc();
        $select->close();
        if (!$user) {
            throw new RuntimeException('invalid_account');
        }
        if (password_verify($newPassword, $user['password']) || passwordWasRecentlyUsed($conn, $userId, $newPassword)) {
            $conn->rollback();
            return ['ok' => false, 'error' => 'Choose a password that has not been used recently.'];
        }
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT, ['cost' => 12]);
        $update = $conn->prepare('UPDATE users SET password = ?, must_change_password = 0, password_changed_at = NOW() WHERE id = ?');
        $update->bind_param('si', $newHash, $userId);
        $update->execute();
        $update->close();

        $ip = authenticationClientIp();
        $metadata = json_encode(['otp_transaction_id' => $transaction['otp_transaction_id']]);
        $history = $conn->prepare(
            'INSERT INTO password_security_history (user_id, password_hash, event_type, change_source, actor_user_id, ip_address, metadata)
             VALUES (?, ?, "password_reset", "otp_recovery", NULL, ?, ?)'
        );
        $oldHash = $user['password'];
        $history->bind_param('isss', $userId, $oldHash, $ip, $metadata);
        $history->execute();
        $history->close();

        $transactionId = (int) $transaction['otp_transaction_id'];
        $consume = $conn->prepare('UPDATE otp_transactions SET consumed_at = NOW() WHERE otp_transaction_id = ?');
        $consume->bind_param('i', $transactionId);
        $consume->execute();
        $consume->close();
        $conn->commit();
        return ['ok' => true, 'user_id' => $userId, 'delivery_method' => $transaction['delivery_method']];
    } catch (Throwable $exception) {
        $conn->rollback();
        return ['ok' => false, 'error' => 'Your password reset session expired. Please request a new OTP.'];
    }
}
