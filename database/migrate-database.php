<?php
/**
 * Database Migration Script: Add Sacramental Record Links
 * Adds request_id foreign keys to sacramental record tables
 * Safe to run multiple times - will skip already-migrated tables
 */

// Include database configuration
require_once __DIR__ . '/config.php';

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== Parish Management System Database Migration ===\n\n";
echo "Migration: Adding sacramental record links to requests\n";
echo "Start Time: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // Check connection
    if (!isset($conn) || !$conn) {
        throw new Exception("Database connection failed. Check config.php");
    }

    $migrations = [
        [
            'table' => 'baptism_records',
            'column' => 'baptism_id',
        ],
        [
            'table' => 'first_communion_records',
            'column' => 'communion_id',
        ],
        [
            'table' => 'confirmation_records',
            'column' => 'confirmation_id',
        ],
        [
            'table' => 'marriage_records',
            'column' => 'marriage_id',
        ]
    ];

    $successful = 0;
    $skipped = 0;
    $failed = 0;

    foreach ($migrations as $migration) {
        $table = $migration['table'];
        echo "Processing: $table... ";

        // Check if request_id already exists
        $check_column = $conn->query("SHOW COLUMNS FROM $table LIKE 'request_id'");
        
        if ($check_column && $check_column->num_rows > 0) {
            echo "✓ SKIPPED (already migrated)\n";
            $skipped++;
            continue;
        }

        // Add request_id column
        $sql_column = "ALTER TABLE $table 
                      ADD COLUMN request_id INT DEFAULT NULL AFTER " . $migration['column'];
        
        if (!$conn->query($sql_column)) {
            echo "✗ FAILED\n";
            echo "  Error: " . $conn->error . "\n";
            $failed++;
            continue;
        }

        // Add foreign key constraint
        $sql_fk = "ALTER TABLE $table 
                  ADD FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE SET NULL";
        
        if (!$conn->query($sql_fk)) {
            echo "✗ FAILED\n";
            echo "  Error: " . $conn->error . "\n";
            $failed++;
            continue;
        }

        // Add index for performance
        $sql_index = "ALTER TABLE $table ADD INDEX idx_request_id (request_id)";
        
        if (!$conn->query($sql_index)) {
            echo "✗ FAILED\n";
            echo "  Error: " . $conn->error . "\n";
            $failed++;
            continue;
        }

        echo "✓ SUCCESS\n";
        $successful++;
    }

    echo "\n=== Migration Summary ===\n";
    echo "Successful: $successful\n";
    echo "Skipped: $skipped\n";
    echo "Failed: $failed\n";
    echo "End Time: " . date('Y-m-d H:i:s') . "\n\n";

    if ($failed === 0) {
        echo "✓ Migration completed successfully!\n";
        echo "\nYour sacramental records are now linked to requests:\n";
        echo "- Baptism records → Requests\n";
        echo "- First Communion records → Requests\n";
        echo "- Confirmation records → Requests\n";
        echo "- Marriage records → Requests\n";
        echo "\nYou can now optionally delete this file (migrate-database.php).\n";
    } else {
        echo "✗ Migration completed with errors. Please review the messages above.\n";
    }

} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
}

$conn->close();
?>
