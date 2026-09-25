<?php
/**
 * SacramentalRecordMatcher Service
 *
 * Automatically links certificate requests to sacramental records (Baptism,
 * First Communion, Confirmation, Marriage, Funeral) based on requester's full name
 * and supporting identifiers (Date of Birth, Sacrament Date, Parents' Names).
 */

class SacramentalRecordMatcher {

    /**
     * Map request types to canonical record types and database tables.
     */
    public static function mapRequestTypeToRecordType(string $requestType): ?string {
        $rt = strtolower(trim($requestType));
        if (in_array($rt, ['baptismal_certificate', 'baptism_certification', 'baptism', 'baptismal'], true)) {
            return 'baptism';
        }
        if (in_array($rt, ['first_communion_certificate', 'first_communion_certification', 'first_communion', 'communion', 'communion_certification'], true)) {
            return 'communion';
        }
        if (in_array($rt, ['confirmation_certificate', 'confirmation_certification', 'confirmation'], true)) {
            return 'confirmation';
        }
        if (in_array($rt, ['marriage_certification', 'marriage', 'marriage_certificate'], true)) {
            return 'marriage';
        }
        if (in_array($rt, ['funeral_certification', 'funeral', 'burial_certification', 'death_certification'], true)) {
            return 'funeral';
        }
        return null;
    }

    /**
     * Get table configuration for a canonical record type.
     */
    public static function getTableConfig(string $recordType): ?array {
        switch ($recordType) {
            case 'baptism':
                return [
                    'table' => 'baptism_records',
                    'id_col' => 'baptism_id',
                    'name_col' => 'fullname',
                    'date_col' => 'baptism_date',
                    'has_dob' => true,
                    'dob_col' => 'birth_date',
                    'father_col' => 'father_name',
                    'mother_col' => 'mother_name',
                    'priest_col' => 'priest',
                    'in_charge_col' => 'parish_priest',
                ];
            case 'communion':
                return [
                    'table' => 'first_communion_records',
                    'id_col' => 'communion_id',
                    'name_col' => 'fullname',
                    'date_col' => 'communion_date',
                    'has_dob' => true,
                    'dob_col' => 'birth_date',
                    'father_col' => 'father_name',
                    'mother_col' => 'mother_name',
                    'priest_col' => 'priest',
                    'in_charge_col' => 'parish_priest',
                ];
            case 'confirmation':
                return [
                    'table' => 'confirmation_records',
                    'id_col' => 'confirmation_id',
                    'name_col' => 'fullname',
                    'date_col' => 'confirmation_date',
                    'has_dob' => true,
                    'dob_col' => 'birth_date',
                    'father_col' => 'father_name',
                    'mother_col' => 'mother_name',
                    'priest_col' => 'bishop_priest',
                    'in_charge_col' => 'parish_priest',
                ];
            case 'marriage':
                return [
                    'table' => 'marriage_records',
                    'id_col' => 'marriage_id',
                    'name_col' => 'husband_name', // or wife_name
                    'date_col' => 'wedding_date',
                    'has_dob' => false,
                    'dob_col' => null,
                    'father_col' => 'husband_parents',
                    'mother_col' => 'wife_parents',
                    'priest_col' => 'officiating_priest',
                    'in_charge_col' => 'parish_priest',
                ];
            case 'funeral':
                return [
                    'table' => 'funeral_records',
                    'id_col' => 'funeral_id',
                    'name_col' => 'deceased_name',
                    'date_col' => 'date_of_burial',
                    'has_dob' => false,
                    'dob_col' => null,
                    'father_col' => 'father_name',
                    'mother_col' => 'mother_name',
                    'priest_col' => 'minister',
                    'in_charge_col' => 'parish_priest',
                ];
            default:
                return null;
        }
    }

    /**
     * Normalize a name by transliterating characters (e.g. ñ -> n), lowercasing,
     * stripping punctuation, and collapsing whitespace.
     */
    public static function normalizeName(string $name): string {
        $str = mb_strtolower(trim($name), 'UTF-8');
        // Transliterate accents
        $trans = [
            'ñ' => 'n', 'Ñ' => 'n',
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        ];
        $str = strtr($str, $trans);
        // Strip common suffixes for comparison if needed, or remove punctuation
        $str = preg_replace('/[.,\/#!$%\^&\*;:{}=\-_`~()]/', ' ', $str);
        $str = preg_replace('/\s+/', ' ', $str);
        return trim($str);
    }

    /**
     * Extract key tokens from a person's name (first, middle, last).
     */
    public static function tokenizeName(string $name): array {
        $clean = self::normalizeName($name);
        if ($clean === '') return [];
        return array_values(array_filter(explode(' ', $clean)));
    }

    /**
     * Compute name match similarity score (0 to 100).
     */
    public static function compareNames(string $name1, string $name2): float {
        $n1 = self::normalizeName($name1);
        $n2 = self::normalizeName($name2);

        if ($n1 === '' || $n2 === '') return 0.0;
        if ($n1 === $n2) return 100.0;

        $tokens1 = self::tokenizeName($name1);
        $tokens2 = self::tokenizeName($name2);

        if (empty($tokens1) || empty($tokens2)) return 0.0;

        $first1 = $tokens1[0];
        $first2 = $tokens2[0];
        $last1 = end($tokens1);
        $last2 = end($tokens2);

        // If first and last name tokens match exactly
        if ($first1 === $first2 && $last1 === $last2) {
            // Check middle tokens / initials
            if (count($tokens1) === 2 || count($tokens2) === 2) {
                // One has middle name, other doesn't: strong match
                return 90.0;
            }
            if (count($tokens1) >= 3 && count($tokens2) >= 3) {
                $mid1 = $tokens1[1];
                $mid2 = $tokens2[1];
                if ($mid1 === $mid2) {
                    return 98.0;
                }
                // Middle initial check (e.g. "c" vs "cantomayor")
                if ($mid1[0] === $mid2[0]) {
                    return 95.0;
                }
            }
            return 85.0;
        }

        // Levenshtein distance on full normalized string
        $maxLen = max(strlen($n1), strlen($n2));
        if ($maxLen > 0) {
            $lev = levenshtein($n1, $n2);
            $sim = ($maxLen - $lev) / $maxLen;
            if ($sim >= 0.85) {
                return $sim * 100.0;
            }
        }

        // Check if all tokens of one name exist in the other
        $intersect = array_intersect($tokens1, $tokens2);
        $minCount = min(count($tokens1), count($tokens2));
        if ($minCount >= 2 && count($intersect) >= $minCount) {
            return 85.0;
        }

        return 0.0;
    }

    /**
     * Search existing sacramental records for a match.
     *
     * @param mysqli $conn
     * @param string $requestType
     * @param string $recordHolderName
     * @param array $criteria Additional identifiers: birth_date, sacrament_date, father_name, mother_name
     * @return array ['status' => 'matched'|'multiple'|'no_match', 'record_id' => ?int, 'record' => ?array, 'candidates' => array]
     */
    public static function findMatches($conn, string $requestType, string $recordHolderName, array $criteria = []): array {
        $recType = self::mapRequestTypeToRecordType($requestType);
        if (!$recType) {
            return ['status' => 'no_match', 'record_type' => null, 'record_id' => null, 'record' => null, 'candidates' => []];
        }

        $config = self::getTableConfig($recType);
        if (!$config) {
            return ['status' => 'no_match', 'record_type' => $recType, 'record_id' => null, 'record' => null, 'candidates' => []];
        }

        $cleanTargetName = self::normalizeName($recordHolderName);
        if ($cleanTargetName === '') {
            return ['status' => 'no_match', 'record_type' => $recType, 'record_id' => null, 'record' => null, 'candidates' => []];
        }

        $tokens = self::tokenizeName($recordHolderName);
        $lastName = !empty($tokens) ? end($tokens) : '';
        $firstName = !empty($tokens) ? $tokens[0] : '';

        // Query candidate records from table
        $table = $config['table'];
        $idCol = $config['id_col'];
        $nameCol = $config['name_col'];

        $candidates = [];

        // 1. Broad candidate search: match on last name or full name LIKE
        $sql = "SELECT * FROM {$table} WHERE (status = 'active' OR status IS NULL OR status = '')";
        $whereClauses = [];
        $params = [];
        $types = '';

        if ($recType === 'marriage') {
            $whereClauses[] = "(husband_name LIKE ? OR wife_name LIKE ?)";
            $params[] = '%' . $lastName . '%';
            $params[] = '%' . $lastName . '%';
            $types .= 'ss';
        } else {
            if ($lastName !== '') {
                $whereClauses[] = "({$nameCol} LIKE ?)";
                $params[] = '%' . $lastName . '%';
                $types .= 's';
            }
            if ($firstName !== '' && $firstName !== $lastName) {
                $whereClauses[] = "({$nameCol} LIKE ?)";
                $params[] = '%' . $firstName . '%';
                $types .= 's';
            }
        }

        if (!empty($whereClauses)) {
            $sql .= " AND (" . implode(' OR ', $whereClauses) . ")";
        }
        $sql .= " ORDER BY {$idCol} ASC LIMIT 50";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if (!empty($types)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $candidates[] = $row;
            }
            $stmt->close();
        }

        // Also query by exact birth_date or sacrament_date if provided in criteria and no candidates yet
        if (empty($candidates) && (!empty($criteria['birth_date']) || !empty($criteria['sacrament_date']))) {
            $extraSql = "SELECT * FROM {$table} WHERE (status = 'active' OR status IS NULL OR status = '')";
            $extraParams = [];
            $extraTypes = '';
            if (!empty($criteria['birth_date']) && $config['has_dob']) {
                $extraSql .= " AND {$config['dob_col']} = ?";
                $extraParams[] = $criteria['birth_date'];
                $extraTypes .= 's';
            }
            if (!empty($criteria['sacrament_date']) && !empty($config['date_col'])) {
                $extraSql .= " AND {$config['date_col']} = ?";
                $extraParams[] = $criteria['sacrament_date'];
                $extraTypes .= 's';
            }
            $stmt2 = $conn->prepare($extraSql);
            if ($stmt2) {
                if (!empty($extraTypes)) {
                    $stmt2->bind_param($extraTypes, ...$extraParams);
                }
                $stmt2->execute();
                $res2 = $stmt2->get_result();
                while ($row = $res2->fetch_assoc()) {
                    $candidates[] = $row;
                }
                $stmt2->close();
            }
        }

        if (empty($candidates)) {
            return ['status' => 'no_match', 'record_type' => $recType, 'record_id' => null, 'record' => null, 'candidates' => []];
        }

        // Score each candidate
        $scored = [];
        $targetDob = !empty($criteria['birth_date']) ? trim($criteria['birth_date']) : null;
        $targetSacramentDate = !empty($criteria['sacrament_date']) ? trim($criteria['sacrament_date']) : null;
        $targetFather = !empty($criteria['father_name']) ? self::normalizeName($criteria['father_name']) : null;
        $targetMother = !empty($criteria['mother_name']) ? self::normalizeName($criteria['mother_name']) : null;

        foreach ($candidates as $cand) {
            $candName = ($recType === 'marriage')
                ? ($cand['husband_name'] . ' ' . $cand['wife_name'])
                : ($cand[$nameCol] ?? '');

            $nameScore = self::compareNames($recordHolderName, $candName);
            if ($nameScore < 75.0) {
                // If marriage, also test against individual husband or wife name
                if ($recType === 'marriage') {
                    $hScore = self::compareNames($recordHolderName, $cand['husband_name'] ?? '');
                    $wScore = self::compareNames($recordHolderName, $cand['wife_name'] ?? '');
                    $nameScore = max($hScore, $wScore);
                }
            }

            if ($nameScore < 75.0) {
                continue; // Not a plausible name match
            }

            $score = $nameScore;
            $reasons = ["Name similarity: " . round($nameScore, 1) . "%"];

            // Cross-check Date of Birth
            if ($targetDob && $config['has_dob'] && !empty($cand[$config['dob_col']])) {
                $candDob = trim($cand[$config['dob_col']]);
                if ($candDob !== '0000-00-00') {
                    if ($candDob === $targetDob) {
                        $score += 30.0;
                        $reasons[] = "Matching Date of Birth ($targetDob)";
                    } else {
                        $score -= 40.0;
                        $reasons[] = "Conflicting Date of Birth ($candDob vs $targetDob)";
                    }
                }
            }

            // Cross-check Sacrament Date
            if ($targetSacramentDate && !empty($config['date_col']) && !empty($cand[$config['date_col']])) {
                $candDate = trim($cand[$config['date_col']]);
                if ($candDate !== '0000-00-00') {
                    if ($candDate === $targetSacramentDate) {
                        $score += 30.0;
                        $reasons[] = "Matching Sacrament Date ($targetSacramentDate)";
                    } else {
                        $score -= 30.0;
                        $reasons[] = "Conflicting Sacrament Date ($candDate vs $targetSacramentDate)";
                    }
                }
            }

            // Cross-check Father's Name
            if ($targetFather && !empty($config['father_col']) && !empty($cand[$config['father_col']])) {
                $fScore = self::compareNames($criteria['father_name'], $cand[$config['father_col']]);
                if ($fScore >= 80.0) {
                    $score += 20.0;
                    $reasons[] = "Matching Father's Name (" . $cand[$config['father_col']] . ")";
                }
            }

            // Cross-check Mother's Name
            if ($targetMother && !empty($config['mother_col']) && !empty($cand[$config['mother_col']])) {
                $mScore = self::compareNames($criteria['mother_name'], $cand[$config['mother_col']]);
                if ($mScore >= 80.0) {
                    $score += 20.0;
                    $reasons[] = "Matching Mother's Name (" . $cand[$config['mother_col']] . ")";
                }
            }

            if ($score >= 80.0) {
                $candId = intval($cand[$idCol]);
                $candSummary = [
                    'id' => $candId,
                    'record_type' => $recType,
                    'fullname' => $cand[$nameCol] ?? '',
                    'sacrament_date' => $cand[$config['date_col']] ?? null,
                    'birth_date' => $config['has_dob'] ? ($cand[$config['dob_col']] ?? null) : null,
                    'father_name' => $cand[$config['father_col']] ?? null,
                    'mother_name' => $cand[$config['mother_col']] ?? null,
                    'book_no' => $cand['book_no'] ?? null,
                    'page_no' => $cand['page_no'] ?? null,
                    'entry_no' => $cand['entry_no'] ?? null,
                    'priest' => $cand[$config['priest_col']] ?? null,
                    'parish_priest' => $cand[$config['in_charge_col']] ?? null,
                    'score' => round($score, 1),
                    'reasons' => $reasons,
                    'raw_record' => $cand
                ];
                $scored[] = $candSummary;
            }
        }

        // Sort descending by score
        usort($scored, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        if (empty($scored)) {
            return [
                'status' => 'no_match',
                'record_type' => $recType,
                'record_id' => null,
                'record' => null,
                'candidates' => []
            ];
        }

        // Check if multiple matches exist with similar high scores
        if (count($scored) === 1) {
            return [
                'status' => 'matched',
                'record_type' => $recType,
                'record_id' => $scored[0]['id'],
                'record' => $scored[0],
                'candidates' => $scored
            ];
        }

        // Multiple candidates
        $topScore = $scored[0]['score'];
        $secondScore = $scored[1]['score'];

        // If top score is overwhelmingly better (e.g. >= 25 points higher due to matching DOB/sacrament date), choose top
        if (($topScore - $secondScore) >= 25.0 && $topScore >= 110.0) {
            return [
                'status' => 'matched',
                'record_type' => $recType,
                'record_id' => $scored[0]['id'],
                'record' => $scored[0],
                'candidates' => $scored
            ];
        }

        // Ambiguous matches: flag for staff review
        return [
            'status' => 'multiple',
            'record_type' => $recType,
            'record_id' => null,
            'record' => null,
            'candidates' => array_slice($scored, 0, 5)
        ];
    }

    /**
     * Perform matching on a request and save the match result in the database.
     *
     * @param mysqli $conn
     * @param int $requestId
     * @return array Match result
     */
    public static function matchAndLinkRequest($conn, int $requestId): array {
        ensureRequestMatchingSchema($conn);

        $stmt = $conn->prepare("SELECT request_id, user_id, request_type, record_holder_name, description, reference_number FROM requests WHERE request_id = ? LIMIT 1");
        if (!$stmt) {
            return ['status' => 'error', 'message' => $conn->error];
        }
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$req) {
            return ['status' => 'error', 'message' => 'Request not found'];
        }

        $requestType = (string)$req['request_type'];
        $recordType = self::mapRequestTypeToRecordType($requestType);

        if (!$recordType) {
            // Not a sacramental certificate request
            return ['status' => 'skipped', 'message' => 'Not a sacramental certificate request'];
        }

        $recordHolderName = trim((string)($req['record_holder_name'] ?? ''));
        $description = (string)($req['description'] ?? '');

        // Extract criteria from description or metadata
        $criteria = [];

        // Check for embedded BAPTISM_RECORD_META JSON
        if (preg_match('/<!--BAPTISM_RECORD_META:(.*?)-->/s', $description, $bm)) {
            $meta = json_decode(trim($bm[1]), true);
            if (is_array($meta)) {
                if (empty($recordHolderName) && !empty($meta['fullname'])) {
                    $recordHolderName = trim($meta['fullname']);
                }
                if (!empty($meta['birth_date'])) $criteria['birth_date'] = trim($meta['birth_date']);
                if (!empty($meta['baptism_date'])) $criteria['sacrament_date'] = trim($meta['baptism_date']);
                if (!empty($meta['father_name'])) $criteria['father_name'] = trim($meta['father_name']);
                if (!empty($meta['mother_name'])) $criteria['mother_name'] = trim($meta['mother_name']);
            }
        }

        // Generic regex parsing from description lines
        if (empty($criteria['birth_date']) && preg_match('/(?:Birthday|Date of Birth|DOB)\s*[:\-]\s*(\d{4}-\d{2}-\d{2})/i', $description, $m)) {
            $criteria['birth_date'] = trim($m[1]);
        }
        if (empty($criteria['sacrament_date']) && preg_match('/(?:Date of (?:Baptism|First Communion|Confirmation|Wedding|Burial))\s*[:\-]\s*(\d{4}-\d{2}-\d{2})/i', $description, $m)) {
            $criteria['sacrament_date'] = trim($m[1]);
        }
        if (empty($criteria['father_name']) && preg_match('/(?:Father(?:\'s)?(?:\s*Full)?\s*Name)\s*[:\-]\s*([^\n\r]+)/i', $description, $m)) {
            $criteria['father_name'] = trim($m[1]);
        }
        if (empty($criteria['mother_name']) && preg_match('/(?:Mother(?:\'s)?(?:\s*(?:Maiden|Full))?\s*Name)\s*[:\-]\s*([^\n\r]+)/i', $description, $m)) {
            $criteria['mother_name'] = trim($m[1]);
        }

        // Fallback: If record_holder_name is still empty, look at description or user's fullname
        if ($recordHolderName === '') {
            if (preg_match('/Record Holder Name\s*[:\-]\s*([^\n\r]+)/i', $description, $rm)) {
                $recordHolderName = trim($rm[1]);
            } else {
                $uStmt = $conn->prepare("SELECT fullname FROM users WHERE user_id = ? LIMIT 1");
                if ($uStmt) {
                    $uStmt->bind_param('i', $req['user_id']);
                    $uStmt->execute();
                    $uRow = $uStmt->get_result()->fetch_assoc();
                    $uStmt->close();
                    if (!empty($uRow['fullname'])) {
                        $recordHolderName = trim($uRow['fullname']);
                    }
                }
            }
        }

        // Execute matching
        $result = self::findMatches($conn, $requestType, $recordHolderName, $criteria);

        $matchStatus = $result['status']; // 'matched', 'multiple', 'no_match'
        $matchedRecordId = $result['record_id']; // int or null
        $matchedRecordType = $result['record_type'];
        $matchDetailsJson = json_encode([
            'evaluated_at' => date('Y-m-d H:i:s'),
            'record_holder_name' => $recordHolderName,
            'criteria' => $criteria,
            'candidates_count' => count($result['candidates']),
            'candidates' => array_map(function($c) {
                // exclude raw_record to keep json clean
                unset($c['raw_record']);
                return $c;
            }, $result['candidates'])
        ]);

        // Update requests table
        $upStmt = $conn->prepare("UPDATE requests SET matched_record_id = ?, matched_record_type = ?, match_status = ?, match_details = ? WHERE request_id = ?");
        if ($upStmt) {
            $upStmt->bind_param('isssi', $matchedRecordId, $matchedRecordType, $matchStatus, $matchDetailsJson, $requestId);
            $upStmt->execute();
            $upStmt->close();
        }

        // If matched single record, link request_id in the sacramental record table
        if ($matchStatus === 'matched' && $matchedRecordId && $matchedRecordType) {
            $config = self::getTableConfig($matchedRecordType);
            if ($config) {
                $table = $config['table'];
                $idCol = $config['id_col'];
                $conn->query("UPDATE {$table} SET request_id = " . intval($requestId) . " WHERE {$idCol} = " . intval($matchedRecordId));
            }
        }

        return $result;
    }

    /**
     * Fetch full record details for a specific sacramental record type and ID.
     */
    public static function getRecordDetails($conn, string $recordType, int $recordId): ?array {
        $config = self::getTableConfig($recordType);
        if (!$config) {
            return null;
        }
        $table = $config['table'];
        $idCol = $config['id_col'];
        $stmt = $conn->prepare("SELECT * FROM {$table} WHERE {$idCol} = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $recordId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $res ?: null;
    }

    /**
     * Backfill matching for any existing certificate requests in the database.
     */
    public static function backfillUnmatchedRequests($conn): int {
        ensureRequestMatchingSchema($conn);
        $res = $conn->query("SELECT request_id FROM requests 
            WHERE (match_status IS NULL OR match_status = 'unmatched') 
              AND (request_type LIKE '%cert%' OR request_type IN ('baptism', 'communion', 'confirmation', 'marriage', 'funeral'))
            ORDER BY request_id ASC");
        
        $count = 0;
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                self::matchAndLinkRequest($conn, intval($row['request_id']));
                $count++;
            }
        }
        return $count;
    }
}
