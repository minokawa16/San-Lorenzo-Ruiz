<?php
/**
 * Schedule Conflict Service
 * Detects and prevents double-booking of parish schedule requests.
 * Evaluates date, time, and location conflicts against approved/reserved/confirmed schedules.
 */

class ScheduleConflictService {
    /**
     * Default time conflict buffer window in minutes.
     * 0 = exact-match only (default as specified).
     * Set to 60 for a 1-hour window or pass in options.
     */
    public const BUFFER_MINUTES_DEFAULT = 0;

    protected $db;

    public function __construct($database_connection = null) {
        $this->db = $database_connection;
    }

    /**
     * Normalize location string for reliable comparison
     */
    public static function normalizeLocation(?string $location): string {
        $loc = trim(strtolower((string) $location));
        if ($loc === '') {
            return '';
        }
        // Normalize punctuation and whitespace
        $loc = preg_replace('/[^\w\s]/u', ' ', $loc);
        $loc = preg_replace('/\s+/', ' ', $loc);
        $loc = trim($loc);

        // Common parish church synonyms / aliases in San Lorenzo Ruiz Parish
        $parishAliases = [
            'church',
            'main church',
            'parish',
            'parish church',
            'san lorenzo',
            'san lorenzo ruiz',
            'san lorenzo ruiz parish',
            'san lorenzo ruiz parish church',
            'san lorenzo church',
            'parish grounds',
            'altar'
        ];

        if (in_array($loc, $parishAliases, true)) {
            return 'main church';
        }

        return $loc;
    }

    /**
     * Check if two location strings refer to the same location
     */
    public static function locationsMatch(?string $loc1, ?string $loc2): bool {
        $norm1 = self::normalizeLocation($loc1);
        $norm2 = self::normalizeLocation($loc2);

        // If both are empty or unspecified, in parish context church schedules default to main church
        if ($norm1 === '' && $norm2 === '') {
            return true;
        }
        if ($norm1 === '' || $norm2 === '') {
            return ($norm1 === 'main church' || $norm2 === 'main church');
        }

        if ($norm1 === $norm2) {
            return true;
        }

        // Substring check for chapel or address variations (e.g. "Chapel 1" inside "Chapel 1, Brgy...")
        if (strlen($norm1) >= 4 && strlen($norm2) >= 4) {
            if (strpos($norm1, $norm2) !== false || strpos($norm2, $norm1) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize time to HH:MM format (24-hour)
     */
    public static function normalizeTime(?string $time): string {
        $time = trim((string) $time);
        if ($time === '') {
            return '';
        }
        $ts = strtotime('2000-01-01 ' . $time);
        if ($ts !== false) {
            return date('H:i', $ts);
        }
        if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }
        return substr($time, 0, 5);
    }

    /**
     * Normalize date to YYYY-MM-DD
     */
    public static function normalizeDate(?string $date): string {
        $date = trim((string) $date);
        if ($date === '') {
            return '';
        }
        $ts = strtotime($date);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
        return $date;
    }

    /**
     * Check whether target time conflicts with an existing schedule
     */
    public static function timesConflict(string $targetTime, string $existingStartTime, ?string $existingEndTime = null, bool $exactMatch = true, int $bufferMinutes = 0): bool {
        $tNorm = self::normalizeTime($targetTime);
        $eStartNorm = self::normalizeTime($existingStartTime);

        if ($tNorm === '' || $eStartNorm === '') {
            return false;
        }

        if ($exactMatch && $bufferMinutes <= 0) {
            return $tNorm === $eStartNorm;
        }

        // Overlapping window calculation
        $tTs = strtotime('2000-01-01 ' . $tNorm . ':00');
        $eStartTs = strtotime('2000-01-01 ' . $eStartNorm . ':00');

        $eEndNorm = $existingEndTime ? self::normalizeTime($existingEndTime) : date('H:i', strtotime('+1 hour', $eStartTs));
        $eEndTs = strtotime('2000-01-01 ' . $eEndNorm . ':00');
        if ($eEndTs <= $eStartTs) {
            $eEndTs = $eStartTs + 3600;
        }

        $targetEndTs = $tTs + 3600;
        $bufSec = max(0, $bufferMinutes) * 60;

        $effStart = $eStartTs - $bufSec;
        $effEnd = $eEndTs + $bufSec;

        return ($tTs < $effEnd && $targetEndTs > $effStart);
    }

    /**
     * Check for schedule conflicts on a given date, time, and location
     *
     * @param string $date YYYY-MM-DD
     * @param string $time HH:MM or similar time string
     * @param string $location Location/venue string
     * @param array $options [
     *   'exact_match' => bool (default true),
     *   'buffer_minutes' => int (default 0),
     *   'exclude_request_id' => int,
     *   'exclude_schedule_id' => int,
     *   'exclude_reservation_id' => int
     * ]
     * @return array
     */
    public function checkConflict(string $date, string $time, string $location, array $options = []): array {
        $normDate = self::normalizeDate($date);
        $normTime = self::normalizeTime($time);
        $normLoc = self::normalizeLocation($location);

        if ($normDate === '' || $normTime === '' || $normLoc === '') {
            return [
                'has_conflict' => false,
                'conflict' => false,
                'message' => 'Missing date, time, or location for conflict evaluation.'
            ];
        }

        $exactMatch = $options['exact_match'] ?? true;
        $bufferMinutes = isset($options['buffer_minutes']) ? (int) $options['buffer_minutes'] : self::BUFFER_MINUTES_DEFAULT;
        $excludeRequestId = (int) ($options['exclude_request_id'] ?? 0);
        $excludeScheduleId = (int) ($options['exclude_schedule_id'] ?? 0);
        $excludeReservationId = (int) ($options['exclude_reservation_id'] ?? 0);

        if (!$this->db || !($this->db instanceof mysqli)) {
            return [
                'has_conflict' => false,
                'conflict' => false,
                'message' => 'Database connection not available.'
            ];
        }

        // 1. Check schedule_events table (Parish Calendar source of truth)
        $stmt = $this->db->prepare("
            SELECT schedule_id, title, event_date, start_time, end_time, location, category, approval_status, status, source_type, source_id
            FROM schedule_events
            WHERE event_date = ?
              AND status != 'cancelled'
              AND (approval_status = 'approved' OR status IN ('active', 'upcoming', 'ongoing', 'confirmed', 'approved'))
        ");
        if ($stmt) {
            $stmt->bind_param('s', $normDate);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                if ($excludeScheduleId > 0 && (int) $row['schedule_id'] === $excludeScheduleId) {
                    continue;
                }
                if ($excludeRequestId > 0 && $row['source_type'] === 'request' && (int) $row['source_id'] === $excludeRequestId) {
                    continue;
                }
                if ($excludeReservationId > 0 && $row['source_type'] === 'reservation' && (int) $row['source_id'] === $excludeReservationId) {
                    continue;
                }

                $evLoc = $row['location'] ?? '';
                if (self::locationsMatch($location, $evLoc)) {
                    $evStart = (string) $row['start_time'];
                    $evEnd = !empty($row['end_time']) ? (string) $row['end_time'] : null;
                    if (self::timesConflict($normTime, $evStart, $evEnd, $exactMatch, $bufferMinutes)) {
                        $stmt->close();
                        return $this->formatConflictResponse($normDate, $normTime, $evLoc ?: $location, [
                            'source' => 'schedule_events',
                            'title' => $row['title'] ?? 'Parish Schedule',
                            'type' => ucfirst(str_replace('_', ' ', (string) ($row['category'] ?? 'Event'))),
                            'schedule_id' => (int) $row['schedule_id']
                        ]);
                    }
                }
            }
            $stmt->close();
        }

        // 2. Check reservations table (Reserved/Approved/Confirmed)
        $resStmt = $this->db->prepare("
            SELECT r.reservation_id, r.request_id, r.reservation_type, r.event_date, r.event_time, r.start_at, r.end_at, r.status,
                   GROUP_CONCAT(DISTINCT COALESCE(x.location, x.name) SEPARATOR ', ') AS resource_locations
            FROM reservations r
            LEFT JOIN reservation_resources rr ON rr.reservation_id = r.reservation_id
            LEFT JOIN resources x ON x.resource_id = rr.resource_id
            WHERE r.event_date = ?
              AND r.status IN ('approved', 'confirmed')
              AND NOT EXISTS (
                  SELECT 1 FROM schedule_events se
                  WHERE se.source_type = 'reservation' AND se.source_id = r.reservation_id AND se.status != 'cancelled'
              )
            GROUP BY r.reservation_id
        ");
        if ($resStmt) {
            $resStmt->bind_param('s', $normDate);
            $resStmt->execute();
            $resRes = $resStmt->get_result();
            while ($row = $resRes->fetch_assoc()) {
                if ($excludeReservationId > 0 && (int) $row['reservation_id'] === $excludeReservationId) {
                    continue;
                }
                if ($excludeRequestId > 0 && (int) ($row['request_id'] ?? 0) === $excludeRequestId) {
                    continue;
                }

                $resLoc = $row['resource_locations'] ?: 'Main Church';
                if (self::locationsMatch($location, $resLoc)) {
                    $resTime = $row['event_time'] ?: ($row['start_at'] ? date('H:i', strtotime($row['start_at'])) : '08:00');
                    if (self::timesConflict($normTime, $resTime, null, $exactMatch, $bufferMinutes)) {
                        $resStmt->close();
                        return $this->formatConflictResponse($normDate, $normTime, $resLoc ?: $location, [
                            'source' => 'reservations',
                            'title' => ucfirst(str_replace('_', ' ', (string) $row['reservation_type'])) . ' Reservation',
                            'type' => ucfirst(str_replace('_', ' ', (string) $row['reservation_type'])),
                            'reservation_id' => (int) $row['reservation_id']
                        ]);
                    }
                }
            }
            $resStmt->close();
        }

        // 3. Check requests table (Approved / Confirmed / Processing / Completed schedule requests not yet in schedule_events)
        $reqStmt = $this->db->prepare("
            SELECT r.request_id, r.request_type, r.reference_number, r.status, r.description
            FROM requests r
            WHERE r.status IN ('approved', 'confirmed', 'completed', 'processing')
              AND r.deleted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM schedule_events se
                  WHERE se.source_type = 'request' AND se.source_id = r.request_id AND se.status != 'cancelled'
              )
        ");
        if ($reqStmt) {
            $reqStmt->execute();
            $reqRes = $reqStmt->get_result();
            while ($row = $reqRes->fetch_assoc()) {
                if ($excludeRequestId > 0 && (int) $row['request_id'] === $excludeRequestId) {
                    continue;
                }
                $desc = (string) ($row['description'] ?? '');
                $reqDate = function_exists('requestCalendarField')
                    ? requestCalendarField($desc, ['Preferred date', 'Date of Baptism', 'Date of Marriage', 'Wedding ceremony schedule', 'Date of Funeral', 'Date of Burial', 'Date of Patronal Fiesta', 'Service date', 'Event date', 'Date'])
                    : '';
                $reqDate = self::normalizeDate($reqDate);
                if ($reqDate !== $normDate) {
                    continue;
                }

                $reqLoc = function_exists('requestCalendarField')
                    ? requestCalendarField($desc, ['Location', 'Address', 'Venue'])
                    : 'Main Church';

                if (self::locationsMatch($location, $reqLoc)) {
                    $reqTime = function_exists('requestCalendarField')
                        ? requestCalendarField($desc, ['Preferred time', 'Event time', 'Time'])
                        : '';
                    $reqTime = self::normalizeTime($reqTime);
                    if (self::timesConflict($normTime, $reqTime, null, $exactMatch, $bufferMinutes)) {
                        $reqStmt->close();
                        return $this->formatConflictResponse($normDate, $normTime, $reqLoc ?: $location, [
                            'source' => 'requests',
                            'title' => ucfirst(str_replace('_', ' ', (string) $row['request_type'])),
                            'type' => ucfirst(str_replace('_', ' ', (string) $row['request_type'])),
                            'request_id' => (int) $row['request_id'],
                            'reference_number' => $row['reference_number'] ?? ''
                        ]);
                    }
                }
            }
            $reqStmt->close();
        }

        return [
            'has_conflict' => false,
            'conflict' => false,
            'message' => 'Schedule is available.'
        ];
    }

    /**
     * Format polite conflict response matching exact user requirement:
     * "This date and time (e.g., Sep 29, 2026 at 8:30 AM) at [Location] is already occupied. Please choose another available schedule."
     */
    protected function formatConflictResponse(string $date, string $time, string $location, array $details = []): array {
        $dateDisplay = date('M j, Y', strtotime($date));
        $timeDisplay = date('g:i A', strtotime($date . ' ' . $time));
        $locDisplay = trim($location) !== '' ? trim($location) : 'San Lorenzo Ruiz Parish Church';

        $message = "This date and time ({$dateDisplay} at {$timeDisplay}) at {$locDisplay} is already occupied. Please choose another available schedule.";

        return [
            'has_conflict' => true,
            'conflict' => true,
            'message' => $message,
            'conflicting_schedule' => [
                'type' => $details['type'] ?? 'Schedule',
                'date' => $date,
                'time' => $time,
                'location' => $locDisplay,
                'reference_number' => $details['reference_number'] ?? ''
            ]
        ];
    }

    /**
     * Fetch all occupied schedule slots on a given date (and optionally location)
     */
    public function getOccupiedSlots(string $date, ?string $location = null, array $options = []): array {
        $normDate = self::normalizeDate($date);
        if ($normDate === '') {
            return [];
        }

        $slots = [];

        if (!$this->db || !($this->db instanceof mysqli)) {
            return [];
        }

        // 1. From schedule_events
        $stmt = $this->db->prepare("
            SELECT schedule_id, title, event_date, start_time, end_time, location, category
            FROM schedule_events
            WHERE event_date = ?
              AND status != 'cancelled'
              AND (approval_status = 'approved' OR status IN ('active', 'upcoming', 'ongoing', 'confirmed', 'approved'))
            ORDER BY start_time ASC
        ");
        if ($stmt) {
            $stmt->bind_param('s', $normDate);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $evLoc = $row['location'] ?? 'Main Church';
                if ($location === null || $location === '' || self::locationsMatch($location, $evLoc)) {
                    $t = self::normalizeTime($row['start_time']);
                    $slots[] = [
                        'time' => $t,
                        'time_display' => date('g:i A', strtotime($normDate . ' ' . $t)),
                        'location' => $evLoc,
                        'type' => ucfirst(str_replace('_', ' ', (string) ($row['category'] ?? 'Event'))),
                        'title' => $row['title'] ?? 'Parish Schedule'
                    ];
                }
            }
            $stmt->close();
        }

        // 2. From reservations
        $resStmt = $this->db->prepare("
            SELECT r.reservation_id, r.reservation_type, r.event_date, r.event_time, r.start_at,
                   GROUP_CONCAT(DISTINCT COALESCE(x.location, x.name) SEPARATOR ', ') AS resource_locations
            FROM reservations r
            LEFT JOIN reservation_resources rr ON rr.reservation_id = r.reservation_id
            LEFT JOIN resources x ON x.resource_id = rr.resource_id
            WHERE r.event_date = ?
              AND r.status IN ('approved', 'confirmed')
              AND NOT EXISTS (
                  SELECT 1 FROM schedule_events se
                  WHERE se.source_type = 'reservation' AND se.source_id = r.reservation_id AND se.status != 'cancelled'
              )
            GROUP BY r.reservation_id
            ORDER BY r.event_time ASC
        ");
        if ($resStmt) {
            $resStmt->bind_param('s', $normDate);
            $resStmt->execute();
            $resRes = $resStmt->get_result();
            while ($row = $resRes->fetch_assoc()) {
                $resLoc = $row['resource_locations'] ?: 'Main Church';
                if ($location === null || $location === '' || self::locationsMatch($location, $resLoc)) {
                    $rawTime = $row['event_time'] ?: ($row['start_at'] ? date('H:i', strtotime($row['start_at'])) : '08:00');
                    $t = self::normalizeTime($rawTime);
                    $slots[] = [
                        'time' => $t,
                        'time_display' => date('g:i A', strtotime($normDate . ' ' . $t)),
                        'location' => $resLoc,
                        'type' => ucfirst(str_replace('_', ' ', (string) $row['reservation_type'])) . ' Reservation',
                        'title' => ucfirst(str_replace('_', ' ', (string) $row['reservation_type'])) . ' Reservation'
                    ];
                }
            }
            $resStmt->close();
        }

        return $slots;
    }
}
