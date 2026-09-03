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

        // 8. Grounded System Guidance & Personalized User Transaction Inquiries
        $systemResponse = $this->resolveSystemOrUserTransactionQuery($userId, $contextualQuery, $language);
        if ($systemResponse !== null) {
            return $this->persist($userId, $audience, $mode, $language, $message, $systemResponse['answer'], $systemResponse['sources'] ?? [], $searchResults, null, $correlation, 'system-transaction-grounded', $systemResponse['prompts'] ?? []);
        }

        // 9. Smart Proactive Follow-ups for Incomplete Requests
        $proactiveResponse = $this->checkIncompleteRequest($contextualQuery, $language);
        if ($proactiveResponse !== null) {
            return $this->persist($userId, $audience, $mode, $language, $message, $proactiveResponse['answer'], $proactiveResponse['sources'] ?? [], $searchResults, null, $correlation, 'proactive-guidance', $proactiveResponse['prompts'] ?? []);
        }

        // 10. RAG Knowledge Base Retrieval
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
     * Resolve direct parish system guidance and personalized user transaction lookups.
     */
    private function resolveSystemOrUserTransactionQuery(int $userId, string $query, string $language): ?array
    {
        $normalized = mb_strtolower(trim($query));
        $isFil = ($language === 'fil' || $language === 'taglish');

        // A. User's Own Request Count, Listing & Status Inquiry
        $isRequestQuery = (bool) preg_match('/\b(?:(?:how|hoy|hw)\s*many\s*requests?|count\s*(?:of\s*)?(?:my\s*)?requests?|number\s*of\s*(?:my\s*)?requests?|show\s*(?:me\s*)?(?:all\s*)?(?:the\s*)?(?:my\s*)?requests?|list\s*(?:all\s*)?(?:the\s*)?(?:my\s*)?requests?|view\s*(?:all\s*)?(?:the\s*)?(?:my\s*)?requests?|see\s*(?:all\s*)?(?:the\s*)?(?:my\s*)?requests?|display\s*(?:all\s*)?(?:the\s*)?(?:my\s*)?requests?|all\s*(?:the\s*)?requests?\s*(?:that\s*)?i\s*(?:did|have|made|submitted)?|requests?\s*(?:that\s*)?i\s*(?:did|have|made|submitted)|what\s*(?:are\s*)?(?:all\s*)?my\s*requests?|what\s*requests?\s*(?:do\s*i\s*have|did\s*i\s*(?:make|do|submit))|status\s*of\s*(?:my|the)\s*(?:request|certificate|blessing)|check\s*(?:my|the)\s*requests?|my\s*requests?(?:\s*status)?|kumusta\s*(?:ang\s*|yung\s*)?request|anong\s*status\s*ng\s*request|follow[- ]?up\s*(?:sa\s*)?request|check\s*certificate\s*status|mga\s*request\s*ko|lahat\s*ng\s*request\s*ko|ilan\s*(?:ang\s*|na\s*ang\s*)?request\s*ko|ilang\s*request\s*(?:meron\s*ako|ang\s*(?:nagawa|isinumite)\s*ko)|pakita\s*(?:ang\s*)?mga\s*request\s*ko|tingnan\s*(?:ang\s*)?mga\s*request\s*ko)\b/iu', $normalized);
        if ($isRequestQuery) {
            $countStmt = $this->db->prepare("SELECT COUNT(*) AS c FROM requests WHERE user_id=? AND deleted_at IS NULL");
            $totalCount = 0;
            if ($countStmt) {
                $countStmt->bind_param('i', $userId);
                $countStmt->execute();
                $totalCount = (int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
                $countStmt->close();
            }

            $stmt = $this->db->prepare("SELECT request_id, reference_number, request_type, status, date_requested FROM requests WHERE user_id=? AND deleted_at IS NULL ORDER BY date_requested DESC LIMIT 10");
            $requests = [];
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $requests[] = $row;
                }
                $stmt->close();
            }

            if ($totalCount > 0 && !empty($requests)) {
                $lines = [];
                foreach ($requests as $r) {
                    $ref = $r['reference_number'] ?: ('REQ-' . $r['request_id']);
                    $type = ucwords(str_replace('_', ' ', $r['request_type']));
                    $status = ucfirst($r['status']);
                    $date = date('M d, Y', strtotime($r['date_requested']));
                    $lines[] = "• **{$ref}** — {$type}\n  Status: **{$status}** (Submitted on {$date})";
                }
                $listStr = implode("\n\n", $lines);

                $isCounting = (bool) preg_match('/\b(?:how\s*many|hoy\s*many|hw\s*many|count|number\s*of|ilan|ilang)\b/iu', $normalized);
                if ($isCounting) {
                    $answer = $isFil
                        ? "Mayroon po kayong kabuuang **{$totalCount}** na naisumiteng request sa TUGON:\n\n{$listStr}\n\nMaaari ninyong buksan ang inyong kahilingan upang makita ang buong detalye, admin notes, o mag-upload ng GCash receipt:\n[View My Requests](../users/my-requests.php)"
                        : "You have submitted a total of **{$totalCount}** request(s) on record in TUGON:\n\n{$listStr}\n\nYou can track details, read admin notes, or upload your GCash payment receipt anytime:\n[View My Requests](../users/my-requests.php)";
                } else {
                    $answer = $isFil
                        ? "Narito po ang tala ng inyong **{$totalCount}** na isinumiteng request sa parokya:\n\n{$listStr}\n\nMaaari ninyong buksan ang inyong kahilingan upang makita ang buong detalye, admin notes, o mag-upload ng GCash receipt:\n[View My Requests](../users/my-requests.php)"
                        : "Here are your **{$totalCount}** submitted request(s) on record in TUGON:\n\n{$listStr}\n\nYou can track details, read admin notes, or upload your GCash payment receipt anytime:\n[View My Requests](../users/my-requests.php)";
                }
            } else {
                $answer = $isFil
                    ? "Wala pa po kayong naitalang request sa kasalukuyan (**0 requests**). Kung nais ninyong kumuha ng sertipiko o humiling ng basbas, maaari po kayong magsumite dito:\n\n[Request Certificate](../users/request-certificate.php) • [Request Blessing](../users/request-blessing.php)"
                    : "You currently have **0** submitted requests on record in TUGON. If you need a parish certificate or blessing, you can submit one below:\n\n[Request Certificate](../users/request-certificate.php) • [Request Blessing](../users/request-blessing.php)";
            }
            return [
                'answer' => $answer,
                'prompts' => ['Request Certificate', 'Request Blessing', 'Parish Schedule']
            ];
        }

        // B. User's Own Reservation Count, Listing & Status Inquiry
        $isReservationQuery = (bool) preg_match('/\b(?:(?:how|hoy|hw)\s*many\s*reservations?|count\s*(?:of\s*)?(?:my\s*)?reservations?|number\s*of\s*(?:my\s*)?reservations?|show\s*(?:me\s*)?(?:all\s*)?(?:the\s*)?(?:my\s*)?reservations?|list\s*(?:all\s*)?(?:the\s*)?(?:my\s*)?reservations?|view\s*(?:all\s*)?(?:the\s*)?(?:my\s*)?reservations?|see\s*(?:all\s*)?(?:the\s*)?(?:my\s*)?reservations?|display\s*(?:all\s*)?(?:the\s*)?(?:my\s*)?reservations?|all\s*(?:the\s*)?reservations?\s*(?:that\s*)?i\s*(?:did|have|made|booked)?|reservations?\s*(?:that\s*)?i\s*(?:did|have|made|booked)|what\s*(?:are\s*)?(?:all\s*)?my\s*reservations?|what\s*reservations?\s*(?:do\s*i\s*have|did\s*i\s*(?:make|do|book))|status\s*of\s*(?:my|the)\s*reservations?|check\s*(?:my|the)\s*reservations?|my\s*reservations?(?:\s*status)?|kumusta\s*(?:ang\s*|yung\s*)?reservation|anong\s*status\s*ng\s*reservation|check\s*reservation|mga\s*reservation\s*ko|lahat\s*ng\s*reservation\s*ko|ilan\s*(?:ang\s*|na\s*ang\s*)?reservation\s*ko|ilang\s*reservation\s*(?:meron\s*ako|ang\s*(?:nagawa|na-book)\s*ko)|pakita\s*(?:ang\s*)?mga\s*reservation\s*ko|tingnan\s*(?:ang\s*)?mga\s*reservation\s*ko)\b/iu', $normalized);
        if ($isReservationQuery) {
            $countStmt = $this->db->prepare("SELECT COUNT(*) AS c FROM reservations WHERE user_id=?");
            $totalResCount = 0;
            if ($countStmt) {
                $countStmt->bind_param('i', $userId);
                $countStmt->execute();
                $totalResCount = (int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
                $countStmt->close();
            }

            $stmt = $this->db->prepare("SELECT reservation_id, reservation_type, event_date, event_time, status FROM reservations WHERE user_id=? ORDER BY event_date DESC LIMIT 10");
            $reservations = [];
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $reservations[] = $row;
                }
                $stmt->close();
            }

            if ($totalResCount > 0 && !empty($reservations)) {
                $lines = [];
                foreach ($reservations as $res) {
                    $type = ucwords(str_replace('_', ' ', $res['reservation_type']));
                    $date = date('M d, Y', strtotime($res['event_date']));
                    $time = substr((string)$res['event_time'], 0, 5);
                    $status = ucfirst($res['status']);
                    $lines[] = "• **{$type}** on **{$date}** ({$time})\n  Status: **{$status}**";
                }
                $listStr = implode("\n\n", $lines);
                $answer = $isFil
                    ? "Mayroon po kayong **{$totalResCount}** na reservation booking sa talaan:\n\n{$listStr}\n\n[Make Reservation](../users/make-reservation.php)"
                    : "You have **{$totalResCount}** reservation booking(s) on file:\n\n{$listStr}\n\n[Make Reservation](../users/make-reservation.php)";
            } else {
                $answer = $isFil
                    ? "Wala pa po kayong aktibong reservation sa ating pasilidad o kaganapan (**0 reservations**). Kung nais ninyong magpa-reserve, i-click lamang po ang link sa ibaba:\n\n[Make Reservation](../users/make-reservation.php)"
                    : "You currently have **0** reservation bookings on file. If you would like to book a parish facility or schedule, click the link below:\n\n[Make Reservation](../users/make-reservation.php)";
            }
            return [
                'answer' => $answer,
                'prompts' => ['Make Reservation', 'Parish Schedule', 'Contact Parish Staff']
            ];
        }

        // C. Account Creation & Registration
        if (preg_match('/\b(?:how (?:can|do) i (?:create|register|make|open) (?:a )?(?:tugon )?account|how to (?:register|create an account)|paano (?:gumawa ng|mag-?register ng) account|sign up)\b/iu', $normalized)) {
            $answer = $isFil
                ? "Para gumawa ng **TUGON Account**:\n\n1. Buksan ang **Registration** page.\n2. Ilagay ang inyong buong pangalan, email, mobile number, at tirahan.\n3. Magtakda ng matibay na password.\n4. Mag-upload ng malinaw na **Valid Government ID** para sa OCR verification.\n5. Kumpletuhin ang live selfie face verification.\n6. Ipasok ang natanggap na OTP upang ma-activate ang inyong account.\n\n[Register Now](../auth/register.php)"
                : "To create a **TUGON Account**:\n\n1. Open the **Registration** page.\n2. Enter your full name, email, mobile number, and residential address.\n3. Create a secure password (minimum 8 characters).\n4. Upload a clear **Valid Government ID** for automated OCR identity verification.\n5. Complete the live selfie face verification.\n6. Enter the OTP code sent to your mobile or email to activate your account.\n\n[Register Now](../auth/register.php)";
            return [
                'answer' => $answer,
                'prompts' => ['How can I log in to my account?', 'How to upload valid ID', 'Request Certificate']
            ];
        }

        // D. Account Login
        if (preg_match('/\b(?:how (?:can|do) i (?:log in|login|sign in)|how to login|paano mag-?login|paano pumasok sa account)\b/iu', $normalized)) {
            $answer = $isFil
                ? "Para mag-login sa inyong account:\n\n1. Pumunta sa **Login** page.\n2. Ilagay ang inyong rehistradong email o mobile number.\n3. I-type ang inyong password.\n4. I-click ang **Sign In**.\n\n[Log In Now](../auth/login.php)"
                : "To log in to your account:\n\n1. Open the **Login** page.\n2. Enter your registered email address or mobile number.\n3. Type your password.\n4. Click **Sign In** to access your portal.\n\n[Log In Now](../auth/login.php)";
            return [
                'answer' => $answer,
                'prompts' => ['How can I change my password?', 'Create Account', 'Track My Requests']
            ];
        }

        // E. Specific Certificate Requests (Baptism, Confirmation, Communion, General)
        if (preg_match('/\b(?:how (?:do|can) i request (?:a )?(?:baptismal|confirmation|first communion|marriage) certificate|request (?:baptismal|confirmation|communion|marriage) certificate|paano kumuha ng sertipiko ng (?:binyag|kumpil|komunyon|kasal)|how to request a certificate|paano kumuha ng certificate)\b/iu', $normalized)) {
            $certType = 'Certificate';
            if (preg_match('/\bbaptism/i', $normalized)) $certType = 'Baptismal Certificate';
            elseif (preg_match('/\bconfirmation|kumpil/i', $normalized)) $certType = 'Confirmation Certificate';
            elseif (preg_match('/\bcommunion|komunyon/i', $normalized)) $certType = 'First Communion Certificate';
            elseif (preg_match('/\bmarriage|kasal/i', $normalized)) $certType = 'Marriage Certificate';

            $answer = $isFil
                ? "Narito po ang mga hakbang para sa paghiling ng **{$certType}**:\n\n1. Pumunta sa **Certificate Request** page.\n2. Piliin ang **{$certType}**.\n3. Ilagay ang mga personal na detalye (Pangalan, Petsa ng Kapanganakan, Pangalan ng mga Magulang) at layunin ng request.\n4. Mag-upload ng malinaw na kopya ng **PSA / Birth Certificate** o Valid ID.\n5. I-click ang **Submit Certificate Request** at itabi ang inyong Reference Number.\n\n[Open Certificate Requests](../users/request-certificate.php)"
                : "Here is the step-by-step guide to request an official **{$certType}**:\n\n1. Open the **Certificate Request** page.\n2. Select **{$certType}**.\n3. Fill in the required personal details (Full Name, Date of Birth, Parents' Names) and purpose of request.\n4. Upload a clear copy of your **PSA / Birth Certificate** or Valid ID.\n5. Click **Submit Certificate Request** and save your assigned Reference Number.\n\n[Open Certificate Requests](../users/request-certificate.php)";
            return [
                'answer' => $answer,
                'prompts' => ['What documents do I need to submit?', 'How long does certificate processing take?', 'Track My Requests']
            ];
        }

        // F. Specific Blessings (House, Vehicle, General)
        if (preg_match('/\b(?:how (?:do|can) i request (?:a )?(?:house|vehicle|car|motorcycle) blessing|request (?:house|vehicle|car) blessing|pabasbas ng (?:bahay|sasakyan)|how to submit a blessing request|paano magpa-?bless)\b/iu', $normalized)) {
            $blessType = 'Blessing';
            if (preg_match('/\bhouse|bahay/i', $normalized)) $blessType = 'House Blessing';
            elseif (preg_match('/\bvehicle|car|motorcycle|sasakyan/i', $normalized)) $blessType = 'Vehicle Blessing';

            $answer = $isFil
                ? "Maaari po kayong mag-request ng **{$blessType}**:\n\n1. Buksan ang **Request Blessing** page.\n2. Piliin ang kategorya (**{$blessType}**).\n3. Ilagay ang kumpletong address at landmark (para sa bahay) o uri ng sasakyan at plate number.\n4. Itakda ang nais na petsa, oras, at contact number.\n5. Isumite para sa pagtatalaga ng pari.\n\n[Open Blessing Requests](../users/request-blessing.php)"
                : "You can request an official **{$blessType}**:\n\n1. Open the **Request Blessing** page.\n2. Select the category (**{$blessType}**).\n3. Specify complete address and landmarks (for home) or vehicle model and plate number.\n4. Set your preferred date, time, and contact information.\n5. Submit for parish review and clergy assignment.\n\n[Open Blessing Requests](../users/request-blessing.php)";
            return [
                'answer' => $answer,
                'prompts' => ['What information should I provide for a blessing request?', 'Parish Schedule', 'Contact Parish Staff']
            ];
        }

        // G. Sacramental Reservations (Baptism Service, Wedding Service, Funeral Mass)
        if (preg_match('/\b(?:how (?:do|can) i (?:request|reserve|book) (?:a )?(?:baptism|marriage|wedding|funeral) (?:service|mass|reservation)|how do i make a sacramental service reservation|magpa-?binyag|magpakasal|misa sa patay|reserve wedding|reserve baptism|reserve funeral)\b/iu', $normalized)) {
            $serviceName = 'Sacramental Service';
            if (preg_match('/\bbaptism|binyag/i', $normalized)) $serviceName = 'Baptism Service';
            elseif (preg_match('/\bmarriage|wedding|kasal/i', $normalized)) $serviceName = 'Matrimony / Wedding Service';
            elseif (preg_match('/\bfuneral|patay|libing/i', $normalized)) $serviceName = 'Funeral Mass / Blessing';

            $answer = $isFil
                ? "Para sa pag-reserve ng **{$serviceName}**:\n\n1. Buksan ang **Request Service** o **Make Reservation** page.\n2. Piliin ang **{$serviceName}**.\n3. Pumili ng bakanteng petsa at oras sa liturgical calendar.\n4. I-upload ang mga kinakailangang dokumento (PSA Birth/Death cert, Marriage contract, atbp.).\n5. Isumite para sa kumpirmasyon ng opisina ng parokya.\n\n[Request Service](../users/request-service.php)"
                : "To book an official **{$serviceName}**:\n\n1. Open **Request Service** or **Make Reservation**.\n2. Select **{$serviceName}**.\n3. Choose an available calendar date and timeslot.\n4. Upload supporting documents (PSA birth/death cert, marriage contract, etc.).\n5. Submit for parish schedule verification.\n\n[Request Service](../users/request-service.php)";
            return [
                'answer' => $answer,
                'prompts' => ['What are the requirements for ' . $serviceName . '?', 'View Parish Schedules', 'Contact Parish Staff']
            ];
        }

        // H. Status Meaning Inquiries (Pending, Approved, Processing, Rejected)
        if (preg_match('/\b(?:what does (?:pending|approved|processing|rejected) mean|ano (?:ang )?ibig sabihin ng (?:pending|approved|processing|rejected)|why was my request rejected|what should i do if my request was rejected|can i submit another request after rejection)\b/iu', $normalized)) {
            if (preg_match('/\brejected/i', $normalized)) {
                $answer = $isFil
                    ? "Tungkol sa **Rejected Status**:\n\n• **Bakit na-reject?**: Karaniwang dahilan ay malabo o maling dokumento, kulang na requirements, o discrepancy sa rekord. Ang eksaktong dahilan ay nakasulat sa **Admin Remarks** ng inyong request.\n• **Ano ang dapat gawin?**: Basahin ang admin remarks sa [My Requests](../users/my-requests.php), ihanda ang tamang dokumento, at magsumite ng panibagong request.\n• **Maaari bang mag-submit ulit?**: **Opo, tiyak.** Maaari kayong magsumite muli agad nang walang abala.\n\n[View My Requests](../users/my-requests.php)"
                    : "Regarding **Rejected Status**:\n\n• **Why was it rejected?**: Common reasons include blurry/incorrect document uploads, missing requirements, or record discrepancies. The specific reason is written in the **Admin Remarks** on your request details.\n• **What should you do?**: Review the remarks in [My Requests](../users/my-requests.php), prepare the corrected document, and submit a new request.\n• **Can you submit another request?**: **Yes, absolutely.** You can submit a fresh request anytime.\n\n[View My Requests](../users/my-requests.php)";
            } else {
                $answer = $isFil
                    ? "Kahulugan ng mga Status sa TUGON:\n\n• **Pending**: Natanggap na ang request at kasalukuyang sinusuri ng parish staff.\n• **Approved**: Na-verify na ang mga dokumento at opisyal nang sinisimulan o nakareserba na ang schedule.\n• **Processing**: Iniimprenta, pinipirmahan ng Parish Priest, at nilalagyan ng opisyal na dry seal ang inyong sertipiko.\n• **Ready for Pickup**: Handa na pong kunin sa tanggapan ng parokya dala ang inyong Valid ID at Reference Number.\n\n[View My Requests](../users/my-requests.php)"
                    : "Status Definitions in TUGON:\n\n• **Pending**: Request received and currently awaiting initial review by parish staff.\n• **Approved**: Information verified; document preparation or calendar booking is officially confirmed.\n• **Processing**: Certificate is being formatted, printed on official parchment, signed by the Parish Priest, and dry-sealed.\n• **Ready for Pickup**: Official document is ready to claim at the parish office by presenting your Valid ID and Reference Number.\n\n[View My Requests](../users/my-requests.php)";
            }
            return [
                'answer' => $answer,
                'prompts' => ['Track My Requests', 'How long does certificate processing take?', 'Contact Parish Staff']
            ];
        }

        // I. Document Submission & Valid ID Upload Guidance
        if (preg_match('/\b(?:how (?:do|can) i upload (?:my )?valid id|what documents (?:do i need to submit|to submit)|what documents do i need|paano mag-?upload ng id|anong dokumento ang kailangan)\b/iu', $normalized)) {
            $answer = $isFil
                ? "Gabay sa **Pag-upload ng Valid ID at Dokumento**:\n\n• **Paano mag-upload**: Sa form, i-click ang 'Choose File' o i-drag ang malinaw na kopya (JPG, PNG, o PDF, hanggang 10MB). Tiyaking maliwanag at kitang-kita ang 4 na sulok ng ID.\n• **Mga Tinatanggap na Valid ID**: PhilSys National ID, Driver's License, Passport, UMID, Postal ID, PRC ID, Voter's ID.\n• **Pangunahing Dokumento**:\n  - *Sertipiko*: PSA Birth Certificate, Valid ID\n  - *Binyag*: PSA Birth Certificate ng bata, Marriage Contract ng magulang\n  - *Kasal*: PSA Birth Certs, CENOMAR, Annotated Baptismal/Confirmation certs, Pre-Cana cert, Marriage License.\n\n[Request Certificate](../users/request-certificate.php)"
                : "Guide for **Uploading Valid ID and Supporting Documents**:\n\n• **How to upload**: Click 'Choose File' or drag your file (JPG, PNG, or PDF, up to 10MB) into the upload box. Ensure good lighting and all 4 corners are visible.\n• **Accepted Valid IDs**: PhilSys National ID, Driver's License, Passport, UMID, Postal ID, PRC ID, Voter's ID.\n• **Required Documents**:\n  - *Certificates*: PSA Birth Certificate & Valid ID\n  - *Baptism Service*: Child's PSA Birth Certificate & Parents' Marriage Contract\n  - *Wedding Service*: PSA Birth Certs, CENOMAR, Annotated Baptismal/Confirmation certs, Pre-Cana cert, Marriage License.\n\n[Request Certificate](../users/request-certificate.php)";
            return [
                'answer' => $answer,
                'prompts' => ['Requirements for Baptism', 'Requirements for Marriage', 'Request Certificate']
            ];
        }

        // J. Sacramental Requirements (Baptism, Confirmation, Marriage, Communion, Blessings)
        if (preg_match('/\b(?:what are the requirements for (?:baptism|confirmation|marriage|first communion|wedding)|requirements for (?:baptism|confirmation|marriage|communion|kasal|binyag|kumpil)|what information should i provide for a blessing request)\b/iu', $normalized)) {
            if (preg_match('/\bblessing/i', $normalized)) {
                $answer = $isFil
                    ? "Mga kailangan para sa **Blessing Request**:\n1. Uri ng blessing (Bahay, Sasakyan, Negosyo, Imahen)\n2. Kumpletong address at landmark\n3. Nais na petsa at oras\n4. Pangalan at contact number ng humihiling\n5. Karagdagang paalala para sa pari.\n\n[Request Blessing](../users/request-blessing.php)"
                    : "Information required for a **Blessing Request**:\n1. Blessing category (House, Vehicle, Business, Religious Articles)\n2. Complete physical address and landmark\n3. Preferred date and time\n4. Contact person name and mobile number\n5. Any special notes for the priest.\n\n[Request Blessing](../users/request-blessing.php)";
            } elseif (preg_match('/\bmarriage|wedding|kasal/i', $normalized)) {
                $answer = $isFil
                    ? "Requirements para sa **Kasal (Holy Matrimony)**:\n1. PSA Birth Certificate (Groom & Bride)\n2. PSA CENOMAR (Certificate of No Marriage Record)\n3. Updated Baptismal & Confirmation Certificates na may tatak na 'For Marriage Purposes'\n4. Pre-Cana Marriage Preparation Seminar Certificate\n5. Canonical Interview sa Kura Paroko\n6. Tawag sa Simbahan (Marriage Banns - 3 Linggo)\n7. Marriage License o Article 34 Affidavit.\n\n[Reserve Wedding](../users/request-service.php)"
                    : "Requirements for **Holy Matrimony / Wedding**:\n1. PSA Birth Certificates (Bride & Groom)\n2. PSA CENOMAR (Certificate of No Marriage Record)\n3. Updated Baptismal & Confirmation Certificates annotated 'For Marriage Purposes'\n4. Pre-Cana Marriage Seminar Certificate\n5. Canonical Interview with Parish Priest\n6. Publication of Marriage Banns (3 consecutive Sundays)\n7. Marriage License or Article 34 Affidavit.\n\n[Reserve Wedding](../users/request-service.php)";
            } elseif (preg_match('/\bbaptism|binyag/i', $normalized)) {
                $answer = $isFil
                    ? "Requirements para sa **Binyag (Baptism)**:\n1. PSA / Local Civil Registrar Birth Certificate ng bata\n2. Catholic Marriage Certificate ng mga magulang (kung kasal)\n3. Listahan ng mga Ninong at Ninang (kahit isa ay Katoliko)\n4. Pagdalo sa Pre-Baptismal Seminar\n5. Parish Permission Letter (kung nakatira sa labas ng nasasakupan ng parokya).\n\n[Reserve Baptism](../users/request-service.php)"
                    : "Requirements for **Baptism**:\n1. Child's PSA / Civil Registrar Birth Certificate\n2. Parents' Catholic Marriage Certificate (if married)\n3. Godparent / Sponsor list (at least 1 Catholic sponsor)\n4. Pre-Baptismal Seminar attendance\n5. Parish Permission Letter (if living outside parish territory).\n\n[Reserve Baptism](../users/request-service.php)";
            } elseif (preg_match('/\bconfirmation|kumpil/i', $normalized)) {
                $answer = $isFil
                    ? "Requirements para sa **Kumpil (Confirmation)**:\n1. PSA Birth Certificate\n2. Baptismal Certificate na may tatak na 'For Confirmation Purposes'\n3. Isang Katolikong Ninong o Ninang\n4. Pagdalo sa Confirmation Catechesis.\n\n[Request Service](../users/request-service.php)"
                    : "Requirements for **Confirmation**:\n1. PSA Birth Certificate\n2. Baptismal Certificate annotated 'For Confirmation Purposes'\n3. One Catholic sponsor (Ninong/Ninang)\n4. Attendance in parish Confirmation Catechesis.\n\n[Request Service](../users/request-service.php)";
            } else {
                $answer = $isFil
                    ? "Requirements para sa **First Holy Communion**:\n1. PSA Birth Certificate\n2. Baptismal Certificate\n3. Pagkakatapos ng First Communion Catechism classes at unang kumpisal.\n\n[Request Service](../users/request-service.php)"
                    : "Requirements for **First Holy Communion**:\n1. PSA Birth Certificate\n2. Baptismal Certificate\n3. Completion of First Communion Catechism instruction and First Confession.\n\n[Request Service](../users/request-service.php)";
            }
            return [
                'answer' => $answer,
                'prompts' => ['Request Certificate', 'Request Service', 'Parish Schedule']
            ];
        }

        // K. Processing Time & Available Certificates / Services
        if (preg_match('/\b(?:how long does certificate processing take|processing time|what certificate types are available|what parish services are available|available certificates|available services)\b/iu', $normalized)) {
            if (preg_match('/\bhow long|time|tagal/i', $normalized)) {
                $answer = $isFil
                    ? "Ang pagproseso ng opisyal na sertipiko ay tumatagal ng **2 hanggang 3 araw ng trabaho (working days)** mula sa verification ng requirements at kumpirmasyon ng bayad. Makatatanggap kayo ng SMS at email kapag ito ay **Ready for Pickup** na."
                    : "Official certificate processing typically takes **2 to 3 working days** upon verification of submitted requirements and payment confirmation. You will receive an SMS and email notification when it is **Ready for Pickup**.";
            } else {
                $answer = $isFil
                    ? "Mga Serbisyo at Sertipiko sa TUGON:\n\n• **Mga Sertipiko**: Baptismal, Confirmation, First Communion, Marriage, at Death Certificates.\n• **Mga Sakramento**: Binyag, Kumpil, Kasal, Funeral Mass, at Mass Intentions.\n• **Mga Basbas**: Bahay, Sasakyan, Negosyo, at mga Banal na Imahen.\n• **Pasilidad**: Parish Hall at Church Venue reservation.\n\n[Request Certificate](../users/request-certificate.php) • [Request Service](../users/request-service.php)"
                    : "Available Services & Certificates in TUGON:\n\n• **Certificates**: Baptismal, Confirmation, First Communion, Marriage, and Death Certificates.\n• **Sacraments**: Baptism, Confirmation, Holy Matrimony (Wedding), Funeral Mass, and Mass Intentions.\n• **Blessings**: House, Vehicle, Business, and Religious Articles.\n• **Facilities**: Parish Hall & Church Venue reservations.\n\n[Request Certificate](../users/request-certificate.php) • [Request Service](../users/request-service.php)";
            }
            return [
                'answer' => $answer,
                'prompts' => ['Request Certificate', 'Request Service', 'Track My Requests']
            ];
        }

        // L. Notifications & Preferences
        if (preg_match('/\b(?:where can i view (?:my )?notifications|how can i manage my notification preferences|notification preferences|notifications center|tingnan ang notifications)\b/iu', $normalized)) {
            $answer = $isFil
                ? "Maaari ninyong tingnan ang inyong mga abiso sa **Notification Center** sa pamamagitan ng pag-click sa 🔔 Bell icon o pagbukas ng `users/notifications.php`. Doon din ninyo maaaring i-set ang inyong SMS, Email, at In-App notification preferences.\n\n[View Notifications](../users/notifications.php)"
                : "You can view all system updates in the **Notification Center** by clicking the 🔔 Bell icon or opening `users/notifications.php`. You can also configure SMS, Email, and In-App alert preferences there.\n\n[View Notifications](../users/notifications.php)";
            return [
                'answer' => $answer,
                'prompts' => ['View Notifications', 'Profile Settings', 'Track My Requests']
            ];
        }

        // M. AI Assistant Capabilities & Approvals
        if (preg_match('/\b(?:can the ai assistant approve my request|who approves my request|what can the tugon ai assistant help me with|how do i know if my reservation was approved|can i change my requested schedule)\b/iu', $normalized)) {
            if (preg_match('/\bcan the ai|ai approve/i', $normalized)) {
                $answer = $isFil
                    ? "**Hindi po.** Ang TUGON AI ay isang read-only guide para sa impormasyon, gabay sa form, at pag-track. Lahat ng opisyal na pag-apruba at pag-isyu ng sertipiko ay eksklusibong isinasagawa ng **Parish Secretary (Agnes C. Calapaan)** at **Kura Paroko (Rev. Fr. Alberto G. Cahilig, OMI)**."
                    : "**No.** TUGON AI is a read-only assistant for information, form guidance, and status lookups. Official approvals, document verifications, and issuances are strictly handled by the **Parish Secretary (Agnes C. Calapaan)** and the **Parish Priest (Rev. Fr. Alberto G. Cahilig, OMI)**.";
            } elseif (preg_match('/\bwho approves/i', $normalized)) {
                $answer = $isFil
                    ? "Ang inyong mga request at reservation ay sinusuri at inaaprubahan ng **Parish Office Staff & Secretary (Agnes C. Calapaan)** sa ilalim ng pamumuno ng **Parish Priest (Rev. Fr. Alberto G. Cahilig, OMI)**."
                    : "Your requests and reservations are reviewed, verified, and approved by the **Parish Office Staff & Secretary (Agnes C. Calapaan)** under the pastoral authority of **Parish Priest Rev. Fr. Alberto G. Cahilig, OMI**.";
            } elseif (preg_match('/\bchange.*schedule|reschedule/i', $normalized)) {
                $answer = $isFil
                    ? "Kung ang inyong request ay **Pending** pa, maaari itong i-cancel at magsumite ng bago, o makipag-ugnayan sa opisina ng parokya. Kung **Approved** na, mangyaring direktang tumawag sa Parish Secretary sa **0997 742 8176** upang maisaayos ang kalendaryo nang walang conflict."
                    : "If your request is still **Pending**, you can cancel and resubmit with your new preferred date, or contact the parish office. If already **Approved**, please contact the Parish Secretary directly at **0997 742 8176** to safely adjust the calendar.";
            } else {
                $answer = $isFil
                    ? "Ang **TUGON AI Assistant** ay makatutulong sa inyo sa:\n1. Pagsagot sa mga katanungan tungkol sa requirements at bayarin sa sertipiko\n2. Pagbibigay ng iskedyul ng Misa, oras ng opisina, at mga kaganapan\n3. Pagsusuri ng bilang at live status ng inyong mga naisumiteng request\n4. Hakbang-hakbang na gabay sa paghiling ng basbas at reserbasyon\n5. Pagpapaliwanag ng mga patakaran ng parokya sa Tagalog o English."
                    : "The **TUGON AI Assistant** can help you with:\n1. Answering questions about certificate requirements and procedures\n2. Providing Mass schedules, office hours, and liturgical calendars\n3. Checking the count and live status of your active requests\n4. Step-by-step guidance for booking blessings and sacramental reservations\n5. Explaining parish guidelines in English, Tagalog, or Taglish.";
            }
            return [
                'answer' => $answer,
                'prompts' => ['Request Certificate', 'Track My Requests', 'Contact Parish Staff']
            ];
        }

        // N. Data Privacy, Security & Discrepancy Handling
        if (preg_match('/\b(?:how does tugon protect my information|who can see my submitted documents|what should i do if i uploaded the wrong document|what should i do if my information is incorrect|protect my information|wrong document|information is incorrect|why does tugon require a valid id|can i submit a request without uploading|what should i do if my uploaded document is blurry|can i upload another document after submitting|how do i know if my document was successfully uploaded|can i request a certificate for another family member|what should i do if my name is different|what should i do if my sacramental record cannot be found|can i request a correction to my parish record|what should i do if the information on my certificate is incorrect)\b/iu', $normalized)) {
            if (preg_match('/\bwho can see/i', $normalized)) {
                $answer = $isFil
                    ? "Kayo lamang (ang may-ari ng account) at ang mga **Awtorisadong Parish Personnel** (Kura Paroko at Parish Secretary) ang may pahintulot na makakita ng inyong mga naisumiteng ID at dokumento. Naka-store ang mga ito sa protektadong storage."
                    : "Only you (the account owner) and **Authorized Parish Personnel** (Parish Priest & Secretary) have permission to view your submitted IDs and sacramental documents. They are stored in secure, restricted storage.";
            } elseif (preg_match('/\bwhy.*valid id/i', $normalized)) {
                $answer = $isFil
                    ? "Hinihingi ng TUGON ang **Valid ID** upang maprotektahan ang mga sagradong talaan ng parokya, maiwasan ang identity theft, at matiyak na ang mga sertipiko ay maibibigay lamang sa may-ari o awtorisadong kinatawan."
                    : "TUGON requires a **Valid ID** to safeguard sacramental records, prevent fraudulent requests, and ensure official certificates are released only to verified individuals or authorized representatives.";
            } elseif (preg_match('/\bwithout uploading|no document/i', $normalized)) {
                $answer = $isFil
                    ? "Hindi po maaaring mag-submit nang walang kinakailangang dokumento. Ang mga mandatoryong dokumento (tulad ng PSA Birth Certificate o Valid ID) ay kailangan bago maiproseso ang request."
                    : "No, you cannot submit without the required documents. Mandatory supporting documents (such as a PSA Birth Certificate or Valid ID) must be attached before submitting.";
            } elseif (preg_match('/\bblurry|malabo/i', $normalized)) {
                $answer = $isFil
                    ? "Kung malabo ang na-upload na dokumento, buksan ang inyong request sa [My Requests](../users/my-requests.php) o magsumite ng bago na may malinaw at maliwanag na litrato (JPG/PNG) o scanned PDF kung saan kita ang lahat ng sulok."
                    : "If your uploaded file is blurry, open your request in [My Requests](../users/my-requests.php) to re-upload, or submit a high-resolution, well-lit photo (JPG/PNG) or scanned PDF showing all 4 corners.";
            } elseif (preg_match('/\bupload.*another|upload.*after/i', $normalized)) {
                $answer = $isFil
                    ? "Opo! Kung ang inyong request ay **Pending** pa o kung may admin remark ang opisina ng parokya, maaari kayong mag-upload ng karagdagang dokumento sa detalye ng inyong request sa [My Requests](../users/my-requests.php)."
                    : "Yes! While your request is **Pending** or if staff requested additional requirements, you can upload supplemental documents directly from your request details page in [My Requests](../users/my-requests.php).";
            } elseif (preg_match('/\bsuccessfully uploaded|uploaded checkmark/i', $normalized)) {
                $answer = $isFil
                    ? "Malalaman ninyong matagumpay ang upload kapag may lumitaw na berdeng checkmark (✓), pangalan ng file, at preview thumbnail sa requirements section."
                    : "You will know your document was successfully uploaded when a green checkmark (✓), filename, and preview thumbnail appear in the upload section.";
            } elseif (preg_match('/\banother family member|kumuha para sa iba/i', $normalized)) {
                $answer = $isFil
                    ? "Opo, maaari kayong kumuha ng sertipiko para sa inyong kapamilya (halimbawa, magulang para sa anak). Magdala lamang ng **Authorization Letter** at Valid ID ninyo at ng may-ari ng dokumento kapag kukunin na sa opisina."
                    : "Yes, you can request a certificate for an immediate family member (e.g., parents for their children). Please bring an **Authorization Letter** and Valid IDs of both parties when claiming at the parish office.";
            } elseif (preg_match('/\bname is different|record cannot be found|correction|mali ang nakasulat/i', $normalized)) {
                $answer = $isFil
                    ? "Kung may discrepancy sa pangalan, hindi mahanap ang rekord, o kailangan ng pagwawasto:\n1. Maghanda ng opisyal na **PSA Birth Certificate** o **Affidavit of One and the Same Person**.\n2. Makipag-ugnayan sa Parish Secretary sa **0997 742 8176** upang masuri nang manual ang mga pisikal na libro ng parokya."
                    : "If your name is different, record cannot be found, or you need a record correction:\n1. Prepare an official **PSA Birth Certificate** or **Affidavit of One and the Same Person**.\n2. Contact the Parish Secretary at **0997 742 8176** so staff can conduct a manual search in the parish physical registry books.";
            } elseif (preg_match('/\bwrong document|information is incorrect/i', $normalized)) {
                $answer = $isFil
                    ? "Kung may maling dokumento o impormasyon:\n1. Para sa profile details, i-update agad sa [Profile Settings](../auth/profile.php).\n2. Para sa naisumiteng request na Pending, kontakin ang Parish Secretary sa **0997 742 8176** dala ang inyong Reference Number upang maiwasto bago i-print ang sertipiko.\n\n[Profile Settings](../auth/profile.php)"
                    : "If you uploaded a wrong document or have incorrect details:\n1. For profile details, update them in [Profile Settings](../auth/profile.php).\n2. For an active Pending request, contact the Parish Secretary at **0997 742 8176** with your Reference Number so the record can be corrected before printing.\n\n[Profile Settings](../auth/profile.php)";
            } else {
                $answer = $isFil
                    ? "Pinangangalagaan ng TUGON ang inyong impormasyon sa pamamagitan ng:\n• Matibay na password hashing (bcrypt)\n• SSL/TLS encrypted data transmission\n• Awtomatikong pag-redact ng mga sensitibong detalye sa AI query logs\n• Mahigpit na Role-Based Access Control (RBAC)\n• Araw-araw na backup at proteksyon sa data."
                    : "TUGON protects your information through:\n• Strong password hashing (bcrypt)\n• SSL/TLS encrypted data transmission\n• Automated redaction of sensitive identifiers in AI query logs\n• Strict Role-Based Access Control (RBAC)\n• Regular encrypted backups and data privacy safeguards.";
            }
            return [
                'answer' => $answer,
                'prompts' => ['Profile Settings', 'Contact Parish Staff', 'Track My Requests']
            ];
        }

        // O. Edit, Cancel & Multiple Requests Operations
        if (preg_match('/\b(?:can i edit my request|can i cancel a submitted request|can i submit more than one|can i have more than one active reservation|accidentally submit the same request twice|how do i know if my certificate is ready for release|what should i do after my certificate request is approved|can i download my certificate from tugon)\b/iu', $normalized)) {
            if (preg_match('/\bedit/i', $normalized)) {
                $answer = $isFil
                    ? "Kung ang request ay **Pending** pa, maaari itong i-cancel at magsumite ng bago, o tawagan ang Parish Secretary sa **0997 742 8176**. Kapag In Processing na, hindi na ito mababago nang direkta sa portal."
                    : "If your request is still **Pending**, you can cancel and resubmit, or contact the Parish Secretary at **0997 742 8176**. Once In Processing, details cannot be modified directly in the portal.";
            } elseif (preg_match('/\bcancel|twice|duplicate/i', $normalized)) {
                $answer = $isFil
                    ? "Opo! Maaari ninyong i-cancel ang isang Pending request o nadobleng submission sa pamamagitan ng pagbukas ng inyong request sa [My Requests](../users/my-requests.php) at pag-click sa **Cancel Request**."
                    : "Yes! You can cancel a Pending or accidental duplicate request by opening it in [My Requests](../users/my-requests.php) and clicking **Cancel Request**.";
            } elseif (preg_match('/\bmore than one|multiple/i', $normalized)) {
                $answer = $isFil
                    ? "Opo, maaari kayong magsumite ng mahigit sa isang certificate request o reservation nang sabay. Bawat isa ay magkakaroon ng sariling Reference Number para sa hiwalay na tracking."
                    : "Yes! You can have multiple certificate requests and active reservations at the same time. Each will have its own unique Reference Number for tracking.";
            } elseif (preg_match('/\bdownload/i', $normalized)) {
                $answer = $isFil
                    ? "Ang opisyal na sertipiko ng Simbahang Katolika ay kailangang may orihinal na lagda ng Kura Paroko at dry seal ng parokya, kaya kailangan itong kunin nang personal sa tanggapan ng parokya dala ang inyong Reference Number at Valid ID."
                    : "Official Catholic certificates must bear the original pen signature of the Parish Priest and the parish embossed dry seal, so they must be claimed physically at the parish office.";
            } else {
                $answer = $isFil
                    ? "Kapag ang inyong request ay naging **Ready for Pickup**, magtungo lamang sa tanggapan ng parokya dala ang inyong **Reference Number** at isang **Valid ID** upang makuha ang inyong opisyal na sertipiko."
                    : "When your request status updates to **Ready for Pickup**, proceed to the parish office with your **Reference Number** and **1 Valid ID** to claim your official certificate.";
            }
            return [
                'answer' => $answer,
                'prompts' => ['Track My Requests', 'Parish Office Hours', 'Request Certificate']
            ];
        }

        // P. Technical, Mobile & Browser Compatibility
        if (preg_match('/\b(?:can i use tugon on my mobile phone|what browsers can i use|what should i do if tugon is not loading|lose internet connection|why does my account need administrator approval|what should i do if my registration is rejected|why did i not receive a request notification|can i still check my request if i did not receive a notification)\b/iu', $normalized)) {
            if (preg_match('/\bmobile|cellphone|browser/i', $normalized)) {
                $answer = $isFil
                    ? "Opo! Gumagana ang TUGON sa anumang smartphone, tablet, o computer gamit ang **Google Chrome, Safari, Mozilla Firefox, Microsoft Edge, o Opera**."
                    : "Yes! TUGON is fully optimized for mobile smartphones, tablets, and computers using **Google Chrome, Apple Safari, Mozilla Firefox, Microsoft Edge, or Opera**.";
            } elseif (preg_match('/\bnot loading|internet|connection/i', $normalized)) {
                $answer = $isFil
                    ? "Kung hindi naglo-load o nawalan ng internet:\n1. I-refresh ang page o i-clear ang cache ng browser.\n2. Pagkabalik ng internet, buksan ang [My Requests](../users/my-requests.php) upang tingnan kung pumasok ang inyong submission."
                    : "If TUGON is not loading or you lost internet connection:\n1. Refresh the page or clear browser cache.\n2. Upon reconnecting, check [My Requests](../users/my-requests.php) to verify if your request went through.";
            } elseif (preg_match('/\badmin.*approval|rejected/i', $normalized)) {
                $answer = $isFil
                    ? "Ang pagsusuri ng Administrator sa bagong account ay upang mapatunayan ang tunay na pagkakakilanlan ng parokyano gamit ang Valid ID at mapanatiling ligtas ang sistema. Kung na-reject, mag-register muli gamit ang malinaw na Valid ID."
                    : "Administrator approval verifies authentic parishioner identity via government ID and keeps the portal secure. If rejected, please register again with a clear, valid ID.";
            } else {
                $answer = $isFil
                    ? "Kahit walang natanggap na SMS o email notification, maaari ninyong tingnan ang inyong mga request 24/7 sa [My Requests](../users/my-requests.php) o sa 🔔 Notifications page."
                    : "Even without receiving an SMS or email alert, you can always check your live request status 24/7 in [My Requests](../users/my-requests.php) or under the 🔔 Notifications center.";
            }
            return [
                'answer' => $answer,
                'prompts' => ['Track My Requests', 'Profile Settings', 'Create Account']
            ];
        }

        // Q. AI Assistant Scope & Human Authority Clarification
        if (preg_match('/\b(?:can the ai assistant answer questions outside|which information should i follow|what should i do if the information from the ai assistant is different|can the ai assistant change my request status|can i ask the ai assistant about|what can the tugon ai assistant help me with)\b/iu', $normalized)) {
            if (preg_match('/\bwhich information|different|follow/i', $normalized)) {
                $answer = $isFil
                    ? "Laging sundin ang **Parish Secretary (Agnes C. Calapaan)** at ang **Parish Priest (Rev. Fr. Alberto G. Cahilig, OMI)**. Ang opisina ng parokya ang opisyal na awtoridad; ang TUGON AI ay isang gabay lamang."
                    : "Always follow the **Parish Secretary (Agnes C. Calapaan)** and the **Parish Priest (Rev. Fr. Alberto G. Cahilig, OMI)**. The parish office is the official canonical authority; TUGON AI serves as an informational assistant.";
            } elseif (preg_match('/\boutside/i', $normalized)) {
                $answer = $isFil
                    ? "Ang TUGON AI ay nakadisenyo lamang para sa mga serbisyo, iskedyul, sakramento, sertipiko, at patakaran ng Parokya ng San Lorenzo Ruiz."
                    : "TUGON AI is strictly dedicated to San Lorenzo Ruiz Parish services, sacraments, Mass schedules, certificates, and parishioner requests.";
            } else {
                $answer = $isFil
                    ? "Maaari ninyong itanong sa TUGON AI ang tungkol sa mga requirements sa sakramento, iskedyul ng misa, paano mag-request ng sertipiko o basbas, at ang live status ng inyong mga kahilingan."
                    : "You can ask TUGON AI about sacramental requirements, Mass times, how to request certificates or blessings, and check the live status of your active requests.";
            }
            return [
                'answer' => $answer,
                'prompts' => ['Mass Schedule', 'Request Certificate', 'Contact Parish Staff']
            ];
        }

        // R. Announcements
        if (preg_match('/\b(?:where can i (?:see|view|find|check) (?:parish )?announcements|what are the (?:latest )?announcements|parish announcements|mga anunsyo|balita sa parokya)\b/iu', $normalized)) {
            $answer = $isFil
                ? "Maaari ninyong basahin ang mga pinakabagong balita, anunsyo para sa kapistahan, paalala sa misa, at mga aktibidad ng komunidad sa **Announcements** page:\n\n[View Announcements](../users/announcements.php)"
                : "You can view the latest parish news, mass advisories, feast day schedules, and community announcements on the **Announcements** page:\n\n[View Announcements](../users/announcements.php)";
            return [
                'answer' => $answer,
                'prompts' => ['View Announcements', 'Parish Schedule', 'Mass Schedule']
            ];
        }

        // S. Schedules and Events
        if (preg_match('/\b(?:where can i (?:see|view|find|check) (?:the )?(?:parish )?schedule|how can i check upcoming (?:parish )?events?|upcoming (?:parish )?events?|parish events?|parish schedule|mass schedule|mass times?|parish calendar|oras ng misa|iskedyul ng misa|upcoming mass schedules|what schedules are available)\b/iu', $normalized)) {
            $answer = $isFil
                ? "Maaari ninyong tingnan ang kumpletong iskedyul ng mga Misa (Linggo at Araw-araw), mga kaganapan, at banal na pagdiriwang sa ating **Parish Calendar**:\n\n• **Misa tuwing Linggo**: 6:00 AM, 8:00 AM, 10:00 AM, 4:00 PM, 5:30 PM, 7:00 PM\n• **Araw-araw (Martes - Sabado)**: 6:30 AM, 6:00 PM\n\n[View Schedule](../users/view-schedule.php)"
                : "You can view the comprehensive parish schedule, regular Sunday and weekday Mass times, feast day celebrations, and sacramental calendar here:\n\n• **Sunday Masses**: 6:00 AM, 8:00 AM, 10:00 AM, 4:00 PM, 5:30 PM, 7:00 PM\n• **Weekday Masses (Tue - Sat)**: 6:30 AM, 6:00 PM\n\n[View Schedule](../users/view-schedule.php)";
            return [
                'answer' => $answer,
                'prompts' => ['View Schedule', 'Request Certificate', 'Make Reservation']
            ];
        }

        // T. Payments and GCash
        if (preg_match('/\b(?:how (?:do|can) i pay|payment (?:info|information|status|details)|gcash (?:payment|receipt)|paano magbayad|bayad sa certificate)\b/iu', $normalized)) {
            $answer = $isFil
                ? "Para sa pagbabayad at pag-upload ng resibo:\n\n1. Buksan ang **Track Requests** (`my-requests.php`).\n2. Piliin ang inyong request upang makita ang detalye.\n3. Makikita roon ang opisyal na GCash account number ng parokya.\n4. I-upload ang screenshot ng inyong GCash transaction receipt upang ma-verify ng parish staff.\n\n[View My Requests](../users/my-requests.php)"
                : "To manage payments and upload transaction receipts:\n\n1. Open **Track Requests** (`my-requests.php`).\n2. Click on your request to view its details.\n3. View the official parish GCash details listed on the payment card.\n4. Upload your GCash transaction confirmation screenshot for staff verification.\n\n[View My Requests](../users/my-requests.php)";
            return [
                'answer' => $answer,
                'prompts' => ['Check My Requests', 'When can I claim my certificate?', 'Contact Parish Staff']
            ];
        }

        // U. Account Profile Updates & Password
        if (preg_match('/\b(?:how (?:do|can) i (?:update|change|edit) (?:my )?(?:profile|account|password|email)|paano palitan ang (?:profile|password)|how can i change my password|how can i update my profile information)\b/iu', $normalized)) {
            $answer = $isFil
                ? "Maaari ninyong i-update ang inyong pangalan, mobile number, tirahan, at palitan ang inyong password sa **Profile Settings**:\n\n[Profile Settings](../auth/profile.php)"
                : "You can update your personal contact details, residential address, and change your password in **Profile Settings**:\n\n[Profile Settings](../auth/profile.php)";
            return [
                'answer' => $answer,
                'prompts' => ['Profile Settings', 'Check My Requests', 'Parish Schedule']
            ];
        }

        // V. Parish Secretary & Office Contact
        if (preg_match('/\b(?:how (?:do|can) i contact the parish|who is the (?:parish )?secretary|sino (?:ang )?(?:parish )?secretary|sino (?:ang )?kalihim|parish secretary|secretary name|secretary contact|contact (?:the )?secretary|contact (?:the )?parish|agnes calapaan|agnes|calapaan)\b/iu', $normalized)) {
            $answer = $isFil
                ? "Maaari po kayong makipag-ugnayan sa tanggapan ng parokya sa pamamagitan ng:\n\n• **Parish Secretary**: Agnes C. Calapaan\n• 📞 **Contact**: 0997 742 8176\n• ⛪ **Parish Priest**: Rev. Fr. Alberto G. Cahilig, OMI\n• 🕒 **Oras ng Opisina**:\n  - Martes hanggang Sabado: 8:00 AM – 5:00 PM (Lunch: 12:00 PM – 1:00 PM)\n  - Linggo: 7:00 AM – 12:00 PM\n  - Lunes: Sarado ang opisina"
                : "You can contact the parish office through:\n\n• **Parish Secretary**: Agnes C. Calapaan\n• 📞 **Contact**: 0997 742 8176\n• ⛪ **Parish Priest**: Rev. Fr. Alberto G. Cahilig, OMI\n• 🕒 **Office Hours**:\n  - Tuesday to Saturday: 8:00 AM – 5:00 PM (Lunch: 12:00 PM – 1:00 PM)\n  - Sunday: 7:00 AM – 12:00 PM\n  - Monday: Office Closed";
            return [
                'answer' => $answer,
                'prompts' => ['Mass Schedule', 'Request Certificate', 'Parish Priest']
            ];
        }

        // W. Parish Priest & Clergy Inquiry
        if (preg_match('/\b(?:who is the (?:parish )?priest|sino (?:ang )?(?:parish )?priest|sino (?:ang )?pari|parish priest|who is the priest|pari ng parokya)\b/iu', $normalized)) {
            $answer = $isFil
                ? "Ang ating **Parish Priest** ay si **Rev. Fr. Alberto G. Cahilig, OMI**, at ang ating **Parochial Vicar** ay si **Rev. Fr. Alvin Vicente C. Barretto, OMI**."
                : "The Parish Priest is **Rev. Fr. Alberto G. Cahilig, OMI**, and the Parochial Vicar is **Rev. Fr. Alvin Vicente C. Barretto, OMI**.";
            return [
                'answer' => $answer,
                'prompts' => ['Parish Secretary', 'Mass Schedule', 'Parish Office Hours']
            ];
        }

        return null;
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
            '/\bpari\b/iu' => 'parish priest pari',
            '/\bsecretary\b/iu' => 'parish secretary kalihim agnes calapaan',
            '/\bkalihim\b/iu' => 'parish secretary agnes calapaan',
            '/\bhoy\s*many\b/iu' => 'how many',
            '/\bhw\s*many\b/iu' => 'how many',
            '/\bhw\s*much\b/iu' => 'how much'
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
        return (bool) preg_match('/parish|parokya|church|mass|misa|office|opisina|bapt|binyag|confirm|kumpil|communion|komunyon|marriage|wedding|kasal|bless|basbas|certificate|sertipiko|request|kahilingan|reserv|venue|schedule|iskedyul|announcement|anunsyo|payment|bayad|funeral|burial|libing|priest|pari|secretary|kalihim|agnes|calapaan|vicar|record|tala|sacrament|analytics|report|ulat|TUGON|requirement|kailangan|cost|magkano/i', $text);
    }
}
