<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/chatbot/ConversationalIntent.php';
require_once __DIR__ . '/ParishKnowledgeBase.php';
require_once __DIR__ . '/ParishAssistantTools.php';

final class AiAssistantService
{
    private mysqli $db;
    private ParishKnowledgeBase $kb;
    private ParishAssistantTools $tools;

    public const SYSTEM_PROMPT = "You are the Parish Assistant for this parish management system. Help parishioners and staff understand how to use the system and answer questions about parish services. Use ONLY the provided knowledge base and live data tool results. Never invent steps, numbers, names, or dates. The user may have typos, short phrases, or mix English, Filipino, and Bisaya; infer their intent and answer anyway. Give simple numbered steps using the exact page and button names in the system. If the information is not available, say so and direct them to the parish office. Respect the user's role: never share another person's private records. Be warm, brief, and respectful. FIXED FACTS (always use these exact answers): If asked who the parish priest is (or 'kura paroko', 'pari priest', 'head priest'), answer exactly: 'The Parish Priest is REV. FR. ALBERTO G. CAHILIG, OMI.' If asked who the assistant priest is (or 'assistant parish priest', 'associate priest'), answer exactly: 'The Assistant Priest is REV. FR. ALVIN VICENTE C. BARRETTO, OMI.' Only explain how to change the priest signatory on certificates if the user specifically asks about updating or changing the signatory in the Admin panel. Do not use that guide to answer 'who is' questions.";

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->kb = new ParishKnowledgeBase($db);
        $this->tools = new ParishAssistantTools($db);
    }

    public function respond(int $userId, array $capabilities, string $message, string $mode = 'chat', array $conversation = []): array
    {
        $message = trim(mb_strimwidth($message, 0, 1000, ''));
        if ($message === '') {
            throw new InvalidArgumentException('Please enter a question or message.');
        }

        $correlation = tugonCorrelationId();
        $audience = !empty($capabilities['staff']) || !empty($capabilities['admin']) ? 'staff' : 'parishioner';

        // 1. Security Check: Prompt Injection & Internal Architecture Guardrail
        if ($this->isInjection($message)) {
            $norm = $this->kb->normalizeQuery($message);
            $lang = $norm['language'];
            $answer = match ($lang) {
                'ceb' => "Dili nako mahimong laktawan ang mga lagda sa sistema o magpagawas og sekreto. Makatabang lamang ako sa mga serbisyo sa parokya, mga rekisitos, ug iskedyul.",
                'fil' => "Hindi ko maaaring balewalain ang mga patakaran, maglabas ng lihim, o magbigay ng impormasyon ng server. Maaari lamang po akong tumulong sa mga serbisyo ng parokya tulad ng mga kahilingan, iskedyul, at mga kailangan.",
                default => "I cannot ignore safeguards, reveal system secrets, or bypass permissions. I can only help with parish services such as requests, schedules, and requirements."
            };
            return $this->persist($userId, $audience, $mode, $lang, $message, $answer, [], [], null, $correlation, 'security-refusal', [], TugonConversationalIntent::TOPIC_OFF_TOPIC_OR_UNSAFE);
        }

        // 1b. Security Check: Sensitive Credentials & OTP Guardrail
        if ($this->isSensitiveCredential($message)) {
            $norm = $this->kb->normalizeQuery($message);
            $lang = $norm['language'];
            $answer = match ($lang) {
                'ceb' => "Palihug ayaw ipaambit ang imong OTP o password kang bisan kinsa. Dili gayod mangayo ang parokya o ang assistant sa imong OTP.",
                'fil' => "Huwag po ninyong ibahagi ang inyong OTP o password kaninuman, kabilang sa akin. Hindi po hihingin ng parish staff o ng Tugon AI ang inyong OTP. Ilagay lamang po ito sa verification screen ng portal.",
                default => "Please don't share your OTP or password with anyone, including me. Neither parish staff nor this assistant will ever ask for it. Enter it only on the portal's verification screen."
            };
            return $this->persist($userId, $audience, $mode, $lang, $message, $answer, [], [], null, $correlation, 'credential-warning', [], TugonConversationalIntent::TOPIC_OFF_TOPIC_OR_UNSAFE);
        }

        // 1c. Pastoral Care / Grief / Emergency Guardrail
        if ($this->isGriefOrEmergency($message)) {
            $norm = $this->kb->normalizeQuery($message);
            $lang = $norm['language'];
            $isDying = (bool) preg_match('/\b(?:dying|naghihingalo|last rites|emergency|dangerously ill|tinga)\b/iu', $message);
            if ($isDying) {
                $answer = match ($lang) {
                    'ceb' => "Nag-ampo kami alang sa inyong hinigugma. Alang sa **Anointing of the Sick (Pahid sa Masakiton / Last Rites)**, palihug tawag dayon sa **Parish Emergency Hotline: 0997 742 8176** aron maadtoan dayon sa pari ang masakiton.",
                    'fil' => "Sumasainyo ang aming panalangin. Para sa **Anointing of the Sick (Pahid sa May Sakit / Last Rites)**, mangyaring tumawag agad sa **Parish Emergency Hotline: 0997 742 8176** upang mapuntahan agad ng pari ang maysakit.",
                    default => "We are holding your loved one and family in prayer. For the **Anointing of the Sick (Last Rites)**, please call the **Parish Emergency Hotline at 0997 742 8176 immediately** so a priest can be dispatched for visitation."
                };
            } else {
                $answer = match ($lang) {
                    'ceb' => "Nakigduyog kami sa inyong kasubo. Palihug tawag sa opisina sa parokya sa **0997 742 8176** aron ma-coordinate ang Funeral Mass ug pagbasbas sa lubong.",
                    'fil' => "Nakikiramay po kami sa inyong pagdadalamhati. Mangyaring tumawag sa opisina ng parokya sa **0997 742 8176** upang mai-coordinate ang Funeral Mass at pagbabasbas ng libing.",
                    default => "I'm so sorry for your loss. We'll keep your family and loved one in our prayers. Please call the parish office at **0997 742 8176** so they can coordinate the funeral Mass and wake blessing."
                };
            }
            return $this->persist($userId, $audience, $mode, $lang, $message, $answer, [], [], null, $correlation, 'pastoral-care', ['0997 742 8176'], TugonConversationalIntent::TOPIC_CHURCH_TEACHING);
        }

        // 2. Security Check: Read-Only Mutation Refusal
        if ($this->requestsMutation($message)) {
            $norm = $this->kb->normalizeQuery($message);
            $lang = $norm['language'];
            $answer = match ($lang) {
                'ceb' => "Read-only po kini nga assistant ug dili makabag-o, maka-apruba, o makatangtang og mga rekord. Palihug gamita ang mga pamaagi sa inyong dashboard.",
                'fil' => "Read-only po ang TUGON AI at hindi ito maaaring magbago, mag-apruba, mag-isyu, o magtanggal ng tala. Gamitin po ang awtorisadong workflow sa inyong dashboard.",
                default => "This assistant is read-only and cannot change, approve, issue, or delete records. Use the authorized dashboard workflow for that action."
            };
            return $this->persist($userId, $audience, $mode, $lang, $message, $answer, [], [], null, $correlation, 'read-only-refusal', [], TugonConversationalIntent::TOPIC_OFF_TOPIC_OR_UNSAFE);
        }

        // 3. Conversational Layer: Check for pure social / greeting / small talk
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
                $intentAnalysis['suggested_prompts'] ?? [],
                'GREETING'
            );
        }

        // 4. Resolve Context across multi-turn conversation
        $contextualQuery = $this->resolveConversationContext($message, $conversation);

        // Normalize input and detect language (English, Filipino, or Bisaya)
        $norm = $this->kb->normalizeQuery($contextualQuery);
        $language = $norm['language'];

        // 4b. Fixed Facts: Parish Clergy Inquiries
        $cleanLower = mb_strtolower(trim($contextualQuery));
        $isWhoQuestion = (bool) preg_match('/\b(who|sino|kinsa)\b/iu', $cleanLower);
        $isChangingSignatory = (bool) (preg_match('/\b(change|update|palitan|ilis)\b/iu', $cleanLower) && preg_match('/\b(signatory|priest on certificate|pari sa certificate|pari sa sertipiko)\b/iu', $cleanLower))
            || (bool) preg_match('/\b(how (?:do|can|to)|paano|unsaon)\b.*\b(change|update|palitan|ilis)\b.*\b(priest|signatory|pari)\b/iu', $cleanLower);

        if (!$isChangingSignatory) {
            // Assistant Priest check
            if (preg_match('/\b(assistant\s*(?:parish\s*)?priest|associate\s*priest|parochial\s*vicar)\b/iu', $cleanLower)
                || ($isWhoQuestion && preg_match('/\b(assistant|associate)\b/iu', $cleanLower) && preg_match('/\b(priest|pari)\b/iu', $cleanLower))) {
                return $this->persist($userId, $audience, $mode, $language, $message, "The Assistant Priest is REV. FR. ALVIN VICENTE C. BARRETTO, OMI.", [], [], null, $correlation, 'fixed-fact-clergy', ['Who is the Parish Priest?', 'Parish Office Hours'], TugonConversationalIntent::TOPIC_PARISH_OFFICE);
            }
            // Parish Priest check
            if (preg_match('/\b(parish\s*priest|kura\s*paroko|pari\s*priest|head\s*priest)\b/iu', $cleanLower)
                || ($isWhoQuestion && preg_match('/\b(priest|pari)\b/iu', $cleanLower))) {
                return $this->persist($userId, $audience, $mode, $language, $message, "The Parish Priest is REV. FR. ALBERTO G. CAHILIG, OMI.", [], [], null, $correlation, 'fixed-fact-clergy', ['Who is the Assistant Priest?', 'Parish Office Hours'], TugonConversationalIntent::TOPIC_PARISH_OFFICE);
            }
        }

        // 5. Off-Topic Check
        if (!$this->isParishRelated($norm['normalized']) && $mode !== 'search') {
            $answer = match ($language) {
                'ceb' => "Andam ako motabang sa mga serbisyo ug pangutana bahin sa parokya. Aduna ba kay pangutana bahin sa mga sakramento, sertipiko, o iskedyul?",
                'fil' => "Nandito po ako upang tumulong sa mga serbisyo at katanungan tungkol sa parokya. Mayroon po ba akong maitutulong sa inyo tungkol sa ating parokya?",
                default => "I'm here to help with parish services, sacramental requests, certificates, schedules, and using this system. Is there something about the parish I can help you with?"
            };
            return $this->persist($userId, $audience, $mode, $language, $message, $answer, [], [], null, $correlation, 'topic-refusal', [], TugonConversationalIntent::TOPIC_OFF_TOPIC_OR_UNSAFE);
        }

        // 6. Authorized Records / Smart Search
        $searchResults = $this->searchOwnedOrAuthorizedData($userId, $capabilities, $contextualQuery);

        // 7. Analytics Mode Handling
        if ($mode === 'analytics' || preg_match('/\b(report|analytics|istatistika)\b/i', $norm['normalized'])) {
            if (empty($capabilities['reports'])) {
                $answer = match ($language) {
                    'ceb' => "Wala kay pagtugot nga motan-aw niini nga report.",
                    'fil' => "Wala po kayong pahintulot na tingnan ang analytics report na ito.",
                    default => "You do not have permission to view that report."
                };
                return $this->persist($userId, $audience, $mode, $language, $message, $answer, [], $searchResults, null, $correlation, 'permission-refusal', [], 'ANALYTICS');
            }
            $analytics = $this->analytics($capabilities);
            $answer = match ($language) {
                'ceb' => "Mao kini ang awtorisadong sumaryo sa mga talaan sa parokya:",
                'fil' => "Narito po ang awtorisadong buod ng mga tala sa parokya batay sa kasalukuyang rekord:",
                default => "Here is the authorized summary based on current parish records:"
            };
            return $this->persist($userId, $audience, 'analytics', $language, $message, $answer, [], $searchResults, $analytics, $correlation, 'authorized-analytics', [], 'ANALYTICS');
        }

        // 8. Live Data Tool Execution: Detect if live query needed
        $toolExecutionResult = $this->detectAndExecuteTool($norm['normalized'], $userId, $audience);

        // 9. RAG Knowledge Base Retrieval
        $sources = $this->kb->search($norm['normalized'], $audience, 4);
        $fewShots = $this->kb->getRelevantFewShots($norm['normalized'], 3);

        // 10. Generate Response: Try Gemini with System Prompt, Knowledge, & Tools
        $geminiAnswer = $this->callGeminiWithRagAndTools(
            $message,
            $sources,
            $toolExecutionResult,
            $fewShots,
            $conversation,
            $language,
            $audience
        );

        if ($geminiAnswer !== null) {
            $answer = $geminiAnswer;
            $provider = 'gemini-rag-dynamic';
        } else {
            // Grounded Dynamic Synthesis fallback
            $answer = $this->synthesizeGroundedAnswer(
                $contextualQuery,
                $sources,
                $toolExecutionResult,
                $fewShots,
                $language,
                $audience
            );
            $provider = 'grounded-rag-synthesis';
        }

        // Generate context-sensitive suggested prompts
        $suggestedPrompts = $this->generatePrompts($contextualQuery, $sources, $audience);

        return $this->persist(
            $userId,
            $audience,
            $mode,
            $language,
            $message,
            $answer,
            $sources,
            $searchResults,
            null,
            $correlation,
            $provider,
            $suggestedPrompts,
            TugonConversationalIntent::classifyTopicIntent($contextualQuery)
        );
    }

    /**
     * Detect live data inquiry and execute secure tool call.
     */
    private function detectAndExecuteTool(string $normQuery, int $userId, string $role): ?array
    {
        // A. Summary counts inquiry
        if (preg_match('/\b(how many|pila kabuok|ilan|bilang|total|count of)\b/i', $normQuery)) {
            if (preg_match('/\b(blessing|blessings|pabasbas|basbas)\b/i', $normQuery)) {
                $counts = $this->tools->execute('get_summary_counts', ['filter_type' => 'blessings_completed'], $userId, $role);
                return ['tool' => 'get_summary_counts', 'type' => 'blessings', 'data' => $counts];
            }
            if (preg_match('/\b(certificate|certificates|sertipiko)\b/i', $normQuery)) {
                $counts = $this->tools->execute('get_summary_counts', ['filter_type' => 'certificates_total'], $userId, $role);
                return ['tool' => 'get_summary_counts', 'type' => 'certificates', 'data' => $counts];
            }
            if (preg_match('/\b(request|requests|kahilingan)\b/i', $normQuery)) {
                $counts = $this->tools->execute('get_summary_counts', ['filter_type' => 'all'], $userId, $role);
                return ['tool' => 'get_summary_counts', 'type' => 'all', 'data' => $counts];
            }
        }

        // B. Request status inquiry
        if (preg_match('/\b(status|track|subaybayan|kumusta|nasaan|stats)\b/i', $normQuery) 
            && preg_match('/\b(request|certificate|blessing|req)\b/i', $normQuery)
            && !preg_match('/\b(update|change|edit|palitan|ilis|baguhin|how to update|how do i update)\b/i', $normQuery)) {
            // Check if specific reference number mentioned (e.g., REQ-2026-0001 or digits)
            preg_match('/REQ[-\d]+/i', $normQuery, $matches);
            $ref = $matches[0] ?? '';
            $statusData = $this->tools->execute('get_request_status', ['reference_number' => $ref], $userId, $role);
            return ['tool' => 'get_request_status', 'data' => $statusData];
        }

        // C. Schedule inquiry
        if (preg_match('/\b(schedule|iskedyul|sked|oras|when is|kailan|kanus-a|mass time|mass schedule)\b/i', $normQuery)) {
            $schedData = $this->tools->execute('get_schedule', ['query' => $normQuery], $userId, $role);
            return ['tool' => 'get_schedule', 'data' => $schedData];
        }

        // D. Certificate types & fees
        if (preg_match('/\b(types of certificates|certificate types|mga uri ng sertipiko|klase sa sertipiko|how much is a certificate|tagpila ang certificate|magkano ang sertipiko)\b/i', $normQuery)) {
            $certData = $this->tools->execute('get_certificate_types', [], $userId, $role);
            return ['tool' => 'get_certificate_types', 'data' => $certData];
        }

        // E. Blessing types
        if (preg_match('/\b(types of blessings|blessing types|mga uri ng basbas|klase sa blessing)\b/i', $normQuery)) {
            $blessingData = $this->tools->execute('get_blessing_types', [], $userId, $role);
            return ['tool' => 'get_blessing_types', 'data' => $blessingData];
        }

        // F. Contact & hours
        if (preg_match('/\b(office hours|contact number|hotline|phone|address|location|saan ang|asa ang|parish office)\b/i', $normQuery)) {
            $contactData = $this->tools->execute('get_parish_contact_info', [], $userId, $role);
            return ['tool' => 'get_parish_contact_info', 'data' => $contactData];
        }

        return null;
    }

    /**
     * Synthesize grounded, dynamic, role-appropriate answers when Gemini is offline.
     * Uses real system modules, exact page and button names, and real tool outputs.
     */
    private function synthesizeGroundedAnswer(
        string $query,
        array $sources,
        ?array $toolResult,
        array $fewShots,
        string $lang,
        string $role
    ): string {
        $norm = $this->kb->normalizeQuery($query);
        $normStr = $norm['normalized'];

        // 1. If tool result exists for live summary counts
        if ($toolResult && $toolResult['tool'] === 'get_summary_counts') {
            $data = $toolResult['data'] ?? [];
            if (isset($data['blessings_completed'])) {
                $count = (int) $data['blessings_completed'];
                return match ($lang) {
                    'ceb' => "Subay sa kasamtangang talaan sa parokya, aduna karoy **{$count}** ka nahuman (Completed) nga blessing requests.",
                    'fil' => "Batay sa kasalukuyang talaan ng parokya, mayroon ngayong **{$count}** na natapos (Completed) na kahilingan sa pagbabasbas (blessings).",
                    default => "Based on current parish database records, there are **{$count}** completed blessing requests."
                };
            }
            if (isset($data['total_certificates_created'])) {
                $count = (int) $data['total_certificates_created'];
                return match ($lang) {
                    'ceb' => "Adunay **{$count}** ka opisyal nga sertipiko ang nagama sa sistema sa parokya.",
                    'fil' => "May kabuuang **{$count}** na opisyal na sertipiko ang nagawa sa sistema ng parokya.",
                    default => "A total of **{$count}** official certificates have been generated in the parish system."
                };
            }
            if (isset($data['my_requests'])) {
                $my = $data['my_requests'];
                $total = (int) ($my['total'] ?? 0);
                return match ($lang) {
                    'ceb' => "Aduna kay **{$total}** ka aktibong requests sa imong account. Makat-onan ang kompletong detalye sa **My Requests**.",
                    'fil' => "Mayroon kayong **{$total}** na request sa inyong account. Maaari ninyong suriin ang bawat isa sa **My Requests**.",
                    default => "You have submitted **{$total}** requests in your account. You can view each one under **My Requests**."
                };
            }
        }

        // 2. If tool result exists for request status
        if ($toolResult && $toolResult['tool'] === 'get_request_status') {
            $data = $toolResult['data'] ?? [];
            if (!empty($data['found'])) {
                if (isset($data['reference_number'])) {
                    $ref = $data['reference_number'];
                    $type = $data['request_type'];
                    $st = $data['status'];
                    $remarks = !empty($data['admin_remarks']) ? "\n• **Admin Remarks**: " . $data['admin_remarks'] : '';
                    return match ($lang) {
                        'ceb' => "Mao kini ang live status sa imong request:\n\n• **Reference Number**: `{$ref}`\n• **Klase sa Serbisyo**: {$type}\n• **Status**: **{$st}**{$remarks}\n\nMaka-download ka sa sertipiko sa **My Requests** kung kini **Completed** na.",
                        'fil' => "Narito ang live status ng inyong kahilingan:\n\n• **Reference Number**: `{$ref}`\n• **Uri ng Request**: {$type}\n• **Status**: **{$st}**{$remarks}\n\nMaaari ninyong i-download ang inyong kopya sa **My Requests** kapag ito ay **Completed** na.",
                        default => "Here is the real-time status of the request:\n\n• **Reference Number**: `{$ref}`\n• **Request Type**: {$type}\n• **Status**: **{$st}**{$remarks}\n\nYou can track progress or download your completed certificate directly in **My Requests**."
                    };
                }
                if (!empty($data['requests'])) {
                    $lines = [];
                    foreach ($data['requests'] as $req) {
                        $lines[] = "• `{$req['reference_number']}` ({$req['request_type']}): **{$req['status']}** ({$req['date_requested']})";
                    }
                    $list = implode("\n", $lines);
                    return match ($lang) {
                        'ceb' => "Mao kini ang imong mga bag-ong request sa parokya:\n\n{$list}\n\nBisitaha ang **My Requests** alang sa dugang detalye.",
                        'fil' => "Narito ang inyong mga pinakabagong request sa parokya:\n\n{$list}\n\nPumunta sa **My Requests** upang makita ang buong detalye.",
                        default => "Here are your most recent requests on file:\n\n{$list}\n\nYou can check more details or download certificates under **My Requests**."
                    };
                }
            } else {
                return match ($lang) {
                    'ceb' => "Wala koy nakit-an nga request nga nitugma niana. Palihug susiha ang imong Reference Number o ablihi ang **My Requests** sa imong dashboard.",
                    'fil' => "Wala po akong nahanap na tugmang request. Mangyaring suriin ang inyong Reference Number o buksan ang **My Requests** sa inyong dashboard.",
                    default => "No matching request was found. Please check your Reference Number or visit **My Requests** in your portal."
                };
            }
        }

        // 2b. If tool result exists for schedule
        if ($toolResult && $toolResult['tool'] === 'get_schedule') {
            $data = $toolResult['data'] ?? [];
            $events = $data['events'] ?? [];
            $eventLines = [];
            foreach ($events as $ev) {
                $eventLines[] = "• **{$ev['title']}**: {$ev['date']} ({$ev['time']}) - {$ev['location']}";
            }
            $eventsStr = !empty($eventLines) ? "\n\n**Upcoming Events:**\n" . implode("\n", $eventLines) : '';
            return match ($lang) {
                'ceb' => "Mao kini ang opisyal nga iskedyul sa Misa ug mga kalihokan sa parokya:\n\n• **Domingo**: 6:00 AM, 8:00 AM, 5:00 PM\n• **Miyerkules (Novena)**: 5:30 PM\n• **Biyernes**: 5:30 PM\n• **Sabado (Anticipated)**: 5:30 PM{$eventsStr}",
                'fil' => "Narito ang opisyal na iskedyul ng Misa at mga kaganapan sa parokya:\n\n• **Linggo**: 6:00 AM, 8:00 AM, 5:00 PM\n• **Miyerkules (Novena)**: 5:30 PM\n• **Biyernes**: 5:30 PM\n• **Sabado (Anticipated)**: 5:30 PM{$eventsStr}",
                default => "Here is the official parish Mass and schedule of events:\n\n• **Sunday**: 6:00 AM, 8:00 AM, 5:00 PM\n• **Wednesday (Novena)**: 5:30 PM\n• **Friday**: 5:30 PM\n• **Saturday (Anticipated Mass)**: 5:30 PM{$eventsStr}"
            };
        }

        // 2c. If tool result exists for certificate types & fees
        if ($toolResult && $toolResult['tool'] === 'get_certificate_types') {
            $data = $toolResult['data'] ?? [];
            $certs = $data['certificates'] ?? [];
            $lines = [];
            foreach ($certs as $c) {
                $lines[] = "• **{$c['name']}**: {$c['fee']} ({$c['processing_time']}) - {$c['release_options']}";
            }
            $certList = implode("\n", $lines);
            return match ($lang) {
                'ceb' => "Mao kini ang mga opisyal nga sertipiko sa parokya:\n\n{$certList}\n\n• **Pagbayad**: GCash kang Agnes Calapaan (0997 742 8176) o Cash sa opisina.",
                'fil' => "Narito ang mga opisyal na uri ng sertipiko sa parokya:\n\n{$certList}\n\n• **Paraan ng Pagbabayad**: GCash kay Agnes Calapaan (0997 742 8176) o Cash upon pickup.",
                default => "Here are the official certificate types available in the parish system:\n\n{$certList}\n\n• **Payment**: GCash to Agnes Calapaan (0997 742 8176) or Cash upon pickup."
            };
        }

        // 2d. If tool result exists for contact & office hours
        if ($toolResult && $toolResult['tool'] === 'get_parish_contact_info') {
            return match ($lang) {
                'ceb' => "Mao kini ang mga detalye sa tanggapan sa parokya:\n\n• ⛪ **Lokasyon**: San Lorenzo Ruiz Parish, San Mateo, Aleosan, Cotabato\n• 📞 **Telepono / Hotline**: 0997 742 8176\n• 👤 **Parish Secretary**: Agnes Calapaan\n• 🕒 **Oras sa Opisina**:\n  - Martes hangtod Sabado: 8:00 AM – 5:00 PM (Paniudto: 12:00 PM – 1:00 PM)\n  - Domingo: 7:00 AM – 12:00 PM (Katunga sa adlaw)\n  - Lunes: SIRADO",
                'fil' => "Narito ang mga detalye at oras ng tanggapan ng parokya:\n\n• ⛪ **Lokasyon**: San Lorenzo Ruiz Parish, San Mateo, Aleosan, Cotabato\n• 📞 **Telepono / Hotline**: 0997 742 8176\n• 👤 **Parish Secretary**: Agnes Calapaan\n• 🕒 **Oras ng Opisina**:\n  - Martes hanggang Sabado: 8:00 AM – 5:00 PM (Tanghalian: 12:00 PM – 1:00 PM)\n  - Linggo: 7:00 AM – 12:00 PM (Kalahating araw)\n  - Lunes: SARADO",
                default => "Here are the official parish office details:\n\n• ⛪ **Location**: San Lorenzo Ruiz Parish, San Mateo, Aleosan, Cotabato\n• 📞 **Phone / Hotline**: 0997 742 8176\n• 👤 **Parish Secretary**: Agnes Calapaan\n• 🕒 **Office Hours**:\n  - Tuesday to Saturday: 8:00 AM – 5:00 PM (Lunch: 12:00 PM – 1:00 PM)\n  - Sunday: 7:00 AM – 12:00 PM (Half-day)\n  - Monday: CLOSED"
            };
        }

        // 3. Admin: Change priest signatory (ONLY when specifically asking about updating or changing the signatory in Admin panel)
        $isAskingToChangeSignatory = (preg_match('/\b(change|update|palitan|ilis)\b/i', $normStr) && preg_match('/\b(priest|signatory|pari)\b/i', $normStr))
            || preg_match('/\b(how (?:do|can|to)|paano|unsaon)\b.*\b(change|update|palitan|ilis)\b/i', $normStr);
        $isWhoQuestion = (bool) preg_match('/\b(who|sino|kinsa)\b/i', $normStr);

        if ($isAskingToChangeSignatory && !$isWhoQuestion) {
            return match ($lang) {
                'ceb' => "Aron usbon ang pari nga nagpirma sa mga sertipiko (Admin):\n\n1. Mag-log in isip Admin.\n2. Adto sa **Certificates** o **System Settings** sa sidebar menu.\n3. Pangitaa ang seksyon nga **Certificate Signatory**.\n4. I-type o pilia ang bag-ong ngalan ug titulo sa Kura Paroko (Parish Priest).\n5. I-click ang **Save Changes** o **Update Signatory** aron ma-apply kini sa tanang bag-ong sertipiko.",
                'fil' => "Para palitan ang pari sa mga sertipiko (Admin):\n\n1. Mag-log in sa Admin account.\n2. Pumunta sa **Certificates** o **System Settings** sa sidebar.\n3. Hanapin ang bahagi ng **Certificate Signatory**.\n4. I-update ang pangalan at titulo ng Kura Paroko (Parish Priest).\n5. I-click ang **Save Changes** o **Update Signatory**. Ang lahat ng bagong sertipiko ay magtataglay na ng bagong pangalan.",
                default => "To change or update the priest signatory on certificates (Admin):\n\n1. Log in to your Admin account.\n2. In the sidebar menu, open **Certificates** or **System Settings**.\n3. Locate the **Certificate Signatory** section.\n4. Enter or select the new Parish Priest / Minister's full name and ecclesiastical title.\n5. Click **Save Changes** (or **Update Signatory**). All newly generated certificates will automatically carry the updated name."
            };
        }

        // Fixed Facts: Assistant Priest
        if (preg_match('/\b(assistant\s*(?:parish\s*)?priest|associate\s*priest|parochial\s*vicar)\b/i', $normStr)
            || preg_match('/\b(assistant\s*(?:parish\s*)?priest|associate\s*priest|parochial\s*vicar)\b/i', $query)
            || ($isWhoQuestion && preg_match('/\b(assistant|associate)\b/i', $normStr) && preg_match('/\b(priest|pari)\b/i', $normStr))) {
            return "The Assistant Priest is REV. FR. ALVIN VICENTE C. BARRETTO, OMI.";
        }

        // Fixed Facts: Parish Priest
        if (preg_match('/\b(parish\s*priest|kura\s*paroko|pari\s*priest|priest\s*priest|head\s*priest)\b/i', $normStr)
            || preg_match('/\b(parish\s*priest|kura\s*paroko|pari\s*priest|head\s*priest)\b/i', $query)
            || ($isWhoQuestion && preg_match('/\b(priest|pari)\b/i', $normStr))) {
            return "The Parish Priest is REV. FR. ALBERTO G. CAHILIG, OMI.";
        }

        // 4. Admin: Generate certificate
        if (preg_match('/\b(generate.*cert|generate.*sertipiko|himo.*cert|create.*cert)\b/i', $normStr)) {
            return match ($lang) {
                'ceb' => "Mao kini ang mga lakang sa pag-generate og sertipiko (Admin/Staff):\n\n1. Mag-log in bilang Admin o Staff ug ablihi ang **Certificates** sa sidebar menu.\n2. Pindota ang button nga **Generate Certificate**.\n3. Pilia ang klase sa sertipiko (Baptism, Confirmation, Marriage, o First Communion).\n4. Sa **Select Record**, i-type ang ngalan sa deboto sa searchable field ug pilia ang rekord.\n5. Tinoa ang Certificate Signatory ug petsa sa preview.\n6. Pindota ang **Issue Certificate** o **Save & Download**.",
                'fil' => "Narito ang mga hakbang para sa admin o staff sa pag-generate ng sertipiko:\n\n1. Mag-log in bilang Admin o Staff at pumunta sa **Certificates** sa sidebar menu.\n2. I-click ang button na **Generate Certificate**.\n3. Piliin ang uri ng sertipiko (Baptism, Confirmation, Marriage, o First Communion).\n4. Sa **Select Record**, gamitin ang searchable field upang i-type ang pangalan at piliin ang tugmang talaan.\n5. Suriin ang preview ng sertipiko at tiyaking tama ang Certificate Signatory.\n6. I-click ang **Issue Certificate** o **Save & Download**.",
                default => "Here are the steps for Admin or Staff to generate a certificate:\n\n1. Log in to your Admin or Staff account and navigate to **Certificates** in the sidebar.\n2. Click the **Generate Certificate** button.\n3. Choose the certificate type (Baptism, Confirmation, Marriage, or First Communion).\n4. In the **Select Record** field, type the person's name into the searchable combobox and select their registry record.\n5. Verify the Certificate Signatory and issuance details in the live preview.\n6. Click **Issue Certificate** (or **Save & Download**)."
            };
        }

        // 5. Admin: Update request status
        if (preg_match('/\b(update.*status|update.*request|ilis.*status|palitan.*status)\b/i', $normStr)) {
            return match ($lang) {
                'ceb' => "Aron i-update ang status sa request (Admin/Staff):\n\n1. Adto sa **Requests** sa admin sidebar menu.\n2. I-click ang maong request o pindota ang **View Details**.\n3. I-scroll paubos sa seksyon nga **REVIEW STATUS & UPDATES**.\n4. Pilia ang bag-ong status sa **Current Status** dropdown (e.g. Processing, Ready for Pickup, Completed, o Rejected).\n5. Isulat ang Admin Remarks o iskedyul kung gikinahanglan.\n6. I-click ang brown nga button nga **Update Request** aron ma-save.",
                'fil' => "Para mag-update ng status ng request bilang Admin o Staff:\n\n1. Pumunta sa **Requests** sa admin sidebar menu.\n2. I-click ang partikular na request o piliin ang **View Details**.\n3. Bumaba sa seksyon ng **REVIEW STATUS & UPDATES**.\n4. Piliin ang bagong status sa **Current Status** dropdown (hal. Processing, Completed, o Rejected).\n5. Maglagay ng Admin Remarks kung kinakailangan o iskedyul ng seremonya.\n6. I-click ang brown na button na **Update Request** upang mai-save.",
                default => "To update a request status as Admin or Staff:\n\n1. Go to **Requests** in the admin sidebar menu.\n2. Click on the specific request row or click **View Details**.\n3. Scroll down to the **REVIEW STATUS & UPDATES** section.\n4. Select the new status in the **Current Status** dropdown (e.g. Processing, Ready for Pickup, Completed, or Rejected).\n5. If needed, enter Admin Remarks or schedule details (Ceremony Date, Time, Minister).\n6. Click the brown **Update Request** button to save changes."
            };
        }

        // 6. User: Request Baptism Certificate
        if (preg_match('/\b(baptizm|baptism|binyag|bunyag)\b/i', $normStr) && preg_match('/\b(cert|certifcate|certificate|sertipiko)\b/i', $normStr)) {
            return match ($lang) {
                'ceb' => "Mao kini ang mga lakang sa pag-request og Baptismal Certificate:\n\n1. Mag-log in sa imong TUGON account.\n2. Sa kilid nga menu, adto sa **Requests** dayon pilia ang **New Request** (o **Request Certificate**).\n3. Sa Certificate Type, pilia ang **Baptismal Certificate**.\n4. Isulod ang kompletong ngalan sa bata, petsa sa pagkatawo, ug mga ginikanan.\n5. I-upload ang klaro nga kopya sa Valid Government ID ug PSA Birth Certificate.\n6. Pilia ang paagi sa pagbayad (GCash kang Agnes Calapaan sa 0997 742 8176 o Cash sa opisina).\n7. Pindota ang **Submit Request** ug itago ang Reference Number (`REQ-2026-XXXX`).",
                'fil' => "Narito ang mga simpleng hakbang para mag-request ng baptismal certificate sa TUGON:\n\n1. Mag-log in sa inyong account.\n2. Pumunta sa **Requests** at i-click ang **New Request** (o buksan ang **Request Certificate**).\n3. Piliin ang **Baptismal Certificate** bilang uri ng sertipiko.\n4. Ilagay ang buong pangalan ng bata, petsa ng kapanganakan, at pangalan ng mga magulang.\n5. I-upload ang malinaw na kopya ng Valid Government ID at PSA Birth Certificate.\n6. Piliin ang paraan ng pagbabayad (GCash kay Agnes Calapaan sa 0997 742 8176 o Cash on pickup sa opisina).\n7. I-click ang **Submit Request** at itabi ang inyong Reference Number (`REQ-2026-XXXX`).",
                default => "Here are the step-by-step instructions to request a Baptismal Certificate:\n\n1. Log in to your TUGON account.\n2. In the navigation sidebar, click **Requests** and choose **New Request** (or open **Request Certificate**).\n3. Select **Baptismal Certificate** under Certificate Type.\n4. Enter the child's full name, birth date, and parents' names.\n5. Upload a clear photo or scan of your Valid Government ID and PSA Birth Certificate.\n6. Choose your payment method (GCash to Agnes Calapaan at 0997 742 8176 or Cash upon pickup).\n7. Click **Submit Request** and save your Tracking Reference Number (`REQ-2026-XXXX`)."
            };
        }

        // 7. User: Book a Blessing
        if (preg_match('/\b(blessng|blessing|blesing|pabasbas|basbas)\b/i', $normStr)) {
            return match ($lang) {
                'ceb' => "Mao kini ang mga lakang sa pag-book og blessing:\n\n1. Mag-log in sa imong TUGON account.\n2. Sa menu, adto sa **Requests** ug pilia ang **Request Blessing** (o **New Request** -> **Blessing**).\n3. Pilia ang klase sa blessing (House Blessing, Business Blessing, o Vehicle Blessing).\n4. Isulod ang gusto nga petsa, oras, ug kompleto nga address o detalye sa sakyanan.\n5. I-click ang **Submit Request** ug itago ang Reference Number (`REQ-2026-XXXX`).\n6. Paabuta ang kumpirmasyon gikan sa opisina sa parokya. *(Pahibalo: Walay piho nga bayad; voluntary love offering para sa pari ang gidawat).* ",
                'fil' => "Narito ang mga simpleng hakbang para mag-book ng Blessing:\n\n1. Mag-log in sa inyong TUGON account.\n2. Pumunta sa **Requests** at piliin ang **Request Blessing** (o **New Request** -> **Blessing**).\n3. Piliin ang uri ng blessing (House Blessing, Business Blessing, o Vehicle Blessing).\n4. Ilagay ang inyong mga detalye, nais na petsa at oras, at kumpletong address o detalye ng sasakyan.\n5. I-click ang **Submit Request** at itabi ang Reference Number (`REQ-2026-XXXX`).\n6. Hintayin ang kumpirmasyon mula sa parish office. *(Paunawa: Walang takdang bayad; kusang-loob na love offering para sa pari ang tinatanggap).* ",
                default => "Here are the clear steps to book a blessing in the system:\n\n1. Log in to your TUGON account.\n2. In the menu, go to **Requests** and select **Request Blessing** (or **New Request** -> **Blessing**).\n3. Choose your blessing type (House Blessing, Business Blessing, or Vehicle Blessing).\n4. Provide your preferred date and time, along with the complete address or vehicle details.\n5. Click **Submit Request** and take note of your Reference Number (`REQ-2026-XXXX`).\n6. Wait for parish office confirmation. *(Note: There is no mandatory fixed fee; voluntary love offering for the priest is welcome).* "
            };
        }

        // 8. User: Track/Check Request Status
        if (preg_match('/\b(status|track|check.*request|subaybayan)\b/i', $normStr)) {
            return match ($lang) {
                'ceb' => "Mao kini ang mga paagi sa pagsusi sa imong request status:\n\n1. **Kung Naka-log in**: Ablihi ang **My Requests** sa imong dashboard aron makita ang live status sa tanan nimong requests.\n2. **Gamit ang Reference Number**: Sa login page, i-click ang **Check Request Status** ug isulod ang imong Reference Number (pananglitan `REQ-2026-XXXX`).",
                'fil' => "Maaari ninyong suriin ang status ng inyong request sa dalawang paraan:\n\n1. **Kung Naka-log in**: Buksan ang **My Requests** sa inyong dashboard upang makita ang lahat ng inyong request at ang live status ng bawat isa.\n2. **Gamit ang Reference Number**: Sa Login page, i-click ang **Check Request Status** at ilagay ang inyong Reference Number (halimbawa, `REQ-2026-XXXX`).",
                default => "You can check your request status in two easy ways:\n\n1. **Logged-in users**: Open **My Requests** from your dashboard to view the real-time status of all your requests.\n2. **Using Reference Number**: On the login page, click **Check Request Status** and enter your Tracking Reference Number (e.g., `REQ-2026-XXXX`)."
            };
        }

        // 9. If matching RAG source found in knowledge base
        if (!empty($sources)) {
            $primary = $sources[0];
            $title = $primary['title'] ?? 'Parish Information';

            // Check if action steps exist in module
            if (!empty($primary['actions'])) {
                $act = $primary['actions'][0];
                $steps = $act['steps'] ?? [];
                $stepList = '';
                foreach ($steps as $idx => $st) {
                    $stepList .= ($idx + 1) . ". {$st}\n";
                }
                return match ($lang) {
                    'ceb' => "Mao kini ang mga lakang alang sa **{$act['name']}**:\n\n{$stepList}",
                    'fil' => "Narito ang mga hakbang para sa **{$act['name']}**:\n\n{$stepList}",
                    default => "Here are the steps for **{$act['name']}**:\n\n{$stepList}"
                };
            }

            // Check if requirements exist in sacrament
            if (!empty($primary['requirements'])) {
                $reqs = implode("\n• ", $primary['requirements']);
                $feeInfo = !empty($primary['fee']) ? "\n• **Fee**: " . $primary['fee'] : '';
                return match ($lang) {
                    'ceb' => "Mao kini ang mga rekisitos alang sa **{$title}**:\n\n• {$reqs}{$feeInfo}",
                    'fil' => "Narito ang mga kailangan para sa **{$title}**:\n\n• {$reqs}{$feeInfo}",
                    default => "Here are the requirements for **{$title}**:\n\n• {$reqs}{$feeInfo}"
                };
            }

            // Check if request status definition
            if (!empty($primary['meaning_en'])) {
                $meaning = ($lang === 'ceb' || $lang === 'fil') ? ($primary['meaning_fil'] ?? $primary['meaning_en']) : $primary['meaning_en'];
                $action = ($lang === 'ceb' || $lang === 'fil') ? ($primary['parishioner_action'] ?? '') : ($primary['parishioner_action'] ?? '');
                return "**{$title}**\n\n{$meaning}\n\n• **Action**: {$action}";
            }

            $content = $primary['content'] ?? ($primary['summary'] ?? '');
            $steps = $primary['steps'] ?? '';
            if (is_array($steps)) {
                $formattedSteps = [];
                foreach ($steps as $idx => $s) {
                    $formattedSteps[] = ($idx + 1) . ". " . $s;
                }
                $steps = implode("\n", $formattedSteps);
            }
            $out = "{$content}";
            if (!empty($steps)) {
                $out .= "\n\n" . $steps;
            }
            return $out;
        }

        // 10. Honest fallback when question is outside knowledge base or data
        return match ($lang) {
            'ceb' => "Pasayloa ko, apan wala nako makit-an ang maong impormasyon sa kasamtangang talaan sa parokya. Palihug bisitaha o kontaka ang opisina sa parokya sa **0997 742 8176** (Martes hangtod Sabado, 8:00 AM – 5:00 PM; Domingo, 7:00 AM – 12:00 PM; Sirado Lunes) alang sa dugang tabang.",
            'fil' => "Paumanhin po, hindi ko po nahanap ang partikular na impormasyong iyan sa kasalukuyang talaan o knowledge base ng parokya. Mangyaring makipag-ugnayan sa opisina ng parokya sa **0997 742 8176** (Martes hanggang Sabado, 8:00 AM – 5:00 PM; Linggo, 7:00 AM – 12:00 PM; Sarado Lunes) para sa tumpak na gabay.",
            default => "I'm sorry, but I could not find specific details for that in our current parish records or knowledge base. Please contact the parish office at **0997 742 8176** (Tuesday to Saturday 8:00 AM – 5:00 PM, Sunday 7:00 AM – 12:00 PM, closed Monday) or visit in person for assistance."
        };
    }

    /**
     * Gemini RAG integration with Function/Tool Calling & Mandated System Prompt
     */
    private function callGeminiWithRagAndTools(
        string $userMessage,
        array $kbSources,
        ?array $toolOutput,
        array $fewShots,
        array $conversation,
        string $language,
        string $audience
    ): ?string {
        require_once __DIR__ . '/../includes/GeminiGatewayClient.php';

        $client = new GeminiGatewayClient();
        if (!$client->isAvailable()) {
            return null;
        }

        // Build knowledge context blocks
        $contextBlocks = [];
        foreach ($kbSources as $idx => $src) {
            $title = $src['title'] ?? 'Parish Knowledge Item';
            $block = "[KNOWLEDGE ITEM " . ($idx + 1) . "] Title: " . $title;
            if (!empty($src['page'])) $block .= "\nPage Route: " . $src['page'];
            if (!empty($src['summary'])) $block .= "\nSummary: " . $src['summary'];
            if (!empty($src['content'])) $block .= "\nContent: " . $src['content'];
            if (!empty($src['steps'])) $block .= "\nSteps: " . (is_array($src['steps']) ? implode("\n", $src['steps']) : $src['steps']);
            if (!empty($src['requirements'])) $block .= "\nRequirements: " . implode(', ', $src['requirements']);
            if (!empty($src['fee'])) $block .= "\nFee: " . $src['fee'];
            if (!empty($src['actions'])) {
                foreach ($src['actions'] as $act) {
                    $block .= "\nAction: " . ($act['name'] ?? '') . " (Button: " . ($act['button_label'] ?? '') . ", Page: " . ($act['page'] ?? '') . ")";
                    if (!empty($act['steps'])) {
                        $block .= "\nWorkflow Steps:\n" . implode("\n", $act['steps']);
                    }
                }
            }
            $contextBlocks[] = $block;
        }
        $kbContext = implode("\n\n---\n\n", $contextBlocks);

        // Build live tool results block
        $toolContext = "None";
        if ($toolOutput !== null) {
            $toolContext = "Tool: " . ($toolOutput['tool'] ?? 'query') . "\nResults:\n" . json_encode($toolOutput['data'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        // Build few-shot examples block
        $shotBlocks = [];
        foreach ($fewShots as $shot) {
            $shotBlocks[] = "User: " . ($shot['user_query'] ?? '') . "\nAssistant:\n" . ($shot['ideal_answer'] ?? '');
        }
        $fewShotContext = implode("\n\n", $shotBlocks);

        $langDirective = match ($language) {
            'ceb' => 'The user wrote in Bisaya/Cebuano. Answer in warm, respectful Bisaya/Cebuano.',
            'fil' => 'The user wrote in Filipino/Tagalog. Answer in warm, respectful Filipino/Tagalog with po/opo.',
            default => 'The user wrote in English. Answer in warm, clear, simple English.'
        };

        $roleContext = "User Role: {$audience}. (Parishioners see only user features and personal data. Staff/Admins see administrative actions).";

        $fullSystemInstruction = <<<PROMPT
=== MANDATED SYSTEM PROMPT ===
You are the Parish Assistant for this parish management system. Help parishioners and staff understand how to use the system and answer questions about parish services. Use ONLY the provided knowledge base and live data tool results. Never invent steps, numbers, names, or dates. The user may have typos, short phrases, or mix English, Filipino, and Bisaya; infer their intent and answer anyway. Give simple numbered steps using the exact page and button names in the system. If the information is not available, say so and direct them to the parish office. Respect the user's role: never share another person's private records. Be warm, brief, and respectful.

=== FIXED FACTS (ALWAYS USE THESE EXACT ANSWERS) ===
- If asked who the parish priest is (or "kura paroko", "pari priest", "head priest"), answer exactly:
  "The Parish Priest is REV. FR. ALBERTO G. CAHILIG, OMI."
- If asked who the assistant priest is (or "assistant parish priest", "associate priest"), answer exactly:
  "The Assistant Priest is REV. FR. ALVIN VICENTE C. BARRETTO, OMI."

Only explain how to change the priest signatory on certificates if the user specifically asks about updating or changing the signatory in the Admin panel. Do not use that guide to answer "who is" questions.

=== AUDIENCE & LANGUAGE ===
{$roleContext}
Language Directive: {$langDirective}

=== LIVE DATABASE TOOL RESULTS ===
{$toolContext}

=== RETRIEVED KNOWLEDGE BASE ===
{$kbContext}

=== FEW-SHOT TRAINING EXAMPLES ===
{$fewShotContext}
PROMPT;

        $history = [];
        foreach (array_slice($conversation, -6) as $turn) {
            $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = mb_strimwidth((string) ($turn['content'] ?? ''), 0, 400, '');
            if ($content !== '') {
                $history[] = ['role' => $role, 'content' => $content];
            }
        }

        $fullPrompt = $fullSystemInstruction . "\n\n---\n\nUser Question: " . $userMessage;
        $reply = $client->chat($fullPrompt, $history);

        if ($reply === null || trim($reply) === '') {
            return null;
        }

        // Clean any leaking prompt headers
        $cleaned = preg_replace('/=== (MANDATED SYSTEM PROMPT|RETRIEVED KNOWLEDGE BASE|LIVE DATABASE TOOL RESULTS).*$/si', '', $reply);
        return trim((string) $cleaned);
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
        writeAuditLog($this->db, $reviewerId, 'AI_FEEDBACK_SUBMITTED', 'ai_responses', $responseId, null, ['rating' => $rating, 'comments' => $comments], 'ai', 'ai.feedback');
    }

    private function resolveConversationContext(string $query, array $conversation): string
    {
        $normalized = mb_strtolower(trim($query));
        $referentialPattern = '/\b(it|that|this|the same|cost|fee|fees|magkano|tagpila|bayad|requirements|kailangan|papers|documents|process|paano|unsaon|saan|asa|where|kailan|kanus-a|when|schedule|oras|who|sino|kinsa)\b/u';

        if (empty($conversation) || !preg_match($referentialPattern, $normalized)) {
            return $query;
        }

        $entities = [
            'baptismal certificate' => ['baptismal cert', 'baptism certificate', 'sertipiko sa bunyag', 'sertipiko ng binyag'],
            'baptism' => ['binyag', 'bunyag', 'pabinyag', 'pabunyag', 'christening'],
            'confirmation' => ['kumpil', 'pakumpil', 'confirmand'],
            'marriage' => ['wedding', 'kasal', 'pakasal', 'church wedding', 'pre-cana'],
            'first holy communion' => ['first communion', 'komunyon', 'communion'],
            'anointing of the sick' => ['sick call', 'pahid sa masakiton', 'pahid ng langis', 'anointing'],
            'funeral mass' => ['funeral', 'burol', 'libing', 'lubong', 'burial', 'pamisa sa patay'],
            'house blessing' => ['pabasbas ng bahay', 'blessing sa balay', 'home blessing'],
            'vehicle blessing' => ['pabasbas ng sasakyan', 'blessing sa sakyanan', 'car blessing'],
            'certificate' => ['sertipiko', 'records copy'],
            'mass schedule' => ['mass time', 'misa', 'sunday mass'],
            'office hours' => ['office schedule', 'opening hours', 'opisina'],
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

        if ($foundEntity !== null && mb_strpos($normalized, $foundEntity) === false) {
            return $query . ' ' . $foundEntity;
        }

        return $query;
    }

    private function generatePrompts(string $query, array $sources, string $role): array
    {
        if ($role === 'staff' || $role === 'admin') {
            return ['Generate Certificate', 'Certificate Signatory', 'Review Status & Updates', 'Total Certificates Created'];
        }

        $lower = mb_strtolower($query);
        if (str_contains($lower, 'cert')) {
            return ['How much is a certificate?', 'How do I check my request status?', 'How to book a blessing?'];
        }
        if (str_contains($lower, 'bless')) {
            return ['How to book a blessing?', 'Requirements for Certificates', 'Parish Schedule'];
        }
        if (str_contains($lower, 'status') || str_contains($lower, 'req')) {
            return ['What do the request statuses mean?', 'How to request a baptism certificate?', 'Parish Office Hours'];
        }

        return ['How do I request a baptism certificate?', 'How to book a blessing?', 'How do I check my request status?', 'What are the Mass schedules?'];
    }

    private function searchOwnedOrAuthorizedData(int $userId, array $caps, string $query): array
    {
        $items = [];
        $term = '%' . mb_strimwidth($query, 0, 120, '') . '%';
        if (!empty($caps['records'])) {
            $stmt = $this->db->prepare("SELECT request_id, reference_number, request_type, status, date_requested FROM requests WHERE deleted_at IS NULL AND (reference_number LIKE ? OR request_type LIKE ?) ORDER BY date_requested DESC LIMIT 6");
            $stmt->bind_param('ss', $term, $term);
        } else {
            $stmt = $this->db->prepare("SELECT request_id, reference_number, request_type, status, date_requested FROM requests WHERE user_id=? AND deleted_at IS NULL AND (reference_number LIKE ? OR request_type LIKE ?) ORDER BY date_requested DESC LIMIT 6");
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
            'insights' => ['Counts are live from real parish records. Open Analytics & Reports for date breakdown and exports.']
        ];
    }

    private function persist(int $userId, string $audience, string $mode, string $language, string $question, string $answer, array $sources, array $results, ?array $analytics, string $correlation, string $provider, array $prompts = [], string $detectedIntent = ''): array
    {
        $hex = bin2hex(random_bytes(16));
        $reference = sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
        $question = tugonRedactSensitive($question);
        $answer = tugonRedactSensitive($answer);
        $publicSources = array_map(static fn($s) => [
            'title' => $s['title'] ?? 'Parish Knowledge',
            'source' => $s['source_type'] ?? 'system_kb',
            'version' => 1,
            'last_updated' => date('F j, Y')
        ], $sources);
        $snapshot = json_encode($publicSources, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $stmt = $this->db->prepare('INSERT INTO ai_responses(response_reference, user_id, audience, mode, language, question_redacted, answer_redacted, source_snapshot, provider, detected_intent, correlation_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            if ($stmt) {
                $stmt->bind_param('sisssssssss', $reference, $userId, $audience, $mode, $language, $question, $answer, $snapshot, $provider, $detectedIntent, $correlation);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('[TUGON AI persist ai_responses warning] ' . $e->getMessage());
        }

        try {
            $stmt = $this->db->prepare('INSERT INTO chatbot_inquiries(user_id, user_role, question, answer_preview, mode, detected_intent, context_limited, correlation_id, response_reference) VALUES(?,?,?,?,?,?,1,?,?)');
            if ($stmt) {
                $stmt->bind_param('isssssss', $userId, $audience, $question, $answer, $mode, $detectedIntent, $correlation, $reference);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('[TUGON AI persist chatbot_inquiries warning] ' . $e->getMessage());
        }

        return [
            'success' => true,
            'answer' => $answer,
            'guidance' => ['title' => 'TUGON AI', 'steps' => []],
            'sources' => $publicSources,
            'search_results' => $results,
            'analytics' => $analytics,
            'language' => $language,
            'detected_intent' => $detectedIntent,
            'category' => $detectedIntent ?: 'general',
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
        return (bool) preg_match('/\b(?:ignore (?:all |the )?(?:previous|system|your )?instructions?|tell me (?:your |the )?(?:system )?prompt|what is your prompt|reveal (?:the |your )?(?:prompt|secret|credential)|system prompt|server address|what server does your system run on|developer instructions|database credentials|bypass (?:permission|authorization)|execute (?:sql|command)|database password|session (?:id|token)|prompt injection|override system|jailbreak)\b/iu', $text);
    }

    private function isSensitiveCredential(string $text): bool
    {
        return (bool) preg_match('/\b(?:here(?:\'s|\s+is)\s+my\s+(?:otp|password|code)|otp\s+(?:is\s+)?\d{4,6}|\b\d{6}\b.*(?:can you verify|verify my (?:account|otp))|here is my otp|here is my password|ito ang aking otp|narito ang otp)\b/iu', $text);
    }

    private function isGriefOrEmergency(string $text): bool
    {
        return (bool) preg_match('/\b(?:mother (?:is )?dying|father (?:is )?dying|someone (?:is )?dying|dying.*help|naghihingalo|last rites|father just died|mother just died|namatay ang|just passed away)\b/iu', $text);
    }

    private function requestsMutation(string $text): bool
    {
        if (preg_match('/\b(how (?:do|can|to)|paano|pano|unsaon|unsa pag|steps|guide|instructions?)\b/iu', $text)) {
            return false;
        }
        return (bool) preg_match('/\b(update|delete|approve|reject|issue|revoke|publish|modify|change)\b.{0,40}\b(record|request|certificate|payment|reservation|announcement|database)\b/i', $text);
    }

    private function isParishRelated(string $text): bool
    {
        return (bool) preg_match('/parish|parokya|simbahan|mass|misa|office|opisina|bapt|binyag|bunyag|confirm|kumpil|communion|komunyon|marriage|wedding|kasal|bless|basbas|bendisyon|bendita|certificate|sertipiko|papeles|confess|kumpisal|kompisal|reconciliation|penance|adoration|novena|nobena|rosary|rosaryo|request|kahilingan|reserv|venue|schedule|iskedyul|sked|announcement|anunsyo|payment|bayad|pay|gcash|funeral|burial|libing|lubong|priest|pari|secretary|kalihim|agnes|calapaan|vicar|record|tala|sacrament|analytics|report|ulat|TUGON|requirement|kailangan|rekisitos|kinahanglan|cost|magkano|tagpila|upload|format|docx|pdf|file|otp|password|login|log in|can t log|cant log|can\'t log|cannot log|reset password|forgot password|slot|9:30|aleosan|status|pending|processing|completed|rejected|release|pickup|claim|download|intention|pamisa|turnaround|working days|duration|tagal|kailan|kanus-a|paano|pano|unsaon|unsa|how to|docs|papers|sponsor|godparents|recollection|seminar|pre-cana|banns|how long|bago makuha|gaano katagal|pila/iu', $text);
    }
}
