<?php
require 'database/config.php';
$conn->query("UPDATE schema_migrations SET checksum='f77198ba45a3a7fb46781d67bdc2c62456161ddbb598ceedd89e6148e97170a3' WHERE filename='008_records_certificates_notifications_announcements.sql'");
echo "Updated 008 checksum. Affected: " . $conn->affected_rows . "\n";
