<?php
/**
 * Record Details API - Retrieves full details of a sacramental record for certificate prefill and overrides.
 */
header('Content-Type: application/json');
require_once '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireAdmin();
requirePermission('certificates.manage');

$type = $_GET['type'] ?? '';
$id = intval($_GET['id'] ?? 0);
$response = ['success' => false, 'data' => null];

if ($id <= 0 || empty($type)) {
    echo json_encode(['success' => false, 'error' => 'Invalid record parameters']);
    exit;
}

try {
    if ($type === 'baptism' || $type === 'baptism_certification') {
        $stmt = $conn->prepare("SELECT * FROM baptism_records WHERE baptism_id = ? AND status = 'active' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                // Parse parents if separate columns are empty
                $fatherName = trim((string)($row['father_name'] ?? ''));
                $motherName = trim((string)($row['mother_name'] ?? ''));
                $fatherBirthplace = trim((string)($row['father_birth_place'] ?? ''));
                $motherBirthplace = trim((string)($row['mother_birth_place'] ?? ''));

                if (empty($fatherName) || empty($motherName)) {
                    $parents = trim((string)($row['parents'] ?? ''));
                    if (preg_match('/father\s*[:\-]\s*(.+?)(?:\s*(?:mother|and)\s*[:\-]\s*|\s+\/\s+)(.+)$/i', $parents, $m)) {
                        if (empty($fatherName)) $fatherName = trim($m[1]);
                        if (empty($motherName)) $motherName = trim($m[2]);
                    } else {
                        $parts = preg_split('/\s+(?:and|&)\s+|\s*\/\s*|\s*,\s*/i', $parents);
                        $parts = array_values(array_filter(array_map('trim', $parts)));
                        if (count($parts) >= 2) {
                            if (empty($fatherName)) $fatherName = $parts[0];
                            if (empty($motherName)) $motherName = $parts[1];
                        } elseif (count($parts) === 1 && empty($fatherName)) {
                            $fatherName = $parts[0];
                        }
                    }
                }

                if (empty($fatherBirthplace) && !empty($row['remarks'])) {
                    if (preg_match('/father(?:\'s)?\s*birthplace\s*[:\-]\s*([^\|\n\r;]+)/i', $row['remarks'], $m)) {
                        $fatherBirthplace = trim($m[1]);
                    }
                }
                if (empty($motherBirthplace) && !empty($row['remarks'])) {
                    if (preg_match('/mother(?:\'s)?\s*birthplace\s*[:\-]\s*([^\|\n\r;]+)/i', $row['remarks'], $m)) {
                        $motherBirthplace = trim($m[1]);
                    }
                }

                // Parse sponsors into list
                $sponsorsRaw = trim((string)($row['godparents'] ?? ''));
                $sponsorsList = [];
                if ($sponsorsRaw !== '') {
                    $lines = preg_split('/[\r\n]+/', $sponsorsRaw);
                    foreach ($lines as $line) {
                        $parts = preg_split('/,\s*|\s+and\s+|\s*;\s*|\s*\/\s*/i', $line);
                        foreach ($parts as $p) {
                            $p = trim($p);
                            if ($p !== '' && !in_array($p, $sponsorsList, true)) {
                                $sponsorsList[] = $p;
                            }
                        }
                    }
                }

                // Ensure at least 2 sponsor slots
                while (count($sponsorsList) < 2) {
                    $sponsorsList[] = '';
                }

                $residence = trim((string)($row['parent_address'] ?? ($row['parish_address'] ?? '')));

                $response['success'] = true;
                $response['data'] = [
                    'id' => (int)$row['baptism_id'],
                    'fullname' => $row['fullname'] ?? '',
                    'birth_place' => $row['birth_place'] ?? '',
                    'birth_date' => $row['birth_date'] ?? '',
                    'residence' => $residence,
                    'father_name' => $fatherName,
                    'father_birth_place' => $fatherBirthplace,
                    'mother_name' => $motherName,
                    'mother_birth_place' => $motherBirthplace,
                    'baptism_date' => $row['baptism_date'] ?? '',
                    'priest' => $row['priest'] ?? '',
                    'sponsors' => $sponsorsList,
                    'book_no' => $row['book_no'] ?? '',
                    'page_no' => $row['page_no'] ?? '',
                    'entry_no' => $row['entry_no'] ?? '',
                    'remarks' => $row['remarks'] ?? '',
                ];
                $response['active_priests'] = getActivePriestsRoster($conn);
            } else {
                $response['error'] = 'Record not found';
            }
        }
    } else {
        $response['error'] = 'Unsupported record type';
    }
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
