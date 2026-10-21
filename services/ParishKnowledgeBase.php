<?php

declare(strict_types=1);

final class ParishKnowledgeBase
{
    private mysqli $db;
    private string $kbDir;
    private array $modules = [];
    private array $sacraments = [];
    private array $statuses = [];
    private array $fewShots = [];

    public function __construct(mysqli $db, ?string $kbDir = null)
    {
        $this->db = $db;
        $this->kbDir = $kbDir ?? (__DIR__ . '/../knowledge_base');
        $this->loadJsonFiles();
    }

    private function loadJsonFiles(): void
    {
        // 1. Load modules.json
        $modulesFile = $this->kbDir . '/modules.json';
        if (file_exists($modulesFile)) {
            $data = json_decode((string) file_get_contents($modulesFile), true);
            $this->modules = $data['modules'] ?? [];
        }

        // 2. Load sacraments_requirements.json
        $sacramentsFile = $this->kbDir . '/sacraments_requirements.json';
        if (file_exists($sacramentsFile)) {
            $data = json_decode((string) file_get_contents($sacramentsFile), true);
            $flattened = [];

            // Certificates
            if (!empty($data['certificates']['types'])) {
                $certFee = $data['certificates']['fee'] ?? '₱100.00 per copy';
                $certTurnaround = $data['certificates']['turnaround_time'] ?? '1 to 3 working days';
                foreach ($data['certificates']['types'] as $ct) {
                    $flattened[] = [
                        'name' => $ct['type'] ?? 'Certificate',
                        'category' => 'certificates',
                        'fee' => $certFee,
                        'lead_time' => $certTurnaround,
                        'description' => $ct['description'] ?? '',
                        'requirements' => $ct['requirements'] ?? []
                    ];
                }
            }

            // Blessings
            if (!empty($data['blessings']['types'])) {
                $blessFee = $data['blessings']['fee'] ?? 'Voluntary love offering';
                $blessLead = $data['blessings']['lead_time'] ?? '3 to 5 days in advance';
                foreach ($data['blessings']['types'] as $bt) {
                    $flattened[] = [
                        'name' => $bt['type'] ?? 'Blessing',
                        'category' => 'blessings',
                        'fee' => $blessFee,
                        'lead_time' => $blessLead,
                        'description' => $bt['description'] ?? '',
                        'requirements' => $bt['requirements'] ?? []
                    ];
                }
            }

            // Sacramental Services
            if (!empty($data['sacramental_services']['services'])) {
                foreach ($data['sacramental_services']['services'] as $st) {
                    $flattened[] = [
                        'name' => $st['service'] ?? 'Sacramental Service',
                        'category' => 'sacraments',
                        'fee' => 'Contact parish office',
                        'lead_time' => $st['schedule'] ?? 'Check schedule',
                        'description' => $st['service'] ?? '',
                        'requirements' => $st['requirements'] ?? []
                    ];
                }
            }

            $this->sacraments = $flattened;
        }

        // 3. Load request_statuses.json
        $statusesFile = $this->kbDir . '/request_statuses.json';
        if (file_exists($statusesFile)) {
            $data = json_decode((string) file_get_contents($statusesFile), true);
            $this->statuses = $data['statuses'] ?? [];
        }

        // 4. Load training_examples.json
        $fewShotsFile = $this->kbDir . '/training_examples.json';
        if (file_exists($fewShotsFile)) {
            $this->fewShots = json_decode((string) file_get_contents($fewShotsFile), true) ?: [];
        }
    }

    /**
     * Normalize query: lowercases, trims, cleans punctuation, and fixes common typos
     * across English, Filipino, Taglish, and Bisaya/Cebuano.
     */
    public function normalizeQuery(string $raw): array
    {
        $trimmed = trim(mb_strtolower($raw));
        $language = $this->detectLanguage($trimmed);

        // Common spelling corrections and morphological normalizations
        $replacements = [
            // Typos for baptism & certificates
            '/\bbaptizm\b/iu' => 'baptism',
            '/\bbaptzm\b/iu' => 'baptism',
            '/\bbaptisimal\b/iu' => 'baptismal',
            '/\bbaptismal\s*cert(?:ificate)?\b/iu' => 'baptism certificate',
            '/\bcertifcate\b/iu' => 'certificate',
            '/\bcertifkate\b/iu' => 'certificate',
            '/\bcertifikate\b/iu' => 'certificate',
            '/\bcertificatee\b/iu' => 'certificate',
            '/\bsertipiko\b/iu' => 'certificate',
            '/\bsertipikasyon\b/iu' => 'certificate',
            '/\bcert\b/iu' => 'certificate',

            // Typos for request & status
            '/\breqest\b/iu' => 'request',
            '/\brequst\b/iu' => 'request',
            '/\breqwst\b/iu' => 'request',
            '/\breq\b/iu' => 'request',
            '/\brekeust\b/iu' => 'request',
            '/\brequestt\b/iu' => 'request',
            '/\bstats\b/iu' => 'status',
            '/\bstatuss\b/iu' => 'status',
            '/\bistatus\b/iu' => 'status',

            // Typos for blessing
            '/\bblessng\b/iu' => 'blessing',
            '/\bblesing\b/iu' => 'blessing',
            '/\bblesin\b/iu' => 'blessing',
            '/\bpabasbas\b/iu' => 'blessing',
            '/\bbasbas\b/iu' => 'blessing',
            '/\bbendisyon\b/iu' => 'blessing',

            // Confirmation / Marriage / Communion
            '/\bconfrmation\b/iu' => 'confirmation',
            '/\bconfiramtion\b/iu' => 'confirmation',
            '/\bkumpil\b/iu' => 'confirmation',
            '/\bpakumpil\b/iu' => 'confirmation',
            '/\bmerriage\b/iu' => 'marriage',
            '/\bweddding\b/iu' => 'wedding',
            '/\bkasal\b/iu' => 'marriage wedding',
            '/\bpakasal\b/iu' => 'marriage wedding',
            '/\bkomunyon\b/iu' => 'communion',
            '/\bkumunyon\b/iu' => 'communion',

            // Admin / Signatory / Priest
            '/\bpari\b/iu' => 'priest',
            '/\b(?:ilis|palitan|change|update)\s*(?:sa\s*|ang\s*)?(?:pari|priest|signatory)\b/iu' => 'change priest signatory',

            // Question words
            '/\bhw\s*many\b/iu' => 'how many',
            '/\bhoy\s*many\b/iu' => 'how many',
            '/\bhw\s*to\b/iu' => 'how to',
            '/\bhw\s*much\b/iu' => 'how much',
            '/\bpila\s*kabuok\b/iu' => 'how many',
            '/\bilan\s*(?:ang|na\s*ang)\b/iu' => 'how many',
            '/\bunsa\s*pag\b/iu' => 'how to',
            '/\bunsaon\s*pag\b/iu' => 'how to',
            '/\bpano\s*mag\b/iu' => 'how to',
            '/\bpaano\s*mag\b/iu' => 'how to',
            '/\bpaano\s*po\b/iu' => 'how to',
        ];

        $cleaned = preg_replace(array_keys($replacements), array_values($replacements), $trimmed);
        $cleanSearch = trim(preg_replace('/[?!.,;:\"\'\(\)\[\]{}]+/u', ' ', (string) $cleaned));

        return [
            'original' => $raw,
            'normalized' => $cleaned,
            'search_tokens' => array_values(array_filter(explode(' ', $cleanSearch), fn($t) => strlen($t) >= 2)),
            'language' => $language,
        ];
    }

    /**
     * Detect user language: 'ceb' (Bisaya/Cebuano), 'fil' (Filipino/Tagalog), or 'en' (English).
     */
    public function detectLanguage(string $text): string
    {
        $cebMarkers = [
            'unsa', 'unsaon', 'unsaang', 'pila', 'kabuok', 'nahuman', 'adlaw', 'pahuway',
            'abli', 'sirado', 'kinahanglan', 'buhaton', 'pilia', 'adto', 'kaayo', 'namo',
            'kanus-a', 'asa', 'nganong', 'og', 'og blessing', 'sa akong', 'palihug', 'ilis',
            'pabunyag', 'bunyag', 'pamisa', 'himo', 'lubong', 'kompisal'
        ];
        $filMarkers = [
            'paano', 'pano', 'ano', 'kailangan', 'ilan', 'sertipiko', 'kailan', 'saan',
            'bakit', 'meron', 'wala', 'po', 'opo', 'namin', 'inyo', 'mga', 'lahat',
            'pumunta', 'i-download', 'palitan', 'binyag', 'libing', 'pari', 'kumpil',
            'pakumpil', 'kasal', 'papakasal'
        ];

        $lower = mb_strtolower($text);
        $cebScore = 0;
        foreach ($cebMarkers as $m) {
            if (preg_match('/\b' . preg_quote($m, '/') . '\b/u', $lower)) {
                $cebScore += 2;
            }
        }

        $filScore = 0;
        foreach ($filMarkers as $m) {
            if (preg_match('/\b' . preg_quote($m, '/') . '\b/u', $lower)) {
                $filScore += 2;
            }
        }

        if ($cebScore > $filScore && $cebScore >= 2) {
            return 'ceb';
        }
        if ($filScore >= 2) {
            return 'fil';
        }
        return 'en';
    }

    /**
     * RAG Retrieval: Search modules, sacramental requirements, request statuses, and DB knowledge.
     * Returns ranked knowledge chunks with exact system names, paths, and steps.
     */
    public function search(string $query, string $role = 'parishioner', int $limit = 4, ?string $topicFilter = null, ?string $typeFilter = null): array
    {
        $norm = $this->normalizeQuery($query);
        $tokens = $norm['search_tokens'];
        $normStr = $norm['normalized'];
        $isAdminQuery = (bool) preg_match('/\b(admin|staff|update request|generate certificate|review status|combobox|change priest signatory)\b/i', $normStr);
        if (preg_match('/\b(who|sino|kinsa)\b/i', $normStr)) {
            $isAdminQuery = false;
        }

        $results = [];

        // 1. Search Modules from modules.json
        foreach ($this->modules as $mod) {
            $audienceList = is_array($mod['audience'] ?? null) ? $mod['audience'] : ['all'];
            $isStaffOnly = in_array('staff', $audienceList, true) && !in_array('parishioner', $audienceList, true);

            if ($role === 'parishioner' && $isStaffOnly && !$isAdminQuery) {
                // Skip admin-only modules for parishioners unless they explicitly ask about an admin workflow
                continue;
            }

            $pageUrl = $mod['page_url'] ?? '';
            // Topic isolation for modules
            if ($topicFilter === 'certificates' && (str_contains($pageUrl, 'request-service') || str_contains($pageUrl, 'request-blessing'))) {
                continue;
            }
            if ($topicFilter === 'sacraments' && (str_contains($pageUrl, 'request-certificate') || str_contains($pageUrl, 'request-blessing'))) {
                continue;
            }
            if ($topicFilter === 'blessings' && (str_contains($pageUrl, 'request-certificate') || str_contains($pageUrl, 'request-service'))) {
                continue;
            }
            if ($topicFilter === 'general' && (str_contains($pageUrl, 'request-certificate') || str_contains($pageUrl, 'request-service') || str_contains($pageUrl, 'request-blessing'))) {
                continue;
            }

            $title = $mod['title'] ?? 'Module';
            $summary = $mod['summary'] ?? '';
            $keywords = is_array($mod['keywords'] ?? null) ? implode(' ', $mod['keywords']) : '';
            $steps = is_array($mod['steps'] ?? null) ? implode(' ', $mod['steps']) : '';

            $score = $this->scoreItem($normStr, $tokens, "{$title} {$summary} {$keywords} {$steps}");

            if ($score > 0) {
                $results[] = [
                    'source_type' => 'module',
                    'title' => $title,
                    'page' => $pageUrl,
                    'audience' => implode(', ', $audienceList),
                    'score' => $score,
                    'summary' => $summary,
                    'steps' => $mod['steps'] ?? [],
                    'raw' => $mod
                ];
            }
        }

        // 2. Search Sacramental & Certificate Requirements
        foreach ($this->sacraments as $sac) {
            $sacCat = $sac['category'] ?? '';

            // STRICT TOPIC FILTER: Never mix certificates, sacraments, and blessings!
            if ($topicFilter === 'general') {
                continue; // Do NOT load certificate or sacramental service requirements on general inquiries!
            }
            if ($topicFilter !== null && in_array($topicFilter, ['certificates', 'sacraments', 'blessings'], true)) {
                if ($sacCat !== $topicFilter) {
                    continue; // Skip items from other topics entirely!
                }
            }

            $sacName = $sac['name'] ?? 'Sacrament';
            $sacReqs = implode(' ', $sac['requirements'] ?? []);
            $score = $this->scoreItem($normStr, $tokens, "{$sacName} {$sacCat} {$sacReqs}");

            // Specific type filter boost: e.g. 'baptism', 'confirmation', 'marriage', etc.
            if ($typeFilter !== null) {
                $typeAliases = match ($typeFilter) {
                    'baptism' => ['baptism', 'binyag', 'bunyag'],
                    'confirmation' => ['confirmation', 'kumpil'],
                    'communion' => ['communion', 'komunyon', 'kumunyon'],
                    'marriage' => ['marriage', 'wedding', 'matrimony', 'kasal'],
                    'funeral' => ['funeral', 'death', 'libing', 'burol', 'lubong'],
                    default => [$typeFilter]
                };
                $nameLower = mb_strtolower($sacName);
                foreach ($typeAliases as $alias) {
                    if (str_contains($nameLower, $alias)) {
                        $score += 50.0;
                        break;
                    }
                }
            }

            if ($score > 0) {
                $results[] = [
                    'source_type' => 'sacrament_requirement',
                    'title' => $sacName,
                    'category' => $sacCat,
                    'fee' => $sac['fee'] ?? '',
                    'lead_time' => $sac['lead_time'] ?? '',
                    'score' => $score * 1.5,
                    'requirements' => $sac['requirements'] ?? [],
                    'raw' => $sac
                ];
            }
        }

        // 3. Search Request Statuses
        if ($topicFilter === null || $topicFilter === 'general' || $topicFilter === 'status') {
            foreach ($this->statuses as $st) {
                $textToMatch = ($st['status'] ?? '') . ' ' . ($st['meaning_en'] ?? '') . ' ' . ($st['meaning_fil'] ?? '') . ' ' . ($st['parishioner_action_en'] ?? '') . ' ' . ($st['staff_action_en'] ?? '');
                $score = $this->scoreItem($normStr, $tokens, $textToMatch);
                if ($score > 0) {
                    $results[] = [
                        'source_type' => 'request_status',
                        'title' => 'Status: ' . ($st['status'] ?? ''),
                        'code' => $st['code'] ?? '',
                        'meaning_en' => $st['meaning_en'] ?? '',
                        'meaning_fil' => $st['meaning_fil'] ?? '',
                        'parishioner_action' => $st['parishioner_action_en'] ?? '',
                        'staff_action' => $st['staff_action_en'] ?? '',
                        'score' => $score * 1.1,
                        'raw' => $st
                    ];
                }
            }
        }

        // 4. Search Database chatbot_knowledge Table (if available)
        if ($topicFilter === null || $topicFilter === 'general') {
            try {
                $dbKnowledge = $this->searchDbKnowledge($normStr, $tokens);
                foreach ($dbKnowledge as $dbItem) {
                    $results[] = $dbItem;
                }
            } catch (Throwable $e) {
                // Non-blocking fallback
            }
        }

        // Sort results by score descending
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $limit);
    }

    private function searchDbKnowledge(string $normStr, array $tokens): array
    {
        $items = [];
        if (empty($tokens)) {
            return $items;
        }

        $term = '%' . mb_strimwidth($tokens[0], 0, 30, '') . '%';
        $stmt = $this->db->prepare("SELECT knowledge_id, topic, keywords, answer, steps, category, source, version, updated_at
            FROM chatbot_knowledge
            WHERE status='active' AND approval_status='approved'
            AND (topic LIKE ? OR keywords LIKE ?)
            LIMIT 4");
        if ($stmt) {
            $stmt->bind_param('ss', $term, $term);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $score = $this->scoreItem($normStr, $tokens, ($row['topic'] ?? '') . ' ' . ($row['keywords'] ?? '') . ' ' . ($row['answer'] ?? ''));
                if ($score > 0) {
                    $items[] = [
                        'source_type' => 'database_kb',
                        'title' => $row['topic'],
                        'category' => $row['category'],
                        'content' => $row['answer'],
                        'steps' => $row['steps'] ?? '',
                        'source' => $row['source'],
                        'version' => (int) $row['version'],
                        'updated_at' => $row['updated_at'],
                        'score' => $score * 0.9
                    ];
                }
            }
            $stmt->close();
        }

        return $items;
    }

    private function scoreItem(string $normStr, array $tokens, string $targetText): float
    {
        $targetLower = mb_strtolower($targetText);
        $score = 0.0;

        // Exact phrase match bonus
        if (mb_strpos($targetLower, $normStr) !== false && mb_strlen($normStr) > 4) {
            $score += 15.0;
        }

        // Token match & partial stems
        foreach ($tokens as $token) {
            if (mb_strlen($token) < 2) continue;
            if (mb_strpos($targetLower, $token) !== false) {
                $score += 3.0;
            } else {
                $stem = rtrim($token, 's');
                if (mb_strlen($stem) >= 4 && mb_strpos($targetLower, $stem) !== false) {
                    $score += 2.0;
                }
            }
        }

        return $score;
    }

    /**
     * Get 2-3 most relevant few-shot examples for the prompt from training_examples.json
     */
    public function getRelevantFewShots(string $query, int $limit = 3): array
    {
        if (empty($this->fewShots)) {
            return [];
        }

        $norm = $this->normalizeQuery($query);
        $tokens = $norm['search_tokens'];
        $scored = [];

        foreach ($this->fewShots as $shot) {
            $score = $this->scoreItem($norm['normalized'], $tokens, ($shot['user_query'] ?? '') . ' ' . ($shot['category'] ?? '') . ' ' . ($shot['intent'] ?? ''));
            // Boost if language matches
            if (($shot['language'] ?? '') === $norm['language']) {
                $score += 2.0;
            }
            if ($score > 0) {
                $scored[] = ['score' => $score, 'shot' => $shot];
            }
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        $result = [];
        foreach (array_slice($scored, 0, $limit) as $item) {
            $result[] = $item['shot'];
        }

        // If no matches, return the top default examples
        if (empty($result)) {
            $result = array_slice($this->fewShots, 0, $limit);
        }

        return $result;
    }
}
