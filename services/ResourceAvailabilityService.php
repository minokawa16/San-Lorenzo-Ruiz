<?php

final class ResourceAvailabilityService {
    public const TIMEZONE = 'Asia/Manila';

    public function __construct(private mysqli $db) {}

    public function parseLocalDateTime(string $value): DateTimeImmutable {
        $tz = new DateTimeZone(self::TIMEZONE);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', trim($value), $tz);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d H:i:s') !== trim($value)) {
            throw new InvalidArgumentException('The reservation date and time are invalid.');
        }
        return $date;
    }

    public function normalizeResourceIds(array $resourceIds): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $resourceIds), fn($id) => $id > 0)));
        sort($ids, SORT_NUMERIC);
        if (!$ids) throw new InvalidArgumentException('Choose at least one parish resource.');
        if (count($ids) > 20) throw new InvalidArgumentException('Too many resources were selected.');
        return $ids;
    }

    public function lockAvailableResources(array $resourceIds): array {
        $ids = $this->normalizeResourceIds($resourceIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $stmt = $this->db->prepare("SELECT resource_id,name,status FROM resources WHERE resource_id IN ($placeholders) AND deleted_at IS NULL ORDER BY resource_id FOR UPDATE");
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (count($rows) !== count($ids)) throw new DomainException('One or more selected resources no longer exist.');
        foreach ($rows as $row) {
            if ($row['status'] !== 'available') throw new DomainException($row['name'] . ' is currently unavailable.');
        }
        return $rows;
    }

    public function assertAvailable(array $resourceIds, string $startAt, string $endAt, int $setupMinutes = 0, int $cleanupMinutes = 0, ?int $excludeReservationId = null): void {
        $ids = $this->normalizeResourceIds($resourceIds);
        $start = $this->parseLocalDateTime($startAt);
        $end = $this->parseLocalDateTime($endAt);
        if ($end <= $start) throw new InvalidArgumentException('The end time must be after the start time.');
        if ($setupMinutes < 0 || $setupMinutes > 1440 || $cleanupMinutes < 0 || $cleanupMinutes > 1440) throw new InvalidArgumentException('Setup or cleanup duration is invalid.');
        $occupiedStart = $start->modify('-' . $setupMinutes . ' minutes')->format('Y-m-d H:i:s');
        $occupiedEnd = $end->modify('+' . $cleanupMinutes . ' minutes')->format('Y-m-d H:i:s');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT r.reservation_id,x.name FROM reservation_resources rr JOIN reservations r ON r.reservation_id=rr.reservation_id JOIN resources x ON x.resource_id=rr.resource_id WHERE rr.resource_id IN ($placeholders) AND r.status NOT IN ('cancelled','rejected') AND DATE_SUB(r.start_at,INTERVAL r.setup_duration_minutes MINUTE) < ? AND DATE_ADD(r.end_at,INTERVAL r.cleanup_duration_minutes MINUTE) > ?";
        $params = $ids;
        $types = str_repeat('i', count($ids)) . 'ss';
        $params[] = $occupiedEnd; $params[] = $occupiedStart;
        if ($excludeReservationId !== null) { $sql .= ' AND r.reservation_id<>?'; $types .= 'i'; $params[] = $excludeReservationId; }
        $sql .= ' LIMIT 1 FOR UPDATE';
        $stmt = $this->db->prepare($sql); $stmt->bind_param($types, ...$params); $stmt->execute();
        $conflict = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($conflict) throw new DomainException($conflict['name'] . ' is already occupied during this schedule, including setup, cleanup, and transition buffer time. Please select an available time slot.');

        $stmt = $this->db->prepare("SELECT u.*,x.name FROM resource_unavailability u JOIN resources x ON x.resource_id=u.resource_id WHERE u.resource_id IN ($placeholders) AND (u.recurrence_rule IS NOT NULL OR (u.start_at < ? AND u.end_at > ?))");
        $params = $ids; $types = str_repeat('i', count($ids)) . 'ss'; $params[] = $occupiedEnd; $params[] = $occupiedStart;
        $stmt->bind_param($types, ...$params); $stmt->execute(); $blackouts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        foreach ($blackouts as $blackout) {
            if ($this->blackoutOverlaps($blackout, $occupiedStart, $occupiedEnd)) {
                throw new DomainException($blackout['name'] . ' is unavailable: ' . $blackout['reason']);
            }
        }
    }

    public function suggestAvailableSlots(array $resourceIds,string $from,int $durationMinutes,int $setupMinutes=0,int $cleanupMinutes=0,int $limit=3,int $stepMinutes=60):array {
        $cursor=$this->parseLocalDateTime($from);$limit=max(1,min(20,$limit));$stepMinutes=max(15,min(1440,$stepMinutes));$durationMinutes=max(15,min(1440,$durationMinutes));$slots=[];
        // Search a bounded 30-day horizon and return only slots validated by the
        // same authoritative conflict/blackout engine used for booking.
        for($i=0;$i<(int)(30*1440/$stepMinutes)&&count($slots)<$limit;$i++,$cursor=$cursor->modify('+'.$stepMinutes.' minutes')){
            if((int)$cursor->format('H')<7||(int)$cursor->format('H')>=20)continue;
            $end=$cursor->modify('+'.$durationMinutes.' minutes');if($end->format('Y-m-d')!==$cursor->format('Y-m-d')||(int)$end->format('H')>21)continue;
            try{$this->assertAvailable($resourceIds,$cursor->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'),$setupMinutes,$cleanupMinutes);$slots[]=['start_at'=>$cursor->format('Y-m-d H:i:s'),'end_at'=>$end->format('Y-m-d H:i:s')];}catch(DomainException $e){}
        }
        return$slots;
    }

    private function blackoutOverlaps(array $row, string $occupiedStart, string $occupiedEnd): bool {
        $start = $this->parseLocalDateTime($occupiedStart); $end = $this->parseLocalDateTime($occupiedEnd);
        $rule = strtolower(trim((string)($row['recurrence_rule'] ?? '')));
        if ($rule === '' || $rule === 'none') return $row['start_at'] < $occupiedEnd && $row['end_at'] > $occupiedStart;
        $from = $this->parseLocalDateTime($row['start_at']); $to = $this->parseLocalDateTime($row['end_at']);
        if (str_starts_with($rule, 'weekly:')) {
            $weekday = (int)substr($rule, 7);
            for ($day = $start->setTime(0, 0); $day < $end; $day = $day->modify('+1 day')) {
                if ((int)$day->format('w') !== $weekday) continue;
                $occStart = $day->setTime((int)$from->format('H'), (int)$from->format('i'), (int)$from->format('s'));
                $occEnd = $day->setTime((int)$to->format('H'), (int)$to->format('i'), (int)$to->format('s'));
                if ($occEnd <= $occStart) $occEnd = $occEnd->modify('+1 day');
                if ($occStart < $end && $occEnd > $start) return true;
            }
            return false;
        }
        if (str_starts_with($rule, 'annual:')) {
            $monthDay = substr($rule, 7);
            foreach (range((int)$start->format('Y'), (int)$end->format('Y')) as $year) {
                $occStart = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', sprintf('%04d-%s %s', $year, $monthDay, $from->format('H:i:s')), new DateTimeZone(self::TIMEZONE));
                if (!$occStart) continue;
                $occEnd = $occStart->modify('+' . max(1, $to->getTimestamp() - $from->getTimestamp()) . ' seconds');
                if ($occStart < $end && $occEnd > $start) return true;
            }
        }
        return false;
    }
}
