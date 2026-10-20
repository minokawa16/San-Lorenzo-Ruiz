<?php
/**
 * Analytics Drill-Down Requesters Endpoint
 * Returns requests joined with requester names matching the exact summary filters.
 * Restricted strictly to Admin and Secretary roles.
 * Privacy-safe: returns only name and request information.
 */

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

// 1. Authentication & Role Authorization: Only admin and secretary allowed
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
}

$userRoles = array_map('strtolower', (array) userRoleKeys());
$sessionRole = strtolower(trim((string) ($_SESSION['role'] ?? '')));
$allowedRoles = ['admin', 'administrator', 'secretary', 'parish_secretary'];
$hasAccess = in_array($sessionRole, $allowedRoles, true);
if (!$hasAccess) {
    foreach ($userRoles as $r) {
        if (in_array($r, $allowedRoles, true)) {
            $hasAccess = true;
            break;
        }
    }
}
if (!$hasAccess && hasPermission('admin.access') && (isAdmin() || in_array('secretary', $userRoles, true))) {
    $hasAccess = true;
}

if (!$hasAccess) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied. Only Administrator and Parish Secretary can access analytics drill-down.']);
    exit;
}

// 2. Sanitise inputs
$cleanText = static fn($v) => preg_match('/^[a-z0-9_ -]{0,80}$/i', (string)$v) ? trim((string)$v) : '';
$cleanDate = static fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? (string)$v : '';

$cat    = strtolower($cleanText($_GET['cat'] ?? ($_POST['cat'] ?? '')));
$type   = $cleanText($_GET['type'] ?? ($_POST['type'] ?? ''));
$status = strtolower($cleanText($_GET['status'] ?? ($_POST['status'] ?? '')));
$range  = strtolower($cleanText($_GET['range'] ?? ($_GET['summary_range'] ?? ($_POST['range'] ?? 'all'))));
$from   = $cleanDate($_GET['from'] ?? ($_GET['summary_from'] ?? ($_POST['from'] ?? '')));
$to     = $cleanDate($_GET['to'] ?? ($_GET['summary_to'] ?? ($_POST['to'] ?? '')));
$title  = trim((string)($_GET['title'] ?? ($_POST['title'] ?? '')));
$filterUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : (isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0);
$filterRequesterName = trim((string)($_GET['requester_name'] ?? ($_POST['requester_name'] ?? '')));

if (!in_array($range, ['all', 'month', 'year', 'custom'], true)) {
    $range = 'all';
}
if (!in_array($cat, ['certificates', 'blessings', 'services'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid category specified. Must be certificates, blessings, or services.']);
    exit;
}

// 3. Build identical date & WHERE filters as reports.php
$matchingRequests = [];

if ($cat === 'certificates') {
    $whereIssuance = ["ci.status != 'revoked'"];
    $typesIss = '';
    $valsIss = [];

    $dateCol = "COALESCE(ci.issued_at, ci.drafted_at, ci.updated_at)";
    if ($range === 'month') {
        $whereIssuance[] = "DATE_FORMAT($dateCol, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
    } elseif ($range === 'year') {
        $whereIssuance[] = "YEAR($dateCol) = YEAR(CURDATE())";
    } elseif ($range === 'custom') {
        if ($from !== '') {
            $whereIssuance[] = "$dateCol >= ?";
            $typesIss .= 's';
            $valsIss[] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $whereIssuance[] = "$dateCol <= ?";
            $typesIss .= 's';
            $valsIss[] = $to . ' 23:59:59';
        }
    }

    if ($type !== '') {
        $whereIssuance[] = "ci.certificate_type = ?";
        $typesIss .= 's';
        $valsIss[] = $type;
    }

    if ($status !== '') {
        $whereIssuance[] = "ci.status = ?";
        $typesIss .= 's';
        $valsIss[] = $status;
    }

    if ($filterUserId > 0) {
        $whereIssuance[] = "(r.user_id = ? OR ci.issued_by = ?)";
        $typesIss .= 'ii';
        $valsIss[] = $filterUserId;
        $valsIss[] = $filterUserId;
    } elseif ($filterRequesterName !== '') {
        $whereIssuance[] = "(ci.issued_to = ? OR u.fullname = ?)";
        $typesIss .= 'ss';
        $valsIss[] = $filterRequesterName;
        $valsIss[] = $filterRequesterName;
    }

    $whereSql = implode(' AND ', $whereIssuance);
    $sql = "SELECT 
                ci.certificate_id AS id,
                COALESCE(ci.certificate_number, r.reference_number, CONCAT('CERT-', ci.certificate_id)) AS reference_number,
                ci.certificate_type AS request_type,
                ci.status AS status,
                $dateCol AS date_submitted,
                'certificates' AS category,
                COALESCE(u.id, r.user_id, 0) AS user_id,
                COALESCE(NULLIF(TRIM(u.fullname), ''), NULLIF(TRIM(ci.issued_to), ''), 'Parishioner') AS requester_name
            FROM certificate_issuances ci
            LEFT JOIN requests r ON r.request_id = ci.request_id
            LEFT JOIN users u ON u.id = COALESCE(r.user_id, ci.issued_by)
            WHERE $whereSql
            ORDER BY date_submitted DESC, ci.certificate_id DESC";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($typesIss !== '') {
            $stmt->bind_param($typesIss, ...$valsIss);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $matchingRequests[] = $row;
        }
        $stmt->close();
    }

} elseif ($cat === 'blessings' || $cat === 'services') {
    $whereRequests = ["r.deleted_at IS NULL"];
    $typesReq = '';
    $valsReq = [];

    if ($range === 'month') {
        $whereRequests[] = "DATE_FORMAT(r.date_requested, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
    } elseif ($range === 'year') {
        $whereRequests[] = "YEAR(r.date_requested) = YEAR(CURDATE())";
    } elseif ($range === 'custom') {
        if ($from !== '') {
            $whereRequests[] = "r.date_requested >= ?";
            $typesReq .= 's';
            $valsReq[] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $whereRequests[] = "r.date_requested <= ?";
            $typesReq .= 's';
            $valsReq[] = $to . ' 23:59:59';
        }
    }

    if ($cat === 'blessings') {
        $whereRequests[] = "r.request_type LIKE '%blessing%'";
    } else {
        $whereRequests[] = "(r.request_type LIKE '%service%' OR r.request_type LIKE '%mass%' OR r.request_type IN ('baptism_service','confirmation_service','first_communion_service','marriage_wedding_service','funeral_mass')) AND r.request_type NOT LIKE '%certif%'";
    }

    if ($type !== '') {
        $whereRequests[] = "r.request_type = ?";
        $typesReq .= 's';
        $valsReq[] = $type;
    }

    if ($status !== '') {
        if ($status === 'completed') {
            $whereRequests[] = "r.status IN ('completed', 'released')";
        } elseif ($status === 'pending') {
            $whereRequests[] = "r.status IN ('pending', 'submitted', 'requirements_review')";
        } elseif ($status === 'approved' || $status === 'processing') {
            $whereRequests[] = "r.status IN ('approved', 'processing', 'in_processing', 'ready_for_pickup', 'ready')";
        } elseif ($status === 'cancelled' || $status === 'rejected') {
            $whereRequests[] = "r.status IN ('rejected', 'cancelled', 'declined')";
        } else {
            $whereRequests[] = "r.status = ?";
            $typesReq .= 's';
            $valsReq[] = $status;
        }
    }

    if ($filterUserId > 0) {
        $whereRequests[] = "r.user_id = ?";
        $typesReq .= 'i';
        $valsReq[] = $filterUserId;
    } elseif ($filterRequesterName !== '') {
        $whereRequests[] = "u.fullname = ?";
        $typesReq .= 's';
        $valsReq[] = $filterRequesterName;
    }

    $whereSql = implode(' AND ', $whereRequests);
    $catVal = $cat === 'blessings' ? 'blessings' : 'services';
    $sql = "SELECT 
                r.request_id AS id,
                COALESCE(r.reference_number, CONCAT('REQ-', r.request_id)) AS reference_number,
                r.request_type,
                r.status,
                r.date_requested AS date_submitted,
                '$catVal' AS category,
                r.user_id,
                COALESCE(NULLIF(TRIM(u.fullname), ''), 'Parishioner') AS requester_name
            FROM requests r
            LEFT JOIN users u ON u.id = r.user_id
            WHERE $whereSql
            ORDER BY r.date_requested DESC, r.request_id DESC";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($typesReq !== '') {
            $stmt->bind_param($typesReq, ...$valsReq);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $matchingRequests[] = $row;
        }
        $stmt->close();
    }
}

// 4. Formatting helpers
$formatType = static function($t) {
    return ucwords(str_replace(['_', '-'], ' ', (string)$t));
};

$normalizeStatus = static function($s) {
    $s = strtolower((string)$s);
    if (in_array($s, ['completed', 'released', 'issued'], true)) {
        return ['label' => 'Completed', 'class' => 'status-completed'];
    }
    if (in_array($s, ['pending', 'submitted', 'requirements_review'], true)) {
        return ['label' => 'Pending', 'class' => 'status-pending'];
    }
    if (in_array($s, ['approved', 'processing', 'in_processing', 'ready_for_pickup', 'ready', 'review', 'approval'], true)) {
        return ['label' => 'Approved / Processing', 'class' => 'status-approved'];
    }
    if (in_array($s, ['rejected', 'cancelled', 'declined', 'revoked'], true)) {
        return ['label' => 'Cancelled', 'class' => 'status-cancelled'];
    }
    return ['label' => ucfirst($s), 'class' => 'status-neutral'];
};

// 5. Group matching requests by requester
$requestersMap = [];
$matchingIds = [];

foreach ($matchingRequests as $req) {
    $uid = (int)($req['user_id'] ?? 0);
    $name = trim((string)($req['requester_name'] ?? 'Parishioner'));
    $key = $uid > 0 ? "user_{$uid}" : "name_" . md5(strtolower($name));
    $matchingIds[$req['category'] . '_' . $req['id']] = true;

    if (!isset($requestersMap[$key])) {
        $requestersMap[$key] = [
            'key' => $key,
            'user_id' => $uid,
            'name' => $name,
            'count' => 0,
            'latest_date' => $req['date_submitted'],
            'matching_items' => []
        ];
    }

    $requestersMap[$key]['count']++;
    if (strtotime($req['date_submitted']) > strtotime($requestersMap[$key]['latest_date'])) {
        $requestersMap[$key]['latest_date'] = $req['date_submitted'];
    }
    $requestersMap[$key]['matching_items'][] = $req;
}

// 6. Assemble finalized requesters list (only matching requests included)
$requestersList = [];
foreach ($requestersMap as $key => $data) {
    $uid = $data['user_id'];
    $matchingItems = $data['matching_items'];

    // Sort matching requests of requester by date DESC
    usort($matchingItems, function($a, $b) {
        return strtotime($b['date_submitted']) <=> strtotime($a['date_submitted']);
    });

    $formattedRequests = [];
    foreach ($matchingItems as $item) {
        $norm = $normalizeStatus($item['status']);
        $formattedRequests[] = [
            'id' => (int)$item['id'],
            'reference_number' => $item['reference_number'] ?: ('REQ-' . $item['id']),
            'type_raw' => $item['request_type'],
            'type_label' => $formatType($item['request_type']),
            'category' => $item['category'],
            'status_raw' => $item['status'],
            'status_label' => $norm['label'],
            'status_class' => $norm['class'],
            'date_submitted' => $item['date_submitted'],
            'date_formatted' => date('M d, Y', strtotime($item['date_submitted'])),
            'time_formatted' => date('h:i A', strtotime($item['date_submitted'])),
        ];
    }

    $requestersList[] = [
        'key' => $key,
        'user_id' => $uid,
        'name' => $data['name'],
        'selection_count' => count($formattedRequests),
        'latest_date' => $data['latest_date'],
        'latest_date_formatted' => date('M d, Y', strtotime($data['latest_date'])),
        'requests' => $formattedRequests,
    ];
}

// 8. Sort requesters: count DESC, latest_date DESC, name ASC
usort($requestersList, function($a, $b) {
    if ($b['selection_count'] !== $a['selection_count']) {
        return $b['selection_count'] <=> $a['selection_count'];
    }
    $tB = strtotime($b['latest_date']);
    $tA = strtotime($a['latest_date']);
    if ($tB !== $tA) {
        return $tB <=> $tA;
    }
    return strcasecmp($a['name'], $b['name']);
});

// 9. Format title if empty
if ($title === '') {
    $tParts = [];
    if ($type !== '') {
        $tParts[] = $formatType($type);
    } elseif ($cat === 'certificates') {
        $tParts[] = 'Certificates';
    } elseif ($cat === 'blessings') {
        $tParts[] = 'Blessings';
    } elseif ($cat === 'services') {
        $tParts[] = 'Sacramental Services';
    }

    if ($status !== '') {
        $tParts[] = $normalizeStatus($status)['label'];
    }
    $title = implode(' · ', $tParts);
}

echo json_encode([
    'success' => true,
    'title' => $title,
    'category' => $cat,
    'type' => $type,
    'status' => $status,
    'range' => $range,
    'from' => $from,
    'to' => $to,
    'total_requests' => count($matchingRequests),
    'total_people' => count($requestersList),
    'requesters' => $requestersList,
], JSON_UNESCAPED_UNICODE);
