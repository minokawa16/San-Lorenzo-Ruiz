<?php
/**
 * Schedule Conflict Service
 * Detects and prevents double-booking of parish schedule requests.
 * Evaluates date, time, and location conflicts against approved/reserved/confirmed/pending schedules.
 * Enforces fixed 60-minute slots [start, start + 60m) and hourly :00 selection.
 */

if (!defined('SLOT_DURATION_MINUTES')) {
    define('SLOT_DURATION_MINUTES', 60);
}
if (!defined('CONFLICT_SCOPE')) {
    define('CONFLICT_SCOPE', strtolower(trim((string) (getenv('CONFLICT_SCOPE') ?: 'calendar')))); // 'calendar' | 'location'
}
if (!defined('ALLOW_NON_HOURLY_SLOTS')) {
    define('ALLOW_NON_HOURLY_SLOTS', false);
}

class ScheduleConflictService {
    /**
     * Duration in minutes of each schedule slot.
     */
    public const SLOT_DURATION_MINUTES = 60;

    /**
     * Default conflict window buffer in minutes (legacy constant maintained for compatibility).
     */
    public const BUFFER_MINUTES_DEFAULT = 30;

    /**
     * Default conflict evaluation scope: 'calendar' (parish-wide) or 'location' (same venue only).
     */
    public const CONFLICT_SCOPE_DEFAULT = 'calendar';

    /**
     * Standard parish operating hours for schedule slot suggestions.
     */
    public const STANDARD_SLOTS = [
        '08:00', '09:00', '10:00', '11:00',
        '12:00', '13:00', '14:00', '15:00',
        '16:00', '17:00'
    ];

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

        if ($norm1 === '' && $norm2 === '') {
            return true;
        }
        if ($norm1 === '' || $norm2 === '') {
            return ($norm1 === 'main church' || $norm2 === 'main church');
        }

        if ($norm1 === $norm2) {
            return true;
        }

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
     * Format a readable time range (e.g. "9:00–10:00 AM", "11:00 AM–12:00 PM")
     */
    public static function formatTimeRange(string $start, string $end): string {
        $sTs = strtotime('2000-01-01 ' . self::normalizeTime($start) . ':00');
        $eTs = strtotime('2000-01-01 ' . self::normalizeTime($end) . ':00');
        if ($sTs === false || $eTs === false) {
            return self::normalizeTime($start) . '–' . self::normalizeTime($end);
        }
        $sAmPm = date('A', $sTs);
        $eAmPm = date('A', $eTs);
        if ($sAmPm === $eAmPm) {
            return date('g:i', $sTs) . '–' . date('g:i A', $eTs);
        }
        return date('g:i A', $sTs) . '–' . date('g:i A', $eTs);
    }

    /**
     * Check if a given date and optional time is in the past according to Asia/Manila.
     */
    public static function isPastDateTime(string $date, ?string $time = null): bool {
        $normDate = self::normalizeDate($date);
        if ($normDate === '') {
            return false;
        }
        $tz = new DateTimeZone('Asia/Manila');
        $now = new DateTime('now', $tz);
        $today = $now->format('Y-m-d');
        if ($normDate < $today) {
            return true;
        }
        if ($normDate === $today && $time !== null && trim($time) !== '') {
            $normTime = self::normalizeTime($time);
            $currentTime = $now->format('H:i');
            if ($normTime <= $currentTime) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check whether target time conflicts with an existing schedule.
     * Core rule: every booking occupies a 1-hour slot [start, start + 60 minutes).
     * Two bookings conflict if: newStart < existingEnd AND newEnd > existingStart.
     *
     * @param string $targetTime Target requested start time
     * @param string $existingStartTime Existing schedule start time
     * @param string|null $existingEndTime Existing schedule end time (if tracked)
     * @param bool $exactMatch If true and bufferMinutes === 0, checks exact match
     * @param int|null $bufferMinutes Conflict buffer in minutes (if null, uses 60-minute slot interval)
     * @param string|null $targetEndTime Optional target requested end time
     * @param int $slotDurationMinutes Slot duration in minutes (default: 60)
     * @return bool
     */
    public static function timesConflict(
        string $targetTime,
        string $existingStartTime,
        ?string $existingEndTime = null,
        bool $exactMatch = false,
        ?int $bufferMinutes = null,
        ?string $targetEndTime = null,
        int $slotDurationMinutes = self::SLOT_DURATION_MINUTES
    ): bool {
        $tNorm = self::normalizeTime($targetTime);
        $eStartNorm = self::normalizeTime($existingStartTime);

        if ($tNorm === '' || $eStartNorm === '') {
            return false;
        }

        // Exact match check when bufferMinutes is explicitly 0 and exactMatch is requested
        if ($bufferMinutes === 0 && $exactMatch && ($targetEndTime === null || $targetEndTime === '')) {
            return $tNorm === $eStartNorm;
        }

        $tTs = strtotime('2000-01-01 ' . $tNorm . ':00');
        $eStartTs = strtotime('2000-01-01 ' . $eStartNorm . ':00');

        // Backward compatibility: If explicit positive bufferMinutes is passed
        if ($bufferMinutes !== null && $bufferMinutes > 0) {
            $bufSec = $bufferMinutes * 60;
            $windowStart = $eStartTs - $bufSec;
            if ($existingEndTime !== null && trim((string) $existingEndTime) !== '') {
                $eEndNorm = self::normalizeTime($existingEndTime);
                $eEndTs = strtotime('2000-01-01 ' . $eEndNorm . ':00');
                $windowEnd = ($eEndTs > $eStartTs) ? ($eEndTs + $bufSec) : ($eStartTs + $bufSec);
            } else {
                $windowEnd = $eStartTs + $bufSec;
            }

            if ($targetEndTime !== null && trim((string) $targetEndTime) !== '') {
                $tEndNorm = self::normalizeTime($targetEndTime);
                $tEndTs = strtotime('2000-01-01 ' . $tEndNorm . ':00');
                if ($tEndTs > $tTs) {
                    return ($tTs < $windowEnd && $tEndTs > $windowStart);
                }
            }

            return ($tTs >= $windowStart && $tTs <= $windowEnd);
        }

        if ($bufferMinutes === 0 && !empty($existingEndTime)) {
            $eEndNorm = self::normalizeTime($existingEndTime);
            $eEndTs = strtotime('2000-01-01 ' . $eEndNorm . ':00');
            return ($tTs >= $eStartTs && $tTs < $eEndTs);
        }

        // Core 60-minute slot interval collision:
        // Each booking occupies [start, start + SLOT_DURATION_MINUTES).
        // Conflict occurs if: newStart < existingEnd AND newEnd > existingStart
        $slotSec = max(1, $slotDurationMinutes) * 60;
        $newStart = $tTs;
        if ($targetEndTime !== null && trim((string) $targetEndTime) !== '') {
            $tEndNorm = self::normalizeTime($targetEndTime);
            $tEndTs = strtotime('2000-01-01 ' . $tEndNorm . ':00');
            $newEnd = ($tEndTs > $newStart) ? $tEndTs : ($newStart + $slotSec);
        } else {
            $newEnd = $newStart + $slotSec;
        }

        $existingStart = $eStartTs;
        if ($existingEndTime !== null && trim((string) $existingEndTime) !== '') {
            $eEndNorm = self::normalizeTime($existingEndTime);
            $eEndTs = strtotime('2000-01-01 ' . $eEndNorm . ':00');
            $existingEnd = ($eEndTs > $existingStart) ? $eEndTs : ($existingStart + $slotSec);
        } else {
            $existingEnd = $existingStart + $slotSec;
        }

        return ($newStart < $existingEnd && $newEnd > $existingStart);
    }

    /**
     * Check for schedule conflicts on a given date, time, and location
     *
     * @param string $date YYYY-MM-DD
     * @param string $time HH:MM (24-hour)
     * @param string $location Location/venue string
     * @param array $options [
     *   'exact_match' => bool,
     *   'buffer_minutes' => int|null,
     *   'exclude_request_id' => int,
     *   'exclude_schedule_id' => int,
     *   'exclude_reservation_id' => int,
     *   'scope' => 'calendar' | 'location'
     * ]
     * @return array
     */
    public function checkConflict(string $date, string $time, string $location = 'Main Church', array $options = []): array {
        $normDate = self::normalizeDate($date);
        $normTime = self::normalizeTime($time);
        $normLoc = self::normalizeLocation($location);

        if ($normDate === '' || $normTime === '') {
            return [
                'available' => false,
                'has_conflict' => true,
                'conflict' => true,
                'message' => 'Please provide a valid date and time for schedule evaluation.',
                'conflicts' => [],
                'suggestions' => []
            ];
        }

        // 1. Time snapping check: reject non-:00 values unless allowed by config
        $allowNonHourly = defined('ALLOW_NON_HOURLY_SLOTS') ? (bool) ALLOW_NON_HOURLY_SLOTS : false;
        if (!$allowNonHourly) {
            $parts = explode(':', $normTime);
            if (!isset($parts[1]) || $parts[1] !== '00') {
                $suggestions = $this->getAvailableSuggestions($normDate, $location, $options);
                return [
                    'available' => false,
                    'has_conflict' => true,
                    'conflict' => true,
                    'message' => 'Schedule time must be on the hour (e.g. 09:00, 10:00). Half-hour or custom minute slots are not permitted.',
                    'conflicts' => [],
                    'suggestions' => $suggestions,
                    'date' => $normDate,
                    'time' => $normTime,
                    'location' => $location
                ];
            }
        }

        // 2. Block past dates and times using Asia/Manila (PST)
        if (self::isPastDateTime($normDate, $normTime)) {
            $suggestions = $this->getAvailableSuggestions($normDate, $location, $options);
            return [
                'available' => false,
                'has_conflict' => true,
                'conflict' => true,
                'is_past' => true,
                'message' => 'Cannot book a past date or time. Please select an upcoming date and time.',
                'conflicts' => [],
                'suggestions' => $suggestions,
                'date' => $normDate,
                'time' => $normTime,
                'location' => $location
            ];
        }

        if (!$this->db || !($this->db instanceof mysqli)) {
            return [
                'available' => true,
                'has_conflict' => false,
                'conflict' => false,
                'message' => 'Database connection not available; unable to evaluate schedule conflict.',
                'conflicts' => [],
                'suggestions' => []
            ];
        }

        $scope = strtolower(trim((string) ($options['scope'] ?? (defined('CONFLICT_SCOPE') ? CONFLICT_SCOPE : self::CONFLICT_SCOPE_DEFAULT))));
        $exactMatch = isset($options['exact_match']) ? (bool) $options['exact_match'] : false;
        $bufferMinutes = array_key_exists('buffer_minutes', $options) && $options['buffer_minutes'] !== null
            ? (int) $options['buffer_minutes']
            : null;
        $excludeRequestId = (int) ($options['exclude_request_id'] ?? ($options['excludeId'] ?? 0));
        $excludeScheduleId = (int) ($options['exclude_schedule_id'] ?? 0);
        $excludeReservationId = (int) ($options['exclude_reservation_id'] ?? 0);

        $conflicts = [];

        // 1. Check schedule_events table (Calendar source of truth)
        $stmt = $this->db->prepare("
            SELECT schedule_id, title, event_date, start_time, end_time, location, category, approval_status, status, source_type, source_id
            FROM schedule_events
            WHERE event_date = ?
              AND status != 'cancelled'
              AND approval_status != 'rejected'
            ORDER BY start_time ASC
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

                $evLoc = $row['location'] ?? 'Main Church';
                if ($scope === 'location' && !self::locationsMatch($location, $evLoc)) {
                    continue;
                }

                $evStart = self::normalizeTime((string) $row['start_time']);
                $rawEnd = !empty($row['end_time']) ? self::normalizeTime((string) $row['end_time']) : null;
                $evEnd = ($rawEnd && $rawEnd > $evStart) ? $rawEnd : date('H:i', strtotime("2000-01-01 $evStart:00 +" . self::SLOT_DURATION_MINUTES . " minutes"));

                if (self::timesConflict($normTime, $evStart, $rawEnd, $exactMatch, $bufferMinutes)) {
                    $conflicts[] = [
                        'title' => $row['title'] ?? 'Parish Schedule',
                        'type' => ucfirst(str_replace('_', ' ', (string) ($row['category'] ?? 'Event'))),
                        'start_time' => $evStart,
                        'end_time' => $evEnd,
                        'time_range' => self::formatTimeRange($evStart, $evEnd),
                        'location' => $evLoc,
                        'source' => 'schedule_events',
                        'schedule_id' => (int) $row['schedule_id'],
                        'status' => $row['status']
                    ];
                }
            }
            $stmt->close();
        }

        // 2. Check reservations table (Pending/Approved/Confirmed)
        $resStmt = $this->db->prepare("
            SELECT r.reservation_id, r.request_id, r.reservation_type, r.event_date, r.event_time, r.start_at, r.end_at, r.status,
                   GROUP_CONCAT(DISTINCT COALESCE(x.location, x.name) SEPARATOR ', ') AS resource_locations
            FROM reservations r
            LEFT JOIN reservation_resources rr ON rr.reservation_id = r.reservation_id
            LEFT JOIN resources x ON x.resource_id = rr.resource_id
            WHERE r.event_date = ?
              AND r.status NOT IN ('cancelled', 'rejected')
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
                if ($excludeReservationId > 0 && (int) $row['reservation_id'] === $excludeReservationId) {
                    continue;
                }
                if ($excludeRequestId > 0 && (int) ($row['request_id'] ?? 0) === $excludeRequestId) {
                    continue;
                }

                $resLoc = $row['resource_locations'] ?: 'Main Church';
                if ($scope === 'location' && !self::locationsMatch($location, $resLoc)) {
                    continue;
                }

                $rawTime = $row['event_time'] ?: ($row['start_at'] ? date('H:i', strtotime($row['start_at'])) : '08:00');
                $resStart = self::normalizeTime($rawTime);
                $rawEnd = !empty($row['end_at']) ? self::normalizeTime(date('H:i', strtotime($row['end_at']))) : null;
                $resEnd = ($rawEnd && $rawEnd > $resStart) ? $rawEnd : date('H:i', strtotime("2000-01-01 $resStart:00 +" . self::SLOT_DURATION_MINUTES . " minutes"));

                if (self::timesConflict($normTime, $resStart, $rawEnd, $exactMatch, $bufferMinutes)) {
                    $conflicts[] = [
                        'title' => ucfirst(str_replace('_', ' ', (string) $row['reservation_type'])) . ' Reservation',
                        'type' => ucfirst(str_replace('_', ' ', (string) $row['reservation_type'])),
                        'start_time' => $resStart,
                        'end_time' => $resEnd,
                        'time_range' => self::formatTimeRange($resStart, $resEnd),
                        'location' => $resLoc,
                        'source' => 'reservations',
                        'reservation_id' => (int) $row['reservation_id'],
                        'status' => $row['status']
                    ];
                }
            }
            $resStmt->close();
        }

        // 3. Check requests table (Pending, Approved, Confirmed, Processing schedule requests)
        // Note: Do not block on requests that are Rejected, Cancelled, or Completed in the past.
        $reqStmt = $this->db->prepare("
            SELECT r.request_id, r.request_type, r.reference_number, r.status, r.description
            FROM requests r
            WHERE r.status IN ('pending', 'approved', 'confirmed', 'processing', 'completed')
              AND r.deleted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM schedule_events se
                  WHERE se.source_type = 'request' AND se.source_id = r.request_id AND se.status != 'cancelled'
              )
            ORDER BY r.request_id ASC
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

                // Completed requests in the past do not block
                if ($row['status'] === 'completed' && self::isPastDateTime($reqDate)) {
                    continue;
                }

                $reqLoc = function_exists('requestCalendarField')
                    ? requestCalendarField($desc, ['Location', 'Address', 'Venue'])
                    : 'Main Church';

                if ($scope === 'location' && !self::locationsMatch($location, $reqLoc)) {
                    continue;
                }

                $rawTime = function_exists('requestCalendarField')
                    ? requestCalendarField($desc, ['Preferred time', 'Event time', 'Time'])
                    : '';
                $reqStart = self::normalizeTime($rawTime);
                if ($reqStart === '') {
                    continue;
                }
                $reqEnd = date('H:i', strtotime("2000-01-01 $reqStart:00 +" . self::SLOT_DURATION_MINUTES . " minutes"));

                if (self::timesConflict($normTime, $reqStart, null, $exactMatch, $bufferMinutes)) {
                    $conflicts[] = [
                        'title' => ucfirst(str_replace('_', ' ', (string) $row['request_type'])),
                        'type' => ucfirst(str_replace('_', ' ', (string) $row['request_type'])),
                        'start_time' => $reqStart,
                        'end_time' => $reqEnd,
                        'time_range' => self::formatTimeRange($reqStart, $reqEnd),
                        'location' => $reqLoc ?: 'Main Church',
                        'source' => 'requests',
                        'request_id' => (int) $row['request_id'],
                        'reference_number' => $row['reference_number'] ?? '',
                        'status' => $row['status']
                    ];
                }
            }
            $reqStmt->close();
        }

        // Compile suggestions
        $suggestions = $this->getAvailableSuggestions($normDate, $location, $options);

        if (!empty($conflicts)) {
            $first = $conflicts[0];
            return [
                'available' => false,
                'has_conflict' => true,
                'conflict' => true,
                'message' => 'This schedule is already occupied and not available. Please choose a different date or time.',
                'conflicts' => $conflicts,
                'conflicting_schedule' => $first,
                'suggestions' => $suggestions,
                'date' => $normDate,
                'time' => $normTime,
                'location' => $location,
                'scope' => $scope
            ];
        }

        return [
            'available' => true,
            'has_conflict' => false,
            'conflict' => false,
            'message' => 'This schedule slot is currently available!',
            'conflicts' => [],
            'suggestions' => $suggestions,
            'date' => $normDate,
            'time' => $normTime,
            'location' => $location,
            'scope' => $scope
        ];
    }

    /**
     * Generate available on-the-hour suggestions on a given date (and optionally location).
     * Enforces that suggestions are always :00 slots, never :30, and never past times.
     */
    public function getAvailableSuggestions(string $date, ?string $location = null, array $options = []): array {
        $normDate = self::normalizeDate($date);
        if ($normDate === '') {
            return [];
        }

        $occupied = $this->getOccupiedSlots($normDate, $location, $options);
        $suggestions = [];

        foreach (self::STANDARD_SLOTS as $candTime) {
            // Must not be in the past
            if (self::isPastDateTime($normDate, $candTime)) {
                continue;
            }

            $candTs = strtotime('2000-01-01 ' . $candTime . ':00');
            $candEndTs = $candTs + (self::SLOT_DURATION_MINUTES * 60);

            $hasOverlap = false;
            foreach ($occupied as $occ) {
                $occStartTs = strtotime('2000-01-01 ' . self::normalizeTime($occ['time']) . ':00');
                $occEndTs = !empty($occ['end_time'])
                    ? strtotime('2000-01-01 ' . self::normalizeTime($occ['end_time']) . ':00')
                    : ($occStartTs + (self::SLOT_DURATION_MINUTES * 60));

                if ($candTs < $occEndTs && $candEndTs > $occStartTs) {
                    $hasOverlap = true;
                    break;
                }
            }

            if (!$hasOverlap) {
                $suggestions[] = $candTime;
            }
        }

        return $suggestions;
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

        $scope = strtolower(trim((string) ($options['scope'] ?? (defined('CONFLICT_SCOPE') ? CONFLICT_SCOPE : self::CONFLICT_SCOPE_DEFAULT))));
        $excludeRequestId = (int) ($options['exclude_request_id'] ?? ($options['excludeId'] ?? 0));
        $excludeScheduleId = (int) ($options['exclude_schedule_id'] ?? 0);
        $excludeReservationId = (int) ($options['exclude_reservation_id'] ?? 0);

        // 1. From schedule_events
        $stmt = $this->db->prepare("
            SELECT schedule_id, title, event_date, start_time, end_time, location, category, status, source_type, source_id
            FROM schedule_events
            WHERE event_date = ?
              AND status != 'cancelled'
              AND approval_status != 'rejected'
            ORDER BY start_time ASC
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

                $evLoc = $row['location'] ?? 'Main Church';
                if ($scope === 'location' && $location !== null && $location !== '' && !self::locationsMatch($location, $evLoc)) {
                    continue;
                }

                $t = self::normalizeTime($row['start_time']);
                $rawEnd = !empty($row['end_time']) ? self::normalizeTime($row['end_time']) : null;
                $endT = ($rawEnd && $rawEnd > $t) ? $rawEnd : date('H:i', strtotime("2000-01-01 $t:00 +" . self::SLOT_DURATION_MINUTES . " minutes"));

                $slots[] = [
                    'time' => $t,
                    'time_display' => date('g:i A', strtotime($normDate . ' ' . $t)),
                    'end_time' => $endT,
                    'end_display' => date('g:i A', strtotime($normDate . ' ' . $endT)),
                    'time_range' => self::formatTimeRange($t, $endT),
                    'location' => $evLoc,
                    'type' => ucfirst(str_replace('_', ' ', (string) ($row['category'] ?? 'Event'))),
                    'title' => $row['title'] ?? 'Parish Schedule',
                    'source' => 'schedule_events',
                    'status' => $row['status']
                ];
            }
            $stmt->close();
        }

        // 2. From reservations
        $resStmt = $this->db->prepare("
            SELECT r.reservation_id, r.request_id, r.reservation_type, r.event_date, r.event_time, r.start_at, r.end_at, r.status,
                   GROUP_CONCAT(DISTINCT COALESCE(x.location, x.name) SEPARATOR ', ') AS resource_locations
            FROM reservations r
            LEFT JOIN reservation_resources rr ON rr.reservation_id = r.reservation_id
            LEFT JOIN resources x ON x.resource_id = rr.resource_id
            WHERE r.event_date = ?
              AND r.status NOT IN ('cancelled', 'rejected')
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
                if ($excludeReservationId > 0 && (int) $row['reservation_id'] === $excludeReservationId) {
                    continue;
                }
                if ($excludeRequestId > 0 && (int) ($row['request_id'] ?? 0) === $excludeRequestId) {
                    continue;
                }

                $resLoc = $row['resource_locations'] ?: 'Main Church';
                if ($scope === 'location' && $location !== null && $location !== '' && !self::locationsMatch($location, $resLoc)) {
                    continue;
                }

                $rawTime = $row['event_time'] ?: ($row['start_at'] ? date('H:i', strtotime($row['start_at'])) : '08:00');
                $t = self::normalizeTime($rawTime);
                $rawEnd = !empty($row['end_at']) ? self::normalizeTime(date('H:i', strtotime($row['end_at']))) : null;
                $endT = ($rawEnd && $rawEnd > $t) ? $rawEnd : date('H:i', strtotime("2000-01-01 $t:00 +" . self::SLOT_DURATION_MINUTES . " minutes"));

                $slots[] = [
                    'time' => $t,
                    'time_display' => date('g:i A', strtotime($normDate . ' ' . $t)),
                    'end_time' => $endT,
                    'end_display' => date('g:i A', strtotime($normDate . ' ' . $endT)),
                    'time_range' => self::formatTimeRange($t, $endT),
                    'location' => $resLoc,
                    'type' => ucfirst(str_replace('_', ' ', (string) $row['reservation_type'])) . ' Reservation',
                    'title' => ucfirst(str_replace('_', ' ', (string) $row['reservation_type'])) . ' Reservation',
                    'source' => 'reservations',
                    'status' => $row['status']
                ];
            }
            $resStmt->close();
        }

        // 3. From requests (pending, approved, confirmed, processing)
        $reqStmt = $this->db->prepare("
            SELECT r.request_id, r.request_type, r.reference_number, r.status, r.description
            FROM requests r
            WHERE r.status IN ('pending', 'approved', 'confirmed', 'processing', 'completed')
              AND r.deleted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM schedule_events se
                  WHERE se.source_type = 'request' AND se.source_id = r.request_id AND se.status != 'cancelled'
              )
            ORDER BY r.request_id ASC
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

                if ($row['status'] === 'completed' && self::isPastDateTime($reqDate)) {
                    continue;
                }

                $reqLoc = function_exists('requestCalendarField')
                    ? requestCalendarField($desc, ['Location', 'Address', 'Venue'])
                    : 'Main Church';

                if ($scope === 'location' && $location !== null && $location !== '' && !self::locationsMatch($location, $reqLoc)) {
                    continue;
                }

                $rawTime = function_exists('requestCalendarField')
                    ? requestCalendarField($desc, ['Preferred time', 'Event time', 'Time'])
                    : '';
                $t = self::normalizeTime($rawTime);
                if ($t === '') {
                    continue;
                }
                $endT = date('H:i', strtotime("2000-01-01 $t:00 +" . self::SLOT_DURATION_MINUTES . " minutes"));

                $slots[] = [
                    'time' => $t,
                    'time_display' => date('g:i A', strtotime($normDate . ' ' . $t)),
                    'end_time' => $endT,
                    'end_display' => date('g:i A', strtotime($normDate . ' ' . $endT)),
                    'time_range' => self::formatTimeRange($t, $endT),
                    'location' => $reqLoc ?: 'Main Church',
                    'type' => ucfirst(str_replace('_', ' ', (string) $row['request_type'])),
                    'title' => ucfirst(str_replace('_', ' ', (string) $row['request_type'])),
                    'source' => 'requests',
                    'reference_number' => $row['reference_number'] ?? '',
                    'status' => $row['status']
                ];
            }
            $reqStmt->close();
        }

        return $slots;
    }

    /**
     * Format polite conflict response matching exact user requirement:
     * Clear notice explaining that the requested date and time is already occupied.
     */
    protected function formatConflictResponse(string $date, string $time, string $location, array $details = []): array {
        $dateDisplay = date('M j, Y', strtotime($date));
        $timeDisplay = date('g:i A', strtotime($date . ' ' . $time));
        $locDisplay = trim($location) !== '' ? trim($location) : 'San Lorenzo Ruiz Parish Church';

        if (empty($details)) {
            $message = "This date and time ({$dateDisplay} at {$timeDisplay}) at {$locDisplay} is already occupied. Please choose another available schedule.";
        } else {
            $message = "This schedule is already occupied and not available. Please choose a different date or time.";
        }

        return [
            'available' => false,
            'has_conflict' => true,
            'conflict' => true,
            'message' => $message,
            'conflicting_schedule' => [
                'type' => $details['type'] ?? 'Schedule',
                'title' => $details['title'] ?? 'Parish Schedule',
                'date' => $date,
                'time' => $details['event_time'] ?? $time,
                'time_display' => !empty($details['event_time']) ? date('g:i A', strtotime($date . ' ' . $details['event_time'])) : $timeDisplay,
                'time_range' => !empty($details['time_range']) ? $details['time_range'] : $timeDisplay,
                'location' => $locDisplay,
                'reference_number' => $details['reference_number'] ?? ''
            ]
        ];
    }
}
