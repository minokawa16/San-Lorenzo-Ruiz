<?php
/**
 * Migration Runner
 * Executes all SQL migration files in the migrations directory
 * Usage: Visit http://localhost/ParishSystem/database/run-migrations.php
 */

include 'config.php';
include '../includes/Logger.php';

$logger = new Logger();

// Check if user is admin or localhost
session_start();
$is_admin = (!empty($_SESSION['user_id']) && $_SESSION['role'] === 'admin') || $_SERVER['REMOTE_ADDR'] === '127.0.0.1';

if (!$is_admin && $_SERVER['REMOTE_ADDR'] !== '127.0.0.1') {
    die('Access Denied: Only administrators can run migrations.');
}

// Get all migration files
$migration_dir = __DIR__ . '/migrations';
$migration_files = glob($migration_dir . '/*.sql');
sort($migration_files);

if (empty($migration_files)) {
    die('No migration files found in ' . $migration_dir);
}

$results = [];
$errors = [];

echo '<h2>Parish Management System - Database Migrations</h2>';
echo '<pre style="background: #f5f5f5; padding: 10px; border-radius: 4px;">';

foreach ($migration_files as $file) {
    $filename = basename($file);
    echo "Executing: $filename\n";
    
    try {
        // Read the migration file
        $migration_content = file_get_contents($file);
        
        // Split by statement (simple approach - handles most cases)
        $statements = array_filter(array_map('trim', preg_split('/;(?=(?:[^\']*\'[^\']*\')*[^\']*$)/', $migration_content)));
        
        $executed_count = 0;
        foreach ($statements as $statement) {
            if (empty(trim($statement))) continue;
            
            // Skip comments
            if (strpos(trim($statement), '--') === 0) continue;
            if (strpos(trim($statement), '/*') === 0) continue;
            
            if ($conn->query($statement)) {
                $executed_count++;
            } else {
                // Log warning but continue (some statements might use IF NOT EXISTS)
                if (strpos($conn->error, 'already exists') === false) {
                    echo "  WARNING: " . $conn->error . "\n";
                }
            }
        }
        
        echo "  ✓ Completed ($executed_count statements executed)\n";
        $results[$filename] = 'SUCCESS';
        
    } catch (Exception $e) {
        echo "  ✗ Error: " . $e->getMessage() . "\n";
        $errors[$filename] = $e->getMessage();
        $results[$filename] = 'FAILED';
    }
}

echo "</pre>";

echo '<h3>Migration Summary</h3>';
echo '<table border="1" cellpadding="5" cellspacing="0">';
echo '<tr style="background: #e0e0e0;"><th>Migration File</th><th>Status</th></tr>';

foreach ($results as $file => $status) {
    $status_class = ($status === 'SUCCESS') ? 'style="background: #d4edda;"' : 'style="background: #f8d7da;"';
    echo "<tr $status_class><td>$file</td><td>$status</td></tr>";
}

echo '</table>';

if (!empty($errors)) {
    echo '<h3>Errors</h3>';
    echo '<pre style="background: #f8d7da; padding: 10px; border-radius: 4px;">';
    foreach ($errors as $file => $error) {
        echo "$file: $error\n";
    }
    echo '</pre>';
}

// Log migration execution
$logger->logAction(
    $_SESSION['user_id'] ?? 0,
    'system',
    0,
    'migration_executed',
    'database',
    json_encode(['files_run' => count($migration_files), 'results' => $results]),
    'Database migrations executed'
);

echo '<p><a href="' . (isset($_SESSION['role']) && $_SESSION['role'] === 'admin' ? '../../admin/dashboard.php' : '../../index.php') . '">Back to Dashboard</a></p>';
?>
