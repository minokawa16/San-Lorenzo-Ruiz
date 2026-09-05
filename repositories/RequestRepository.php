<?php

/** Read-only persistence operations for parish service requests. */
final class RequestRepository
{
    private $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function findForUser(int $userId, string $search, string $status, int $limit, int $offset): array
    {
        $where = ['user_id = ?'];
        $types = 'i';
        $params = [$userId];

        if ($status !== '') {
            if ($status === 'pending') {
                $where[] = "(status = 'pending' OR status = 'submitted' OR status = 'requirements_review' OR status = 'needs_information' OR status = 'payment_required' OR status = 'payment_review')";
            } elseif ($status === 'processing') {
                $where[] = "(status = 'processing' OR status = 'approved' OR status = 'scheduled' OR status = 'ready_for_release')";
            } elseif ($status === 'rejected') {
                $where[] = "(status = 'rejected' OR status = 'cancelled')";
            } else {
                $where[] = 'status = ?';
                $types .= 's';
                $params[] = $status;
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
