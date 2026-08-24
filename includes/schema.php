<?php
/**
 * Canonical runtime schema assertions.
 *
 * Browser requests must never create or alter database objects. Schema changes
 * belong exclusively to database/canonical-migrations and are applied by the
 * CLI migration runner. Normal HTTP requests consult this manifest rather
 * than repeatedly querying INFORMATION_SCHEMA.
 */

function canonicalSchemaTables(): array
{
    static $tables = [
        'account_status_history', 'ai_feedback', 'ai_responses',
        'announcement_attachments', 'announcement_audiences', 'announcement_recipients', 'announcements',
        'audit_log', 'baptism_records', 'certificate_events', 'certificate_file_templates',
        'certificate_issuances', 'certificate_layouts', 'certificate_number_sequences', 'certificate_templates',
        'chatbot_inquiries', 'chatbot_knowledge', 'chatbot_knowledge_meta', 'confirmation_records',
        'email_verifications', 'first_communion_records', 'funeral_records', 'login_attempts',
        'maintenance_logs', 'marriage_records', 'notification_deliveries', 'notification_logs',
        'notification_preferences', 'notification_templates', 'notifications', 'otp_codes', 'otp_transactions',
        'password_security_history', 'permissions', 'recovery_logs', 'registration_reviews',
        'request_assignments', 'request_documents', 'request_idempotency_keys', 'request_internal_notes',
        'request_messages', 'request_payments', 'request_status_history', 'requests',
        'reservation_conflict_events', 'reservation_notifications', 'reservation_resources',
        'reservation_schedule_history', 'reservations', 'resource_unavailability', 'resources',
        'role_permissions', 'roles', 'sacramental_correction_changes', 'sacramental_import_batches',
        'sacramental_import_rows', 'sacramental_record_corrections', 'schedule_events',
        'schedule_proposal_resources', 'schedule_proposals', 'schema_migrations', 'sms_notification_logs',
        'system_settings', 'user_auth_identifiers', 'user_roles', 'users',
    ];
    return $tables;
}

function schemaTableExists(mysqli $connection, string $table): bool
{
    return in_array($table, canonicalSchemaTables(), true);
}

function schemaColumnExists(mysqli $connection, string $table, string $column): bool
{
    return $column !== '' && schemaTableExists($connection, $table);
}

function requireSchemaTables(mysqli $connection, array $tables, string $feature = 'application'): bool
{
    $missing = [];
    foreach (array_unique($tables) as $table) {
        if (!schemaTableExists($connection, (string) $table)) {
            $missing[] = (string) $table;
        }
    }
    if ($missing) {
        error_log(sprintf(
            'TUGON schema mismatch for %s. Missing tables: %s. Run php database/migrate.php up.',
            $feature,
            implode(', ', $missing)
        ));
        return false;
    }
    return true;
}

function requireSchemaColumns(mysqli $connection, string $table, array $columns, string $feature = 'application'): bool
{
    if (!schemaTableExists($connection, $table)) {
        error_log(sprintf('TUGON schema mismatch for %s. Missing table: %s.', $feature, $table));
        return false;
    }

    $missing = [];
    foreach (array_unique($columns) as $column) {
        if (!schemaColumnExists($connection, $table, (string) $column)) {
            $missing[] = (string) $column;
        }
    }
    if ($missing) {
        error_log(sprintf(
            'TUGON schema mismatch for %s. Missing %s columns: %s. Run php database/migrate.php up.',
            $feature,
            $table,
            implode(', ', $missing)
        ));
        return false;
    }
    return true;
}
