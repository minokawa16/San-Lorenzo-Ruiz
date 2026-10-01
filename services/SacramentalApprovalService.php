<?php
/**
 * SacramentalApprovalService.php
 * Automated Workflow on Sacramental Request Approval:
 * 1. Transfers and populates service data into official Sacramental Records (Baptism, Marriage, Funeral).
 * 2. Inserts and locks scheduled events in the Parish Calendar (schedule_events).
 * 3. Sends automated in-app, email, and SMS notifications to the parishioner.
 * 4. Ensures atomic transactional integrity (BEGIN TRANSACTION ... COMMIT / ROLLBACK).
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/RequestStateMachine.php';

class SacramentalApprovalService {
    private mysqli $conn;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    /**
     * Determines whether the given request type is a sacramental service request.
     */
    public static function isSacramentalRequestType(string $requestType): bool {
        $norm = strtolower(trim($requestType));
        return in_array($norm, [
            'baptism_service', 'baptism',
            'marriage_wedding_service', 'marriage', 'wedding',
            'funeral_mass', 'funeral', 'burial',
            'first_communion_service', 'first_communion', 'communion',
            'confirmation_service', 'confirmation'
        ], true);
    }

    /**
     * Approves a sacramental service request within an atomic transaction (legacy wrapper).
     *
     * @param int $requestId Request ID to approve
     * @param int $actorUserId Administrator or staff user ID
     * @param array $options Optional settings: admin_response, officiating_priest
     * @return array Structured result containing sacramental record, calendar event, and status
     * @throws Throwable Rolls back transaction on any failure
     */
    public function approveRequest(int $requestId, int $actorUserId, array $options = []): array {
        return $this->completeRequest($requestId, $actorUserId, array_merge(['target_status' => 'approved'], $options));
    }

    /**
     * Completes a sacramental service request within an atomic transaction:
     * 1. Updates status to 'completed' (or custom target status).
     * 2. Registers or updates official sacramental records (Baptism, Marriage, Funeral).
     * 3. Automatically inserts/locks the day and time in the Parish Calendar (schedule_events).
     * 4. Dispatches notification to parishioner.
     *
     * @param int $requestId Request ID to complete
     * @param int $actorUserId Administrator or staff user ID
     * @param array $options Optional settings: admin_response, officiating_priest, target_status
     * @return array Structured result
     */
    public function completeRequest(int $requestId, int $actorUserId, array $options = []): array {
        ensureSacramentalRecordViews($this->conn);
        ensureScheduleEventsTable($this->conn);

        $targetStatus = !empty($options['target_status']) ? $options['target_status'] : 'completed';
        $adminResponse = trim((string) ($options['admin_response'] ?? ''));
        $officiatingPriest = trim((string) ($options['officiating_priest'] ?? $options['minister'] ?? ''));
        $parishPriest = trim((string) ($options['parish_priest'] ?? ''));

        $this->conn->begin_transaction();
        try {
            // 1. Lock and retrieve the request row
            $stmt = $this->conn->prepare("
                SELECT r.*, u.fullname AS applicant_fullname, u.email AS applicant_email
                FROM requests r
                JOIN users u ON r.user_id = u.id
                WHERE r.request_id = ? AND r.deleted_at IS NULL
                FOR UPDATE
            ");
            if (!$stmt) {
                throw new RuntimeException("Failed to prepare request query: " . $this->conn->error);
            }
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $request = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$request) {
                throw new InvalidArgumentException("Request #{$requestId} not found.");
            }

            $currentStatus = RequestStateMachine::normalize((string) $request['status']);
            $allowedFrom = ['pending', 'submitted', 'requirements_review', 'needs_information', 'processing', 'scheduled', 'approved', 'completed'];
            if (!in_array($currentStatus, $allowedFrom, true)) {
                throw new DomainException("Request cannot be transitioned to '{$targetStatus}' from '{$currentStatus}' status.");
            }

            // Enforce Minister and Parish Priest assignment before marking sacramental requests Approved or Completed
            $isSacramental = self::isSacramentalRequestType((string) ($request['request_type'] ?? ''));
            $enforcePriests = !empty($options['enforce_priest_assignment']);
            if ($isSacramental && in_array($targetStatus, ['approved', 'completed'], true)) {
                if ($officiatingPriest === '' || strcasecmp($officiatingPriest, 'Rev. Fr. Parish Priest') === 0 || strcasecmp($officiatingPriest, 'N/A') === 0) {
                    if ($enforcePriests) {
                        throw new DomainException('Minister / Officiating Priest must be assigned before marking the sacramental record as ' . ucfirst($targetStatus) . '.');
                    }
                    $officiatingPriest = $this->getDefaultOfficiatingPriest();
                }
                if ($parishPriest === '' || strcasecmp($parishPriest, 'N/A') === 0) {
                    if ($enforcePriests) {
                        throw new DomainException('Parish Priest must be confirmed and assigned before marking the sacramental record as ' . ucfirst($targetStatus) . '.');
                    }
                    $parishPriest = function_exists('getParishPriestName') ? getParishPriestName($this->conn) : $this->getDefaultOfficiatingPriest();
                }
            } else {
                if ($officiatingPriest === '') {
                    $officiatingPriest = $this->getDefaultOfficiatingPriest();
                }
                if ($parishPriest === '') {
                    $parishPriest = function_exists('getParishPriestName') ? getParishPriestName($this->conn) : $this->getDefaultOfficiatingPriest();
                }
            }

            // 2. Validate calendar schedule conflict before completing (skip conflict if already has its own event)
            $calendarConflict = requestApprovalConflict($this->conn, $requestId);
            if ($calendarConflict['conflict']) {
                $chkEv = $this->conn->prepare("SELECT schedule_id FROM schedule_events WHERE source_type = 'request' AND source_id = ? LIMIT 1");
                $hasOwnEvent = false;
                if ($chkEv) {
                    $chkEv->bind_param('i', $requestId);
                    $chkEv->execute();
                    $hasOwnEvent = (bool) $chkEv->get_result()->fetch_assoc();
                    $chkEv->close();
                }
                if (!$hasOwnEvent) {
                    throw new DomainException($calendarConflict['message']);
                }
            }

            // 3. Update Request status to target status
            $updStmt = $this->conn->prepare("
                UPDATE requests 
                SET status = ?, admin_response = ?, updated_at = NOW() 
                WHERE request_id = ?
            ");
            if (!$updStmt) {
                throw new RuntimeException("Failed to prepare request update statement: " . $this->conn->error);
            }
            $updStmt->bind_param('ssi', $targetStatus, $adminResponse, $requestId);
            if (!$updStmt->execute()) {
                $err = $updStmt->error;
                $updStmt->close();
                throw new RuntimeException("Failed to update request status: " . $err);
            }
            $updStmt->close();

            // Insert status history
            $histStmt = $this->conn->prepare("
                INSERT INTO request_status_history (request_id, previous_status, new_status, actor_user_id, reason)
                VALUES (?, ?, ?, ?, ?)
            ");
            if ($histStmt) {
                $reasonText = $adminResponse !== '' ? $adminResponse : "Request {$targetStatus} by parish administration.";
                $histStmt->bind_param('issis', $requestId, $currentStatus, $targetStatus, $actorUserId, $reasonText);
                $histStmt->execute();
                $histStmt->close();
            }

            // Update local request array for downstream steps
            $request['status'] = $targetStatus;
            $request['admin_response'] = $adminResponse;

            // 4. Action 1: Transfer and populate service data into official Sacramental Records
            $sacramentalRecord = $this->populateSacramentalRecord($request, $officiatingPriest, $actorUserId, array_merge($options, [
                'parish_priest' => $parishPriest
            ]));

            // 5. Action 2: Insert and lock scheduled event into Parish Calendar
            $calendarEvent = $this->populateParishCalendarEvent($request, $sacramentalRecord, $actorUserId);

            // 6. Action 3: Parishioner Notification
            $this->notifyParishionerOnApproval($request, $calendarEvent, $adminResponse);

            // 7. Audit log with complete metadata
            $sacType = !empty($sacramentalRecord['type']) ? ucwords(str_replace('_', ' ', (string)$sacramentalRecord['type'])) : 'Sacramental';
            $sacId = !empty($sacramentalRecord['record_id']) ? (int)$sacramentalRecord['record_id'] : null;
            $calId = !empty($calendarEvent['event_id']) ? (int)$calendarEvent['event_id'] : null;
            $trackCode = !empty($request['tracking_code']) ? (string)$request['tracking_code'] : ('REQ-' . $requestId);
            $actionDesc = "Approved Sacramental Request #{$requestId} ({$trackCode}); auto-created {$sacType} Record" . ($sacId ? " #{$sacId}" : "") . ($calId ? " and locked calendar slot #{$calId}" : "") . ".";
            $auditPayload = [
                'status' => $targetStatus,
                'tracking_code' => $trackCode,
                'sacramental_type' => $sacType,
                'sacramental_record_id' => $sacId,
                'calendar_event_id' => $calId,
                'officiating_priest' => $officiatingPriest,
                'admin_response' => $adminResponse
            ];
            createAuditLog(
                $this->conn, $actorUserId, strtoupper($targetStatus) . '_SACRAMENTAL_REQUEST',
                'requests', $requestId, null, $auditPayload, $actionDesc, 'SACRAMENTS', 'INFO'
            );

            // Commit atomic transaction
            $this->conn->commit();

            return [
                'success' => true,
                'message' => "Sacramental request marked as {$targetStatus}, official record registered, and calendar schedule locked.",
                'request_id' => $requestId,
                'status' => $targetStatus,
                'sacramental_record' => $sacramentalRecord,
                'calendar_event' => $calendarEvent
            ];
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    /**
     * Extracts structured fields and populates the official Sacramental Record table.
     */
    public function populateSacramentalRecord(array $request, string $officiatingPriest, int $actorUserId, array $options = []): array {
        $requestType = strtolower(trim((string) ($request['request_type'] ?? '')));
        $description = (string) ($request['description'] ?? '');
        $requestId = intval($request['request_id']);
        $parishPriest = trim((string) ($options['parish_priest'] ?? ''));
        if ($parishPriest === '') {
            $parishPriest = function_exists('getParishPriestName') ? getParishPriestName($this->conn) : $this->getDefaultOfficiatingPriest();
        }

        if ($requestType === 'baptism_service' || $requestType === 'baptism') {
            return $this->populateBaptismRecord($requestId, $description, $request, $officiatingPriest, $parishPriest);
        }

        if ($requestType === 'marriage_wedding_service' || $requestType === 'marriage') {
            return $this->populateMarriageRecord($requestId, $description, $request, $officiatingPriest);
        }

        if ($requestType === 'funeral_mass' || $requestType === 'funeral' || $requestType === 'burial') {
            return $this->populateFuneralRecord($requestId, $description, $request, $officiatingPriest);
        }

        if ($requestType === 'first_communion_service' || $requestType === 'first_communion' || $requestType === 'communion') {
            return $this->populateFirstCommunionRecord($requestId, $description, $request, $officiatingPriest, $parishPriest);
        }

        if ($requestType === 'confirmation_service' || $requestType === 'confirmation') {
            return $this->populateConfirmationRecord($requestId, $description, $request, $officiatingPriest, $parishPriest);
        }

        return [
            'type' => 'other',
            'registered' => false,
            'message' => 'Non-sacramental request type; record registration skipped.'
        ];
    }

    /**
     * Populates baptism_records / sacramental_records_baptism.
     */
    private function populateBaptismRecord(int $requestId, string $desc, array $request, string $priest, string $parishPriest = ''): array {
        if ($parishPriest === '') {
            $parishPriest = function_exists('getParishPriestName') ? getParishPriestName($this->conn) : $this->getDefaultOfficiatingPriest();
        }
        $parsed = $this->parseBaptismDescription($desc, $request);

        // Check if a record already exists for this request_id
        $stmt = $this->conn->prepare("SELECT baptism_id, registry_no FROM baptism_records WHERE request_id = ? LIMIT 1");
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $registryNo = !empty($existing['registry_no']) ? $existing['registry_no'] : $this->generateRegistryNumber('BAP');
        $status = 'active';

        if ($existing) {
            $recordId = intval($existing['baptism_id']);
            $upd = $this->conn->prepare("
                UPDATE baptism_records
                SET fullname = ?, birth_date = ?, birth_place = ?, birth_status = ?,
                    baptism_date = ?, parents = ?, parent_address = ?, godparents = ?,
                    priest = ?, parish_priest = ?, remarks = ?, status = ?, updated_at = NOW()
                WHERE baptism_id = ?
            ");
            $upd->bind_param(
                'ssssssssssssi',
                $parsed['fullname'],
                $parsed['birth_date'],
                $parsed['birth_place'],
                $parsed['birth_status'],
                $parsed['baptism_date'],
                $parsed['parents'],
                $parsed['parent_address'],
                $parsed['godparents'],
                $priest,
                $parishPriest,
                $parsed['remarks'],
                $status,
                $recordId
            );
            $upd->execute();
            $upd->close();
        } else {
            $ins = $this->conn->prepare("
                INSERT INTO baptism_records 
                (request_id, registry_no, fullname, birth_date, birth_place, birth_status, baptism_date, parents, parent_address, godparents, priest, parish_priest, remarks, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->bind_param(
                'isssssssssssss',
                $requestId,
                $registryNo,
                $parsed['fullname'],
                $parsed['birth_date'],
                $parsed['birth_place'],
                $parsed['birth_status'],
                $parsed['baptism_date'],
                $parsed['parents'],
                $parsed['parent_address'],
                $parsed['godparents'],
                $priest,
                $parishPriest,
                $parsed['remarks'],
                $status
            );
            $ins->execute();
            $recordId = $ins->insert_id;
            $ins->close();
        }

        return [
            'type' => 'baptism',
            'table' => 'sacramental_records_baptism',
            'canonical_table' => 'baptism_records',
            'id' => $recordId,
            'registry_no' => $registryNo,
            'subject_name' => $parsed['fullname'],
            'event_date' => $parsed['baptism_date'],
            'officiating_priest' => $priest,
            'registered' => true
        ];
    }

    /**
     * Populates marriage_records / sacramental_records_marriage.
     */
    private function populateMarriageRecord(int $requestId, string $desc, array $request, string $priest): array {
        $parsed = $this->parseMarriageDescription($desc, $request);

        $stmt = $this->conn->prepare("SELECT marriage_id, registry_no FROM marriage_records WHERE request_id = ? LIMIT 1");
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $registryNo = !empty($existing['registry_no']) ? $existing['registry_no'] : $this->generateRegistryNumber('MAR');
        $status = 'active';

        if ($existing) {
            $recordId = intval($existing['marriage_id']);
            $upd = $this->conn->prepare("
                UPDATE marriage_records
                SET husband_name = ?, husband_birth_date = ?, husband_status = ?, husband_age = ?,
                    husband_birth_origin = ?, husband_residence = ?, husband_parents = ?,
                    wife_name = ?, wife_birth_date = ?, wife_status = ?, wife_age = ?,
                    wife_birth_origin = ?, wife_residence = ?, wife_parents = ?,
                    wedding_date = ?, wedding_location = ?, sponsors = ?, witnesses_residence = ?,
                    officiating_priest = ?, remarks = ?, status = ?, updated_at = NOW()
                WHERE marriage_id = ?
            ");
            $upd->bind_param(
                'sssssssssssssssssssssi',
                $parsed['husband_name'],
                $parsed['husband_birth_date'],
                $parsed['husband_status'],
                $parsed['husband_age'],
                $parsed['husband_birth_origin'],
                $parsed['husband_residence'],
                $parsed['husband_parents'],
                $parsed['wife_name'],
                $parsed['wife_birth_date'],
                $parsed['wife_status'],
                $parsed['wife_age'],
                $parsed['wife_birth_origin'],
                $parsed['wife_residence'],
                $parsed['wife_parents'],
                $parsed['wedding_date'],
                $parsed['wedding_location'],
                $parsed['sponsors'],
                $parsed['witnesses_residence'],
                $priest,
                $parsed['remarks'],
                $status,
                $recordId
            );
            $upd->execute();
            $upd->close();
        } else {
            $ins = $this->conn->prepare("
                INSERT INTO marriage_records
                (request_id, registry_no, husband_name, husband_birth_date, husband_status, husband_age,
                 husband_birth_origin, husband_residence, husband_parents, wife_name, wife_birth_date,
                 wife_status, wife_age, wife_birth_origin, wife_residence, wife_parents, wedding_date,
                 wedding_location, sponsors, witnesses_residence, officiating_priest, remarks, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->bind_param(
                'issssssssssssssssssssss',
                $requestId,
                $registryNo,
                $parsed['husband_name'],
                $parsed['husband_birth_date'],
                $parsed['husband_status'],
                $parsed['husband_age'],
                $parsed['husband_birth_origin'],
                $parsed['husband_residence'],
                $parsed['husband_parents'],
                $parsed['wife_name'],
                $parsed['wife_birth_date'],
                $parsed['wife_status'],
                $parsed['wife_age'],
                $parsed['wife_birth_origin'],
                $parsed['wife_residence'],
                $parsed['wife_parents'],
                $parsed['wedding_date'],
                $parsed['wedding_location'],
                $parsed['sponsors'],
                $parsed['witnesses_residence'],
                $priest,
                $parsed['remarks'],
                $status
            );
            $ins->execute();
            $recordId = $ins->insert_id;
            $ins->close();
        }

        return [
            'type' => 'marriage',
            'table' => 'sacramental_records_marriage',
            'canonical_table' => 'marriage_records',
            'id' => $recordId,
            'registry_no' => $registryNo,
            'subject_name' => $parsed['husband_name'] . ' & ' . $parsed['wife_name'],
            'event_date' => $parsed['wedding_date'],
            'officiating_priest' => $priest,
            'registered' => true
        ];
    }

    /**
     * Populates funeral_records / sacramental_records_death.
     */
    private function populateFuneralRecord(int $requestId, string $desc, array $request, string $priest): array {
        $parsed = $this->parseFuneralDescription($desc, $request);

        // If parishioner specified a preferred minister and the admin hasn't assigned a specific one, honour it
        if (!empty($parsed['preferred_minister'])
            && (strcasecmp($priest, 'Rev. Fr. Parish Priest') === 0 || $priest === '')
        ) {
            $priest = $parsed['preferred_minister'];
        }

        $stmt = $this->conn->prepare("SELECT funeral_id, registry_no FROM funeral_records WHERE request_id = ? LIMIT 1");
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $registryNo = !empty($existing['registry_no']) ? $existing['registry_no'] : $this->generateRegistryNumber('FUN');
        $status = 'active';

        // Server-side validation before completion (Part 3): Block completion if required fields are missing
        $missingFields = [];
        if (empty(trim((string)($parsed['deceased_name'] ?? '')))) {
            $missingFields[] = 'Deceased Name';
        }
        if (empty($parsed['date_of_death']) || !validDateValue($parsed['date_of_death'])) {
            $missingFields[] = 'Date of Death';
        }
        if (empty($parsed['date_of_burial']) || !validDateValue($parsed['date_of_burial'])) {
            $missingFields[] = 'Date of Burial';
        }
        if (empty(trim((string)($parsed['civil_status'] ?? '')))) {
            $missingFields[] = 'Civil Status';
        }
        if (empty(trim((string)($parsed['funeral_rites'] ?? '')))) {
            $missingFields[] = 'Funeral Rites';
        }
        if (empty(trim((string)($parsed['place_of_burial'] ?? '')))) {
            $missingFields[] = 'Place of Burial';
        }

        if (!empty($missingFields)) {
            throw new DomainException('Cannot complete funeral request: The following required Funeral Records fields are missing: ' . implode(', ', $missingFields) . '. Please ensure all details are filled in before completing.');
        }

        $requestedBy = !empty($request['applicant_fullname']) ? trim($request['applicant_fullname']) : null;
        $hasRequestedByCol = schemaColumnExists($this->conn, 'funeral_records', 'requested_by');

        if ($existing) {
            $recordId = intval($existing['funeral_id']);
            if ($hasRequestedByCol) {
                $upd = $this->conn->prepare("
                    UPDATE funeral_records
                    SET deceased_name = ?, family_name = ?, requested_by = ?, date_of_death = ?, date_of_burial = ?,
                        civil_status = ?, funeral_rites = ?,
                        cause_of_death = ?, place_of_burial = ?, minister = ?, remarks = ?, status = ?, updated_at = NOW()
                    WHERE funeral_id = ?
                ");
                $upd->bind_param(
                    'ssssssssssssi',
                    $parsed['deceased_name'],
                    $parsed['family_name'],
                    $requestedBy,
                    $parsed['date_of_death'],
                    $parsed['date_of_burial'],
                    $parsed['civil_status'],
                    $parsed['funeral_rites'],
                    $parsed['cause_of_death'],
                    $parsed['place_of_burial'],
                    $priest,
                    $parsed['remarks'],
                    $status,
                    $recordId
                );
            } else {
                $upd = $this->conn->prepare("
                    UPDATE funeral_records
                    SET deceased_name = ?, family_name = ?, date_of_death = ?, date_of_burial = ?,
                        civil_status = ?, funeral_rites = ?,
                        cause_of_death = ?, place_of_burial = ?, minister = ?, remarks = ?, status = ?, updated_at = NOW()
                    WHERE funeral_id = ?
                ");
                $upd->bind_param(
                    'sssssssssssi',
                    $parsed['deceased_name'],
                    $parsed['family_name'],
                    $parsed['date_of_death'],
                    $parsed['date_of_burial'],
                    $parsed['civil_status'],
                    $parsed['funeral_rites'],
                    $parsed['cause_of_death'],
                    $parsed['place_of_burial'],
                    $priest,
                    $parsed['remarks'],
                    $status,
                    $recordId
                );
            }
            $upd->execute();
            $upd->close();
        } else {
            if ($hasRequestedByCol) {
                $ins = $this->conn->prepare("
                    INSERT INTO funeral_records
                    (request_id, registry_no, deceased_name, family_name, requested_by, date_of_death, date_of_burial,
                     civil_status, funeral_rites, cause_of_death, place_of_burial, minister, remarks, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->bind_param(
                    'isssssssssssss',
                    $requestId,
                    $registryNo,
                    $parsed['deceased_name'],
                    $parsed['family_name'],
                    $requestedBy,
                    $parsed['date_of_death'],
                    $parsed['date_of_burial'],
                    $parsed['civil_status'],
                    $parsed['funeral_rites'],
                    $parsed['cause_of_death'],
                    $parsed['place_of_burial'],
                    $priest,
                    $parsed['remarks'],
                    $status
                );
            } else {
                $ins = $this->conn->prepare("
                    INSERT INTO funeral_records
                    (request_id, registry_no, deceased_name, family_name, date_of_death, date_of_burial,
                     civil_status, funeral_rites, cause_of_death, place_of_burial, minister, remarks, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->bind_param(
                    'issssssssssss',
                    $requestId,
                    $registryNo,
                    $parsed['deceased_name'],
                    $parsed['family_name'],
                    $parsed['date_of_death'],
                    $parsed['date_of_burial'],
                    $parsed['civil_status'],
                    $parsed['funeral_rites'],
                    $parsed['cause_of_death'],
                    $parsed['place_of_burial'],
                    $priest,
                    $parsed['remarks'],
                    $status
                );
            }
            $ins->execute();
            $recordId = $ins->insert_id;
            $ins->close();
        }

        return [
            'type' => 'funeral',
            'table' => 'sacramental_records_death',
            'canonical_table' => 'funeral_records',
            'id' => $recordId,
            'registry_no' => $registryNo,
            'subject_name' => $parsed['deceased_name'],
            'event_date' => $parsed['date_of_burial'],
            'officiating_priest' => $priest,
            'registered' => true
        ];
    }

    /**
     * Populates first_communion_records.
     */
    private function populateFirstCommunionRecord(int $requestId, string $desc, array $request, string $priest, string $parishPriest = ''): array {
        if ($parishPriest === '') {
            $parishPriest = function_exists('getParishPriestName') ? getParishPriestName($this->conn) : $this->getDefaultOfficiatingPriest();
        }
        $parsed = $this->parseCommunionDescription($desc, $request);
        if (!empty($parsed['preferred_minister']) && (strcasecmp($priest, 'Rev. Fr. Parish Priest') === 0 || $priest === '' || strcasecmp($priest, 'Parish office will assign') === 0)) {
            $priest = $parsed['preferred_minister'];
        }

        // Check if a record already exists for this request_id to prevent duplicates
        $stmt = $this->conn->prepare("SELECT communion_id, registry_no FROM first_communion_records WHERE request_id = ? LIMIT 1");
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $registryNo = !empty($existing['registry_no']) ? $existing['registry_no'] : $this->generateRegistryNumber('COM');
        $status = 'active';

        if ($existing) {
            $recordId = intval($existing['communion_id']);
            $upd = $this->conn->prepare("
                UPDATE first_communion_records
                SET fullname = ?, communion_date = ?, domicile = ?, parents = ?,
                    father_name = ?, mother_name = ?, priest = ?, baptismal_date = ?,
                    baptismal_place = ?, remarks = ?, parish_priest = ?, status = ?, updated_at = NOW()
                WHERE communion_id = ?
            ");
            $upd->bind_param(
                'ssssssssssssi',
                $parsed['fullname'],
                $parsed['communion_date'],
                $parsed['domicile'],
                $parsed['parents'],
                $parsed['father_name'],
                $parsed['mother_name'],
                $priest,
                $parsed['baptismal_date'],
                $parsed['baptismal_place'],
                $parsed['remarks'],
                $parishPriest,
                $status,
                $recordId
            );
            $upd->execute();
            $upd->close();
        } else {
            $ins = $this->conn->prepare("
                INSERT INTO first_communion_records
                (request_id, registry_no, fullname, communion_date, domicile, parents, father_name, mother_name, priest, folio, baptismal_date, baptismal_place, remarks, parish_priest, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?)
            ");
            $ins->bind_param(
                'isssssssssssss',
                $requestId,
                $registryNo,
                $parsed['fullname'],
                $parsed['communion_date'],
                $parsed['domicile'],
                $parsed['parents'],
                $parsed['father_name'],
                $parsed['mother_name'],
                $priest,
                $parsed['baptismal_date'],
                $parsed['baptismal_place'],
                $parsed['remarks'],
                $parishPriest,
                $status
            );
            $ins->execute();
            $recordId = $ins->insert_id;
            $ins->close();
        }

        return [
            'type' => 'communion',
            'table' => 'first_communion_records',
            'canonical_table' => 'first_communion_records',
            'id' => $recordId,
            'registry_no' => $registryNo,
            'subject_name' => $parsed['fullname'],
            'event_date' => $parsed['communion_date'],
            'officiating_priest' => $priest,
            'registered' => true
        ];
    }

    /**
     * Populates confirmation_records.
     */
    private function populateConfirmationRecord(int $requestId, string $desc, array $request, string $priest, string $parishPriest = ''): array {
        if ($parishPriest === '') {
            $parishPriest = function_exists('getParishPriestName') ? getParishPriestName($this->conn) : $this->getDefaultOfficiatingPriest();
        }
        $parsed = $this->parseConfirmationDescription($desc, $request);

        // Check if a record already exists for this request_id to prevent duplicates
        $stmt = $this->conn->prepare("SELECT confirmation_id, registry_no FROM confirmation_records WHERE request_id = ? LIMIT 1");
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $registryNo = !empty($existing['registry_no']) ? $existing['registry_no'] : $this->generateRegistryNumber('CNF');
        $status = 'active';

        if ($existing) {
            $recordId = intval($existing['confirmation_id']);
            $upd = $this->conn->prepare("
                UPDATE confirmation_records
                SET fullname = ?, confirmation_date = ?, age = ?, origin_parish = ?,
                    origin_province = ?, baptismal_place = ?, parents = ?, father_name = ?,
                    mother_name = ?, sponsor = ?, bishop_priest = ?, observations = ?,
                    parish_priest = ?, status = ?, updated_at = NOW()
                WHERE confirmation_id = ?
            ");
            $upd->bind_param(
                'ssssssssssssssi',
                $parsed['fullname'],
                $parsed['confirmation_date'],
                $parsed['age'],
                $parsed['origin_parish'],
                $parsed['origin_province'],
                $parsed['baptismal_place'],
                $parsed['parents'],
                $parsed['father_name'],
                $parsed['mother_name'],
                $parsed['sponsor'],
                $priest,
                $parsed['observations'],
                $parishPriest,
                $status,
                $recordId
            );
            $upd->execute();
            $upd->close();
        } else {
            $ins = $this->conn->prepare("
                INSERT INTO confirmation_records
                (request_id, registry_no, fullname, confirmation_date, age, origin_parish, origin_province, baptismal_place, parents, father_name, mother_name, sponsor, bishop_priest, observations, parish_priest, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->bind_param(
                'isssssssssssssss',
                $requestId,
                $registryNo,
                $parsed['fullname'],
                $parsed['confirmation_date'],
                $parsed['age'],
                $parsed['origin_parish'],
                $parsed['origin_province'],
                $parsed['baptismal_place'],
                $parsed['parents'],
                $parsed['father_name'],
                $parsed['mother_name'],
                $parsed['sponsor'],
                $priest,
                $parsed['observations'],
                $parishPriest,
                $status
            );
            $ins->execute();
            $recordId = $ins->insert_id;
            $ins->close();
        }

        return [
            'type' => 'confirmation',
            'table' => 'confirmation_records',
            'canonical_table' => 'confirmation_records',
            'id' => $recordId,
            'registry_no' => $registryNo,
            'subject_name' => $parsed['fullname'],
            'event_date' => $parsed['confirmation_date'],
            'officiating_priest' => $priest,
            'registered' => true
        ];
    }

    /**
     * Action 2: Inserts or updates the event into schedule_events and locks the slot.
     */
    public function populateParishCalendarEvent(array $request, array $sacramentalRecord, int $actorUserId): array {
        $requestId = intval($request['request_id']);
        $description = (string) ($request['description'] ?? '');

        // Extract schedule date and time
        $eventDate = requestCalendarField($description, ['Date of Baptism', 'Date of Marriage', 'Preferred date', 'Date of Patronal Fiesta', 'Date']);
        if (!validDateValue($eventDate)) {
            $eventDate = date('Y-m-d');
        }

        $startTime = normalizeRequestCalendarTime(requestCalendarField($description, ['Preferred time', 'Event time', 'Time']));
        if (!$startTime || $startTime === '00:00:00') {
            $startTime = '09:00:00';
        }
        $endTime = date('H:i:s', strtotime($startTime . ' +1 hour'));

        $location = requestCalendarField($description, ['Location', 'Address', 'Venue']);
        if ($location === '') {
            $location = 'San Lorenzo Ruiz Parish Church';
        }

        // Format Title: "[Service Type]: [Subject Name]"
        $requestType = strtolower(trim((string) ($request['request_type'] ?? '')));
        $subject = $sacramentalRecord['subject_name'] ?? $request['applicant_fullname'];

        if ($requestType === 'baptism_service' || $requestType === 'baptism') {
            $title = 'Baptism: ' . $subject;
        } elseif ($requestType === 'marriage_wedding_service' || $requestType === 'marriage') {
            $title = 'Wedding: ' . $subject;
        } elseif ($requestType === 'funeral_mass' || $requestType === 'funeral' || $requestType === 'burial') {
            $title = 'Funeral Mass: ' . $subject;
        } elseif ($requestType === 'first_communion_service' || $requestType === 'first_communion' || $requestType === 'communion') {
            $title = 'First Communion: ' . $subject;
        } elseif ($requestType === 'confirmation_service' || $requestType === 'confirmation') {
            $title = 'Confirmation: ' . $subject;
        } else {
            $title = ucfirst(str_replace('_', ' ', $requestType)) . ': ' . $subject;
        }

        $category = 'sacramental';
        $priority = 'normal';
        $colorLabel = '#7c3aed';
        $recurrenceRule = 'none';
        $assignedPersonnel = $sacramentalRecord['officiating_priest'] ?? '';
        $visibility = 'public';
        $approvalStatus = 'approved';
        $status = 'upcoming';
        $reminderMinutes = 30;
        $notifyEmail = 1;
        $notifySms = 0;
        $sourceType = 'request';

        // Check if calendar schedule event already exists
        $stmt = $this->conn->prepare("SELECT schedule_id FROM schedule_events WHERE source_type = 'request' AND source_id = ? LIMIT 1");
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            $scheduleId = intval($existing['schedule_id']);
            $upd = $this->conn->prepare("
                UPDATE schedule_events
                SET title = ?, description = ?, event_date = ?, start_time = ?, end_time = ?, location = ?,
                    category = ?, priority = ?, color_label = ?, recurrence_rule = ?, assigned_personnel = ?,
                    visibility = ?, approval_status = ?, status = ?, reminder_minutes = ?, notify_email = ?, notify_sms = ?,
                    updated_at = NOW()
                WHERE schedule_id = ?
            ");
            $upd->bind_param(
                'ssssssssssssssiiii',
                $title,
                $description,
                $eventDate,
                $startTime,
                $endTime,
                $location,
                $category,
                $priority,
                $colorLabel,
                $recurrenceRule,
                $assignedPersonnel,
                $visibility,
                $approvalStatus,
                $status,
                $reminderMinutes,
                $notifyEmail,
                $notifySms,
                $scheduleId
            );
            $upd->execute();
            $upd->close();
        } else {
            $ins = $this->conn->prepare("
                INSERT INTO schedule_events
                (title, description, event_date, start_time, end_time, location, category, priority, color_label,
                 recurrence_rule, assigned_personnel, visibility, approval_status, status, reminder_minutes,
                 notify_email, notify_sms, source_type, source_id, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->bind_param(
                'ssssssssssssssiiisii',
                $title,
                $description,
                $eventDate,
                $startTime,
                $endTime,
                $location,
                $category,
                $priority,
                $colorLabel,
                $recurrenceRule,
                $assignedPersonnel,
                $visibility,
                $approvalStatus,
                $status,
                $reminderMinutes,
                $notifyEmail,
                $notifySms,
                $sourceType,
                $requestId,
                $actorUserId
            );
            $ins->execute();
            $scheduleId = $ins->insert_id;
            $ins->close();
        }

        // Also ensure any linked reservation row is updated to approved
        $resUpd = $this->conn->prepare("UPDATE reservations SET status = 'approved', updated_at = NOW() WHERE request_id = ?");
        if ($resUpd) {
            $resUpd->bind_param('i', $requestId);
            $resUpd->execute();
            $resUpd->close();
        }

        return [
            'schedule_id' => $scheduleId,
            'title' => $title,
            'event_date' => $eventDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'location' => $location,
            'status' => 'approved'
        ];
    }

    /**
     * Action 3: Dispatches user in-app and external notifications.
     */
    private function notifyParishionerOnApproval(array $request, array $calendarEvent, string $adminResponse): void {
        $userId = intval($request['user_id']);
        if ($userId <= 0) {
            return;
        }

        $requestType = strtolower(trim((string) ($request['request_type'] ?? '')));
        $serviceLabel = match ($requestType) {
            'baptism_service', 'baptism' => 'Baptism',
            'marriage_wedding_service', 'marriage' => 'Marriage / Wedding',
            'funeral_mass', 'funeral' => 'Funeral Mass',
            'first_communion_service', 'first_communion', 'communion' => 'First Communion',
            'confirmation_service', 'confirmation' => 'Confirmation',
            'anointing_of_the_sick' => 'Anointing of the Sick',
            'patronal_fiesta' => 'Patronal Fiesta',
            default => ucwords(str_replace('_', ' ', $requestType))
        };

        $eventDate = $calendarEvent['event_date'] ?? date('Y-m-d');
        $startTime = $calendarEvent['start_time'] ?? '09:00:00';

        $formattedDate = date('F d, Y', strtotime($eventDate));
        $formattedTime = date('g:i A', strtotime($startTime));

        $statusWord = ($request['status'] ?? '') === 'completed' ? 'completed' : 'approved';
        $message = "Your request for {$serviceLabel} on {$formattedDate} at {$formattedTime} has been {$statusWord} and added to the official parish schedule.";
        $title = "Request " . ucfirst($statusWord) . ": {$serviceLabel}";

        // 1. Direct in-app notification insertion
        $requestId = intval($request['request_id'] ?? 0);
        $ins = $this->conn->prepare("INSERT INTO notifications (user_id, notification_type, title, message, entity_type, entity_id, action_key, state, is_read) VALUES (?, 'request', ?, ?, 'request', ?, 'request.view', 'unread', 0)");
        if ($ins) {
            $ins->bind_param('issi', $userId, $title, $message, $requestId);
            $ins->execute();
            $ins->close();
        }

        // 2. Multi-channel notification delivery (handles email & SMS preference check)
        dispatchNotificationDelivery($this->conn, $userId, $title, $message, 'requests');
    }

    /**
     * Generates a sequential, clean registry number.
     */
    private function generateRegistryNumber(string $prefix): string {
        $year = date('Y');
        $pattern = $prefix . '-' . $year . '-%';

        $table = match ($prefix) {
            'BAP' => 'baptism_records',
            'MAR' => 'marriage_records',
            'FUN' => 'funeral_records',
            'COM' => 'first_communion_records',
            'CNF' => 'confirmation_records',
            default => 'baptism_records'
        };

        $stmt = $this->conn->prepare("SELECT COUNT(*) AS total FROM {$table} WHERE registry_no LIKE ?");
        $stmt->bind_param('s', $pattern);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $seq = intval($res['total'] ?? 0) + 1;
        return sprintf("%s-%s-%04d", $prefix, $year, $seq);
    }

    /**
     * Parses Pre-Baptismal sheet details from request description.
     */
    private function parseBaptismDescription(string $desc, array $request): array {
        $fullname = $this->extractField($desc, ['Name of Child', 'Child Name', 'Child', 'Full Name']);
        if ($fullname === '' && !empty($request['record_holder_name'])) {
            $fullname = trim((string)$request['record_holder_name']);
        }

        $birthDate = $this->extractField($desc, ['Date of Birth', 'Birth Date', 'DOB']);
        if (!validDateValue($birthDate)) {
            $birthDate = null;
        }

        $birthPlace = $this->extractField($desc, ['Place of Birth', 'Birth Place']);
        $birthStatus = $this->extractField($desc, ["Parents' Marriage Status", 'Parents Marriage Status', 'Marriage Status']);

        $baptismDate = $this->extractField($desc, ['Date of Baptism', 'Baptism Date', 'Preferred date']);
        if (!validDateValue($baptismDate)) {
            $baptismDate = date('Y-m-d');
        }

        $fatherName = $this->extractField($desc, ['Father', "Father's Complete Name", 'Father Name']);
        $fatherOrigin = $this->extractField($desc, ['Father Place of Origin', "Father's Place of Origin", 'Father Origin']);
        $motherName = $this->extractField($desc, ['Mother', "Mother's Complete Maiden Name", 'Mother Name']);
        $motherOrigin = $this->extractField($desc, ['Mother Place of Origin', "Mother's Place of Origin", 'Mother Origin']);

        $parents = trim(($fatherName ? "Father: {$fatherName}" : "") . ($motherName ? " | Mother: {$motherName}" : ""));
        if (function_exists('format_baptism_parents')) {
            $parents = format_baptism_parents($parents);
        }
        $parentAddress = trim(($fatherOrigin ? "Father Origin: {$fatherOrigin}" : "") . ($motherOrigin ? " | Mother Origin: {$motherOrigin}" : ""));

        $maleSponsor = $this->extractField($desc, ['Principal Male Sponsor (Ninong)', 'Male Sponsor', 'Ninong']);
        $femaleSponsor = $this->extractField($desc, ['Principal Female Sponsor (Ninang)', 'Female Sponsor', 'Ninang']);
        $additionalSponsors = $this->extractField($desc, ['Additional Sponsors', 'Godparents']);

        $godparents = trim(
            ($maleSponsor ? "Ninong: {$maleSponsor}" : "") .
            ($femaleSponsor ? " | Ninang: {$femaleSponsor}" : "") .
            ($additionalSponsors ? " | {$additionalSponsors}" : "")
        );
        if (function_exists('format_baptism_sponsors')) {
            $godparents = format_baptism_sponsors($godparents);
        }

        $details = $this->extractField($desc, ['Details', 'Additional Details']);

        return [
            'fullname' => $fullname,
            'birth_date' => $birthDate,
            'birth_place' => $birthPlace,
            'birth_status' => $birthStatus ?: 'Church Wedding',
            'baptism_date' => $baptismDate,
            'parents' => $parents ?: 'Information on file',
            'parent_address' => $parentAddress ?: 'San Lorenzo Ruiz Parish',
            'godparents' => $godparents ?: 'Ninong & Ninang on file',
            'remarks' => $details ?: 'Migrated upon request approval'
        ];
    }

    /**
     * Parses Pre-Nuptial sheet details from request description.
     */
    private function parseMarriageDescription(string $desc, array $request): array {
        $groomSection = '';
        $brideSection = '';
        if (preg_match('/1\.\s*Groom.*?(?=2\.\s*Bride|$)/is', $desc, $gm)) {
            $groomSection = $gm[0];
        }
        if (preg_match('/2\.\s*Bride.*?(?=3\.|$)/is', $desc, $bm)) {
            $brideSection = $bm[0];
        }

        // Extract groom details
        $groomName = $this->extractField($groomSection ?: $desc, ['Full Name', "Groom's Full Name", "Groom Name", "Groom"]);
        if ($groomName === '' && preg_match('/(?:Groom.*?Name|Groom):\s*([^\r\n|]+)/i', $desc, $m)) {
            $groomName = trim($m[1]);
        }

        $groomBirthDate = null;
        if (preg_match('/(?:Groom.*?Date of Birth|Date of Birth):\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/i', $groomSection ?: $desc, $m)) {
            $groomBirthDate = trim($m[1]);
        }
        $groomBirthPlace = '';
        if (preg_match('/Place of Birth:\s*([^\r\n|]+)/i', $groomSection ?: $desc, $m)) {
            $groomBirthPlace = trim($m[1]);
        }
        $groomResidence = $this->extractField($groomSection ?: $desc, ['Place of Origin / Current Residence', "Groom's Place of Origin", "Groom Residence", "Groom's Place of Birth"]);
        $groomReligion = $this->extractField($groomSection ?: $desc, ['Religion / Church of Baptism', "Groom's Religion", "Groom Religion"]);
        
        $groomParents = '';
        if (preg_match('/Father:\s*([^\r\n|]+)\s*\|\s*Mother:\s*([^\r\n|]+)/i', $groomSection, $pm)) {
            $groomParents = 'Father: ' . trim($pm[1]) . ' | Mother: ' . trim($pm[2]);
        }

        // Extract bride details
        $brideName = $this->extractField($brideSection ?: $desc, ['Full Maiden Name', "Bride's Full Maiden Name", "Bride's Full Name", "Bride Name", "Bride"]);
        if ($brideName === '' && preg_match('/(?:Bride.*?Name|Bride):\s*([^\r\n|]+)/i', $desc, $m)) {
            $brideName = trim($m[1]);
        }

        $brideBirthDate = null;
        if (preg_match('/(?:Bride.*?Date of Birth|Date of Birth):\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/i', $brideSection ?: $desc, $m)) {
            $brideBirthDate = trim($m[1]);
        }
        $brideBirthPlace = '';
        if (preg_match('/Place of Birth:\s*([^\r\n|]+)/i', $brideSection ?: $desc, $m)) {
            $brideBirthPlace = trim($m[1]);
        }
        $brideResidence = $this->extractField($brideSection ?: $desc, ['Place of Origin / Current Residence', "Bride's Place of Origin", "Bride Residence", "Bride's Place of Birth"]);
        $brideReligion = $this->extractField($brideSection ?: $desc, ['Religion / Church of Baptism', "Bride's Religion", "Bride Religion"]);

        $brideParents = '';
        if (preg_match('/Father:\s*([^\r\n|]+)\s*\|\s*Mother:\s*([^\r\n|]+)/i', $brideSection, $pm)) {
            $brideParents = 'Father: ' . trim($pm[1]) . ' | Mother: ' . trim($pm[2]);
        }

        $weddingDate = $this->extractField($desc, ['Date of Marriage', 'Wedding Date', 'Preferred date']);
        if (!validDateValue($weddingDate)) {
            $weddingDate = date('Y-m-d', strtotime('+1 month'));
        }

        // Calculate ages
        $groomAge = '';
        if ($groomBirthDate && validDateValue($groomBirthDate)) {
            $groomAge = (string) date_diff(date_create($groomBirthDate), date_create($weddingDate))->y;
        }
        $wifeAge = '';
        if ($brideBirthDate && validDateValue($brideBirthDate)) {
            $wifeAge = (string) date_diff(date_create($brideBirthDate), date_create($weddingDate))->y;
        }

        $maleWitness = $this->extractField($desc, ['Male Principal Sponsor', 'Witness Male']);
        $femaleWitness = $this->extractField($desc, ['Female Principal Sponsor', 'Witness Female']);
        $additionalSponsors = $this->extractField($desc, ['Additional Sponsors / Entourage', 'Additional Sponsors']);

        $sponsors = trim(
            ($maleWitness ? "Ninong: {$maleWitness}" : "") .
            ($femaleWitness ? " | Ninang: {$femaleWitness}" : "") .
            ($additionalSponsors ? " | {$additionalSponsors}" : "")
        );

        $location = $this->extractField($desc, ['Location', 'Wedding Venue']);
        $details = $this->extractField($desc, ['Details', 'Additional Details']);

        return [
            'husband_name' => $groomName ?: 'Groom on file',
            'husband_birth_date' => $groomBirthDate,
            'husband_status' => $groomReligion ?: 'Roman Catholic',
            'husband_age' => $groomAge,
            'husband_birth_origin' => $groomBirthPlace ?: ($groomResidence ?: 'Philippines'),
            'husband_residence' => $groomResidence ?: 'Manila',
            'husband_parents' => $groomParents ?: 'Parents on file',
            'wife_name' => $brideName ?: 'Bride on file',
            'wife_birth_date' => $brideBirthDate,
            'wife_status' => $brideReligion ?: 'Roman Catholic',
            'wife_age' => $wifeAge,
            'wife_birth_origin' => $brideBirthPlace ?: ($brideResidence ?: 'Philippines'),
            'wife_residence' => $brideResidence ?: 'Manila',
            'wife_parents' => $brideParents ?: 'Parents on file',
            'wedding_date' => $weddingDate,
            'wedding_location' => $location ?: 'San Lorenzo Ruiz Parish Church',
            'sponsors' => $sponsors ?: 'Principal Witnesses on file',
            'witnesses_residence' => 'Residence on file',
            'remarks' => $details ?: 'Migrated upon request approval'
        ];
    }

    /**
     * Parses Funeral mass details from request description.
     */
    private function parseFuneralDescription(string $desc, array $request): array {
        // 1. Try to extract from the structured Funeral Investigation Sheet block first
        $deceasedName   = $this->extractField($desc, ['Deceased Full Name', 'Deceased Name', 'Deceased', 'Name of Deceased']);
        $deathDate      = $this->extractField($desc, ['Date of Death', 'Death Date']);
        $burialDate     = $this->extractField($desc, ['Date of Burial', 'Date of Funeral', 'Preferred date']);
        $civilStatus    = $this->extractField($desc, ['Civil Status']);
        $funeralRites   = $this->extractField($desc, ['Type of Funeral Rites', 'Funeral Rites']);
        $causeOfDeath   = $this->extractField($desc, ['Cause of Death']);
        $burialPlace    = $this->extractField($desc, ['Place of Burial', 'Cemetery', 'Location']);
        $minister       = $this->extractField($desc, ['Minister / Officiant Name', 'Minister', 'Officiant']);
        $familyContact  = $this->extractField($desc, ['Surviving Family', 'Family Contact', 'Contact Person']);
        $age            = $this->extractField($desc, ['Age', 'Deceased Age', 'Age at Death']);
        $residence      = $this->extractField($desc, ['Residence', 'Address', 'Place of Origin']);
        $details        = $this->extractField($desc, ['Details', 'Additional Details']);

        // 2. Normalization and fallback only for non-critical/inferred fields
        if ($deceasedName === '' && !empty($request['record_holder_name'])) {
            $deceasedName = trim((string)$request['record_holder_name']);
        }
        // IMPORTANT: The deceased person is NEVER the requesting applicant.
        // Never fall back to $request['applicant_fullname'] for deceased_name.
        if (!validDateValue($burialDate) && !empty($request['preferred_date']) && validDateValue($request['preferred_date'])) {
            $burialDate = $request['preferred_date'];
        }
        if ($burialPlace === '' && !empty($request['location'])) {
            $burialPlace = $request['location'];
        }
        if ($causeOfDeath === '') {
            $causeOfDeath = 'Not specified';
        }
        if ($funeralRites === '') {
            $funeralRites = 'Full Catholic Rites';
        }


        $remarksParts = [];
        if ($age !== '') {
            $remarksParts[] = "Age: {$age}";
        }
        if ($residence !== '') {
            $remarksParts[] = "Residence: {$residence}";
        }
        if ($details !== '') {
            $remarksParts[] = $details;
        } else {
            $remarksParts[] = 'Migrated upon request approval';
        }
        $remarks = implode(' | ', $remarksParts);

        // If minister is provided in the sheet, use it; otherwise fall back to the $priest param from the caller
        // (the actual minister used is passed separately via $priest; we store it for reference in remarks only)
        return [
            'deceased_name'  => $deceasedName,
            'family_name'    => $familyContact ?: null,
            'date_of_death'  => $deathDate,
            'date_of_burial' => $burialDate,
            'civil_status'   => $civilStatus ?: null,
            'funeral_rites'  => $funeralRites ?: null,
            'cause_of_death' => $causeOfDeath,
            'place_of_burial'=> $burialPlace,
            // If parishioner specified a preferred minister, store it; the $priest caller arg overrides only if blank
            'preferred_minister' => $minister,
            'remarks'        => $remarks
        ];
    }

    /**
     * Parses First Communion details from request description.
     */
    private function parseCommunionDescription(string $desc, array $request): array {
        $fullname = $this->extractField($desc, ['Name of Communicant', 'Communicant Name', 'Communicant', 'Full Name']);
        if ($fullname === '' && !empty($request['record_holder_name'])) {
            $fullname = trim((string)$request['record_holder_name']);
        }
        if ($fullname === '' && !empty($request['applicant_fullname'])) {
            $fullname = trim((string)$request['applicant_fullname']);
        }
        $fullname = mb_strtoupper($fullname, 'UTF-8');

        $communionDate = $this->extractField($desc, ['Preferred date', 'Date of First Communion', 'Communion Date', 'Date']);
        if (!validDateValue($communionDate) && !empty($request['preferred_date']) && validDateValue($request['preferred_date'])) {
            $communionDate = $request['preferred_date'];
        }
        if (!validDateValue($communionDate)) {
            $communionDate = date('Y-m-d');
        }

        $domicile = $this->extractField($desc, ['Domicile', 'Address', 'Residence']);
        $fatherName = $this->extractField($desc, ["Father's Complete Name", 'Father Name', 'Father']);
        $motherName = $this->extractField($desc, ["Mother's Complete Maiden Name", 'Mother Name', 'Mother']);
        $parents = $this->extractField($desc, ['Parents']);
        if ($parents === '') {
            if ($fatherName !== '' && $motherName !== '') {
                $parents = mb_strtoupper($fatherName . ' / ' . $motherName, 'UTF-8');
            } elseif ($fatherName !== '') {
                $parents = mb_strtoupper($fatherName, 'UTF-8');
            } elseif ($motherName !== '') {
                $parents = mb_strtoupper($motherName, 'UTF-8');
            }
        } else {
            $parents = mb_strtoupper($parents, 'UTF-8');
        }

        $minister = $this->extractField($desc, ['Minister', 'Officiating Priest', 'Priest']);
        if (strcasecmp($minister, 'Parish office will assign') === 0) {
            $minister = '';
        }

        $baptismalDate = $this->extractField($desc, ['Baptismal Date', 'Date of Baptism']);
        if (!validDateValue($baptismalDate)) {
            $baptismalDate = null;
        }
        $baptismalPlace = $this->extractField($desc, ['Baptismal Place', 'Place of Baptism']);
        $details = $this->extractField($desc, ['Details', 'Additional Details', 'Remarks']);

        return [
            'fullname' => $fullname,
            'communion_date' => $communionDate,
            'domicile' => $domicile ?: null,
            'father_name' => $fatherName ? mb_strtoupper($fatherName, 'UTF-8') : null,
            'mother_name' => $motherName ? mb_strtoupper($motherName, 'UTF-8') : null,
            'parents' => $parents ?: null,
            'preferred_minister' => $minister,
            'baptismal_date' => $baptismalDate,
            'baptismal_place' => $baptismalPlace ?: null,
            'remarks' => $details ?: 'Migrated upon request approval'
        ];
    }

    /**
     * Parses Confirmation details from request description.
     */
    private function parseConfirmationDescription(string $desc, array $request): array {
        $fullname = $this->extractField($desc, ['Name of Confirmed Person', 'Confirmed Person', 'Full Name']);
        if ($fullname === '' && !empty($request['record_holder_name'])) {
            $fullname = trim((string)$request['record_holder_name']);
        }
        if ($fullname === '' && !empty($request['applicant_fullname'])) {
            $fullname = trim((string)$request['applicant_fullname']);
        }
        $fullname = mb_strtoupper($fullname, 'UTF-8');

        $confirmationDate = $this->extractField($desc, ['Preferred date', 'Date of Confirmation', 'Confirmation Date', 'Date']);
        if (!validDateValue($confirmationDate) && !empty($request['preferred_date']) && validDateValue($request['preferred_date'])) {
            $confirmationDate = $request['preferred_date'];
        }
        if (!validDateValue($confirmationDate)) {
            $confirmationDate = date('Y-m-d');
        }

        $age = $this->extractField($desc, ['Age']);
        $originParish = $this->extractField($desc, ['Parish of Origin', 'Origin Parish']);
        $originProvince = $this->extractField($desc, ['Province', 'Origin Province']) ?: 'Cotabato';
        $baptismalPlace = $this->extractField($desc, ['Place of Baptism', 'Baptismal Place']);
        $fatherName = $this->extractField($desc, ["Father's Complete Name", 'Father Name', 'Father']);
        $motherName = $this->extractField($desc, ["Mother's Complete Maiden Name", 'Mother Name', 'Mother']);
        $parents = $this->extractField($desc, ['Parents']);
        if ($parents === '') {
            if ($fatherName !== '' && $motherName !== '') {
                $parents = mb_strtoupper($fatherName . ' / ' . $motherName, 'UTF-8');
            } elseif ($fatherName !== '') {
                $parents = mb_strtoupper($fatherName, 'UTF-8');
            } elseif ($motherName !== '') {
                $parents = mb_strtoupper($motherName, 'UTF-8');
            }
        } else {
            $parents = mb_strtoupper($parents, 'UTF-8');
        }

        $sponsor = $this->extractField($desc, ['Sponsor / Godparent', 'Sponsor', 'Godparent']);
        $details = $this->extractField($desc, ['Details', 'Additional Details', 'Observations', 'Remarks']);

        return [
            'fullname' => $fullname,
            'confirmation_date' => $confirmationDate,
            'age' => $age ?: null,
            'origin_parish' => $originParish ?: null,
            'origin_province' => $originProvince,
            'baptismal_place' => $baptismalPlace ?: null,
            'father_name' => $fatherName ? mb_strtoupper($fatherName, 'UTF-8') : null,
            'mother_name' => $motherName ? mb_strtoupper($motherName, 'UTF-8') : null,
            'parents' => $parents ?: null,
            'sponsor' => $sponsor ? mb_strtoupper($sponsor, 'UTF-8') : null,
            'observations' => $details ?: 'Migrated upon request approval'
        ];
    }

    /**
     * Extracts a key-value field from structured text.
     */
    private function extractField(string $text, array $labels): string {
        foreach ($labels as $label) {
            $pattern = '/(?:^|\n|\r)\s*' . preg_quote($label, '/') . '\s*[:\-]\s*([^\r\n]+)/i';
            if (preg_match($pattern, $text, $matches)) {
                $val = trim($matches[1]);
                if ($val !== '') {
                    return $val;
                }
            }
        }
        return '';
    }

    /**
     * Retrieves the default priest name from system settings or fallback.
     */
    private function getDefaultOfficiatingPriest(): string {
        $res = $this->conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'parish_priest' LIMIT 1");
        if ($res && $row = $res->fetch_assoc()) {
            if (!empty($row['setting_value'])) {
                return trim($row['setting_value']);
            }
        }
        return 'Rev. Fr. Parish Priest';
    }
}
