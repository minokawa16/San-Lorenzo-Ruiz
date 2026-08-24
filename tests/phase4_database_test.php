<?php
require_once dirname(__DIR__) . '/database/config.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$failures = 0;
function dbCheck(bool $ok, string $label): void { global $failures; echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . PHP_EOL; if (!$ok) $failures++; }

$fk = $conn->query("SELECT COUNT(*) c FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL AND CONSTRAINT_NAME IN ('fk_request_documents_request','fk_request_payments_request','fk_reservations_user','fk_notifications_user','fk_announcement_recipients_user','fk_baptism_records_request','fk_certificate_issuances_template')")->fetch_assoc();
dbCheck((int) $fk['c'] >= 7, 'Phase 4 foreign keys are installed');
$orphans = $conn->query("SELECT (SELECT COUNT(*) FROM request_documents d LEFT JOIN requests r ON r.request_id=d.request_id WHERE r.request_id IS NULL) + (SELECT COUNT(*) FROM request_payments p LEFT JOIN requests r ON r.request_id=p.request_id WHERE r.request_id IS NULL) + (SELECT COUNT(*) FROM reservations x LEFT JOIN users u ON u.id=x.user_id WHERE u.id IS NULL) + (SELECT COUNT(*) FROM notifications n LEFT JOIN users u ON u.id=n.user_id WHERE u.id IS NULL) AS total")->fetch_assoc();
dbCheck((int) $orphans['total'] === 0, 'targeted orphan audit is clean');
$indexes = $conn->query("SELECT COUNT(*) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND INDEX_NAME IN ('idx_requests_user_status_created','idx_reservations_conflict','idx_notifications_user_read_created')")->fetch_assoc();
dbCheck((int) $indexes['c'] >= 3, 'request/reservation/notification indexes are installed');
$conn->begin_transaction();
$stmt = $conn->prepare("INSERT INTO requests (user_id, request_type, status, reference_number) VALUES (999999, 'test', 'pending', 'PHASE4-FK-TEST')");
$ok = $stmt && $stmt->execute();
if ($stmt) $stmt->close(); $conn->rollback();
dbCheck(!$ok, 'request with nonexistent user is rejected by FK');
$a = generateReferenceNumber(); $b = generateReferenceNumber();
dbCheck($a !== $b && preg_match('/^TUGON-\d{4}-[A-F0-9]{8}$/', $a), 'request references are collision-resistant');
echo "{$failures} failed database checks." . PHP_EOL;
exit($failures ? 1 : 0);
