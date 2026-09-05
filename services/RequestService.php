<?php
require_once __DIR__ . '/RequestStateMachine.php';

class DuplicateRequestException extends DomainException {
    public function __construct(
        string $message,
        private ?int $existingRequestId = null,
        private ?string $referenceNumber = null,
        private ?string $existingStatus = null,
        int $code = 409,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getExistingRequestId(): ?int { return $this->existingRequestId; }
    public function getReferenceNumber(): ?string { return $this->referenceNumber; }
    public function getExistingStatus(): ?string { return $this->existingStatus; }
}

final class RequestService {
    public function __construct(private mysqli $db) {}

    public static function certificateFamily(string $type): ?string {
        $type = strtolower(trim($type));
        return match ($type) {
            'baptismal_certificate', 'baptism_certification', 'baptism' => 'baptism',
            'confirmation_certificate', 'confirmation_certification', 'confirmation' => 'confirmation',
            'first_communion_certificate', 'first_communion_certification', 'first_communion' => 'first_communion',
            'marriage_certificate', 'marriage_certification', 'marriage' => 'marriage',
            'funeral_certificate', 'funeral_certification', 'funeral', 'burial', 'death' => 'funeral',
            'certificate' => 'general_certificate',
            default => null,
        };
    }

    public static function certificateFamilyTypes(string $family): array {
        return match ($family) {
            'baptism' => ['baptismal_certificate', 'baptism_certification', 'baptism'],
            'confirmation' => ['confirmation_certificate', 'confirmation_certification', 'confirmation'],
            'first_communion' => ['first_communion_certificate', 'first_communion_certification', 'first_communion'],
            'marriage' => ['marriage_certificate', 'marriage_certification', 'marriage'],
            'funeral' => ['funeral_certificate', 'funeral_certification', 'funeral', 'burial', 'death'],
            'general_certificate' => ['certificate'],
            default => [$family],
        };
    }

    public function create(array $data, int $userId, string $idempotencyKey): array {
        $this->db->begin_transaction();
        try {
            $response = $this->createInCurrentTransaction($data, $userId, $idempotencyKey);
            $this->db->commit();
            return $response;
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }

    public function createInCurrentTransaction(array $data, int $userId, string $idempotencyKey): array {
        $type = strtolower(trim((string) ($data['request_type'] ?? '')));
        $supportedTypes = [
            'certificate','blessing','sacramental_service','reservation',
            'baptismal_certificate','baptism_certification',
            'confirmation_certificate','confirmation_certification',
            'first_communion_certificate','first_communion_certification',
            'marriage_certificate','marriage_certification',
            'funeral_certificate','funeral_certification',
            'blessing_request'
        ];
        if (!in_array($type, $supportedTypes, true)) throw new InvalidArgumentException('Unsupported request type.');
        $key = strtolower(trim($idempotencyKey));
        if (!preg_match('/^[a-f0-9]{64}$/', $key)) throw new InvalidArgumentException('A valid idempotency key is required.');
        $existing = $this->db->prepare('SELECT request_id,response_json FROM request_idempotency_keys WHERE user_id=? AND operation=? AND idempotency_key=? FOR UPDATE');
        $operation = 'create_' . $type; $existing->bind_param('iss', $userId, $operation, $key); $existing->execute(); $row = $existing->get_result()->fetch_assoc(); $existing->close();
        if ($row) { return ['request_id'=>(int)$row['request_id'],'replayed'=>true,'response'=>json_decode((string)$row['response_json'],true)]; }

        $certFamily = self::certificateFamily($type);
        $recordHolderName = trim((string) ($data['record_holder_name'] ?? ''));
        if ($recordHolderName === '') {
            $userStmt = $this->db->prepare('SELECT fullname FROM users WHERE id = ? LIMIT 1');
            if ($userStmt) {
                $userStmt->bind_param('i', $userId);
                $userStmt->execute();
                $uRow = $userStmt->get_result()->fetch_assoc();
                $userStmt->close();
                $recordHolderName = trim((string) ($uRow['fullname'] ?? ''));
            }
        }

        // Anti-spam duplicate check for certificate requests:
        // Query database for existing active records matching user_id, certificate_type (or family), and record_holder_name.
        // Block if an active request (status != 'completed', 'rejected', 'cancelled') exists.
        if ($certFamily !== null) {
            $checkTypes = self::certificateFamilyTypes($certFamily);
            $placeholders = implode(',', array_fill(0, count($checkTypes), '?'));

            $hasColumn = false;
            $chkCol = $this->db->query("SHOW COLUMNS FROM requests LIKE 'record_holder_name'");
            if ($chkCol && $chkCol->num_rows > 0) {
                $hasColumn = true;
            }

            if ($hasColumn && $recordHolderName !== '') {
                $sql = "SELECT request_id, reference_number, status, request_type, record_holder_name 
                        FROM requests 
                        WHERE user_id = ? 
                          AND request_type IN ($placeholders)
                          AND LOWER(TRIM(COALESCE(record_holder_name, ''))) = LOWER(TRIM(?))
                          AND status NOT IN ('completed', 'rejected', 'cancelled')
                          AND deleted_at IS NULL
                        ORDER BY request_id DESC 
                        LIMIT 1 
                        FOR UPDATE";
                $typesStr = 'i' . str_repeat('s', count($checkTypes)) . 's';
                $params = array_merge([$userId], $checkTypes, [$recordHolderName]);
            } else {
                $sql = "SELECT request_id, reference_number, status, request_type, record_holder_name 
                        FROM requests 
                        WHERE user_id = ? 
                          AND request_type IN ($placeholders)
                          AND status NOT IN ('completed', 'rejected', 'cancelled')
                          AND deleted_at IS NULL
                        ORDER BY request_id DESC 
                        LIMIT 1 
                        FOR UPDATE";
                $typesStr = 'i' . str_repeat('s', count($checkTypes));
                $params = array_merge([$userId], $checkTypes);
            }

            $dupStmt = $this->db->prepare($sql);
            if ($dupStmt) {
                $dupStmt->bind_param($typesStr, ...$params);
                $dupStmt->execute();
                $existingActive = $dupStmt->get_result()->fetch_assoc();
                $dupStmt->close();

                if ($existingActive) {
                    $activeStatus = strtoupper((string) $existingActive['status']);
                    $activeRef = (string) $existingActive['reference_number'];
                    $holderDisplay = $recordHolderName !== '' ? " for '{$recordHolderName}'" : "";
                    $friendlyType = ucwords(str_replace('_', ' ', $type));
                    throw new DuplicateRequestException(
                        "An active {$friendlyType} request ({$activeRef}){$holderDisplay} is currently in {$activeStatus} status. Duplicate requests cannot be submitted until your previous request is completed, rejected, or cancelled.",
                        (int) $existingActive['request_id'],
                        $activeRef,
                        (string) $existingActive['status']
                    );
                }
            }
        }

        $reference = 'TUGON-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $description = trim((string) ($data['description'] ?? ''));
        if (strlen($description) > 10000) throw new InvalidArgumentException('Request details are too long.');
        $status = 'submitted';

        $hasColumn = false;
        $chkCol = $this->db->query("SHOW COLUMNS FROM requests LIKE 'record_holder_name'");
        if ($chkCol && $chkCol->num_rows > 0) {
            $hasColumn = true;
        }

        if ($hasColumn) {
            $stmt = $this->db->prepare('INSERT INTO requests (user_id,request_type,record_holder_name,description,status,reference_number) VALUES (?,?,?,?,?,?)');
            $stmt->bind_param('isssss', $userId, $type, $recordHolderName, $description, $status, $reference);
        } else {
            $stmt = $this->db->prepare('INSERT INTO requests (user_id,request_type,description,status,reference_number) VALUES (?,?,?,?,?)');
            $stmt->bind_param('issss', $userId, $type, $description, $status, $reference);
        }
        $stmt->execute(); $requestId = $stmt->insert_id; $stmt->close();

        $history = $this->db->prepare('INSERT INTO request_status_history (request_id,previous_status,new_status,actor_user_id,reason) VALUES (?,NULL,?,?,?)');
        $reason = 'Request submitted'; $history->bind_param('isis', $requestId, $status, $userId, $reason); $history->execute(); $history->close();
        $response = ['request_id'=>$requestId,'reference_number'=>$reference,'status'=>$status,'record_holder_name'=>$recordHolderName];
        $json = json_encode($response);
        $idem = $this->db->prepare('INSERT INTO request_idempotency_keys (user_id,operation,idempotency_key,request_id,response_json) VALUES (?,?,?,?,?)');
        $idem->bind_param('issis', $userId, $operation, $key, $requestId, $json); $idem->execute(); $idem->close();
        return $response + ['replayed'=>false];
    }

    public function transition(int $requestId, string $next, int $actorId, ?string $reason = null): array {
        $this->db->begin_transaction();
        try {
            $response = $this->transitionInCurrentTransaction($requestId, $next, $actorId, $reason);
            $this->db->commit();
            return $response;
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }

    public function transitionInCurrentTransaction(int $requestId, string $next, int $actorId, ?string $reason = null): array {
        $next = RequestStateMachine::normalize($next); if (!in_array($next, RequestStateMachine::STATES, true)) throw new InvalidArgumentException('Invalid request state.');
            $stmt = $this->db->prepare('SELECT request_id,user_id,status FROM requests WHERE request_id=? AND deleted_at IS NULL FOR UPDATE'); $stmt->bind_param('i',$requestId); $stmt->execute(); $request=$stmt->get_result()->fetch_assoc();$stmt->close();
            if (!$request) throw new RuntimeException('Request not found.');
            $from = RequestStateMachine::normalize((string)$request['status']);
            if (!RequestStateMachine::canTransition($from, $next)) throw new DomainException('That request transition is not allowed.');
            if (RequestStateMachine::requiresReason($next) && strlen(trim((string)$reason)) < 5) throw new InvalidArgumentException('A meaningful reason is required.');
            $update=$this->db->prepare('UPDATE requests SET status=?,admin_response=?,updated_at=NOW() WHERE request_id=?'); $reasonText=trim((string)$reason);$update->bind_param('ssi',$next,$reasonText,$requestId);$update->execute();$update->close();
            $history=$this->db->prepare('INSERT INTO request_status_history (request_id,previous_status,new_status,actor_user_id,reason) VALUES (?,?,?,?,?)');$history->bind_param('issis',$requestId,$from,$next,$actorId,$reasonText);$history->execute();$history->close();
            return ['request_id'=>$requestId,'previous_status'=>$from,'status'=>$next];
    }
}
