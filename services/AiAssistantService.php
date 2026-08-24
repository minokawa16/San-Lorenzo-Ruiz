<?php

require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/chatbot/ConversationalIntent.php';

final class AiAssistantService
{
    private mysqli $db;
    private const UNKNOWN_EN = "I couldn't find specific details for that in our current parish records. Please feel free to ask about our sacramental services, certificates, Mass schedules, or contact the parish office for confirmation.";
    private const UNKNOWN_FIL = "Paumanhin po, hindi ko po nahanap ang partikular na impormasyong iyon sa kasalukuyang talaan ng parokya. Maaari po kayong magtanong tungkol sa ating mga sakramento, sertipiko, iskedyul ng misa, o direktang makipag-ugnayan sa tanggapan ng parokya.";

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function respond(int $userId, array $capabilities, string $message, string $mode = 'chat', array $conversation = []): array
    {
        $message = trim(mb_strimwidth($message, 0, 1000, ''));
        if ($message === '') {
            throw new InvalidArgumentException('Please enter a question or message.');
        }

        $correlation = tugonCorrelationId();
        $audience = !empty($capabilities['staff']) ? 'staff' : 'parishioner';

        // 1. Security Check: Prompt Injection Guardrail
        if ($this->isInjection($message)) {
            $lang = TugonConversationalIntent::detectLanguage($message, TugonConversationalIntent::normalize($message));
            $answer = ($lang === 'fil' || $lang === 'taglish')
                ? 'Hindi ko maaaring balewalain ang mga patakaran, maglabas ng lihim, o lampasan ang pahintulot. Maaari po kitang tulungan sa mga awtorisadong serbisyo ng TUGON.'
                : 'I cannot ignore safeguards, reveal secrets, or bypass permissions. I can help with authorized TUGON parish services.';
            return $this->persist($userId, $audience, $mode, $lang, $message, $answer, [], [], null, $correlation, 'security-refusal');
        }

        // 2. Security Check: Read-Only Mutation Refusal
        if ($this->requestsMutation($message)) {
            $lang = TugonConversationalIntent::detectLanguage($message, TugonConversationalIntent::normalize($message));
            $answer = ($lang === 'fil' || $lang === 'taglish')
                ? 'Read-only po ang TUGON AI at hindi ito maaaring magbago, mag-apruba, mag-isyu, o magtanggal ng tala. Gamitin po ang awtorisadong workflow sa inyong dashboard.'
                : 'TUGON AI is read-only and cannot change, approve, issue, or delete records. Use the authorized dashboard workflow for that action.';
            return $this->persist($userId, $audience, $mode, $lang, $message, $answer, [], [], null, $correlation, 'read-only-refusal');
        }

        // 3. Conversational Layer: Check for pure social / small talk / greeting / identity intent
        $intentAnalysis = TugonConversationalIntent::analyze($message);
        if ($intentAnalysis['is_pure_social'] && !empty($intentAnalysis['response'])) {
            return $this->persist(
                $userId,
                $audience,
                $mode,
                $intentAnalysis['language'],
                $message,
                $intentAnalysis['response'],
                [],
                [],
                null,
                $correlation,
                'conversational-intent',
                $intentAnalysis['suggested_prompts'] ?? []
            );
        }

        $language = $intentAnalysis['language'];

        // 4. Resolve Context across multi-turn conversation
        $contextualQuery = $this->resolveConversationContext($message, $conversation);

        // 5. Check if query is parish-related or conversational
        if (!$this->isParishRelated($contextualQuery) && $mode !== 'search') {
            $answer = ($language === 'fil' || $language === 'taglish')
                ? 'Ang TUGON AI po ay nakatuon sa mga serbisyo, iskedyul, sakramento, sertipiko, at kahilingan ng ating parokya. Maaari po kayong magtanong tungkol sa alinman sa mga paksang ito.'
                : 'TUGON AI is designed to assist with parish services, Mass schedules, sacraments, certificates, requests, and reservations. Please ask about one of those topics.';
            return $this->persist($userId, $audience, $mode, $language, $message, $answer, [], [], null, $correlation, 'topic-refusal');
        }

        // 6. Authorized Records / Smart Search
        $searchResults = $this->searchOwnedOrAuthorizedData($userId, $capabilities, $contextualQuery);

        // 7. Analytics / Reports Handling
        if ($mode === 'analytics' || preg_match('/\b(report|analytics|summary|ulat|buod|istatistika)\b/i', $message)) {
            if (empty($capabilities['reports'])) {
                $answer = ($language === 'fil' || $language === 'taglish')
                    ? 'Wala po kayong pahintulot na tingnan ang analytics report na ito.'
                    : 'You do not have permission to view that report.';
                return $this->persist($userId, $audience, $mode, $language, $message, $answer, [], $searchResults, null, $correlation, 'permission-refusal');
            }
            $analytics = $this->analytics($capabilities);
            $answer = ($language === 'fil' || $language === 'taglish')
                ? 'Narito po ang awtorisadong buod ng mga tala sa parokya batay sa kasalukuyang rekord:'
                : 'Here is the authorized summary based on current parish records:';
            return $this->persist($userId, $audience, 'analytics', $language, $message, $answer, [], $searchResults, $analytics, $correlation, 'authorized-analytics');
        }

        // 8. Smart Proactive Follow-ups for Incomplete Requests
        $proactiveResponse = $this->checkIncompleteRequest($contextualQuery, $language);
        if ($proactiveResponse !== null) {
            return $this->persist($userId, $audience, $mode, $language, $message, $proactiveResponse['answer'], $proactiveResponse['sources'] ?? [], $searchResults, null, $correlation, 'proactive-guidance', $proactiveResponse['prompts'] ?? []);
        }

        // 9. RAG Knowledge Base Retrieval
        $sources = $this->knowledge($contextualQuery);
        if (!$sources) {
            $answer = ($language === 'fil' || $language === 'taglish') ? self::UNKNOWN_FIL : self::UNKNOWN_EN;
            return $this->persist($userId, $audience, $mode, $language, $message, $answer, [], $searchResults, null, $correlation, 'grounded-unknown');
        }

        $primary = $sources[0];
        $greetingPrefix = '';
        if ($intentAnalysis['greeting_detected'] && !empty($intentAnalysis['greeting_acknowledgement'])) {
            $greetingPrefix = $intentAnalysis['greeting_acknowledgement'] . "\n\n";
        }

        $answer = $greetingPrefix;
        if ($language === 'fil' || $language === 'taglish') {
            $answer .= $primary['content'];
        } else {
            $answer .= $primary['content'];
        }

        if (!empty($primary['steps'])) {
            $answer .= "\n\n" . $primary['steps'];
        }

        return $this->persist($userId, $audience, $mode, $language, $message, $answer, $sources, $searchResults, null, $correlation, 'approved-knowledge');
    }

    public function saveFeedback(int $reviewerId, string $reference, string $rating, string $comments): void
    {
        if (!in_array($rating, ['correct', 'incorrect', 'needs_review'], true)) {
            throw new InvalidArgumentException('Invalid feedback value.');
        }
        $comments = trim(mb_strimwidth(tugonRedactSensitive($comments), 0, 1000, ''));
        $stmt = $this->db->prepare('SELECT response_id, source_snapshot FROM ai_responses WHERE response_reference=? LIMIT 1');
        $stmt->bind_param('s', $reference);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new DomainException('AI response not found.');
        }
        $responseId = (int) $row['response_id'];
        $snapshot = $row['source_snapshot'];
        $stmt = $this->db->prepare('INSERT INTO ai_feedback(response_id, rating, comments, reviewer_user_id, knowledge_source_snapshot)
            VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE rating=VALUES(rating), comments=VALUES(comments), knowledge_source_snapshot=VALUES(knowledge_source_snapshot), updated_at=CURRENT_TIMESTAMP');
        $stmt->bind_param('issis', $responseId, $rating, $comments, $reviewerId, $snapshot);
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to save feedback.');
        }
        $stmt->close();
        writeAuditLog($this->db, $reviewerId, 'AI_FEEDBACK_SUBMITTED', 'ai_responses', $responseId, null, ['rating' => $rating], 'ai', 'ai.feedback');
    }

    /**
     * Multi-Turn Context Resolution:
     * When a user's follow-up message is brief or contains referential terms ("it", "that", "requirements", "how much", "cost", "saan"),
     * inspect recent conversation turns to bind to the active topic.
     */
    private function resolveConversationContext(string $query, array $conversation): string
    {
        $normalized = mb_strtolower(trim($query));
        $referentialPattern = '/\b(it|that|this|the same|cost|fee|fees|magkano|bayad|requirements|kailangan|papers|documents|process|paano|saan|where|kailan|when|schedule|oras|who|sino)\b/u';

        if (empty($conversation) || !preg_match($referentialPattern, $normalized)) {
            return $query;
        }

        // Search backward for the most recent mentioned entity in user/assistant turns
        $entities = [
            'baptismal certificate' => ['baptismal cert', 'baptism certificate', 'certificate of baptism', 'sertipiko ng binyag'],
            'baptism' => ['binyag', 'pabinyag', 'christening', 'baby baptism'],
            'confirmation' => ['kumpil', 'pakumpil', 'confirmand'],
            'marriage' => ['wedding', 'kasal', 'pakasal', 'church wedding', 'pre-cana'],
            'first holy communion' => ['first communion', 'komunyon', 'communion'],
            'anointing of the sick' => ['sick call', 'pahid ng langis', 'anointing'],
            'funeral mass' => ['funeral', 'burol', 'libing', 'burial'],
            'house blessing' => ['pabasbas ng bahay', 'blessing ng bahay', 'home blessing'],
            'vehicle blessing' => ['pabasbas ng sasakyan', 'car blessing', 'motor blessing'],
            'certificate request' => ['certificate', 'sertipiko', 'records copy'],
            'mass schedule' => ['mass time', 'misa', 'sunday mass', 'weekday mass'],
            'office hours' => ['office schedule', 'opening hours', 'opisina'],
            'reservations' => ['reserve', 'booking', 'hall reservation', 'venue']
        ];

        $foundEntity = null;
        for ($i = count($conversation) - 1; $i >= 0; $i--) {
            $turnText = mb_strtolower((string) ($conversation[$i]['content'] ?? ''));
            foreach ($entities as $canonical => $aliases) {
                if (mb_strpos($turnText, $canonical) !== false) {
                    $foundEntity = $canonical;
                    break 2;
                }
                foreach ($aliases as $alias) {
                    if (mb_strpos($turnText, $alias) !== false) {
                        $foundEntity = $canonical;
                        break 2;
                    }
                }
            }
        }

        if ($foundEntity !== null) {
            // Append the found entity to the current query so RAG retrieves accurately
            if (mb_strpos($normalized, $foundEntity) === false) {
                return $query . ' ' . $foundEntity;
            }
        }

        return $query;
    }

    /**
     * Check for incomplete intents and generate intelligent follow-ups.
     */
    private function checkIncompleteRequest(string $query, string $language): ?array
    {
        $normalized = mb_strtolower(trim($query));

        // Incomplete Reservation Inquiry
        if (preg_match('/^(?:i want to make a reservation|how to reserve|reservation|paano mag(?:-|\s*)reserve|gusto ko po mag(?:-|\s*)reserve|mag(?:-|\s*)book ng schedule)(?: po)?$/u', $normalized)) {
            $answer = ($language === 'fil' || $language === 'taglish')
                ? "Malugod po namin kayong tutulungan sa inyong reservation! 😊\n\nUpang maiproseso ang inyong kahilingan, mangyaring ihanda ang mga sumusunod na detalye:\n1. **Petsa at Oras** ng inyong aktibidad\n2. **Pasilidad o Lugar** na nais i-reserve\n3. **Layunin o Uri ng Kaganapan**\n4. **Pangalan at Contact Number** ng nagre-request\n\nMaaari po kayong mag-submit ng opisyal na reservation sa pamamagitan ng **Reservations** menu sa inyong dashboard."
                : "Certainly! 😊 I would be happy to help guide you with your reservation.\n\nTo ensure availability, please have the following information ready:\n1. **Preferred Date and Time**\n2. **Venue or Facility** needed\n3. **Purpose of the Event**\n4. **Contact Details** of the organizer\n\nYou can submit an official reservation directly via the **Reservations** section in your dashboard.";
            return [
                'answer' => $answer,
                'prompts' => ['Parish Office Hours', 'Mass Schedule', 'Contact Parish Staff']
            ];
        }

        // Incomplete Certificate Inquiry
        if (preg_match('/^(?:i need a certificate|how to get certificate|paano kumuha ng certificate|kailangan ko po ng sertipiko)(?: po)?$/u', $normalized)) {
            $answer = ($language === 'fil' || $language === 'taglish')
                ? "Maaari po kayong kumuha ng iba't ibang uri ng sertipiko sa parokya. Aling sertipiko po ang inyong kailangan?\n\n• **Baptismal Certificate** (Para sa kasal, school, o personal record)\n• **Confirmation Certificate**\n• **Marriage Certificate**\n\nSabihin lang po kung aling sertipiko ang inyong kailangan upang maibigay ko ang kumpletong requirements at proseso."
                : "Certainly! You can request several types of parish certificates. Which certificate do you need?\n\n• **Baptismal Certificate** (For marriage, school, or personal records)\n• **Confirmation Certificate**\n• **Marriage Certificate**\n\nPlease specify which certificate you need so I can provide the exact requirements and step-by-step procedure.";
            return [
                'answer' => $answer,
                'prompts' => ['Baptismal Certificate', 'Confirmation Certificate', 'Marriage Certificate']
            ];
        }

        return null;
    }

    /**
     * Retrieve knowledge records with typo tolerance and synonym expansion.
     */
    private function knowledge(string $query): array
    {
        $expanded = $this->expandSynonymsAndTypos($query);
        $search = preg_replace('/[^\pL\pN\s]+/u', ' ', $expanded);
        $cleanTokens = array_filter(preg_split('/\s+/u', $search), static fn($w) => mb_strlen(trim($w)) >= 3);
        $cleanTokens = array_map(static fn($w) => preg_replace('/[^\pL\pN]/u', '', $w), $cleanTokens);
        $cleanTokens = array_slice(array_filter($cleanTokens, static fn($w) => mb_strlen($w) >= 3), 0, 10);
        $boolean = implode(' ', array_map(static fn($w) => $w . '*', $cleanTokens));
        $rows = [];

        if ($boolean !== '') {
            $stmt = $this->db->prepare("SELECT knowledge_id, topic, keywords, answer, steps, category, source, version, effective_date, expiry_date, language, updated_at,
                MATCH(topic, keywords, answer) AGAINST(? IN BOOLEAN MODE) score
                FROM chatbot_knowledge WHERE status='active' AND approval_status='approved'
                AND (effective_date IS NULL OR effective_date <= CURRENT_DATE) AND (expiry_date IS NULL OR expiry_date >= CURRENT_DATE)
                AND MATCH(topic, keywords, answer) AGAINST(? IN BOOLEAN MODE) HAVING score >= 1.0 ORDER BY score DESC, updated_at DESC LIMIT 3");
            $stmt->bind_param('ss', $boolean, $boolean);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $stmt->close();
        }

        // Secondary fallback search if strict boolean yielded no results
        if (empty($rows)) {
            $likeTerm = '%' . mb_strimwidth($query, 0, 50, '') . '%';
            $stmt = $this->db->prepare("SELECT knowledge_id, topic, keywords, answer, steps, category, source, version, effective_date, expiry_date, language, updated_at, 1.0 AS score
                FROM chatbot_knowledge WHERE status='active' AND approval_status='approved'
                AND (topic LIKE ? OR keywords LIKE ?) LIMIT 3");
            $stmt->bind_param('ss', $likeTerm, $likeTerm);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $stmt->close();
        }

        $rows = array_values(array_filter($rows, fn($row) => $this->knowledgeRelevant($expanded, $row)));
        return array_map(static fn($row) => [
            'title' => $row['topic'],
            'category' => $row['category'],
            'content' => $row['answer'],
            'steps' => $row['steps'],
            'source' => $row['source'],
            'version' => (int) $row['version'],
            'updated_at' => $row['updated_at']
        ], $rows);
    }

    /**
     * Expand typos, abbreviations, and Tagalog terms to canonical search tokens.
     */
    private function expandSynonymsAndTypos(string $text): string
    {
        $map = [
            '/\bbaptizm\b/iu' => 'baptism',
            '/\bbaptismal\s*cert(?:ificate)?\b/iu' => 'certificate request baptismal certificate',
            '/\bbinyag\b/iu' => 'baptism binyag',
            '/\bpabinyag\b/iu' => 'baptism binyag',
            '/\bkumpil\b/iu' => 'confirmation kumpil',
            '/\bpakumpil\b/iu' => 'confirmation kumpil',
            '/\bmerriage\b/iu' => 'marriage',
            '/\bweddding\b/iu' => 'wedding marriage',
            '/\bkasal\b/iu' => 'marriage wedding kasal',
            '/\bpakasal\b/iu' => 'marriage wedding kasal',
            '/\bkomunyon\b/iu' => 'first holy communion komunyon',
            '/\bsertipiko\b/iu' => 'certificate request sertipiko',
            '/\boras\s*ng\s*misa\b/iu' => 'mass schedule misa',
            '/\biskedyul\s*ng\s*misa\b/iu' => 'mass schedule misa',
            '/\bpabasbas\b/iu' => 'blessing basbas',
            '/\bpari\b/iu' => 'parish priest pari'
        ];
        return preg_replace(array_keys($map), array_values($map), $text);
    }

    private function knowledgeRelevant(string $query, array $row): bool
    {
        $stop = ['what', 'where', 'when', 'which', 'with', 'from', 'that', 'this', 'official', 'policy', 'need', 'needed', 'please', 'about', 'paano', 'mga', 'ang', 'ano', 'para', 'opisyal', 'how', 'much', 'cost', 'fee', 'want', 'gusto', 'mag'];
        $tokens = array_values(array_unique(array_filter(preg_split('/[^\pL\pN]+/u', mb_strtolower($query)), static fn($w) => mb_strlen($w) >= 3 && !in_array($w, $stop, true))));
        if (!$tokens) {
            return true;
        }
        $source = mb_strtolower(($row['topic'] ?? '') . ' ' . ($row['keywords'] ?? '') . ' ' . ($row['category'] ?? ''));
        $matched = 0;
        foreach ($tokens as $token) {
            $stem = rtrim($token, 's');
            if (mb_strpos($source, $token) !== false || ($stem !== '' && mb_strpos($source, $stem) !== false)) {
                $matched++;
            }
        }
        return ($matched / count($tokens)) >= 0.3;
    }

    private function searchOwnedOrAuthorizedData(int $userId, array $caps, string $query): array
    {
        $items = [];
        $term = '%' . mb_strimwidth($query, 0, 120, '') . '%';
        if (!empty($caps['records'])) {
            $stmt = $this->db->prepare("SELECT request_id, reference_number, request_type, status, date_requested FROM requests WHERE deleted_at IS NULL AND (reference_number LIKE ? OR request_type LIKE ?) ORDER BY date_requested DESC LIMIT 8");
            $stmt->bind_param('ss', $term, $term);
        } else {
            $stmt = $this->db->prepare("SELECT request_id, reference_number, request_type, status, date_requested FROM requests WHERE user_id=? AND deleted_at IS NULL AND (reference_number LIKE ? OR request_type LIKE ?) ORDER BY date_requested DESC LIMIT 8");
            $stmt->bind_param('iss', $userId, $term, $term);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'module' => 'Request',
                'title' => $row['reference_number'] ?: ucwords(str_replace('_', ' ', $row['request_type'])),
                'meta' => ucfirst($row['status']),
                'url' => !empty($caps['staff']) ? '../admin/manage-requests.php' : '../users/view-request.php?id=' . (int) $row['request_id']
            ];
        }
        $stmt->close();
        return $items;
    }

    private function analytics(array $caps): array
    {
        $metrics = [];
        if (!empty($caps['reports'])) {
            $queries = [
                "Pending Requests" => "SELECT COUNT(*) c FROM requests WHERE deleted_at IS NULL AND status NOT IN ('completed','rejected','cancelled')",
                "Open Reservations" => "SELECT COUNT(*) c FROM reservations WHERE status IN ('pending','approved')",
                "Certificates Issued" => "SELECT COUNT(*) c FROM certificate_issuances WHERE status IN ('issued','released','reissued')"
            ];
            foreach ($queries as $label => $sql) {
                $metrics[$label] = (int) ($this->db->query($sql)->fetch_assoc()['c'] ?? 0);
            }
        }
        return [
            'metrics' => $metrics,
            'insights' => ['Counts are current and permission-scoped. Open Reports for date filters and export.']
        ];
    }

    private function persist(int $userId, string $audience, string $mode, string $language, string $question, string $answer, array $sources, array $results, ?array $analytics, string $correlation, string $provider, array $prompts = []): array
    {
        $hex = bin2hex(random_bytes(16));
        $reference = sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
        $question = tugonRedactSensitive($question);
        $answer = tugonRedactSensitive($answer);
        $publicSources = array_map(static fn($s) => [
            'title' => $s['title'],
            'source' => $s['source'],
            'version' => $s['version'],
            'last_updated' => date('F j, Y', strtotime($s['updated_at']))
        ], $sources);
        $snapshot = json_encode($publicSources, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $stmt = $this->db->prepare('INSERT INTO ai_responses(response_reference, user_id, audience, mode, language, question_redacted, answer_redacted, source_snapshot, provider, correlation_id) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $stmt->bind_param('sissssssss', $reference, $userId, $audience, $mode, $language, $question, $answer, $snapshot, $provider, $correlation);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare('INSERT INTO chatbot_inquiries(user_id, user_role, question, answer_preview, mode, context_limited, correlation_id, response_reference) VALUES(?,?,?,?,?,1,?,?)');
        $stmt->bind_param('issssss', $userId, $audience, $question, $answer, $mode, $correlation, $reference);
        $stmt->execute();
        $stmt->close();

        return [
            'success' => true,
            'answer' => $answer,
            'guidance' => ['title' => 'TUGON AI', 'steps' => []],
            'sources' => $publicSources,
            'search_results' => $results,
            'analytics' => $analytics,
            'language' => $language,
            'response_reference' => $reference,
            'correlation_id' => $correlation,
            'suggested_prompts' => $prompts,
            'escalation' => [
                'label' => 'Contact Parish Staff',
                'url' => '../users/request-service.php'
            ]
        ];
    }

    private function isInjection(string $text): bool
    {
        return (bool) preg_match('/ignore (all |the )?(previous|system)|reveal (the )?(prompt|secret|credential)|bypass (permission|authorization)|execute (sql|command)|database password|session (id|token)/i', $text);
    }

    private function requestsMutation(string $text): bool
    {
        return (bool) preg_match('/\b(update|delete|approve|reject|issue|revoke|publish|modify|change)\b.{0,40}\b(record|request|certificate|payment|reservation|announcement|database)\b/i', $text);
    }

    private function isParishRelated(string $text): bool
    {
        return (bool) preg_match('/parish|parokya|church|mass|misa|office|opisina|bapt|binyag|confirm|kumpil|communion|komunyon|marriage|wedding|kasal|bless|basbas|certificate|sertipiko|request|kahilingan|reserv|venue|schedule|iskedyul|announcement|anunsyo|payment|bayad|funeral|burial|libing|priest|pari|record|tala|sacrament|analytics|report|ulat|TUGON|requirement|kailangan|cost|magkano/i', $text);
    }
}
