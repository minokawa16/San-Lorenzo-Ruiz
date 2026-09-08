<?php
/**
 * Organization Service - Manages the canonical 5-tier Parish Organizational Hierarchy.
 *
 * Tier 1: Parish Priest (Pastoral & Canonical Head) - Single occupant
 * Tier 2: Assistant Priest / Parochial Vicar - 0 or more occupants
 * Tier 3: Parish Secretary (Office & Sacramental Operations) - Single occupant
 * Tier 4: Parish Pastoral Council (PPC) Executive Board - Peer group
 * Tier 5: Ministry Coordinators - Dynamic peer group (Custom roles with full CRUD)
 *
 * Enforces system immutability guards (Ranks 1-4 cannot be deleted/archived),
 * vacancy states, term dates, and audit trail compliance.
 */

require_once __DIR__ . '/../includes/audit.php';

final class OrganizationService
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Retrieve the structured 5-tier organizational hierarchy tree.
     */
    public function getHierarchyTree(): array
    {
        $sql = "
            SELECT 
                p.position_id,
                p.title AS position_title,
                p.rank_level,
                p.display_order,
                p.is_system_role,
                p.max_occupants,
                p.role_code,
                p.description AS position_description,
                p.status AS position_status,
                pa.assignment_id,
                pa.start_date,
                pa.end_date,
                pa.is_active AS assignment_is_active,
                pa.notes AS assignment_notes,
                m.member_id,
                m.user_id,
                m.title_prefix,
                m.full_name,
                m.email AS member_email,
                m.phone AS member_phone,
                m.photo_url,
                m.bio,
                u.profile_picture AS user_profile_picture
            FROM org_positions p
            LEFT JOIN position_assignments pa ON pa.position_id = p.position_id AND pa.is_active = 1
            LEFT JOIN org_members m ON m.member_id = pa.member_id AND m.status = 'active'
            LEFT JOIN users u ON u.id = m.user_id
            WHERE p.status = 'active'
            ORDER BY p.rank_level ASC, p.display_order ASC, p.position_id ASC
        ";

        $result = $this->db->query($sql);
        if (!$result) {
            throw new RuntimeException('Failed to load organizational hierarchy: ' . $this->db->error);
        }

        $positionsMap = [];
        while ($row = $result->fetch_assoc()) {
            $posId = (int)$row['position_id'];

            if (!isset($positionsMap[$posId])) {
                $positionsMap[$posId] = [
                    'position_id' => $posId,
                    'title' => $row['position_title'],
                    'rank_level' => (int)$row['rank_level'],
                    'display_order' => (int)$row['display_order'],
                    'is_system_role' => (bool)$row['is_system_role'],
                    'max_occupants' => (int)$row['max_occupants'],
                    'role_code' => $row['role_code'],
                    'description' => $row['position_description'],
                    'status' => $row['position_status'],
                    'occupants' => [],
                    'is_vacant' => true,
                ];
            }

            if (!empty($row['member_id'])) {
                $avatar = !empty($row['photo_url']) ? $row['photo_url'] : ($row['user_profile_picture'] ?? null);
                $positionsMap[$posId]['occupants'][] = [
                    'assignment_id' => (int)$row['assignment_id'],
                    'member_id' => (int)$row['member_id'],
                    'user_id' => $row['user_id'] ? (int)$row['user_id'] : null,
                    'title_prefix' => $row['title_prefix'],
                    'full_name' => $row['full_name'],
                    'display_name' => trim(($row['title_prefix'] ? $row['title_prefix'] . ' ' : '') . $row['full_name']),
                    'email' => $row['member_email'],
                    'phone' => $row['member_phone'],
                    'photo_url' => $avatar,
                    'bio' => $row['bio'],
                    'start_date' => $row['start_date'],
                    'end_date' => $row['end_date'],
                    'notes' => $row['assignment_notes'],
                ];
                $positionsMap[$posId]['is_vacant'] = false;
            }
        }
        $result->close();

        // Organize into canonical 5 tiers
        $tiers = [
            1 => ['title' => 'Parish Priest', 'rank' => 1, 'positions' => []],
            2 => ['title' => 'Assistant Priest (Parochial Vicar)', 'rank' => 2, 'positions' => []],
            3 => ['title' => 'Parish Secretary', 'rank' => 3, 'positions' => []],
            4 => ['title' => 'Parish Pastoral Council (PPC) Executive Board', 'rank' => 4, 'positions' => []],
            5 => ['title' => 'Ministry & Commission Coordinators', 'rank' => 5, 'positions' => []],
        ];

        foreach ($positionsMap as $pos) {
            $rank = $pos['rank_level'];
            if (isset($tiers[$rank])) {
                $tiers[$rank]['positions'][] = $pos;
            } else {
                $tiers[5]['positions'][] = $pos;
            }
        }

        return [
            'tier1' => $tiers[1]['positions'][0] ?? null,
            'tier2' => $tiers[2]['positions'],
            'tier3' => $tiers[3]['positions'][0] ?? null,
            'tier4' => $tiers[4]['positions'],
            'tier5' => $tiers[5]['positions'],
            'all_tiers' => $tiers,
        ];
    }

    /**
     * Get all active and archived positions for management tables.
     */
    public function getPositions(bool $includeArchived = false): array
    {
        $where = $includeArchived ? '' : "WHERE p.status = 'active'";
        $sql = "
            SELECT 
                p.*,
                COUNT(pa.assignment_id) AS active_occupants_count
            FROM org_positions p
            LEFT JOIN position_assignments pa ON pa.position_id = p.position_id AND pa.is_active = 1
            {$where}
            GROUP BY p.position_id
            ORDER BY p.rank_level ASC, p.display_order ASC
        ";
        $res = $this->db->query($sql);
        $list = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $list[] = $row;
            }
            $res->close();
        }
        return $list;
    }

    /**
     * Get single position by ID.
     */
    public function getPosition(int $positionId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM org_positions WHERE position_id = ? LIMIT 1');
        $stmt->bind_param('i', $positionId);
        $stmt->execute();
        $res = $stmt->get_result();
        $pos = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $pos;
    }

    /**
     * Get list of members.
     */
    public function getMembers(bool $includeArchived = false): array
    {
        $where = $includeArchived ? '' : "WHERE status = 'active'";
        $sql = "SELECT * FROM org_members {$where} ORDER BY full_name ASC";
        $res = $this->db->query($sql);
        $list = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $list[] = $row;
            }
            $res->close();
        }
        return $list;
    }

    /**
     * Assign a member to a position.
     * Enforces single occupant constraints (Ranks 1, 3, 4) by retiring prior active assignment.
     */
    public function assignMember(
        int $positionId,
        int $memberId,
        ?string $startDate,
        ?string $endDate,
        ?string $notes,
        int $actorId
    ): int {
        $pos = $this->getPosition($positionId);
        if (!$pos) {
            throw new DomainException('Target position not found.');
        }

        // Validate member exists
        $stmt = $this->db->prepare('SELECT member_id, title_prefix, full_name FROM org_members WHERE member_id = ? AND status = ? LIMIT 1');
        $statusActive = 'active';
        $stmt->bind_param('is', $memberId, $statusActive);
        $stmt->execute();
        $mem = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$mem) {
            throw new DomainException('Selected member does not exist or is inactive.');
        }

        $startDate = !empty($startDate) ? $startDate : date('Y-m-d');
        $endDate = !empty($endDate) ? $endDate : null;

        // If single occupant, archive existing active assignments for this position
        if ((int)$pos['max_occupants'] === 1) {
            $stmt = $this->db->prepare('UPDATE position_assignments SET is_active = 0, end_date = COALESCE(end_date, CURRENT_DATE()) WHERE position_id = ? AND is_active = 1');
            $stmt->bind_param('i', $positionId);
            $stmt->execute();
            $stmt->close();
        }

        // Insert new assignment
        $stmt = $this->db->prepare('
            INSERT INTO position_assignments (position_id, member_id, start_date, end_date, is_active, notes, assigned_by)
            VALUES (?, ?, ?, ?, 1, ?, ?)
        ');
        $stmt->bind_param('iisssi', $positionId, $memberId, $startDate, $endDate, $notes, $actorId);
        $stmt->execute();
        $newAssignmentId = $stmt->insert_id;
        $stmt->close();

        // Audit Trail
        writeAuditLog(
            $this->db,
            $actorId,
            'ASSIGN_ORG_POSITION',
            'position_assignments',
            $newAssignmentId,
            null,
            [
                'position_id' => $positionId,
                'position_title' => $pos['title'],
                'member_id' => $memberId,
                'member_name' => $mem['title_prefix'] . ' ' . $mem['full_name'],
                'start_date' => $startDate,
            ],
            'organization',
            null,
            null,
            "Assigned {$mem['title_prefix']} {$mem['full_name']} to position: {$pos['title']}.",
            'ACCOUNTS',
            'INFO',
            'org_positions',
            $positionId
        );

        return $newAssignmentId;
    }

    /**
     * Unassign a member from a position (transitioning position to vacant).
     */
    public function unassignMember(int $assignmentId, int $actorId, ?string $reason = null): bool
    {
        $stmt = $this->db->prepare('
            SELECT pa.*, p.title AS position_title, m.full_name, m.title_prefix 
            FROM position_assignments pa
            JOIN org_positions p ON p.position_id = pa.position_id
            JOIN org_members m ON m.member_id = pa.member_id
            WHERE pa.assignment_id = ? LIMIT 1
        ');
        $stmt->bind_param('i', $assignmentId);
        $stmt->execute();
        $assignment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$assignment) {
            throw new DomainException('Position assignment record not found.');
        }

        $now = date('Y-m-d');
        $notes = trim(($assignment['notes'] ? $assignment['notes'] . '; ' : '') . ($reason ? "Unassigned: {$reason}" : 'Term ended'));

        $stmt = $this->db->prepare('UPDATE position_assignments SET is_active = 0, end_date = COALESCE(end_date, ?), notes = ? WHERE assignment_id = ?');
        $stmt->bind_param('ssi', $now, $notes, $assignmentId);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            writeAuditLog(
                $this->db,
                $actorId,
                'UNASSIGN_ORG_POSITION',
                'position_assignments',
                $assignmentId,
                $assignment,
                ['is_active' => 0, 'end_date' => $now, 'notes' => $notes],
                'organization',
                null,
                null,
                "Vacated position: {$assignment['position_title']} (unassigned {$assignment['title_prefix']} {$assignment['full_name']}).",
                'ACCOUNTS',
                'INFO',
                'org_positions',
                (int)$assignment['position_id']
            );
        }

        return $ok;
    }

    /**
     * Direct fill-in-the-blank update for a position occupant.
     * No dropdowns needed: simply type the full name to assign or leave empty to vacate.
     */
    public function setOccupantDirect(int $positionId, string $occupantName, int $actorId): bool
    {
        $pos = $this->getPosition($positionId);
        if (!$pos) {
            throw new DomainException('Target position not found.');
        }

        $name = trim($occupantName);

        // If blank, vacate the position
        if ($name === '') {
            $stmt = $this->db->prepare('UPDATE position_assignments SET is_active = 0, end_date = CURRENT_DATE() WHERE position_id = ? AND is_active = 1');
            $stmt->bind_param('i', $positionId);
            $ok = $stmt->execute();
            $stmt->close();

            writeAuditLog(
                $this->db,
                $actorId,
                'VACATE_ORG_POSITION',
                'org_positions',
                $positionId,
                null,
                ['is_vacant' => true],
                'organization',
                null,
                null,
                "Vacated position: {$pos['title']}.",
                'ACCOUNTS',
                'INFO',
                'org_positions',
                $positionId
            );

            return $ok;
        }

        // Find or create member by full name
        $stmt = $this->db->prepare('SELECT member_id FROM org_members WHERE full_name = ? LIMIT 1');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $memRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($memRow) {
            $memberId = (int)$memRow['member_id'];
        } else {
            $prefix = '';
            if (preg_match('/^(Rev\.\s*Fr\.|Fr\.|Father)\s+/i', $name)) {
                $prefix = 'Rev. Fr.';
            } elseif (preg_match('/^(Bro\.|Brother)\s+/i', $name)) {
                $prefix = 'Bro.';
            } elseif (preg_match('/^(Sis\.|Sister)\s+/i', $name)) {
                $prefix = 'Sis.';
            }

            $stmt = $this->db->prepare('INSERT INTO org_members (title_prefix, full_name, status) VALUES (?, ?, "active")');
            $stmt->bind_param('ss', $prefix, $name);
            $stmt->execute();
            $memberId = $stmt->insert_id;
            $stmt->close();
        }

        // Retire previous active assignments for this position
        $stmt = $this->db->prepare('UPDATE position_assignments SET is_active = 0, end_date = CURRENT_DATE() WHERE position_id = ? AND is_active = 1');
        $stmt->bind_param('i', $positionId);
        $stmt->execute();
        $stmt->close();

        // Create new active assignment
        $startDate = date('Y-m-d');
        $notes = 'Direct name entry';
        $stmt = $this->db->prepare('
            INSERT INTO position_assignments (position_id, member_id, start_date, is_active, notes, assigned_by)
            VALUES (?, ?, ?, 1, ?, ?)
        ');
        $stmt->bind_param('iissi', $positionId, $memberId, $startDate, $notes, $actorId);
        $stmt->execute();
        $newAssignmentId = $stmt->insert_id;
        $stmt->close();

        writeAuditLog(
            $this->db,
            $actorId,
            'ASSIGN_ORG_POSITION',
            'position_assignments',
            $newAssignmentId,
            null,
            ['position_id' => $positionId, 'name' => $name],
            'organization',
            null,
            null,
            "Appointed '{$name}' to '{$pos['title']}'.",
            'ACCOUNTS',
            'INFO',
            'org_positions',
            $positionId
        );

        return true;
    }

    /**
     * Add a new Assistant Priest (Parochial Vicar) card slot (Rank 2).
     */
    public function addAssistantPriest(string $occupantName, int $actorId): int
    {
        $q = $this->db->query("SELECT MAX(display_order) AS m FROM org_positions WHERE rank_level = 2");
        $maxOrder = $q ? (int)($q->fetch_assoc()['m'] ?? 0) : 0;
        $displayOrder = $maxOrder + 1;

        $title = 'Parochial Vicar';
        $rankLevel = 2;
        $isSystemRole = 0; // Additional vicars can be removed
        $maxOccupants = 1;
        $desc = 'Assistant Pastoral & Liturgical Ministry';
        $status = 'active';

        $stmt = $this->db->prepare('
            INSERT INTO org_positions (title, rank_level, display_order, is_system_role, max_occupants, description, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->bind_param('siiiiss', $title, $rankLevel, $displayOrder, $isSystemRole, $maxOccupants, $desc, $status);
        $stmt->execute();
        $newId = $stmt->insert_id;
        $stmt->close();

        if (trim($occupantName) !== '') {
            $this->setOccupantDirect($newId, $occupantName, $actorId);
        }

        writeAuditLog(
            $this->db,
            $actorId,
            'ADD_ASSISTANT_PRIEST',
            'org_positions',
            $newId,
            null,
            ['title' => $title, 'display_order' => $displayOrder],
            'organization',
            null,
            null,
            "Added an Assistant Priest (Parochial Vicar) position card.",
            'SYSTEM',
            'INFO',
            'org_positions',
            $newId
        );

        return $newId;
    }

    /**
     * Remove an additional Assistant Priest card slot.
     * Guardrail: The primary core vicar (is_system_role = 1) cannot be deleted.
     */
    public function removeAssistantPriest(int $positionId, int $actorId): bool
    {
        $pos = $this->getPosition($positionId);
        if (!$pos) {
            throw new DomainException('Position not found.');
        }

        if ((int)$pos['rank_level'] !== 2) {
            throw new DomainException('Target position is not an Assistant Priest role.');
        }

        if ((int)$pos['is_system_role'] === 1) {
            throw new DomainException('The primary Assistant Priest role is a fixed system position. You can vacate it, but not delete it.');
        }

        // Deactivate assignments
        $stmt = $this->db->prepare('UPDATE position_assignments SET is_active = 0, end_date = CURRENT_DATE() WHERE position_id = ?');
        $stmt->bind_param('i', $positionId);
        $stmt->execute();
        $stmt->close();

        // Mark archived
        $stmt = $this->db->prepare("UPDATE org_positions SET status = 'archived' WHERE position_id = ?");
        $stmt->bind_param('i', $positionId);
        $ok = $stmt->execute();
        $stmt->close();

        writeAuditLog(
            $this->db,
            $actorId,
            'REMOVE_ASSISTANT_PRIEST',
            'org_positions',
            $positionId,
            null,
            ['status' => 'archived'],
            'organization',
            null,
            null,
            "Removed additional Assistant Priest position #{$positionId}.",
            'SYSTEM',
            'WARNING',
            'org_positions',
            $positionId
        );

        return $ok;
    }

    /**
     * Create a dynamic ministry role (Rank 5 only).
     */
    public function createMinistryRole(
        string $title,
        ?string $description,
        int $displayOrder,
        int $actorId
    ): int {
        $title = trim($title);
        if ($title === '') {
            throw new DomainException('Ministry role title cannot be empty.');
        }

        if ($displayOrder <= 0) {
            $q = $this->db->query("SELECT MAX(display_order) AS m FROM org_positions WHERE rank_level = 5");
            $maxOrder = $q ? (int)($q->fetch_assoc()['m'] ?? 0) : 0;
            $displayOrder = $maxOrder + 1;
        }

        $rankLevel = 5;
        $isSystemRole = 0;
        $maxOccupants = 1;
        $status = 'active';

        $stmt = $this->db->prepare('
            INSERT INTO org_positions (title, rank_level, display_order, is_system_role, max_occupants, description, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->bind_param('siiiiss', $title, $rankLevel, $displayOrder, $isSystemRole, $maxOccupants, $description, $status);
        $stmt->execute();
        $newId = $stmt->insert_id;
        $stmt->close();

        writeAuditLog(
            $this->db,
            $actorId,
            'CREATE_MINISTRY_ROLE',
            'org_positions',
            $newId,
            null,
            ['title' => $title, 'rank_level' => 5, 'display_order' => $displayOrder, 'description' => $description],
            'organization',
            null,
            null,
            "Created new dynamic ministry coordinator role: '{$title}'.",
            'SYSTEM',
            'INFO',
            'org_positions',
            $newId
        );

        return $newId;
    }

    /**
     * Update a dynamic ministry role title, description, and ordering.
     */
    public function updateMinistryRole(
        int $positionId,
        string $title,
        ?string $description,
        int $displayOrder,
        int $actorId
    ): bool {
        $pos = $this->getPosition($positionId);
        if (!$pos) {
            throw new DomainException('Position not found.');
        }

        $title = trim($title);
        if ($title === '') {
            throw new DomainException('Position title cannot be empty.');
        }

        $stmt = $this->db->prepare('UPDATE org_positions SET title = ?, description = ?, display_order = ? WHERE position_id = ?');
        $stmt->bind_param('ssii', $title, $description, $displayOrder, $positionId);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            writeAuditLog(
                $this->db,
                $actorId,
                'UPDATE_MINISTRY_ROLE',
                'org_positions',
                $positionId,
                $pos,
                ['title' => $title, 'description' => $description, 'display_order' => $displayOrder],
                'organization',
                null,
                null,
                "Updated ministry role '{$title}'.",
                'SYSTEM',
                'INFO',
                'org_positions',
                $positionId
            );
        }

        return $ok;
    }

    /**
     * Archive/Delete a dynamic ministry role.
     * HARD GUARDRAIL: Strict block on Ranks 1 to 4 (is_system_role = 1).
     */
    public function archiveMinistryRole(int $positionId, int $actorId): bool
    {
        $pos = $this->getPosition($positionId);
        if (!$pos) {
            throw new DomainException('Position not found.');
        }

        // HARD IMMUTABILITY GUARD
        if ((int)$pos['is_system_role'] === 1 || (int)$pos['rank_level'] < 5) {
            throw new DomainException('Forbidden: Core system roles (Ranks 1 to 4: Parish Priest, Vicar, Secretary, and PPC Board) cannot be deleted or archived.');
        }

        // Deactivate assignments
        $stmt = $this->db->prepare('UPDATE position_assignments SET is_active = 0, end_date = COALESCE(end_date, CURRENT_DATE()) WHERE position_id = ?');
        $stmt->bind_param('i', $positionId);
        $stmt->execute();
        $stmt->close();

        // Mark position as archived
        $stmt = $this->db->prepare("UPDATE org_positions SET status = 'archived' WHERE position_id = ?");
        $stmt->bind_param('i', $positionId);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            writeAuditLog(
                $this->db,
                $actorId,
                'ARCHIVE_MINISTRY_ROLE',
                'org_positions',
                $positionId,
                $pos,
                ['status' => 'archived'],
                'organization',
                null,
                null,
                "Archived custom ministry role: '{$pos['title']}'.",
                'SYSTEM',
                'WARNING',
                'org_positions',
                $positionId
            );
        }

        return $ok;
    }

    /**
     * Create or update member profile in `org_members`.
     */
    public function saveMember(array $data, int $actorId): int
    {
        $memberId = (int)($data['member_id'] ?? 0);
        $fullName = trim((string)($data['full_name'] ?? ''));
        $prefix = trim((string)($data['title_prefix'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));
        $bio = trim((string)($data['bio'] ?? ''));
        $photoUrl = trim((string)($data['photo_url'] ?? ''));
        $userId = !empty($data['user_id']) ? (int)$data['user_id'] : null;

        if ($fullName === '') {
            throw new DomainException('Member full name is required.');
        }

        if ($memberId > 0) {
            // Update
            $stmt = $this->db->prepare('
                UPDATE org_members 
                SET user_id = ?, title_prefix = ?, full_name = ?, email = ?, phone = ?, bio = ?, photo_url = ?
                WHERE member_id = ?
            ');
            $stmt->bind_param('issssssi', $userId, $prefix, $fullName, $email, $phone, $bio, $photoUrl, $memberId);
            $stmt->execute();
            $stmt->close();

            writeAuditLog(
                $this->db,
                $actorId,
                'UPDATE_ORG_MEMBER',
                'org_members',
                $memberId,
                null,
                ['full_name' => $fullName, 'title_prefix' => $prefix],
                'organization',
                null,
                null,
                "Updated organization member profile: {$prefix} {$fullName}.",
                'ACCOUNTS',
                'INFO',
                'org_members',
                $memberId
            );

            return $memberId;
        } else {
            // Insert
            $stmt = $this->db->prepare('
                INSERT INTO org_members (user_id, title_prefix, full_name, email, phone, bio, photo_url, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, "active")
            ');
            $stmt->bind_param('issssss', $userId, $prefix, $fullName, $email, $phone, $bio, $photoUrl);
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();

            writeAuditLog(
                $this->db,
                $actorId,
                'CREATE_ORG_MEMBER',
                'org_members',
                $newId,
                null,
                ['full_name' => $fullName, 'title_prefix' => $prefix],
                'organization',
                null,
                null,
                "Registered new organization member: {$prefix} {$fullName}.",
                'ACCOUNTS',
                'INFO',
                'org_members',
                $newId
            );

            return $newId;
        }
    }
}
