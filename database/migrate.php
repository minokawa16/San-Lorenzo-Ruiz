<?php
/**
 * Canonical, CLI-only database migration runner.
 *
 * Commands:
 *   php database/migrate.php status
 *   php database/migrate.php baseline   # mark an existing database baseline
 *   php database/migrate.php up         # apply pending migrations
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';

$migrationDirectory = __DIR__ . '/canonical-migrations';
$command = strtolower(trim((string) ($argv[1] ?? 'status')));
$allowedCommands = ['status', 'baseline', 'up'];

if (!in_array($command, $allowedCommands, true)) {
    fwrite(STDERR, "Usage: php database/migrate.php [status|baseline|up]\n");
    exit(2);
}

function canonicalMigrationFiles(string $directory): array
{
    $files = glob($directory . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    sort($files, SORT_STRING);

    foreach ($files as $file) {
        if (!preg_match('/^\d{3}_[a-z0-9_]+\.sql$/', basename($file))) {
            throw new RuntimeException('Invalid canonical migration filename: ' . basename($file));
        }
    }

    return $files;
}

function ensureMigrationLedger(mysqli $connection): void
{
    $sql = "CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        filename VARCHAR(190) NOT NULL,
        checksum CHAR(64) NOT NULL,
        execution_ms INT UNSIGNED NOT NULL DEFAULT 0,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (migration_id),
        UNIQUE KEY uniq_schema_migrations_filename (filename)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$connection->query($sql)) {
        throw new RuntimeException('Unable to create schema_migrations: ' . $connection->error);
    }
}

function appliedMigrations(mysqli $connection): array
{
    $applied = [];
    $result = $connection->query('SELECT filename, checksum, applied_at FROM schema_migrations ORDER BY filename');
    if (!$result) {
        throw new RuntimeException('Unable to read schema_migrations: ' . $connection->error);
    }

    while ($row = $result->fetch_assoc()) {
        $applied[$row['filename']] = $row;
    }
    $result->free();

    return $applied;
}

function nonLedgerTableCount(mysqli $connection): int
{
    $stmt = $connection->prepare(
        "SELECT COUNT(*) AS table_count
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND table_name <> 'schema_migrations'"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to inspect current schema: ' . $connection->error);
    }
    $stmt->execute();
    $count = (int) (($stmt->get_result()->fetch_assoc())['table_count'] ?? 0);
    $stmt->close();
    return $count;
}

function recordMigration(mysqli $connection, string $filename, string $checksum, int $executionMs): void
{
    $stmt = $connection->prepare(
        'INSERT INTO schema_migrations (filename, checksum, execution_ms) VALUES (?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare migration ledger update: ' . $connection->error);
    }
    $stmt->bind_param('ssi', $filename, $checksum, $executionMs);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to record migration: ' . $error);
    }
    $stmt->close();
}

function applySqlMigration(mysqli $connection, string $file): int
{
    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('Migration is empty or unreadable: ' . basename($file));
    }

    $databaseResult = $connection->query('SELECT DATABASE() AS database_name');
    $databaseRow = $databaseResult ? $databaseResult->fetch_assoc() : null;
    $databaseName = (string) ($databaseRow['database_name'] ?? '');
    if ($databaseName === '') {
        throw new RuntimeException('No active database is selected.');
    }
    $sql = str_replace(
        '{{DATABASE_NAME}}',
        '`' . str_replace('`', '``', $databaseName) . '`',
        $sql
    );

    $startedAt = microtime(true);
    if (!$connection->multi_query($sql)) {
        throw new RuntimeException('Migration failed: ' . basename($file) . ': ' . $connection->error);
    }

    do {
        $result = $connection->store_result();
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        if (!$connection->more_results()) {
            break;
        }
        if (!$connection->next_result()) {
            throw new RuntimeException('Migration failed: ' . basename($file) . ': ' . $connection->error);
        }
    } while (true);

    return (int) round((microtime(true) - $startedAt) * 1000);
}

$migrationFiles = canonicalMigrationFiles($migrationDirectory);
if (!$migrationFiles) {
    fwrite(STDERR, "No canonical migrations found.\n");
    exit(1);
}

ensureMigrationLedger($conn);

$lockResult = $conn->query("SELECT GET_LOCK('tugon_schema_migrations', 10) AS acquired");
$lockRow = $lockResult ? $lockResult->fetch_assoc() : null;
if (!$lockRow || (int) $lockRow['acquired'] !== 1) {
    fwrite(STDERR, "Unable to acquire the migration lock.\n");
    exit(1);
}

try {
    $applied = appliedMigrations($conn);

    foreach ($migrationFiles as $file) {
        $filename = basename($file);
        if (isset($applied[$filename])) {
            $currentChecksum = hash_file('sha256', $file);
            if (!hash_equals($applied[$filename]['checksum'], $currentChecksum)) {
                throw new RuntimeException('Applied migration checksum changed: ' . $filename);
            }
        }
    }

    if ($command === 'status') {
        foreach ($migrationFiles as $file) {
            $filename = basename($file);
            $status = isset($applied[$filename]) ? 'APPLIED' : 'PENDING';
            echo str_pad($status, 9) . ' ' . $filename . PHP_EOL;
        }
        exit(0);
    }

    if ($command === 'baseline') {
        $baseline = $migrationFiles[0];
        $filename = basename($baseline);
        if ($filename !== '000_schema_baseline.sql') {
            throw new RuntimeException('The first canonical migration must be 000_schema_baseline.sql.');
        }
        if (nonLedgerTableCount($conn) === 0) {
            throw new RuntimeException('The database is empty; run the up command instead of baseline.');
        }
        if (!isset($applied[$filename])) {
            recordMigration($conn, $filename, hash_file('sha256', $baseline), 0);
            echo 'BASELINED ' . $filename . PHP_EOL;
        } else {
            echo 'ALREADY BASELINED ' . $filename . PHP_EOL;
        }
        exit(0);
    }

    if (!isset($applied['000_schema_baseline.sql']) && nonLedgerTableCount($conn) > 0) {
        throw new RuntimeException(
            'Existing tables detected. Review the schema and run: php database/migrate.php baseline'
        );
    }

    foreach ($migrationFiles as $file) {
        $filename = basename($file);
        if (isset($applied[$filename])) {
            continue;
        }

        $checksum = hash_file('sha256', $file);
        echo 'APPLYING ' . $filename . PHP_EOL;
        $executionMs = applySqlMigration($conn, $file);
        recordMigration($conn, $filename, $checksum, $executionMs);
        echo 'APPLIED  ' . $filename . ' (' . $executionMs . ' ms)' . PHP_EOL;
    }

    echo "Database is up to date.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration error: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $conn->query("SELECT RELEASE_LOCK('tugon_schema_migrations')");
}
