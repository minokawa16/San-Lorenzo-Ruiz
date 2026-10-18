<?php

declare(strict_types=1);

final class ParishAssistantTools
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Get definitions of all tools available for function calling / agent execution.
     */
    public function getToolDefinitions(string $role): array
    {
        return [
            [
                'name' => 'get_request_status',
                'description' => 'Retrieve the real-time status and details of a request by reference number or get the logged-in user\'s requests.',
                'parameters' => [
                    'reference_number' => ['type' => 'string', 'description' => 'The request reference number (e.g., REQ-2026-0001). Optional if user asks for their own requests.'],
                ]
            ],
            [
                'name' => 'get_summary_counts',
                'description' => 'Query real database summary counts: completed blessings, generated certificates, total requests, etc.',
                'parameters' => [
                    'filter_type' => ['type' => 'string', 'description' => 'Specific count type requested: "blessings_completed", "certificates_total", "all", "my_requests".']
                ]
            ],
            [
                'name' => 'get_schedule',
                'description' => 'Retrieve the parish public schedule, upcoming events, or Mass times from schedule_events.',
                'parameters' => [
                    'query' => ['type' => 'string', 'description' => 'Optional date or search filter for schedule events.']
                ]
            ],
            [
                'name' => 'get_certificate_types',
                'description' => 'Get the official list of certificate types, fees, and processing times.',
                'parameters' => []
            ],
            [
                'name' => 'get_blessing_types',
                'description' => 'Get the list of blessing categories offered by the parish.',
                'parameters' => []
            ],
            [
                'name' => 'get_parish_contact_info',
                'description' => 'Get the official parish location, office hours, and contact details.',
                'parameters' => []
            ]
        ];
    }

    /**
     * Execute a tool by name with role & permission guardrails.
     */
    public function execute(string $name, array $args, int $userId, string $role): array
    {
        return match ($name) {
            'get_request_status' => $this->getRequestStatus((string) ($args['reference_number'] ?? ''), $userId, $role),
            'get_summary_counts' => $this->getSummaryCounts((string) ($args['filter_type'] ?? 'all'), $userId, $role),
            'get_schedule' => $this->getSchedule((string) ($args['query'] ?? ''), $userId, $role),
            'get_certificate_types' => $this->getCertificateTypes(),
            'get_blessing_types' => $this->getBlessingTypes(),
            'get_parish_contact_info' => $this->getParishContactInfo(),
            default => ['error' => 'Unknown tool: ' . $name]
        };
    }

    /**
     * 1. Tool: get_request_status
     * Respects role: parishioners only see their own requests or sanitized public tracking.
     */
    public function getRequestStatus(string $ref, int $userId, string $role): array
    {
        $ref = trim($ref);

        // If specific reference number provided
        if ($ref !== '') {
            // Find request by reference number or ID
            $stmt = $this->db->prepare("SELECT request_id, user_id, reference_number, request_type, status, date_requested, admin_response, ceremony_date, ceremony_time, ceremony_minister, due_date FROM requests WHERE (reference_number = ? OR reference_number LIKE ?) AND deleted_at IS NULL LIMIT 1");
            $likeRef = '%' . $ref . '%';
            $stmt->bind_param('ss', $ref, $likeRef);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $stmt->close();

            if (!$row) {
                return [
                    'found' => false,
                    'message' => "No active request found matching reference number '{$ref}'. Please check the reference number or visit 'My Requests'."
                ];
            }

            // Role guardrail: Parishioners cannot inspect another user's private records
            $isOwner = ((int) $row['user_id'] === $userId);
            $isStaff = ($role === 'staff' || $role === 'admin');

            if (!$isOwner && !$isStaff) {
                // Return only sanitized tracking info, no personal names/IDs
                return [
                    'found' => true,
                    'reference_number' => $row['reference_number'],
                    'request_type' => ucwords(str_replace('_', ' ', $row['request_type'])),
                    'status' => ucfirst($row['status']),
                    'date_requested' => date('F j, Y', strtotime($row['date_requested'])),
                    'admin_remarks' => $row['admin_response'] ? htmlspecialchars($row['admin_response']) : 'None',
                    'note' => 'Public tracking view (restricted details for privacy).'
                ];
            }

            return [
                'found' => true,
                'request_id' => (int) $row['request_id'],
                'reference_number' => $row['reference_number'],
                'request_type' => ucwords(str_replace('_', ' ', $row['request_type'])),
                'status' => ucfirst($row['status']),
                'date_requested' => date('F j, Y g:i A', strtotime($row['date_requested'])),
                'admin_remarks' => $row['admin_response'] ?: null,
                'ceremony_date' => $row['ceremony_date'] ?: null,
                'ceremony_time' => $row['ceremony_time'] ?: null,
                'minister' => $row['ceremony_minister'] ?: null,
            ];
        }

        // No reference given: return the user's own requests
        if ($userId <= 0) {
            return [
                'found' => false,
                'message' => 'Please provide a Reference Number (e.g. REQ-2026-0001) or log in to view your requests.'
            ];
        }

        $stmt = $this->db->prepare("SELECT request_id, reference_number, request_type, status, date_requested FROM requests WHERE user_id = ? AND deleted_at IS NULL ORDER BY date_requested DESC LIMIT 5");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $requests = [];
        while ($r = $res->fetch_assoc()) {
            $requests[] = [
                'reference_number' => $r['reference_number'] ?: ('REQ-ID-' . $r['request_id']),
                'request_type' => ucwords(str_replace('_', ' ', $r['request_type'])),
                'status' => ucfirst($r['status']),
                'date_requested' => date('M j, Y', strtotime($r['date_requested']))
            ];
        }
        $stmt->close();

        return [
            'found' => !empty($requests),
            'total_user_requests' => count($requests),
            'requests' => $requests
        ];
    }

    /**
     * 2. Tool: get_summary_counts
     * Safe aggregate numbers from real database tables (certificate_issuances, requests).
     */
    public function getSummaryCounts(string $filterType, int $userId, string $role): array
    {
        $counts = [];

        // 1. Total certificates created / issued
        $certRes = $this->db->query("SELECT COUNT(*) AS c FROM certificate_issuances WHERE status != 'revoked'");
        $totalCerts = (int) ($certRes ? ($certRes->fetch_assoc()['c'] ?? 0) : 0);
        $counts['total_certificates_created'] = $totalCerts;

        // 2. Blessings completed
        $blessingRes = $this->db->query("SELECT COUNT(*) AS c FROM requests WHERE request_type LIKE '%blessing%' AND status = 'completed' AND deleted_at IS NULL");
        $blessingsCompleted = (int) ($blessingRes ? ($blessingRes->fetch_assoc()['c'] ?? 0) : 0);
        $counts['blessings_completed'] = $blessingsCompleted;

        // 3. All blessings (pending, processing, completed)
        $allBlessingRes = $this->db->query("SELECT COUNT(*) AS c FROM requests WHERE request_type LIKE '%blessing%' AND deleted_at IS NULL");
        $counts['total_blessing_requests'] = (int) ($allBlessingRes ? ($allBlessingRes->fetch_assoc()['c'] ?? 0) : 0);

        // 4. Sacramental services
        $sacRes = $this->db->query("SELECT COUNT(*) AS c FROM requests WHERE request_type LIKE '%service%' AND deleted_at IS NULL");
        $sacramentalServices = (int) ($sacRes ? ($sacRes->fetch_assoc()['c'] ?? 0) : 0);
        $counts['total_sacramental_services'] = $sacramentalServices;

        // 5. Total active requests
        $reqRes = $this->db->query("SELECT COUNT(*) AS c FROM requests WHERE deleted_at IS NULL");
        $counts['total_requests_all'] = (int) ($reqRes ? ($reqRes->fetch_assoc()['c'] ?? 0) : 0);

        // 6. User's own counts if logged in
        if ($userId > 0) {
            $myRes = $this->db->query("SELECT status, COUNT(*) as c FROM requests WHERE user_id = {$userId} AND deleted_at IS NULL GROUP BY status");
            $myBreakdown = [];
            $myTotal = 0;
            if ($myRes) {
                while ($row = $myRes->fetch_assoc()) {
                    $myBreakdown[$row['status']] = (int) $row['c'];
                    $myTotal += (int) $row['c'];
                }
            }
            $counts['my_requests'] = [
                'total' => $myTotal,
                'breakdown' => $myBreakdown
            ];
        }

        if ($filterType === 'blessings_completed') {
            return ['blessings_completed' => $counts['blessings_completed']];
        }
        if ($filterType === 'certificates_total') {
            return ['total_certificates_created' => $counts['total_certificates_created']];
        }

        return $counts;
    }

    /**
     * 3. Tool: get_schedule
     * Queries schedule_events for upcoming approved parish events and Masses.
     */
    public function getSchedule(string $filterQuery, int $userId, string $role): array
    {
        $events = [];
        $today = date('Y-m-d');

        $sql = "SELECT schedule_id, title, description, event_date, start_time, end_time, location, category
                FROM schedule_events
                WHERE (visibility = 'public' OR ? IN ('admin','staff'))
                AND status != 'cancelled'
                AND event_date >= ?
                ORDER BY event_date ASC, start_time ASC
                LIMIT 5";

        $stmt = $this->db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ss', $role, $today);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $events[] = [
                    'title' => $row['title'],
                    'date' => date('F j, Y', strtotime($row['event_date'])),
                    'time' => date('g:i A', strtotime($row['start_time'])) . ($row['end_time'] ? ' - ' . date('g:i A', strtotime($row['end_time'])) : ''),
                    'location' => $row['location'] ?: 'Parish Church',
                    'category' => $row['category'] ?: 'Parish Event',
                    'description' => $row['description'] ?: null
                ];
            }
            $stmt->close();
        }

        return [
            'upcoming_events_count' => count($events),
            'events' => $events,
            'regular_mass_schedule' => [
                'Sunday' => '6:30 AM',
                'Wednesday (Novena)' => '5:30 PM',
                'Friday' => '5:30 PM'
            ]
        ];
    }

    /**
     * 4. Tool: get_certificate_types
     */
    public function getCertificateTypes(): array
    {
        return [
            'certificates' => [
                ['name' => 'Baptismal Certificate', 'fee' => '₱100.00', 'processing_time' => '1 to 3 working days', 'release_options' => 'Online PDF download or Parish Office pickup'],
                ['name' => 'Confirmation Certificate', 'fee' => '₱100.00', 'processing_time' => '1 to 3 working days', 'release_options' => 'Online PDF download or Parish Office pickup'],
                ['name' => 'First Communion Certificate', 'fee' => '₱100.00', 'processing_time' => '1 to 3 working days', 'release_options' => 'Online PDF download or Parish Office pickup'],
                ['name' => 'Marriage Certificate', 'fee' => '₱100.00', 'processing_time' => '1 to 3 working days', 'release_options' => 'Online PDF download or Parish Office pickup'],
                ['name' => 'Death / Funeral Certificate', 'fee' => '₱100.00', 'processing_time' => '1 to 3 working days', 'release_options' => 'Online PDF download or Parish Office pickup']
            ],
            'payment_options' => [
                'GCash' => 'Agnes Calapaan (0997 742 8176) - upload screenshot in request form',
                'Cash' => 'Direct payment upon pickup at the parish office'
            ]
        ];
    }

    /**
     * 5. Tool: get_blessing_types
     */
    public function getBlessingTypes(): array
    {
        return [
            'blessings' => [
                'House Blessing' => 'For newly constructed, newly bought, or renovated homes.',
                'Business Blessing' => 'For business establishments, stores, and offices.',
                'Vehicle Blessing' => 'For motorcycles, cars, vans, and tricycles (held at parish grounds or designated venue).'
            ],
            'fee_policy' => 'No mandatory fixed fee; voluntary love offering for the officiating priest is welcome.',
            'lead_time' => 'File at least 3 to 7 days in advance under Requests -> Request Blessing.'
        ];
    }

    /**
     * 6. Tool: get_parish_contact_info
     */
    public function getParishContactInfo(): array
    {
        return [
            'parish_name' => 'San Lorenzo Ruiz Parish',
            'diocese' => 'Archdiocese of Cotabato',
            'location' => 'San Mateo, Aleosan, Cotabato, Philippines',
            'phone_hotline' => '0997 742 8176',
            'email' => 'sanlorenzoruiz.midsayap@gmail.com',
            'parish_secretary' => 'Agnes Calapaan',
            'parish_priest' => 'REV. FR. ALBERTO G. CAHILIG, OMI',
            'assistant_priest' => 'REV. FR. ALVIN VICENTE C. BARRETTO, OMI',
            'office_hours' => [
                'Tuesday to Saturday' => '8:00 AM – 5:00 PM (Lunch break: 12:00 PM – 1:00 PM)',
                'Sunday' => '7:00 AM – 12:00 PM (Half-day)',
                'Monday' => 'CLOSED (Staff rest day)'
            ],
            'portal_url' => 'https://tugon-parish-system.vercel.app'
        ];
    }
}
