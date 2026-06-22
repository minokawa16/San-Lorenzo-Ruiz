<?php
/**
 * Local AI-Assisted Parish Assistant API
 *
 * This endpoint provides deterministic AI-style assistance without external API
 * keys. It provides controlled Tugon chat answers from the local parish
 * database and refuses unrelated questions.
 */

header('Content-Type: application/json');

include __DIR__ . '/../includes/session.php';
include __DIR__ . '/../database/config.php';
include __DIR__ . '/../includes/helpers.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => t('chatbot.login_required', 'Please log in to use the AI Parish Assistant.')]);
    exit;
}

// Schema Inspection - Checks optional tables and columns before building database-backed answers.
function aiTableExists($conn, $table) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    return $result && $result->num_rows > 0;
}

// AI Column Exists Function - Documents this helper's role in the parish management workflow.
function aiColumnExists($conn, $table, $column) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && $result->num_rows > 0;
}

// AI Not Deleted Sql Function - Documents this helper's role in the parish management workflow.
function aiNotDeletedSql($conn, $table, $alias = '') {
    if (!aiColumnExists($conn, $table, 'deleted_at')) {
        return '';
    }

    $prefix = $alias ? preg_replace('/[^a-zA-Z0-9_]/', '', $alias) . '.' : '';
    return " AND {$prefix}deleted_at IS NULL";
}

// AI Fetch One Function - Documents this helper's role in the parish management workflow.
function aiFetchOne($conn, $sql) {
    $result = $conn->query($sql);
    if (!$result) {
        return 0;
    }
    $row = $result->fetch_assoc();
    return intval($row['count'] ?? 0);
}

// AI Search Like Function - Documents this helper's role in the parish management workflow.
function aiSearchLike($conn, $sql, $types, $params) {
    $rows = [];
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $rows;
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($result && $row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

// Response Helpers - Builds consistent assistant messages, guidance cards, and refusal text.
function aiOutOfContextMessage() {
    return t('chatbot.out_of_context', 'I specialize in parish services and church-related concerns. I may not have information about that topic, but I would be happy to help you with certificates, sacraments, schedules, announcements, and other parish services.');
}

// AI Guidance Response Function - Documents this helper's role in the parish management workflow.
function aiGuidanceResponse($title, $answer, $link = null, $steps = []) {
    return [
        'title' => $title,
        'answer' => $answer,
        'steps' => $steps,
        'link' => $link
    ];
}

// Context Guardrails - Limits assistant answers to Tugon, parish services, and role-appropriate topics.
function aiQuestionAllowed($query, $role) {
    $q = strtolower(trim($query));
    if ($q === '') {
        return true;
    }

    $adminOnlyActions = [
        'create announcement', 'post announcement', 'add announcement', 'delete announcement',
        'approve request', 'reject request', 'create schedule', 'delete schedule', 'manage users',
        'make me admin', 'admin password'
    ];

    foreach ($adminOnlyActions as $phrase) {
        if ($role !== 'admin' && strpos($q, $phrase) !== false) {
            return false;
        }
    }

    $blockedPatterns = [
        '/\bwhat is my name\b/',
        '/\bwho am i\b/',
        '/\btell me a joke\b/',
        '/\bjoke\b/',
        '/\bstory\b/',
        '/\bessay\b/',
        '/\bpoem\b/',
        '/\bcode\b/',
        '/\bprogramming\b/',
        '/\bweather\b/',
        '/\brecipe\b/',
        '/\bmovie\b/',
        '/\bgame\b/',
        '/\bmath\b/',
        '/\btranslate\b/'
    ];

    foreach ($blockedPatterns as $pattern) {
        if (preg_match($pattern, $q)) {
            return false;
        }
    }

    $allowedKeywords = [
        'tugon', 'parish', 'church', 'office', 'hours', 'schedule', 'mass', 'calendar',
        'certificate', 'baptismal', 'baptism', 'confirmation', 'communion', 'sacramental',
        'service', 'blessing', 'request', 'status', 'reference', 'requirement', 'requirements',
        'document', 'upload', 'announcement', 'notification', 'registration', 'register',
        'account', 'login', 'password', 'payment', 'pay', 'paid', 'unpaid', 'reservation',
        'funeral', 'marriage', 'wedding', 'anointing', 'patronal', 'fiesta', 'navigate',
        'where', 'how', 'when', 'what time', 'open'
    ];

    foreach ($allowedKeywords as $keyword) {
        if (strpos($q, $keyword) !== false) {
            return true;
        }
    }

    if (preg_match('/\bPRQ-\d{8}-\d{5}\b/i', $query)) {
        return true;
    }

    return false;
}

// Public Schedule Lookup - Reads approved Mass schedules and events for user-facing assistant answers.
function aiFetchLatestPublicMassSchedules($conn) {
    if (!aiTableExists($conn, 'schedule_events')) {
        return [];
    }

    $rows = [];
    $sql = "SELECT title, event_date, start_time, location
            FROM schedule_events
            WHERE status != 'cancelled'
              AND visibility = 'public'
              AND approval_status = 'approved'
              AND category = 'mass'
              AND event_date >= CURDATE()
            ORDER BY event_date ASC, start_time ASC
            LIMIT 3";
    $result = $conn->query($sql);
    while ($result && $row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}

// Direct Guidance - Answers common workflow questions before falling back to database search.
function aiDirectGuidance($conn, $query, $role, $userId) {
    $q = strtolower(trim($query));

    if (strpos($q, 'baptism') !== false && (strpos($q, 'requirement') !== false || strpos($q, 'requirements') !== false)) {
        return aiGuidanceResponse(
            'Baptism requirements',
            "I'd be glad to assist you. Before submitting a Baptism request, prepare the chapel recommendation, parents' latest marriage contract or receipt if applicable, photocopy of marriage certificate if married, photocopy of the child's live birth certificate with registry number, two white cards of sponsors, and white cards of parents.",
            $role === 'admin' ? '../admin/manage-requests.php' : '../users/request-service.php',
            [
                'Review the Baptism requirements card in the request page.',
                'Prepare clear copies of all documents required by the parish office.',
                'Fill out the pre-baptismal investigation sheet before proceeding.',
                'Submit the request and wait for parish office review.'
            ]
        );
    }

    if ((strpos($q, 'marriage') !== false || strpos($q, 'wedding') !== false) && (strpos($q, 'requirement') !== false || strpos($q, 'requirements') !== false)) {
        return aiGuidanceResponse(
            'Marriage requirements',
            'Here is the information you need. Marriage requirements include Pre-Cana, municipal license, BEC recommendation, baptismal certificate for marriage purpose, confirmation certificate, permit to marry, interview, confession, and CO permit if applicable for police or army personnel.',
            $role === 'admin' ? '../admin/manage-requests.php' : '../users/request-service.php',
            [
                'Prepare separate requirement documents for the male and female applicants.',
                'Review the Marriage requirements section before filling out the request form.',
                'Upload clear supporting documents when requested by the parish.',
                'Wait for parish office validation and schedule confirmation.'
            ]
        );
    }

    if (strpos($q, 'confirmation') !== false && (strpos($q, 'requirement') !== false || strpos($q, 'requirements') !== false)) {
        return aiGuidanceResponse(
            'Confirmation requirements',
            'Thank you for your question. For Confirmation, prepare accurate personal details and supporting parish records requested by the parish office. If a certificate copy is needed, a PSA or birth certificate copy may be required for verification.',
            $role === 'admin' ? '../admin/manage-requests.php' : '../users/request-service.php',
            [
                'Open Sacramental Services or Certificate Requests depending on what you need.',
                'Check the displayed requirements before proceeding.',
                'Upload clear documents and submit the request for review.'
            ]
        );
    }

    if (strpos($q, 'office') !== false && (strpos($q, 'schedule') !== false || strpos($q, 'hour') !== false || strpos($q, 'open') !== false)) {
        return aiGuidanceResponse(
            t('chatbot.office_title', 'Parish office schedule'),
            t('chatbot.office_answer', 'The parish office is open Monday to Saturday, 8:00 AM to 5:00 PM, with lunch break from 12:00 PM to 1:00 PM. The office is closed on Sunday.'),
            null
        );
    }

    if (strpos($q, 'mass') !== false && strpos($q, 'schedule') !== false) {
        $massRows = aiFetchLatestPublicMassSchedules($conn);
        if (!empty($massRows)) {
            $parts = [];
            foreach ($massRows as $row) {
                $parts[] = $row['title'] . ' on ' . formatDate($row['event_date']) . ' at ' . formatTime($row['start_time']) . ($row['location'] ? ' in ' . $row['location'] : '');
            }
            return aiGuidanceResponse(t('chatbot.mass_title', 'Mass schedule'), implode('; ', $parts) . '.', $role === 'admin' ? '../admin/manage-calendar.php' : '../users/view-schedule.php');
        }

        return aiGuidanceResponse(
            t('chatbot.mass_title', 'Mass schedule'),
            t('chatbot.mass_default', 'The stored Mass schedule is Sunday Mass at 6:00 AM, 8:00 AM, and 5:00 PM; weekday Mass is Monday to Friday at 5:30 PM.'),
            $role === 'admin' ? '../admin/manage-calendar.php' : '../users/view-schedule.php'
        );
    }

    if (preg_match('/\b(PRQ-\d{8}-\d{5})\b/i', $query, $matches)) {
        $reference = strtoupper($matches[1]);
        if ($role === 'admin') {
            $stmt = $conn->prepare("SELECT r.request_id, r.request_type, r.status, u.fullname FROM requests r JOIN users u ON r.user_id = u.id WHERE r.reference_number = ? LIMIT 1");
            $stmt->bind_param('s', $reference);
        } else {
            $stmt = $conn->prepare("SELECT request_id, request_type, status FROM requests WHERE reference_number = ? AND user_id = ? LIMIT 1");
            $stmt->bind_param('si', $reference, $userId);
        }

        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$request) {
            return aiGuidanceResponse(t('chatbot.status_title', 'Request status'), tr('chatbot.no_request', 'No request record was found for reference {reference} in the records available to your account.', ['reference' => $reference]));
        }

        $owner = isset($request['fullname']) ? tr('chatbot.owner_for', ' for {name}', ['name' => $request['fullname']]) : '';
        return aiGuidanceResponse(
            t('chatbot.status_title', 'Request status'),
            tr('chatbot.request_is', 'Request {reference}{owner} is currently {status}.', [
                'reference' => $reference,
                'owner' => $owner,
                'status' => ucfirst($request['status'])
            ]),
            $role === 'admin' ? '../admin/process-request.php?id=' . intval($request['request_id']) : '../users/view-request.php?id=' . intval($request['request_id'])
        );
    }

    if (strpos($q, 'status') !== false && strpos($q, 'request') !== false) {
        return aiGuidanceResponse(
            t('chatbot.status_title', 'Request status'),
            t('chatbot.status_help', 'Open My Requests to view your request status. Pending means waiting for review, processing means staff are checking it, approved means accepted, completed means finished, and rejected means it was not approved or needs correction.'),
            $role === 'admin' ? '../admin/manage-requests.php' : '../users/my-requests.php'
        );
    }

    if (strpos($q, 'requirement') !== false || strpos($q, 'requirements') !== false) {
        return aiGuidanceResponse(
            t('chatbot.requirements_title', 'Request requirements'),
            t('chatbot.requirements_answer', 'For certificate and sacramental requests, submit accurate details and upload a clear requirements file such as a valid ID, supporting parish document, or required record scan before submitting.'),
            $role === 'admin' ? '../admin/manage-requests.php' : '../users/request-certificate.php'
        );
    }

    if (strpos($q, 'certificate') !== false || strpos($q, 'baptismal') !== false || strpos($q, 'confirmation') !== false || strpos($q, 'communion') !== false) {
        return aiGuidanceResponse(
            t('chatbot.certificates_title', 'Certificate requests'),
            t('chatbot.certificates_answer', 'To request a certificate, go to Certificates, select the certificate type, enter the needed details, attach your requirements file, and submit the request for parish review.'),
            $role === 'admin' ? '../admin/manage-requests.php' : '../users/request-certificate.php'
        );
    }

    if (strpos($q, 'blessing') !== false) {
        return aiGuidanceResponse(
            t('chatbot.blessing_title', 'Blessing requests'),
            t('chatbot.blessing_answer', 'To request a blessing, go to Blessings, choose the blessing type, enter the preferred date, time, location, add any details, attach requirements if available, and submit.'),
            $role === 'admin' ? '../admin/manage-requests.php' : '../users/request-blessing.php'
        );
    }

    if (strpos($q, 'sacramental') !== false || strpos($q, 'funeral') !== false || strpos($q, 'wedding') !== false || strpos($q, 'marriage') !== false || strpos($q, 'anointing') !== false || strpos($q, 'patronal') !== false) {
        return aiGuidanceResponse(
            t('chatbot.service_title', 'Sacramental service requests'),
            t('chatbot.service_answer', 'To request a sacramental service, go to Sacramental Services, select the service, enter the date, time, location, details, attach requirements if available, and submit.'),
            $role === 'admin' ? '../admin/manage-requests.php' : '../users/request-service.php'
        );
    }

    if (strpos($q, 'announcement') !== false) {
        if ($role !== 'admin' && (strpos($q, 'create') !== false || strpos($q, 'post') !== false || strpos($q, 'add') !== false)) {
            return aiGuidanceResponse(t('chatbot.outside_title', 'Parish services focus'), aiOutOfContextMessage());
        }

        return aiGuidanceResponse(
            t('chatbot.announcements_title', 'Announcements'),
            t('chatbot.announcements_answer', 'Open Announcements to view active parish announcements, schedules, events, and attached files posted by the parish office.'),
            $role === 'admin' ? '../admin/manage-announcements.php' : '../users/announcements.php'
        );
    }

    if (strpos($q, 'payment') !== false || strpos($q, 'paid') !== false || strpos($q, 'unpaid') !== false || strpos($q, 'pay') !== false) {
        return aiGuidanceResponse(
            t('chatbot.payment_title', 'Payment status'),
            t('chatbot.payment_answer', 'No payment status record is stored for your account in Tugon. Please contact the parish office for payment confirmation.'),
            null
        );
    }

    if (strpos($q, 'register') !== false || strpos($q, 'registration') !== false || strpos($q, 'account') !== false) {
        return aiGuidanceResponse(
            t('chatbot.registration_title', 'Account registration'),
            t('chatbot.registration_answer', 'To register, complete your parishioner details, pass live identity verification, scan your valid ID, and wait for parish administrator approval before logging in.'),
            '../auth/register.php'
        );
    }

    if (strpos($q, 'schedule') !== false || strpos($q, 'calendar') !== false || strpos($q, 'event') !== false) {
        return aiGuidanceResponse(
            t('chatbot.schedule_title', 'Parish schedules'),
            t('chatbot.schedule_answer', 'Open Schedule to view approved public parish schedules, events, Masses, and sacramental service dates.'),
            $role === 'admin' ? '../admin/manage-calendar.php' : '../users/view-schedule.php'
        );
    }

    if (strpos($q, 'login') !== false || strpos($q, 'password') !== false) {
        return aiGuidanceResponse(
            t('chatbot.login_title', 'Login help'),
            t('chatbot.login_answer', 'Use your registered Gmail address and password to log in. For password recovery, open Forgot Password or contact the parish office for account verification.'),
            '../auth/login.php'
        );
    }

    return null;
}

// Static Guidance Library - Maps known parish workflow topics to concise help instructions.
function aiGuidanceForQuery($query, $role) {
    $q = strtolower($query);

    $guides = [
        'certificate' => [
            'title' => 'Certificate request guidance',
            'answer' => 'To request a parish certificate, open My Requests, choose Certificates, select the certificate type, complete all applicant details, upload valid requirements, then submit for parish review.',
            'steps' => [
                'Open My Requests > Certificates.',
                'Choose Baptismal, Confirmation, or First Communion certificate.',
                'Enter accurate names, dates, and parent or sponsor details.',
                'Upload a valid ID and supporting parish information when available.',
                'Submit and track the status from My Requests.'
            ],
            'link' => $role === 'admin' ? '../admin/manage-requests.php' : '../users/request-certificate.php'
        ],
        'blessing' => [
            'title' => 'Blessing request guidance',
            'answer' => 'For blessing requests, choose the blessing type, provide the location and preferred schedule, then wait for parish confirmation.',
            'steps' => [
                'Open My Requests > Blessings.',
                'Select house, vehicle, business, office, or event blessing.',
                'Add the address, preferred date, and contact details.',
                'Submit the request for schedule review.'
            ],
            'link' => $role === 'admin' ? '../admin/manage-requests.php' : '../users/request-blessing.php'
        ],
        'reservation' => [
            'title' => $role === 'admin' ? 'Reservation support' : 'Sacramental service support',
            'answer' => $role === 'admin'
                ? 'Reservations are reviewed by parish staff based on date, type of event, and schedule availability.'
                : 'Sacramental service requests are reviewed by parish staff based on service type, date, time, location, and schedule availability.',
            'steps' => [
                $role === 'admin' ? 'Open Reservations.' : 'Open My Requests > Sacramental Services.',
                $role === 'admin' ? 'Choose the reservation type and event date.' : 'Choose the service type and service date.',
                'Provide time, location, purpose, and contact information.',
                'Submit and wait for approval or admin follow-up.'
            ],
                'link' => $role === 'admin' ? '../admin/manage-reservations.php' : '../users/request-service.php'
        ],
        'status' => [
            'title' => 'Request status explanation',
            'answer' => 'Pending means waiting for review, processing means staff are working on it, approved means accepted, completed means finished, and rejected means it needs correction or cannot be granted.',
            'steps' => [
                'Open My Requests.',
                'Find the reference number.',
                'Review the status badge and any admin note.',
                'Contact the parish office if the request has been pending for several working days.'
            ],
            'link' => $role === 'admin' ? '../admin/manage-requests.php' : '../users/my-requests.php'
        ],
        'schedule' => [
            'title' => 'Mass and schedule information',
            'answer' => 'You can view approved parish schedules, Masses, services, and events from the Schedule page.',
            'steps' => [
                'Open Schedule.',
                'Review upcoming approved events.',
                'Use the date and category details to plan your visit.',
                'For changes, watch announcements and notifications.'
            ],
            'link' => $role === 'admin' ? '../admin/manage-calendar.php' : '../users/view-schedule.php'
        ],
        'analytics' => [
            'title' => 'AI-assisted analytics',
            'answer' => $role === 'admin'
                ? 'The assistant can summarize requests, reservations, announcements, schedules, and sacramental record activity for administrative reporting.'
                : 'Your dashboard summarizes your requests, sacramental services, notifications, and recent parish activity.',
            'steps' => [
                $role === 'admin' ? 'Ask about pending requests, reservations, announcements, schedules, or records.' : 'Ask about pending requests, sacramental services, announcements, schedules, or records.',
                'Use the search results to open the relevant module.',
                'Admins can export formal reports from Reports.'
            ],
            'link' => $role === 'admin' ? '../admin/reports.php' : '../users/index.php'
        ]
    ];

    $map = [
        'certificate' => ['certificate', 'baptismal', 'confirmation', 'communion'],
        'blessing' => ['blessing', 'bless', 'house', 'vehicle', 'business', 'office'],
        'reservation' => ['reservation', 'reserve', 'booking', 'sacramental service', 'service request', 'schedule a'],
        'status' => ['status', 'pending', 'approved', 'completed', 'rejected', 'reference'],
        'schedule' => ['mass', 'service', 'schedule', 'calendar', 'event'],
        'analytics' => ['analytics', 'report', 'summary', 'trend', 'insight', 'search']
    ];

    foreach ($map as $key => $keywords) {
        foreach ($keywords as $keyword) {
            if (strpos($q, $keyword) !== false) {
                return $guides[$key];
            }
        }
    }

    return [
        'title' => 'Parish inquiry assistance',
        'answer' => $role === 'admin'
            ? 'I can help with certificate requests, blessings, reservations, request status, schedules, announcements, and parish activity summaries.'
            : 'I can help with certificate requests, blessings, sacramental services, request status, schedules, requirements, registration, and announcements.',
        'steps' => [
            $role === 'admin' ? 'Mention the service you need: certificate, blessing, reservation, Mass schedule, or status.' : 'Mention the service you need: certificate, blessing, sacramental service, Mass schedule, or status.',
            'Include a name, reference number, or date if you want search results.',
            'Open the recommended module from the result link.'
        ],
        'link' => $role === 'admin' ? '../admin/ai-assistant.php' : '../users/ai-assistant.php'
    ];
}

// Search Builder - Collects matching records, requests, announcements, and schedules for assistant results.
function aiBuildSearchResults($conn, $query, $role, $userId) {
    $results = [];
    $query = trim($query);
    if (strlen($query) < 2) {
        return $results;
    }

    $like = '%' . $query . '%';

    if (aiTableExists($conn, 'announcements')) {
        $notDeleted = aiNotDeletedSql($conn, 'announcements');
        $rows = aiSearchLike(
            $conn,
            "SELECT title, type, published_date FROM announcements WHERE status = 'active'$notDeleted AND (title LIKE ? OR content LIKE ?) ORDER BY published_date DESC LIMIT 5",
            'ss',
            [$like, $like]
        );
        foreach ($rows as $row) {
            $results[] = [
                'module' => 'Announcement',
                'title' => $row['title'],
                'meta' => ucfirst($row['type']) . ' - ' . formatDate($row['published_date']),
                'url' => $role === 'admin' ? '../admin/manage-announcements.php' : '../users/announcements.php'
            ];
        }
    }

    if (aiTableExists($conn, 'requests')) {
        $notDeleted = aiNotDeletedSql($conn, 'requests', $role === 'admin' ? 'r' : '');
        if ($role === 'admin') {
            $rows = aiSearchLike(
                $conn,
                "SELECT r.request_id, r.reference_number, r.request_type, r.status, u.fullname FROM requests r JOIN users u ON r.user_id = u.id WHERE 1=1$notDeleted AND (r.reference_number LIKE ? OR r.request_type LIKE ? OR u.fullname LIKE ?) ORDER BY r.date_requested DESC LIMIT 6",
                'sss',
                [$like, $like, $like]
            );
        } else {
            $rows = aiSearchLike(
                $conn,
                "SELECT request_id, reference_number, request_type, status FROM requests WHERE user_id = ?$notDeleted AND (reference_number LIKE ? OR request_type LIKE ?) ORDER BY date_requested DESC LIMIT 6",
                'iss',
                [$userId, $like, $like]
            );
        }

        foreach ($rows as $row) {
            $owner = isset($row['fullname']) ? ' - ' . $row['fullname'] : '';
            $results[] = [
                'module' => 'Request',
                'title' => ($row['reference_number'] ?: 'Request') . $owner,
                'meta' => ucfirst(str_replace('_', ' ', $row['request_type'])) . ' - ' . ucfirst($row['status']),
                'url' => $role === 'admin'
                    ? '../admin/process-request.php?id=' . intval($row['request_id'])
                    : '../users/view-request.php?id=' . intval($row['request_id'])
            ];
        }
    }

    if (aiTableExists($conn, 'reservations')) {
        if ($role === 'admin') {
            $rows = aiSearchLike(
                $conn,
                "SELECT reservation_id, reservation_type, event_date, status FROM reservations WHERE reservation_type LIKE ? OR status LIKE ? ORDER BY event_date DESC LIMIT 5",
                'ss',
                [$like, $like]
            );

            foreach ($rows as $row) {
                $results[] = [
                    'module' => 'Reservation',
                    'title' => ucfirst(str_replace('_', ' ', $row['reservation_type'])),
                    'meta' => formatDate($row['event_date']) . ' - ' . ucfirst($row['status']),
                    'url' => '../admin/manage-reservations.php'
                ];
            }
        }
    }

    if (aiTableExists($conn, 'schedule_events')) {
        $visibility = $role === 'admin' ? '' : " AND visibility = 'public' AND approval_status = 'approved'";
        $rows = aiSearchLike(
            $conn,
            "SELECT title, event_date, start_time, location FROM schedule_events WHERE status != 'cancelled'$visibility AND (title LIKE ? OR description LIKE ? OR location LIKE ?) ORDER BY event_date ASC LIMIT 5",
            'sss',
            [$like, $like, $like]
        );
        foreach ($rows as $row) {
            $results[] = [
                'module' => 'Schedule',
                'title' => $row['title'],
                'meta' => formatDate($row['event_date']) . ' ' . formatTime($row['start_time']) . ($row['location'] ? ' - ' . $row['location'] : ''),
                'url' => $role === 'admin' ? '../admin/manage-calendar.php' : '../users/view-schedule.php'
            ];
        }
    }

    return array_slice($results, 0, 12);
}

// Analytics Builder - Summarizes parish activity for dashboard-style assistant responses.
function aiBuildAnalytics($conn, $role, $userId) {
    if ($role === 'admin') {
        $requestNotDeleted = aiNotDeletedSql($conn, 'requests');
        $announcementNotDeleted = aiNotDeletedSql($conn, 'announcements');
        $analytics = [
            'title' => 'Administrative activity summary',
            'metrics' => [
                'Parishioners' => aiFetchOne($conn, "SELECT COUNT(*) AS count FROM users WHERE role = 'user'"),
                'Pending Requests' => aiFetchOne($conn, "SELECT COUNT(*) AS count FROM requests WHERE status = 'pending'$requestNotDeleted"),
                'Open Reservations' => aiFetchOne($conn, "SELECT COUNT(*) AS count FROM reservations WHERE status IN ('pending', 'approved')"),
                'Active Announcements' => aiFetchOne($conn, "SELECT COUNT(*) AS count FROM announcements WHERE status = 'active'$announcementNotDeleted")
            ],
            'insights' => []
        ];

        $pending = $analytics['metrics']['Pending Requests'];
        $reservations = $analytics['metrics']['Open Reservations'];
        if ($pending > 10) {
            $analytics['insights'][] = 'High request queue detected. Prioritize pending certificate reviews and notify applicants with incomplete requirements.';
        } elseif ($pending > 0) {
            $analytics['insights'][] = 'Pending requests are manageable. Review oldest items first to keep turnaround time steady.';
        } else {
            $analytics['insights'][] = 'No pending certificate requests are waiting right now.';
        }

        if ($reservations > 0) {
            $analytics['insights'][] = 'There are open reservations to monitor for schedule conflicts and confirmation messages.';
        }

        return $analytics;
    }

    $pending = aiFetchOne($conn, "SELECT COUNT(*) AS count FROM requests WHERE user_id = " . intval($userId) . " AND status = 'pending'");
    $approved = aiFetchOne($conn, "SELECT COUNT(*) AS count FROM requests WHERE user_id = " . intval($userId) . " AND status = 'approved'");

    $insights = [];
    $insights[] = $pending > 0
        ? 'You have pending requests waiting for parish review. Keep your documents and reference numbers ready.'
        : 'You have no pending certificate requests right now.';
    if ($approved > 0) {
        $insights[] = 'Approved requests may be ready for next steps. Open My Requests to check details.';
    }

    return [
        'title' => 'Your parish activity summary',
        'metrics' => [
            'Pending Requests' => $pending,
            'Approved Requests' => $approved,
            'Sacramental Services' => aiFetchOne($conn, "SELECT COUNT(*) AS count FROM requests WHERE user_id = " . intval($userId) . " AND request_type IN ('baptism_service','marriage_wedding_service','funeral_mass','anointing_of_the_sick','patronal_fiesta')"),
            'Unread Notifications' => getUnreadNotificationCount($conn, $userId)
        ],
        'insights' => $insights
    ];
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$query = trim($payload['message'] ?? $payload['q'] ?? '');
$mode = trim($payload['mode'] ?? 'chat');
$role = $_SESSION['role'] ?? 'user';
$userId = intval($_SESSION['user_id']);

if ($query === '') {
    $query = $role === 'admin' ? 'analytics summary and pending requests' : 'help me with parish services';
}

if (!aiQuestionAllowed($query, $role)) {
    $guidance = aiGuidanceResponse(t('chatbot.outside_title', 'Parish services focus'), aiOutOfContextMessage());
    logChatbotInquiry($conn, $userId, $role, $query, $guidance['answer'], $mode, true);
    echo json_encode([
        'success' => true,
        'answer' => $guidance['answer'],
        'guidance' => $guidance,
        'search_results' => [],
        'analytics' => null,
        'mode' => $mode,
        'context_limited' => true
    ]);
    exit;
}

$directGuidance = aiDirectGuidance($conn, $query, $role, $userId);
$guidance = $directGuidance ?: aiGuidanceForQuery($query, $role);
$searchResults = $role === 'admin' ? aiBuildSearchResults($conn, $query, $role, $userId) : [];
$analytics = null;

$answer = $guidance['answer'];
if ($mode === 'search' && !empty($searchResults) && !$directGuidance) {
    $answer = t('chatbot.search_found', 'I found related Tugon records. Open the matching result below.');
}

$analyticsAllowed = $role === 'admin' && ($mode === 'analytics' || strpos(strtolower($query), 'analytics') !== false || strpos(strtolower($query), 'report') !== false || strpos(strtolower($query), 'summary') !== false);
if ($analyticsAllowed) {
    $analytics = aiBuildAnalytics($conn, $role, $userId);
    $answer = $analytics['title'] . ': ' . implode(' ', $analytics['insights']);
} elseif ($mode === 'analytics' && $role !== 'admin') {
    $answer = t('chatbot.analytics_limited', 'Open My Requests to view your request status and activity. Analytics summaries are limited to parish administration.');
    $guidance = aiGuidanceResponse(t('chatbot.activity_title', 'Request activity'), $answer, '../users/my-requests.php');
}

logChatbotInquiry($conn, $userId, $role, $query, $answer, $mode, true);

echo json_encode([
    'success' => true,
    'answer' => $answer,
    'guidance' => $guidance,
    'search_results' => $searchResults,
    'analytics' => $analytics,
    'mode' => $mode
]);
