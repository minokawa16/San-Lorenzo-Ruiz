<?php
require_once __DIR__ . '/RequestStateMachine.php';

final class RequestService {
    public function __construct(private mysqli $db) {}

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
        $supportedTypes = ['certificate','blessing','sacramental_service','reservation','baptismal_certificate','confirmation_certificate','first_communion_certificate','blessing_request'];
        if (!in_array($type, $supportedTypes, true)) throw new InvalidArgumentException('Unsupported request type.');
        $key = strtolower(trim($idempotencyKey));
        if (!preg_match('/^[a-f0-9]{64}$/', $key)) throw new InvalidArgumentException('A valid idempotency key is required.');
            $existing = $this->db->prepare('SELECT request_id,response_json FROM request_idempotency_keys WHERE user_id=? AND operation=? AND idempotency_key=? FOR UPDATE');
            $operation = 'create_' . $type; $existing->bind_param('iss', $userId, $operation, $key); $existing->execute(); $row = $existing->get_result()->fetch_assoc(); $existing->close();
            if ($row) { return ['request_id'=>(int)$row['request_id'],'replayed'=>true,'response'=>json_decode((string)$row['response_json'],true)]; }
            $reference = 'TUGON-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $description = trim((string) ($data['description'] ?? ''));
            if (strlen($description) > 10000) throw new InvalidArgumentException('Request details are too long.');
            $status = 'submitted';
            $stmt = $this->db->prepare('INSERT INTO requests (user_id,request_type,description,status,reference_number) VALUES (?,?,?,?,?)');
            $stmt->bind_param('issss', $userId, $type, $description, $status, $reference); $stmt->execute(); $requestId = $stmt->insert_id; $stmt->close();
            $history = $this->db->prepare('INSERT INTO request_status_history (request_id,previous_status,new_status,actor_user_id,reason) VALUES (?,NULL,?,?,?)');
            $reason = 'Request submitted'; $history->bind_param('isis', $requestId, $status, $userId, $reason); $history->execute(); $history->close();
            $response = ['request_id'=>$requestId,'reference_number'=>$reference,'status'=>$status];
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
