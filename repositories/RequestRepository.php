<?php

/** Read-only persistence operations for parish service requests. */
class RequestRepository
{
    private $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public const CATEGORY_TYPES = [
        'blessings' => [
            'house_blessing', 'car_blessing', 'vehicle_blessing', 'business_blessing',
            'office_blessing', 'event_blessing', 'other_blessing', 'blessing', 'blessing_request'
        ],
        'certificates' => [
            'baptismal_certificate', 'baptism_certification',
            'confirmation_certificate', 'confirmation_certification',
            'first_communion_certificate', 'first_communion_certification',
            'marriage_certification', 'marriage_certificate',
            'funeral_certification', 'funeral_certificate', 'certificate'
        ],
        'sacramental_services' => [
            'baptism_service', 'confirmation_service', 'first_communion_service',
            'marriage_wedding_service', 'anointing_of_the_sick', 'funeral_mass',
            'patronal_fiesta', 'church_reservation', 'wedding_reservation',
            'burial_reservation', 'wedding', 'baptism', 'confirmation',
            'burial', 'church_venue', 'sacramental_service', 'reservation'
        ],
    ];

    public function findForUser(int $userId, string $search, string $status, $typeOrLimit = '', $limitOrOffset = 10, int $offset = 0): array
    {
        if (is_int($typeOrLimit)) {
            $type = '';
            $limit = $typeOrLimit;
            $offset = (int) $limitOrOffset;
        } else {
            $type = (string) $typeOrLimit;
            $limit = (int) $limitOrOffset;
        }

        $where = ['user_id = ?'];
        $types = 'i';
        $params = [$userId];

        if ($type !== '') {
            $normType = strtolower(trim($type));
            if (isset(self::CATEGORY_TYPES[$normType])) {
                $typeList = self::CATEGORY_TYPES[$normType];
                $placeholders = implode(',', array_fill(0, count($typeList), '?'));

                if ($normType === 'blessings') {
                    $where[] = "(request_type IN ($placeholders) OR request_type LIKE ?)";
                    foreach ($typeList as $t) {
                        $types .= 's';
                        $params[] = $t;
                    }
                    $types .= 's';
                    $params[] = '%blessing%';
                } elseif ($normType === 'certificates') {
                    $where[] = "(request_type IN ($placeholders) OR request_type LIKE ?)";
                    foreach ($typeList as $t) {
                        $types .= 's';
                        $params[] = $t;
                    }
                    $types .= 's';
                    $params[] = '%certificat%';
                } elseif ($normType === 'sacramental_services') {
                    $where[] = "(request_type IN ($placeholders) OR request_type LIKE ? OR request_type LIKE ? OR request_type LIKE ?)";
                    foreach ($typeList as $t) {
                        $types .= 's';
                        $params[] = $t;
                    }
                    $types .= 'sss';
                    $params[] = '%service%';
                    $params[] = '%reservation%';
                    $params[] = '%mass%';
                }
            }
        }

        if ($status !== '') {
            $normStatus = strtolower(trim($status));
            if (in_array($normStatus, ['pending', 'processing', 'completed', 'rejected'], true)) {
                $where[] = 'status = ?';
                $types .= 's';
                $params[] = $normStatus;
            }
        }
        if ($search !== '') {
            $where[] = '(reference_number LIKE ? OR request_type LIKE ? OR status LIKE ? OR description LIKE ?)';
            $types .= 'ssss';
            $searchLike = '%' . $search . '%';
            array_push($params, $searchLike, $searchLike, $searchLike, $searchLike);
        }

        $whereSql = implode(' AND ', $where);
        $countStatement = $this->prepare("SELECT COUNT(*) AS count FROM requests WHERE {$whereSql}");
        $countStatement->bind_param($types, ...$params);
        $countStatement->execute();
        $total = (int) (($countStatement->get_result()->fetch_assoc())['count'] ?? 0);
        $countStatement->close();

        $listTypes = $types . 'ii';
        $listParams = array_merge($params, [$offset, $limit]);
        $listStatement = $this->prepare(
            "SELECT * FROM requests WHERE {$whereSql} ORDER BY date_requested DESC LIMIT ?, ?"
        );
        $listStatement->bind_param($listTypes, ...$listParams);
        $listStatement->execute();
        $items = [];
        $result = $listStatement->get_result();
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
        $listStatement->close();

        return ['items' => $items, 'total' => $total];
    }

    private function prepare(string $sql): mysqli_stmt
    {
        $statement = $this->conn->prepare($sql);
        if (!$statement) {
            throw new RuntimeException('Unable to prepare the request query.');
        }
        return $statement;
    }
}
