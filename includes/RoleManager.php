<?php
/**
 * Role Manager Service
 * Manages role configurations, permissions, and dashboard preferences
 */

class RoleManager {
    private $conn;
    private $logger;

    public function __construct($database_connection, $logger = null) {
        $this->conn = $database_connection;
        $this->logger = $logger;
    }

    /**
     * Get role configuration
     * @param string $role_name Role name
     * @return array|null Role configuration
     */
    public function getRoleConfig($role_name) {
        $sql = "SELECT * FROM role_configurations WHERE role_name = ? AND is_active = 1 LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('s', $role_name);
        $stmt->execute();
        $result = $stmt->get_result();
        $config = $result->fetch_assoc();
        $stmt->close();

        if ($config) {
            $config['permissions'] = json_decode($config['permissions'], true) ?: [];
            $config['dashboard_widgets'] = json_decode($config['dashboard_widgets'], true) ?: [];
        }

        return $config;
    }

    /**
     * Get user dashboard preferences
     * @param int $user_id User ID
     * @return array|null Dashboard preferences
     */
    public function getUserDashboardPreferences($user_id) {
        $sql = "SELECT * FROM dashboard_preferences WHERE user_id = ? LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $preferences = $result->fetch_assoc();
        $stmt->close();

        if ($preferences) {
            $preferences['selected_widgets'] = json_decode($preferences['selected_widgets'], true) ?: [];
            $preferences['widget_order'] = json_decode($preferences['widget_order'], true) ?: [];
        }

        return $preferences;
    }

    /**
     * Create or update dashboard preferences
     * @param int $user_id User ID
     * @param string $role Role name
     * @param array $selected_widgets Widgets to display
     * @param array $widget_order Order of widgets
     * @return bool Success status
     */
    public function setDashboardPreferences($user_id, $role, $selected_widgets = [], $widget_order = []) {
        $widgets_json = json_encode($selected_widgets);
        $order_json = json_encode($widget_order);

        // Check if preferences exist
        $check_sql = "SELECT preference_id FROM dashboard_preferences WHERE user_id = ? LIMIT 1";
        $check_stmt = $this->conn->prepare($check_sql);
        $check_stmt->bind_param('i', $user_id);
        $check_stmt->execute();
        $exists = $check_stmt->get_result()->num_rows > 0;
        $check_stmt->close();

        if ($exists) {
            $sql = "UPDATE dashboard_preferences 
                    SET role = ?, selected_widgets = ?, widget_order = ?, updated_at = NOW()
                    WHERE user_id = ?";

            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return false;
            }

            $stmt->bind_param('sssi', $role, $widgets_json, $order_json, $user_id);
        } else {
            $sql = "INSERT INTO dashboard_preferences (user_id, role, selected_widgets, widget_order) 
                    VALUES (?, ?, ?, ?)";

            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return false;
            }

            $stmt->bind_param('isss', $user_id, $role, $widgets_json, $order_json);
        }

        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Check if user has permission
     * @param int $user_id User ID
     * @param string $permission Permission to check
     * @return bool Has permission
     */
    public function hasPermission($user_id, $permission) {
        // Get user role
        $user_role_sql = "SELECT role FROM users WHERE id = ? LIMIT 1";
        $user_stmt = $this->conn->prepare($user_role_sql);
        if (!$user_stmt) {
            return false;
        }

        $user_stmt->bind_param('i', $user_id);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        $user = $user_result->fetch_assoc();
        $user_stmt->close();

        if (!$user) {
            return false;
        }

        // Get role configuration
        $role_config = $this->getRoleConfig($user['role']);
        if (!$role_config) {
            return false;
        }

        return in_array($permission, $role_config['permissions']);
    }

    /**
     * Initialize default roles
     * @return bool Success status
     */
    public function initializeDefaultRoles() {
        $default_roles = [
            [
                'name' => 'admin',
                'display' => 'Administrator',
                'description' => 'System administrator with full access',
                'permissions' => [
                    'manage_users',
                    'manage_requests',
                    'manage_records',
                    'manage_announcements',
                    'manage_settings',
                    'view_reports',
                    'manage_backups',
                    'manage_templates',
                    'view_audit_logs',
                    'manage_roles'
                ],
                'widgets' => [
                    'dashboard_overview',
                    'pending_requests',
                    'user_statistics',
                    'system_health',
                    'recent_activities',
                    'announcements',
                    'calendar_events',
                    'quick_actions'
                ]
            ],
            [
                'name' => 'staff',
                'display' => 'Parish Staff',
                'description' => 'Staff member with limited management access',
                'permissions' => [
                    'view_requests',
                    'update_requests',
                    'manage_records',
                    'view_reports',
                    'post_announcements',
                    'manage_calendar'
                ],
                'widgets' => [
                    'dashboard_overview',
                    'pending_requests',
                    'assigned_tasks',
                    'recent_activities',
                    'calendar_events',
                    'quick_actions'
                ]
            ],
            [
                'name' => 'priest',
                'display' => 'Parish Priest',
                'description' => 'Priest with view and approval access',
                'permissions' => [
                    'view_requests',
                    'approve_requests',
                    'view_records',
                    'post_announcements',
                    'view_calendar',
                    'view_reports'
                ],
                'widgets' => [
                    'dashboard_overview',
                    'pending_approvals',
                    'recent_activities',
                    'announcements',
                    'calendar_events'
                ]
            ],
            [
                'name' => 'coordinator',
                'display' => 'Chapel Coordinator',
                'description' => 'Chapel coordinator with coordination access',
                'permissions' => [
                    'view_requests',
                    'view_records',
                    'view_calendar',
                    'manage_calendar',
                    'view_reports'
                ],
                'widgets' => [
                    'dashboard_overview',
                    'assigned_members',
                    'calendar_events',
                    'announcements'
                ]
            ],
            [
                'name' => 'user',
                'display' => 'Parishioner',
                'description' => 'Regular parishioner with self-service access',
                'permissions' => [
                    'submit_requests',
                    'view_own_requests',
                    'view_announcements',
                    'view_calendar',
                    'update_profile'
                ],
                'widgets' => [
                    'my_requests',
                    'announcements',
                    'calendar_events',
                    'notifications'
                ]
            ]
        ];

        foreach ($default_roles as $role) {
            $this->createRole(
                $role['name'],
                $role['display'],
                $role['description'],
                $role['permissions'],
                $role['widgets']
            );
        }

        return true;
    }

    /**
     * Create a new role
     * @param string $role_name Role name
     * @param string $display_name Display name
     * @param string $description Description
     * @param array $permissions Permissions array
     * @param array $dashboard_widgets Default widgets
     * @return bool Success status
     */
    public function createRole($role_name, $display_name, $description, $permissions, $dashboard_widgets) {
        $permissions_json = json_encode($permissions);
        $widgets_json = json_encode($dashboard_widgets);

        $sql = "INSERT IGNORE INTO role_configurations 
                (role_name, display_name, description, permissions, dashboard_widgets, is_system) 
                VALUES (?, ?, ?, ?, ?, 1)";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('sssss', $role_name, $display_name, $description, $permissions_json, $widgets_json);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Get all roles
     * @param bool $active_only Only active roles
     * @return array List of roles
     */
    public function getAllRoles($active_only = true) {
        $sql = "SELECT * FROM role_configurations";

        if ($active_only) {
            $sql .= " WHERE is_active = 1";
        }

        $sql .= " ORDER BY display_name ASC";

        $result = $this->conn->query($sql);
        if (!$result) {
            return [];
        }

        $roles = [];
        while ($row = $result->fetch_assoc()) {
            $row['permissions'] = json_decode($row['permissions'], true) ?: [];
            $row['dashboard_widgets'] = json_decode($row['dashboard_widgets'], true) ?: [];
            $roles[] = $row;
        }

        return $roles;
    }

    /**
     * Get default dashboard widgets for role
     * @param string $role_name Role name
     * @return array Default widgets
     */
    public function getDefaultWidgetsForRole($role_name) {
        $role_config = $this->getRoleConfig($role_name);
        return $role_config ? $role_config['dashboard_widgets'] : [];
    }
}
?>
