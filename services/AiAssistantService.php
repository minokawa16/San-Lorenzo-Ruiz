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

        // A. User's Own Request Status Inquiry
        if (preg_match('/\b(?:status of (?:my|the) (?:request|certificate|blessing)|check (?:my|the) requests?|my requests?(?: status)?|kumusta (?:ang |yung )?request|anong status ng request|follow[- ]?up (?:sa )?request|check certificate status)\b/iu', $normalized)) {
            $stmt = $this->db->prepare("SELECT request_id, reference_number, request_type, status, date_requested FROM requests WHERE user_id=? AND deleted_at IS NULL ORDER BY date_requested DESC LIMIT 5");
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $result = $stmt->get_result();
                $requests = [];
                while ($row = $result->fetch_assoc()) {
                    $requests[] = $row;
                }
                $stmt->close();

                if (!empty($requests)) {
                    $lines = [];
                    foreach ($requests as $r) {
                        $ref = $r['reference_number'] ?: ('REQ-' . $r['request_id']);
                        $type = ucwords(str_replace('_', ' ', $r['request_type']));
                        $status = ucfirst($r['status']);
                        $date = date('M d, Y', strtotime($r['date_requested']));
                        $lines[] = "• **{$ref}** — {$type}\n  Status: **{$status}** (Requested on {$date})";
                    }
                    $listStr = implode("\n\n", $lines);
                    $answer = $isFil
                        ? "Narito po ang kasalukuyang status ng inyong mga isinumiteng kahilingan:\n\n{$listStr}\n\nMaaari ninyong buksan ang inyong kahilingan upang makita ang buong detalye, admin notes, o mag-upload ng GCash payment receipt:\n[View My Requests](../users/my-requests.php)"
                        : "Here is the current status of your submitted request(s):\n\n{$listStr}\n\nYou can track details, read admin notes, or upload your GCash payment receipt anytime:\n[View My Requests](../users/my-requests.php)";
                } else {
                    $answer = $isFil
                        ? "Wala pa po kayong aktibong request sa kasalukuyan. Kung nais ninyong kumuha ng sertipiko o humiling ng basbas, maaari po kayong magsumite dito:\n\n[Request Certificate](../users/request-certificate.php) [Request Blessing](../users/request-blessing.php)"
                        : "You currently have no submitted requests in the system. If you need a parish certificate or blessing, you can submit one below:\n\n[Request Certificate](../users/request-certificate.php) [Request Blessing](../users/request-blessing.php)";
                }
                return [
                    'answer' => $answer,
                    'prompts' => ['Request Certificate', 'Request Blessing', 'Parish Schedule']
                ];
            }
        }

        // B. User's Own Reservation Status Inquiry
        if (preg_match('/\b(?:status of (?:my|the) reservation|check (?:my|the) reservations?|my reservations?(?: status)?|kumusta (?:ang |yung )?reservation|anong status ng reservation|check reservation)\b/iu', $normalized)) {
            $stmt = $this->db->prepare("SELECT reservation_id, reservation_type, event_date, event_time, status FROM reservations WHERE user_id=? ORDER BY event_date DESC LIMIT 5");
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $result = $stmt->get_result();
                $reservations = [];
                while ($row = $result->fetch_assoc()) {
                    $reservations[] = $row;
                }
                $stmt->close();

                if (!empty($reservations)) {
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
                        ? "Narito po ang tala ng inyong mga reservation sa parokya:\n\n{$listStr}\n\n[Make Reservation](../users/make-reservation.php)"
                        : "Here is the status of your parish reservation(s):\n\n{$listStr}\n\n[Make Reservation](../users/make-reservation.php)";
                } else {
                    $answer = $isFil
                        ? "Wala pa po kayong aktibong reservation sa ating pasilidad o kaganapan. Kung nais ninyong magpa-reserve, i-click lamang po ang link sa ibaba:\n\n[Make Reservation](../users/make-reservation.php)"
                        : "You currently have no reservation bookings on file. If you would like to book a parish facility or schedule, click the link below:\n\n[Make Reservation](../users/make-reservation.php)";
                }
                return [
                    'answer' => $answer,
                    'prompts' => ['Make Reservation', 'Parish Schedule', 'Contact Parish Staff']
                ];
            }
        }

        // C. How to Request a Certificate
        if (preg_match('/\b(?:how (?:do|can) i (?:request|get|apply for|submit) (?:a )?(?:parish )?certificate|how to (?:get|request) (?:a )?certificate|paano (?:kumuha|mag-?request|humingi) ng (?:sertipiko|certificate)|how to request (?:baptism|confirmation|marriage) certificate)\b/iu', $normalized)) {
            $answer = $isFil
                ? "Narito po ang mga hakbang para sa paghiling ng **Sertipiko ng Parokya** (Binyag, Kumpil, o Kasal):\n\n1. Pumunta sa **Certificate Request** page.\n2. Piliin ang uri ng sertipiko (Baptismal, Confirmation, Marriage, o Good Moral).\n3. Ilagay ang mga kinakailangang personal na detalye at layunin.\n4. Mag-upload ng malinaw na kopya ng **PSA / Birth Certificate** (PDF, JPG, o PNG, hanggang 10MB).\n5. I-click ang **Submit Certificate Request** at itabi ang inyong Reference Number.\n\n[Open Certificate Requests](../users/request-certificate.php)"
                : "Here is the step-by-step guide to request an official **Parish Certificate** (Baptism, Confirmation, Marriage, or Good Moral):\n\n1. Open the **Certificate Request** page.\n2. Select your desired certificate type.\n3. Fill in the required personal details and purpose of request.\n4. Upload a clear copy of your **PSA / Birth Certificate** (PDF, JPG, or PNG, up to 10MB).\n5. Click **Submit Certificate Request** and save your assigned Reference Number.\n\n[Open Certificate Requests](../users/request-certificate.php)";
            return [
                'answer' => $answer,
                'prompts' => ['What does Pending mean?', 'When can I claim my certificate?', 'Check My Requests']
            ];
        }

        // D. How to Request a Blessing
        if (preg_match('/\b(?:how (?:do|can) i (?:request|apply for|submit) (?:a )?blessing|how to request (?:a )?blessing|paano (?:magpa-?bless|mag-?request ng blessing|humingi ng basbas)|house blessing|vehicle blessing|pabasbas ng (?:bahay|sasakyan))\b/iu', $normalized)) {
            $answer = $isFil
                ? "Maaari po kayong mag-request ng **Pagbabasbas (Blessing)** para sa inyong tahanan, sasakyan, negosyo, o mga banal na imahen:\n\n1. Buksan ang **Request Blessing** page.\n2. Piliin ang uri ng blessing (House, Vehicle, Business, o Religious Items).\n3. Itakda ang nais na petsa, oras, at kumpletong lokasyon o address.\n4. Isumite ang inyong kahilingan para sa kumpirmasyon ng opisina ng parokya.\n\n[Open Blessing Requests](../users/request-blessing.php)"
                : "You can request an official **Parish Blessing** for your home, vehicle, business, or religious items:\n\n1. Open the **Request Blessing** page.\n2. Select the blessing category (House, Vehicle, Business, or Religious Articles).\n3. Specify your preferred date, time, and complete location address.\n4. Submit your request for parish review and clergy assignment.\n\n[Open Blessing Requests](../users/request-blessing.php)";
            return [
                'answer' => $answer,
                'prompts' => ['Request Blessing', 'Parish Schedule', 'Check My Requests']
            ];
        }

        // E. How to Make a Parish Reservation
        if (preg_match('/\b(?:how (?:do|can) i (?:make|book|apply for) (?:a )?(?:parish )?reservation|how to (?:make|book) (?:a )?reservation|paano (?:mag-?reserve|mag-?book ng (?:simbahan|venue|hall|schedule)))\b/iu', $normalized)) {
            $answer = $isFil
                ? "Narito po ang proseso para sa **Parish Facility & Venue Reservation**:\n\n1. Buksan ang **Make Reservation** form.\n2. Piliin ang uri ng reserbasyon (Kasal, Binyag, Church Venue, atbp.).\n3. Piliin ang pasilidad/resource, petsa, at oras ng inyong kaganapan.\n4. Ilagay ang tagal (service, setup, cleanup) at karagdagang detalye.\n5. Isumite upang ma-review ng staff ng parokya ang schedule.\n\n[Make Reservation](../users/make-reservation.php)"
                : "Here is the guide to book a **Parish Reservation** for church venues and sacramental events:\n\n1. Open the **Make Reservation** page.\n2. Choose the reservation type (Wedding, Baptism, Venue Reservation, etc.).\n3. Select the resource/facility, target date, and start time.\n4. Enter the estimated duration (service, setup, cleanup) and event details.\n5. Submit for official review and schedule validation.\n\n[Make Reservation](../users/make-reservation.php)";
            return [
                'answer' => $answer,
                'prompts' => ['Make Reservation', 'Parish Schedule', 'Contact Parish Staff']
            ];
        }

        // F. Status Meaning Inquiries
        if (preg_match('/\b(?:what does (?:pending|approved|rejected|cancelled|ready for pickup) mean|ano (?:ang )?ibig sabihin ng (?:pending|approved|rejected|cancelled))\b/iu', $normalized)) {
            $answer = $isFil
                ? "Narito po ang kahulugan ng mga status sa TUGON System:\n\n• **Pending**: Natanggap na ang inyong kahilingan at kasalukuyang sinusuri ng parish staff.\n• **Approved**: Naaprubahan na ng tanggapan ng parokya. Sinisimulan na ang paggawa o nakareserba na ang inyong schedule.\n• **Ready for Pickup**: Handa na pong kunin ang inyong opisyal na dokumento sa opisina ng parokya.\n• **Rejected / Cancelled**: Hindi naaprubahan dahil sa kakulangan ng requirements o conflict sa schedule. Pakitingnan ang admin notes sa inyong request details.\n\n[View My Requests](../users/my-requests.php)"
                : "Here is what each request status means in the TUGON System:\n\n• **Pending**: Your request has been received and is in queue awaiting review by parish staff.\n• **Approved**: Your request has been verified and approved by the parish office. Document preparation or schedule booking is confirmed.\n• **Ready for Pickup**: Your physical certificate is printed, signed, stamped, and ready to be claimed at the parish office.\n• **Rejected / Cancelled**: The request could not be processed (e.g. missing requirements or date conflict). Please check admin notes on your request details page.\n\n[View My Requests](../users/my-requests.php)";
            return [
                'answer' => $answer,
                'prompts' => ['When can I claim my certificate?', 'Check My Requests', 'Request Certificate']
            ];
        }

        // G. When/How to Claim Certificate
        if (preg_match('/\b(?:when (?:can|do) i claim|how (?:do|can) i claim|where (?:do|can) i claim|paano i-?claim|saan kukunin|kailan makukuha).*(?:certificate|sertipiko)?\b/iu', $normalized)) {
            $answer = $isFil
                ? "Kapag ang inyong request ay minarkahang **Approved** o **Ready for Pickup**, makatatanggap po kayo ng notification. Maaari ninyong kunin ang inyong opisyal na sertipiko sa tanggapan ng parokya sa pamamagitan ng pagdadala ng:\n\n1. Inyong **Reference Number**\n2. Isang (1) **Valid Government o Student ID**\n3. Resibo o patunay ng bayad (kung kinakailangan)\n\n[View My Requests](../users/my-requests.php)"
                : "Once your certificate request is marked as **Approved** or **Ready for Pickup**, you will receive a notification. You can claim your physical certificate at the parish office by presenting:\n\n1. Your request **Reference Number**\n2. One (1) **Valid Government or Student ID**\n3. Official receipt / payment confirmation (if applicable)\n\n[View My Requests](../users/my-requests.php)";
            return [
                'answer' => $answer,
                'prompts' => ['Check My Requests', 'What does Pending mean?', 'Parish Office Hours']
            ];
        }

        // H. Announcements
        if (preg_match('/\b(?:where can i (?:see|view|find|check) (?:parish )?announcements|what are the (?:latest )?announcements|parish announcements|mga anunsyo|balita sa parokya)\b/iu', $normalized)) {
            $answer = $isFil
                ? "Maaari ninyong basahin ang mga pinakabagong balita, anunsyo para sa kapistahan, paalala sa misa, at mga aktibidad ng komunidad sa **Announcements** page:\n\n[View Announcements](../users/announcements.php)"
                : "You can view the latest parish news, mass advisories, feast day schedules, and community announcements on the **Announcements** page:\n\n[View Announcements](../users/announcements.php)";
            return [
                'answer' => $answer,
                'prompts' => ['View Announcements', 'Parish Schedule', 'Mass Schedule']
            ];
        }

        // I. Schedules and Events
        if (preg_match('/\b(?:where can i (?:see|view|find|check) (?:the )?(?:parish )?schedule|parish schedule|mass schedule|mass times?|parish calendar|oras ng misa|iskedyul ng misa)\b/iu', $normalized)) {
            $answer = $isFil
                ? "Maaari ninyong tingnan ang kumpletong iskedyul ng mga Misa (Linggo at Araw-araw), mga kaganapan, at banal na pagdiriwang sa ating **Parish Calendar**:\n\n[View Schedule](../users/view-schedule.php)"
                : "You can view the comprehensive parish schedule, regular Sunday and weekday Mass times, feast day celebrations, and sacramental calendar here:\n\n[View Schedule](../users/view-schedule.php)";
            return [
                'answer' => $answer,
                'prompts' => ['View Schedule', 'Request Certificate', 'Make Reservation']
            ];
        }

        // J. Payments and GCash
        if (preg_match('/\b(?:how (?:do|can) i pay|payment (?:info|information|status|details)|gcash (?:payment|receipt)|paano magbayad|bayad sa certificate)\b/iu', $normalized)) {
            $answer = $isFil
                ? "Para sa pagbabayad at pag-upload ng resibo:\n\n1. Buksan ang **Track Requests** (`my-requests.php`).\n2. Piliin ang inyong request upang makita ang detalye.\n3. Makikita roon ang opisyal na GCash account number ng parokya.\n4. I-upload ang screenshot ng inyong GCash transaction receipt upang ma-verify ng parish staff.\n\n[View My Requests](../users/my-requests.php)"
                : "To manage payments and upload transaction receipts:\n\n1. Open **Track Requests** (`my-requests.php`).\n2. Click on your request to view its details.\n3. View the official parish GCash details listed on the payment card.\n4. Upload your GCash transaction confirmation screenshot for staff verification.\n\n[View My Requests](../users/my-requests.php)";
            return [
                'answer' => $answer,
                'prompts' => ['Check My Requests', 'When can I claim my certificate?', 'Contact Parish Staff']
            ];
        }

        // K. Account Profile Updates
        if (preg_match('/\b(?:how (?:do|can) i (?:update|change|edit) (?:my )?(?:profile|account|password|email)|paano palitan ang (?:profile|password))\b/iu', $normalized)) {
            $answer = $isFil
                ? "Maaari ninyong i-update ang inyong pangalan, mobile number, address, at palitan ang inyong password sa **Profile Settings**:\n\n[Profile Settings](../auth/profile.php)"
                : "You can update your personal contact details, residential address, and change your password in **Profile Settings**:\n\n[Profile Settings](../auth/profile.php)";
            return [
                'answer' => $answer,
                'prompts' => ['Profile Settings', 'Check My Requests', 'Parish Schedule']
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
