<?php

/** Database-backed role and permission policy. */

// Compatibility map for legacy callers. Authorization itself is resolved from
// roles/role_permissions below; this map intentionally grants no new access.
$canonicalPermissionPolicy = ['admin' => ['*'], 'user' => []];

function normalizeUserRole($role) {
    $role = strtolower(trim((string) $role));
    $aliases = [
        'administrator' => 'admin',
        'staff' => 'parish_staff',
        'parish staff' => 'parish_staff',
        'records clerk' => 'records_clerk',
        'finance' => 'finance_staff',
        'cashier' => 'finance_staff',
        'member' => 'user',
        'parishioner' => 'user',
        'volunteer' => 'user',
    ];
    return $aliases[$role] ?? ($role !== '' ? $role : 'guest');
}

function databaseRoleKey($role) {
    $normalized = normalizeUserRole($role);
    return ['admin' => 'administrator', 'user' => 'parishioner'][$normalized] ?? $normalized;
}

function legacyRoleKey($role) {
    $databaseRole = databaseRoleKey($role);
    return ['administrator' => 'admin', 'parishioner' => 'user'][$databaseRole] ?? $databaseRole;
}

function permissionConnection() {
    global $conn;
    return ($conn ?? null) instanceof mysqli ? $conn : null;
}

function userRoleKeys($userId = null, $connection = null) {
    static $cache = [];
    $connection = $connection instanceof mysqli ? $connection : permissionConnection();
    $userId = (int) ($userId ?? ($_SESSION['user_id'] ?? 0));
    if (!$connection || $userId <= 0) {
        if (!empty($_SESSION['role'])) {
            return [databaseRoleKey($_SESSION['role'])];
        }
        return [];
    }
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    $statement = $connection->prepare(
        'SELECT r.role_key FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id WHERE ur.user_id = ? ORDER BY r.role_id'
    );
    $roles = [];
    if ($statement) {
        $statement->bind_param('i', $userId);
        $statement->execute();
        $result = $statement->get_result();
        while ($row = $result->fetch_assoc()) {
            $roles[] = $row['role_key'];
        }
        $statement->close();
    }
    if (empty($roles)) {
        $stmt = $connection->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $userRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($userRow && !empty($userRow['role'])) {
                $roles = [databaseRoleKey($userRow['role'])];
            }
        }
    }
    if (empty($roles) && !empty($_SESSION['role'])) {
        $roles = [databaseRoleKey($_SESSION['role'])];
    }
    return $cache[$userId] = $roles;
}

function rolePermissions($role) {
    static $cache = [];
    $roleKey = databaseRoleKey($role);
    if (isset($cache[$roleKey])) {
        return $cache[$roleKey];
    }
    $connection = permissionConnection();
    if (!$connection) {
        return [];
    }
    $statement = $connection->prepare(
        'SELECT p.permission_key FROM roles r JOIN role_permissions rp ON rp.role_id = r.role_id JOIN permissions p ON p.permission_id = rp.permission_id WHERE r.role_key = ? ORDER BY p.permission_key'
    );
    if (!$statement) {
        return [];
    }
    $statement->bind_param('s', $roleKey);
    $statement->execute();
    $result = $statement->get_result();
    $permissions = [];
    while ($row = $result->fetch_assoc()) {
        $permissions[] = $row['permission_key'];
    }
    $statement->close();
    return $cache[$roleKey] = $permissions;
}

function hasPermission($permission, $role = null) {
    static $userPermissionCache = [];
    $connection = permissionConnection();
    if (!$connection) {
        return false;
    }

    if ($role !== null) {
        return in_array($permission, rolePermissions($role), true);
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0 || empty($_SESSION['fully_authenticated'])) {
        return false;
    }

    if (isAdmin()) {
        return true;
    }
    if (!isset($userPermissionCache[$userId])) {
        $statement = $connection->prepare(
            'SELECT DISTINCT p.permission_key FROM user_roles ur JOIN role_permissions rp ON rp.role_id = ur.role_id JOIN permissions p ON p.permission_id = rp.permission_id WHERE ur.user_id = ?'
        );
        if (!$statement) {
            return false;
        }
        $statement->bind_param('i', $userId);
        $statement->execute();
        $result = $statement->get_result();
        $userPermissionCache[$userId] = [];
        while ($row = $result->fetch_assoc()) {
            $userPermissionCache[$userId][$row['permission_key']] = true;
        }
        $statement->close();
    }
    return isset($userPermissionCache[$userId][$permission]);
}

function hasAnyPermission($permissions, $role = null) {
    foreach ((array) $permissions as $permission) {
        if (hasPermission($permission, $role)) {
            return true;
        }
    }
    return false;
}

function isAdmin() {
    return in_array('administrator', userRoleKeys(), true);
}

function isBackOfficeUser() {
    return hasPermission('admin.access');
}

function isUser() {
    $roles = userRoleKeys();
    return in_array('parishioner', $roles, true) || in_array('user', $roles, true) || in_array('administrator', $roles, true);
}
