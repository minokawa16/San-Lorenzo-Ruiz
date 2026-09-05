<?php

require_once __DIR__ . '/authentication.php';
require_once __DIR__ . '/helpers.php';

if (!function_exists('synchronizeAuthenticationIdentifier')) {
function synchronizeAuthenticationIdentifier(mysqli $conn, int $userId, string $type, ?string $value, ?string $verifiedAt = null): bool {
    if (!in_array($type, ['email', 'mobile'], true)) {
        return false;
    }
    $normalized = $type === 'email'
        ? strtolower(trim((string) $value))
        : normalizePhilippineMobileForStorage((string) $value);
    $valid = $type === 'email' ? isValidEmail($normalized) : isValidPhilippineMobile($normalized);
    if (!$valid) {
        $delete = $conn->prepare('DELETE FROM user_auth_identifiers WHERE user_id = ? AND identifier_type = ?');
        $delete->bind_param('is', $userId, $type);
        $success = $delete->execute();
        $delete->close();
        return $success;
    }
    $lookup = $conn->prepare(
        'SELECT identifier_id FROM user_auth_identifiers WHERE user_id = ? AND identifier_type = ? LIMIT 1'
    );
    if (!$lookup) {
        return false;
    }
    $lookup->bind_param('is', $userId, $type);
    $lookup->execute();
    $existing = $lookup->get_result()->fetch_assoc();
    $lookup->close();

    if ($existing) {
        $statement = $conn->prepare(
            'UPDATE user_auth_identifiers SET normalized_value = ?, verified_at = ? WHERE identifier_id = ?'
        );
        if (!$statement) {
            return false;
        }
        $identifierId = (int) $existing['identifier_id'];
        $statement->bind_param('ssi', $normalized, $verifiedAt, $identifierId);
    } else {
        $statement = $conn->prepare(
            'INSERT INTO user_auth_identifiers (user_id, identifier_type, normalized_value, verified_at) VALUES (?, ?, ?, ?)'
        );
        if (!$statement) {
            return false;
        }
        $statement->bind_param('isss', $userId, $type, $normalized, $verifiedAt);
    }
    $success = $statement->execute();
    $statement->close();
    return $success;
}
}

if (!function_exists('authenticationIdentifierAvailable')) {
function authenticationIdentifierAvailable(mysqli $conn, string $type, string $value, ?int $exceptUserId = null): bool {
    $normalized = $type === 'email' ? strtolower(trim($value)) : normalizePhilippineMobileForStorage($value);

    // 1. Check user_auth_identifiers table
    $sql = 'SELECT user_id FROM user_auth_identifiers WHERE identifier_type = ? AND normalized_value = ?';
    if ($exceptUserId !== null) {
        $sql .= ' AND user_id <> ?';
    }
    $sql .= ' LIMIT 1';
    $statement = $conn->prepare($sql);
    if ($statement) {
        if ($exceptUserId !== null) {
            $statement->bind_param('ssi', $type, $normalized, $exceptUserId);
        } else {
            $statement->bind_param('ss', $type, $normalized);
        }
        $statement->execute();
        $authRow = $statement->get_result()->fetch_assoc();
        $statement->close();

        if ($authRow) {
            $matchedUserId = (int) $authRow['user_id'];
            // Check if this matched user actually exists in the users table
            $checkUser = $conn->query("SELECT id FROM users WHERE id = $matchedUserId LIMIT 1");
            if ($checkUser && $checkUser->num_rows > 0) {
                return false; // Active existing user owns this identifier
            } else {
                // Orphaned identifier without a user record: cleanly remove it
                $conn->query("DELETE FROM user_auth_identifiers WHERE identifier_type = '$type' AND normalized_value = '$normalized'");
            }
        }
    }

    // 2. Also check the users table directly (in case user was created via legacy flow)
    $userCol = $type === 'email' ? 'email' : 'phone_number';
    $userSql = "SELECT id FROM users WHERE LOWER($userCol) = ?";
    if ($exceptUserId !== null) {
        $userSql .= ' AND id <> ?';
    }
    $userSql .= ' LIMIT 1';
    $uStmt = $conn->prepare($userSql);
    if ($uStmt) {
        if ($exceptUserId !== null) {
            $uStmt->bind_param('si', $normalized, $exceptUserId);
        } else {
            $uStmt->bind_param('s', $normalized);
        }
        $uStmt->execute();
        $userExists = (bool) $uStmt->get_result()->fetch_assoc();
        $uStmt->close();
        if ($userExists) {
            return false;
        }
    }

    return true;
}
}

if (!function_exists('assignUserRole')) {
function assignUserRole(mysqli $conn, int $userId, string $roleKey, ?int $assignedBy = null): bool {
    $role = $conn->prepare('SELECT role_id FROM roles WHERE role_key = ? LIMIT 1');
    if (!$role) {
        return false;
    }
    $role->bind_param('s', $roleKey);
    $role->execute();
    $roleRow = $role->get_result()->fetch_assoc();
    $role->close();
    if (!$roleRow) {
        return false;
    }
    $statement = $conn->prepare(
        'INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE assigned_by = VALUES(assigned_by), assigned_at = CURRENT_TIMESTAMP'
    );
    if (!$statement) {
        return false;
    }
    $roleId = (int) $roleRow['role_id'];
    $statement->bind_param('iii', $userId, $roleId, $assignedBy);
    $success = $statement->execute();
    $statement->close();
    return $success;
}
}

if (!function_exists('recordAccountStatusChange')) {
function recordAccountStatusChange(mysqli $conn, int $userId, ?string $previous, string $next, string $action, ?string $reason, ?int $actor): bool {
    $ip = authenticationClientIp();
    $agent = authenticationUserAgent();
    $statement = $conn->prepare(
        'INSERT INTO account_status_history (user_id, previous_status, new_status, action, reason, actor_user_id, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->bind_param('issssiss', $userId, $previous, $next, $action, $reason, $actor, $ip, $agent);
    $success = $statement->execute();
    $statement->close();
    return $success;
}
}

if (!function_exists('recordRegistrationReview')) {
function recordRegistrationReview(mysqli $conn, int $userId, string $action, ?string $previous, string $next, ?string $reason, ?int $actor): bool {
    $ip = authenticationClientIp();
    $agent = authenticationUserAgent();
    $statement = $conn->prepare(
        'INSERT INTO registration_reviews (user_id, review_action, previous_status, new_status, reason, reviewed_by, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->bind_param('issssiss', $userId, $action, $previous, $next, $reason, $actor, $ip, $agent);
    $success = $statement->execute();
    $statement->close();
    return $success;
}
}

if (!function_exists('transitionAccountStatus')) {
function transitionAccountStatus(mysqli $conn, int $userId, string $nextStatus, string $action, ?string $reason, ?int $actor): bool {
    $allowed = ['pending_verification', 'active', 'rejected', 'inactive', 'archived'];
    if (!in_array($nextStatus, $allowed, true)) {
        return false;
    }
    if ($nextStatus === 'rejected' && mb_strlen(trim((string) $reason)) < 10) {
        return false;
    }
    $conn->begin_transaction();
    try {
        $select = $conn->prepare('SELECT status FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
        $select->bind_param('i', $userId);
        $select->execute();
        $row = $select->get_result()->fetch_assoc();
        $select->close();
        if (!$row) {
            throw new RuntimeException('Account not found.');
        }
        $previous = (string) $row['status'];
        $update = $conn->prepare(
            'UPDATE users SET status = ?, rejection_reason = ?, account_state_changed_at = NOW(), verified_at = CASE WHEN ? IN ("active","rejected") THEN NOW() ELSE verified_at END, verified_by = CASE WHEN ? IN ("active","rejected") THEN ? ELSE verified_by END WHERE id = ?'
        );
        $storedReason = $nextStatus === 'rejected' ? trim((string) $reason) : null;
        $update->bind_param('ssssii', $nextStatus, $storedReason, $nextStatus, $nextStatus, $actor, $userId);
        if (!$update->execute()) {
            throw new RuntimeException('Unable to update account status.');
        }
        $update->close();
        if (!recordAccountStatusChange($conn, $userId, $previous, $nextStatus, $action, $storedReason, $actor)) {
            throw new RuntimeException('Unable to record account status history.');
        }
        if (in_array($action, ['approved', 'rejected', 'resubmitted', 'submitted'], true)
            && !recordRegistrationReview($conn, $userId, $action, $previous, $nextStatus, $storedReason, $actor)) {
            throw new RuntimeException('Unable to record registration history.');
        }
        $conn->commit();

        try {
            if ($nextStatus === 'active') {
                notifyUserAutomatic($conn, $userId, 'Parish Account Approved', 'Congratulations! Your TUGON parish account has been verified and approved. You can now log in to request certificates, schedules, and blessings.', 'account');
            } elseif ($nextStatus === 'rejected') {
                notifyUserAutomatic($conn, $userId, 'Parish Account Registration Update', 'Your TUGON registration was reviewed. Status: Rejected.' . ($storedReason ? ' Reason: ' . $storedReason : '') . ' Please visit the portal to resubmit with corrected information.', 'account');
            } elseif ($nextStatus === 'inactive' || $nextStatus === 'archived') {
                notifyUserAutomatic($conn, $userId, 'Parish Account Status Update', 'Your TUGON parish account status is now ' . ucfirst($nextStatus) . '.', 'account');
            }
        } catch (Throwable $notifError) {
            error_log('Account transition notification error: ' . $notifError->getMessage());
        }

        return true;
    } catch (Throwable $exception) {
        $conn->rollback();
        return false;
    }
}
}
