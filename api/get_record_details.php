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
    $parseParents = function($r) {
        $f = trim((string)($r['father_name'] ?? ''));
        $m = trim((string)($r['mother_name'] ?? ''));
        if (empty($f) || empty($m)) {
            $pStr = trim((string)($r['parents'] ?? ''));
            if (preg_match('/father\s*[:\-]\s*(.+?)(?:\s*(?:mother|and)\s*[:\-]\s*|\s+\/\s+)(.+)$/i', $pStr, $pm)) {
                if (empty($f)) $f = trim($pm[1]);
                if (empty($m)) $m = trim($pm[2]);
            } else {
                $parts = preg_split('/\s+(?:and|&)\s+|\s*\/\s*|\s*,\s*/i', $pStr);
                $parts = array_values(array_filter(array_map('trim', $parts)));
                if (count($parts) >= 2) {
                    if (empty($f)) $f = $parts[0];
                    if (empty($m)) $m = $parts[1];
                } elseif (count($parts) === 1 && empty($f)) {
                    $f = $parts[0];
                }
            }
        }
        return [$f, $m];
    };

    if ($type === 'baptism' || $type === 'baptism_certification') {
        $stmt = $conn->prepare("SELECT * FROM baptism_records WHERE baptism_id = ? AND status = 'active' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                list($fatherName, $motherName) = $parseParents($row);
                $fatherBirthplace = trim((string)($row['father_birth_place'] ?? ''));
                $motherBirthplace = trim((string)($row['mother_birth_place'] ?? ''));

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
                    'officiating_priest' => $row['priest'] ?? '',
                    'parish_priest' => $row['parish_priest'] ?? '',
                    'priest_in_charge' => $row['parish_priest'] ?? '',
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
    } elseif ($type === 'communion' || $type === 'first_communion_certification') {
        $stmt = $conn->prepare("SELECT * FROM first_communion_records WHERE communion_id = ? AND status='active' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                list($fatherName, $motherName) = $parseParents($row);
                $signers = getFirstCommunionSigners($conn, $row);
                $response['success'] = true;
                $response['data'] = [
                    'id'                    => (int)$row['communion_id'],
                    'fullname'              => $row['fullname'] ?? '',
                    'communion_date'        => $row['communion_date'] ?? '',
                    'domicile'              => $row['domicile'] ?? '',
                    'parents'               => $row['parents'] ?? '',
                    'father_name'           => $fatherName,
                    'mother_name'           => $motherName,
                    'priest'                => $row['priest'] ?? '',
                    'officiating_priest'    => $row['priest'] ?? '',
                    'parish_priest'         => $row['parish_priest'] ?? '',
                    'priest_in_charge'      => $row['parish_priest'] ?? '',
                    'catechist_coordinator' => $row['catechist_coordinator'] ?? $signers['catechist_coordinator'],
                    'principal'             => $row['principal'] ?? $signers['principal'],
                    'book_no'               => $row['book_no'] ?? '',
                    'page_no'               => $row['page_no'] ?? '',
                    'entry_no'              => $row['entry_no'] ?? '',
                ];
            } else {
                $response['error'] = 'Record not found';
            }
        }
    } elseif ($type === 'confirmation' || $type === 'confirmation_certification') {
        $stmt = $conn->prepare("SELECT * FROM confirmation_records WHERE confirmation_id = ? AND status='active' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                list($fatherName, $motherName) = $parseParents($row);
                $response['success'] = true;
                $response['data'] = [
                    'id'                => (int)$row['confirmation_id'],
                    'fullname'          => $row['fullname'] ?? '',
                    'confirmation_name' => $row['confirmation_name'] ?? '',
                    'confirmation_date' => $row['confirmation_date'] ?? '',
                    'parents'           => $row['parents'] ?? '',
                    'father_name'       => $fatherName,
                    'mother_name'       => $motherName,
                    'sponsor'           => $row['sponsor'] ?? '',
                    'bishop_priest'     => $row['bishop_priest'] ?? '',
                    'officiating_priest'=> $row['bishop_priest'] ?? '',
                    'parish_priest'     => $row['parish_priest'] ?? '',
                    'priest_in_charge'  => $row['parish_priest'] ?? '',
                    'book_no'           => $row['book_no'] ?? '',
                    'page_no'           => $row['page_no'] ?? '',
                    'entry_no'          => $row['entry_no'] ?? '',
                ];
            } else {
                $response['error'] = 'Record not found';
            }
        }
    } elseif ($type === 'marriage' || $type === 'marriage_certification') {
        $stmt = $conn->prepare("SELECT * FROM marriage_records WHERE marriage_id = ? AND status='active' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $response['success'] = true;
                $response['data'] = [
                    'id'                 => (int)$row['marriage_id'],
                    'husband_name'       => $row['husband_name'] ?? '',
                    'wife_name'          => $row['wife_name'] ?? '',
                    'wedding_date'       => $row['wedding_date'] ?? '',
                    'wedding_location'   => $row['wedding_location'] ?? '',
                    'husband_residence'  => $row['husband_residence'] ?? '',
                    'wife_residence'     => $row['wife_residence'] ?? '',
                    'officiating_priest' => $row['officiating_priest'] ?? '',
                    'priest'             => $row['officiating_priest'] ?? '',
                    'parish_priest'      => $row['parish_priest'] ?? '',
                    'priest_in_charge'   => $row['parish_priest'] ?? '',
                    'book_no'            => $row['book_no'] ?? '',
                    'page_no'            => $row['page_no'] ?? '',
                    'entry_no'           => $row['entry_no'] ?? '',
                ];
            } else {
                $response['error'] = 'Record not found';
            }
        }
    } elseif ($type === 'funeral' || $type === 'funeral_certification') {
        $stmt = $conn->prepare("SELECT * FROM funeral_records WHERE funeral_id = ? AND status='active' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                list($fatherName, $motherName) = $parseParents($row);
                $response['success'] = true;
                $response['data'] = [
                    'id'                 => (int)$row['funeral_id'],
                    'deceased_name'      => $row['deceased_name'] ?? '',
                    'father_name'        => $fatherName,
                    'mother_name'        => $motherName,
                    'parents'            => $row['parents'] ?? '',
                    'date_of_burial'     => $row['date_of_burial'] ?? '',
                    'place_of_burial'    => $row['place_of_burial'] ?? '',
                    'minister'           => $row['minister'] ?? '',
                    'officiating_priest' => $row['minister'] ?? '',
                    'parish_priest'      => $row['parish_priest'] ?? '',
                    'priest_in_charge'   => $row['parish_priest'] ?? '',
                    'book_no'            => $row['book_no'] ?? '',
                    'page_no'            => $row['page_no'] ?? '',
                    'entry_no'           => $row['entry_no'] ?? '',
                ];
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
