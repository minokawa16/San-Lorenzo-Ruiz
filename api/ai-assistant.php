<?php
/**
 * AI-Assisted Parish Assistant API
 *
 * The parish knowledge base supplies verified context. Gemini is the primary
 * language model through the local Node gateway, with Ollama as local fallback.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

include __DIR__ . '/../includes/session.php';
include __DIR__ . '/../database/config.php';
include __DIR__ . '/../includes/helpers.php';
include __DIR__ . '/../includes/GeminiGatewayClient.php';
include __DIR__ . '/../includes/OllamaClient.php';
include __DIR__ . '/../includes/Logger.php';
include __DIR__ . '/../includes/chatbot/ConversationalIntent.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'error' => 'Method not allowed.', 'message' => 'Method not allowed.']);
    exit;
}

$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
    http_response_code(415);
    echo json_encode(['success' => false, 'error' => 'Content-Type must be application/json.', 'message' => 'Unable to process your request.']);
    exit;
}

if (!isLoggedIn() && !defined('TUGON_AI_ALLOW_GUEST')) {
    http_response_code(401);
    $loginRequired = t('chatbot.login_required', 'Please log in to use the AI Parish Assistant.');
    echo json_encode(['success' => false, 'error' => $loginRequired, 'message' => $loginRequired]);
    exit;
}

if (isLoggedIn() && normalizeUserRole($_SESSION['role'] ?? '') !== 'user') {
    http_response_code(403);
    $parishionerOnly = 'The TUGON AI chatbot is available to parishioner accounts only.';
    echo json_encode(['success' => false, 'error' => $parishionerOnly, 'message' => $parishionerOnly]);
    exit;
}

// Schema Inspection - Checks optional tables and columns before building database-backed answers.
function aiTableExists($conn, $table) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    return $result && $result->num_rows > 0;
}

// AI Column Exists Function - Documents this helper's role in the parish management workflow.
function aiColumnExists($conn, $table, $column) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && $result->num_rows > 0;
}

// AI Not Deleted Sql Function - Documents this helper's role in the parish management workflow.
function aiNotDeletedSql($conn, $table, $alias = '') {
    if (!aiColumnExists($conn, $table, 'deleted_at')) {
        return '';
    }

    $prefix = $alias ? preg_replace('/[^a-zA-Z0-9_]/', '', $alias) . '.' : '';
    return " AND {$prefix}deleted_at IS NULL";
}

// AI Fetch One Function - Documents this helper's role in the parish management workflow.
function aiFetchOne($conn, $sql) {
    $result = $conn->query($sql);
    if (!$result) {
        return 0;
    }
    $row = $result->fetch_assoc();
    return intval($row['count'] ?? 0);
}

// AI Search Like Function - Documents this helper's role in the parish management workflow.
function aiSearchLike($conn, $sql, $types, $params) {
    $rows = [];
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $rows;
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($result && $row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

// Response Helpers - Builds consistent assistant messages, guidance cards, and refusal text.
function aiOutOfContextMessage() {
    return t('chatbot.out_of_context', 'I specialize in parish services and church-related concerns. I may not have information about that topic, but I would be happy to help you with certificates, sacraments, schedules, announcements, and other parish services.');
}

function aiOfflineNoContextMessage() {
    return "I'm sorry, but I don't have enough verified information in the parish knowledge base to answer that accurately. Please contact the parish office for confirmation.";
}

function aiDetectUserLanguage($text) {
    $q = strtolower(' ' . trim((string) $text) . ' ');
    if (trim($q) === '') {
        return 'en';
    }

    $filipinoTerms = [
        ' ano ', ' paano ', ' bakit ', ' saan ', ' kailan ', ' sino ', ' magkano ',
        ' para ', ' sa ', ' ng ', ' ang ', ' mga ', ' po ', ' opo ', ' ba ',
        ' pwede ', ' puwede ', ' maaari ', ' ako ', ' ko ', ' namin ', ' kailangan ',
        ' kelangan ', ' gusto ', ' magpa', ' mag-request', ' humingi ', ' kumuha ',
        ' binyag', ' pabinyag', ' kasal', ' pakasal', ' kumpil', ' komunyon',
        ' misa', ' opisina', ' bahay', ' sasakyan', ' libing', ' lamay',
        ' anunsyo', ' abiso', ' bayad', ' sertipiko', ' iskedyul', ' parokya'
    ];
    $englishTerms = [
        ' what ', ' how ', ' where ', ' when ', ' who ', ' why ', ' can ', ' do ',
        ' does ', ' need ', ' requirements ', ' request ', ' certificate ', ' office ',
        ' hours ', ' schedule ', ' blessing ', ' baptism ', ' marriage ', ' funeral ',
        ' announcement ', ' reservation ', ' payment ', ' login ', ' password '
    ];

    $filipinoScore = 0;
    foreach ($filipinoTerms as $term) {
        if (strpos($q, $term) !== false) {
            $filipinoScore++;
        }
    }

    $englishScore = 0;
    foreach ($englishTerms as $term) {
        if (strpos($q, $term) !== false) {
            $englishScore++;
        }
    }

    if ($filipinoScore > 0 && $englishScore > 0) {
        return 'taglish';
    }
    if ($filipinoScore > 0) {
        return 'fil';
    }
    return 'en';
}

function aiLanguageLabel($language) {
    if ($language === 'fil') {
        return 'Filipino (Tagalog)';
    }
    if ($language === 'taglish') {
        return 'natural Taglish, mostly Filipino with common English parish terms';
    }
    return 'English';
}

function aiLanguageInstruction($language) {
    if ($language === 'fil') {
        return "Answer in natural Filipino (Tagalog). Use respectful parish-office tone with po/opo when natural. Keep fixed church/service names and official document names unchanged when needed.";
    }
    if ($language === 'taglish') {
        return "Answer in natural Taglish. Use mostly Filipino sentence structure, but keep common terms like request, certificate, requirements, schedule, and parish office when they sound natural.";
    }
    return "Answer in clear, natural English.";
}

function aiNoContextMessageForLanguage($language) {
    if ($language === 'fil' || $language === 'taglish') {
        return 'Wala po akong naka-file na impormasyon tungkol diyan. Maaari po kayong makipag-ugnayan sa parish office para makumpirma.';
    }
    return aiOfflineNoContextMessage();
}

// AI Guidance Response Function - Documents this helper's role in the parish management workflow.
function aiGuidanceResponse($title, $answer, $link = null, $steps = []) {
    return [
        'title' => $title,
        'answer' => $answer,
        'steps' => $steps,
        'link' => $link
    ];
}

function aiBuildOfflineRagPrompt($guidance, $query, array $conversation = [], $language = 'en') {
    $steps = $guidance['steps'] ?? [];
    $stepText = '';
    if (!empty($steps)) {
        foreach ($steps as $index => $step) {
            $stepText .= ($index + 1) . '. ' . $step . "\n";
        }
    }

    $history = '';
    foreach (array_slice($conversation, -6) as $turn) {
        if (!is_array($turn)) {
            continue;
        }
        $role = ($turn['role'] ?? '') === 'assistant' ? 'Assistant' : 'User';
        $content = trim(strip_tags((string) ($turn['content'] ?? '')));
        if ($content !== '') {
            $history .= $role . ': ' . mb_strimwidth($content, 0, 500, '...') . "\n";
        }
    }

    $knowledgeText = '';
    if (!empty($guidance['knowledge_sources']) && is_array($guidance['knowledge_sources'])) {
        foreach ($guidance['knowledge_sources'] as $index => $source) {
            $knowledgeText .= "[Source " . ($index + 1) . "]\n";
            $knowledgeText .= "Title: " . ($source['title'] ?? 'Parish information') . "\n";
            $knowledgeText .= "Category: " . ($source['category'] ?? 'general') . "\n";
            $knowledgeText .= "Verified source: " . ($source['source'] ?? 'TUGON administrator-managed knowledge base') . "\n";
            $knowledgeText .= "Content: " . ($source['content'] ?? '') . "\n\n";
        }
    } else {
        $knowledgeText = "Title: " . ($guidance['title'] ?? 'Parish information') . "\n" .
            "Answer: " . ($guidance['answer'] ?? '') . "\n" .
            ($stepText !== '' ? "Requirements or steps:\n{$stepText}" : '');
    }

    return trim(
        "You are TUGON AI, the official AI-assisted parish information assistant of San Lorenzo Ruiz Mission Station in Aleosan, North Cotabato, Archdiocese of Cotabato.\n" .
        "You run locally through Ollama.\n" .
        "Detected user language: " . aiLanguageLabel($language) . ".\n" .
        aiLanguageInstruction($language) . "\n" .
        "Answer ONLY using the OFFICIAL PARISH CONTEXT below.\n" .
        "Match the user's question to the specific context entry that answers it; do not include unrelated entries.\n" .
        "Do not add requirements, fees, schedules, policies, names, or dates that are not in the context.\n" .
        "Never reveal private sacramental records, personal data, passwords, internal logs, database details, or information belonging to another user.\n" .
        "If the answer is not in the context, reply exactly: " . aiOfflineNoContextMessage() . "\n" .
        "Keep answers short, direct, respectful, and natural. If there are requirements or steps, format only those official steps as a numbered list.\n" .
        "Do not literally translate official document titles if doing so would make them unclear.\n\n" .
        ($history !== '' ? "RECENT CONVERSATION:\n{$history}\n" : '') .
        "USER QUESTION:\n{$query}\n\n" .
        "OFFICIAL PARISH CONTEXT:\n" . $knowledgeText .
        "\nFINAL ANSWER:"
    );
}

function aiTryOllamaResponse($guidance, $query, array $conversation = [], $language = 'en') {
    $client = null;
    try {
        $client = new OllamaClient();
        if (!$client->isAvailable()) {
            return ['success' => false, 'error_code' => 'curl_unavailable'];
        }

        $systemPrompt = aiBuildOfflineRagPrompt($guidance, $query, [], $language);
        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach (array_slice($conversation, -6) as $turn) {
            if (!is_array($turn)) {
                continue;
            }
            $turnRole = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $turnContent = trim(strip_tags((string) ($turn['content'] ?? '')));
            if ($turnContent !== '' && !($turnRole === 'user' && strcasecmp($turnContent, $query) === 0)) {
                $messages[] = ['role' => $turnRole, 'content' => mb_strimwidth($turnContent, 0, 500, '...')];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $query];
        $answer = $client->chat($messages);
        if ($answer !== null) {
            return ['success' => true, 'answer' => $answer];
        }

        $logger = new Logger();
        $logger->warning('Ollama response unavailable', [
            'reason' => $client->getLastError(),
        ]);
    } catch (Throwable $e) {
        try {
            $logger = new Logger();
            $logger->error('Ollama integration exception', ['message' => $e->getMessage()]);
        } catch (Throwable $ignored) {
            error_log('Ollama integration exception: ' . $e->getMessage());
        }
        return ['success' => false, 'error_code' => 'unavailable'];
    }

    return ['success' => false, 'error_code' => $client ? ($client->getLastErrorCode() ?: 'unavailable') : 'unavailable'];
}

function aiTryGeminiResponse($guidance, $query, array $conversation = [], $language = 'en') {
    $client = null;
    try {
        $client = new GeminiGatewayClient();
        if (!$client->isAvailable()) {
            return ['success' => false, 'error_code' => 'curl_unavailable'];
        }

        // The verified RAG context and recent conversation are composed by PHP.
        // The local Node gateway adds the server-only API key and calls Gemini.
        $prompt = aiBuildOfflineRagPrompt($guidance, $query, $conversation, $language);
        $answer = $client->chat($prompt);
        if ($answer !== null) {
            return ['success' => true, 'answer' => $answer];
        }

        $logger = new Logger();
        $logger->warning('Gemini response unavailable', [
            'reason' => $client->getLastError(),
            'error_code' => $client->getLastErrorCode(),
        ]);
    } catch (Throwable $e) {
        try {
            $logger = new Logger();
            $logger->error('Gemini integration exception', ['message' => $e->getMessage()]);
        } catch (Throwable $ignored) {
            error_log('Gemini integration exception: ' . $e->getMessage());
        }
        return ['success' => false, 'error_code' => 'unavailable'];
    }

    return ['success' => false, 'error_code' => $client ? ($client->getLastErrorCode() ?: 'unavailable') : 'unavailable'];
}

function aiIsGreeting($query) {
    $normalized = aiNormalizeText($query);
    return (bool) preg_match('/^(hello|hi|hey|good morning|good afternoon|good evening|kumusta|kamusta|magandang umaga|magandang hapon|magandang gabi|hello po|hi po)$/i', $normalized);
}

function aiOllamaUnavailableMessage($errorCode) {
    if ($errorCode === 'model_unavailable') {
        return 'The configured TUGON AI model is currently unavailable. Please contact the system administrator or parish office for assistance.';
    }
    return 'TUGON AI is currently unavailable. Please make sure the local AI service is running or contact the parish office for assistance.';
}

function aiAllProvidersUnavailableMessage($geminiErrorCode, $ollamaErrorCode) {
    if ($geminiErrorCode === 'rate_limited') {
        return 'TUGON AI has reached its online request limit, and the local fallback is unavailable. Please wait a few minutes and try again.';
    }
    if ($geminiErrorCode === 'not_configured' || $geminiErrorCode === 'invalid_key') {
        return 'TUGON AI online access is not configured correctly, and the local fallback is unavailable. Please contact the system administrator.';
    }
    if ($ollamaErrorCode === 'model_unavailable') {
        return 'The online AI service and configured local model are currently unavailable. Please contact the system administrator or parish office.';
    }
    return 'TUGON AI is currently unavailable. Please try again shortly or contact the parish office for assistance.';
}

function aiNaturalizeAnswer($query, $answer, $role, $mode, $language = 'en') {
    if ($role === 'admin' || $mode === 'analytics') {
        return $answer;
    }
    $trimmed = trim((string) $answer);
    if ($trimmed === '' || preg_match('/^(Sure|Yes|Okay|I can help|Thanks|Let me|Sige po|Opo|Pwede po|Puwede po|Tutulungan|Wala po)/i', $trimmed)) {
        return $trimmed;
    }
    $openers = ($language === 'fil' || $language === 'taglish') ? [
        'Sige po, tutulungan ko kayo. ',
        'Opo, ito po ang kailangan ninyong malaman. ',
        'Pwede po, ito po ang gabay. ',
        'Tutulungan ko po kayo dito. '
    ] : [
        'Sure, I can help with that. ',
        'Yes, here is what you need to know. ',
        'Okay, let me guide you. ',
        'I can help with that. '
    ];
    $index = abs(crc32(strtolower((string) $query))) % count($openers);
    return $openers[$index] . $trimmed;
}

function aiNormalizeText($value) {
    $q = strtolower(trim((string) $value));
    $q = preg_replace('/[^a-z0-9\s\-]/', ' ', $q);
    $q = preg_replace('/\s+/', ' ', $q);
    $replacements = [
        'merriage' => 'marriage',
        'mariage' => 'marriage',
        'marraige' => 'marriage',
        'baptise' => 'baptize',
        'baptising' => 'baptizing',
        'binyagan' => 'binyag',
        'binayag' => 'binyag',
        'kelangan' => 'kailangan',
        'kylangan' => 'kailangan',
        'reqs' => 'requirements',
        'req' => 'requirements',
        'docs' => 'documents',
        'docu' => 'documents',
        'papel' => 'papers',
        'papeles' => 'papers'
    ];
    foreach ($replacements as $from => $to) {
        $q = preg_replace('/\b' . preg_quote($from, '/') . '\b/u', $to, $q);
    }
    return $q;
}

function aiExpandBilingualQuery($query) {
    $expanded = ' ' . (string) $query . ' ';
    $q = aiNormalizeText($query);
    $map = [
        'binyag' => 'baptism baptismal christening pabinyag',
        'pabinyag' => 'baptism baptismal christening binyag',
        'kumpil' => 'confirmation confirmand pakumpil',
        'pakumpil' => 'confirmation confirmand kumpil',
        'komunyon' => 'communion first holy communion',
        'kasal' => 'marriage wedding pakasal',
        'pakasal' => 'marriage wedding kasal',
        'misa' => 'mass schedule mass schedule oras',
        'pamisa' => 'mass intention memorial prayer intention',
        'libing' => 'funeral burial funeral mass',
        'lamay' => 'funeral burial memorial',
        'bahay' => 'house home house blessing',
        'sasakyan' => 'vehicle car vehicle blessing',
        'opisina' => 'office hours parish office contact',
        'anunsyo' => 'announcement announcements news',
        'abiso' => 'announcement announcements notice',
        'sertipiko' => 'certificate certification record copy',
        'cert' => 'certificate certification record copy',
        'iskedyul' => 'schedule calendar event',
        'kailangan' => 'requirements documents papers need prepare',
        'papeles' => 'requirements documents papers',
        'dokumento' => 'requirements documents papers',
        'paano' => 'how process steps request procedure',
        'magkano' => 'fee payment cost amount'
    ];

    foreach ($map as $term => $addition) {
        if (strpos($q, $term) !== false) {
            $expanded .= ' ' . $addition;
        }
    }

    return trim($expanded);
}

function aiLocalizedGuidance($guidance, $language) {
    if ($language === 'en') {
        return $guidance;
    }
    if (preg_match('/\b(po|opo|wala po|makipag-ugnayan)\b/i', (string) ($guidance['answer'] ?? ''))) {
        return $guidance;
    }

    $title = aiNormalizeText($guidance['title'] ?? '');
    $localized = $guidance;
    $maps = [
        'baptism' => [
            'title' => 'Mga requirement sa Binyag',
            'answer' => 'Bago po mag-submit ng Baptism request, ihanda ang mga opisyal na requirement na ito.'
        ],
        'confirmation' => [
            'title' => 'Mga requirement sa Kumpil',
            'answer' => 'Para po sa Confirmation o Kumpil, ihanda ang impormasyong kailangan at mga supporting parish documents.'
        ],
        'marriage' => [
            'title' => 'Mga requirement sa Kasal',
            'answer' => 'Para po sa Marriage o Kasal, kasama sa mga requirement ang mga opisyal na dokumento at preparation steps na ito.'
        ],
        'communion' => [
            'title' => 'Mga requirement sa First Holy Communion',
            'answer' => 'Para po sa First Holy Communion, ihanda ang communicant information at supporting parish records na hinihingi ng parish office.'
        ],
        'anointing' => [
            'title' => 'Request para sa Anointing of the Sick',
            'answer' => 'Para po sa Anointing of the Sick, ibigay ang pangalan ng may sakit, lokasyon, contact person, preferred date at time, at urgent details kung mayroon.'
        ],
        'funeral' => [
            'title' => 'Request para sa Funeral Mass',
            'answer' => 'Para po sa Funeral Mass request, ibigay ang pangalan ng yumao, preferred date at time, contact person, Death Certificate, at mga instruction ng parish office.'
        ],
        'house blessing' => [
            'title' => 'House Blessing request',
            'answer' => 'Para po sa house blessing, ibigay ang requester name, kumpletong address, preferred schedule, at contact details.'
        ],
        'vehicle blessing' => [
            'title' => 'Vehicle Blessing request',
            'answer' => 'Para po sa vehicle blessing, ibigay ang owner name, vehicle details, preferred schedule, at contact details.'
        ],
        'certificate' => [
            'title' => 'Certificate request',
            'answer' => 'Para po mag-request ng parish certificate, piliin ang certificate type, kumpletuhin ang details, mag-upload ng supporting documents, at hintayin ang parish review.'
        ],
        'mass schedule' => [
            'title' => 'Iskedyul ng Misa',
            'answer' => 'Narito po ang Mass schedule na naka-file sa system. Para sa pinakabagong approved schedule, tingnan po ang Schedule page o makipag-ugnayan sa parish office.'
        ],
        'office hours' => [
            'title' => 'Oras ng parish office',
            'answer' => 'Ito po ang parish office hours na naka-file sa system.'
        ],
        'reservation' => [
            'title' => 'Reservation request',
            'answer' => 'Ang reservation requests po ay nirereview batay sa event type, date, time, location, at availability ng parish schedule.'
        ],
        'status' => [
            'title' => 'Status ng request',
            'answer' => 'Buksan po ang My Requests para makita ang request status. Ang Pending ay naghihintay ng review, Processing ay sinusuri ng staff, Approved ay tinanggap, Completed ay tapos na, at Rejected ay hindi naaprubahan o kailangan ng correction.'
        ],
        'announcement' => [
            'title' => 'Mga anunsyo',
            'answer' => 'Buksan po ang Announcements para makita ang mga active parish announcements, schedules, events, at attached files mula sa parish office.'
        ],
        'payment' => [
            'title' => 'Status ng bayad',
            'answer' => 'Wala pong naka-store na payment status record para sa account ninyo sa TUGON. Makipag-ugnayan po sa parish office para sa payment confirmation.'
        ],
        'registration' => [
            'title' => 'Account registration',
            'answer' => 'Para po mag-register, kumpletuhin ang parishioner details, tapusin ang live identity verification, i-scan ang valid ID, at hintayin ang approval ng parish administrator bago mag-login.'
        ],
        'login' => [
            'title' => 'Tulong sa login',
            'answer' => 'Gamitin po ang registered email address o mobile number at password para mag-login. Para sa password recovery, buksan ang Forgot Password o makipag-ugnayan sa parish office.'
        ],
        'request requirements' => [
            'title' => 'Mga requirement ng request',
            'answer' => 'Para po sa certificate at sacramental requests, maglagay ng tamang detalye at mag-upload ng malinaw na requirements file bago magsumite.'
        ],
        'emergency' => [
            'title' => 'Urgent parish concern',
            'answer' => 'Para po sa urgent sacramental o parish concerns, makipag-ugnayan agad sa parish office para matulungan kayo.'
        ],
        'fee' => [
            'title' => 'Impormasyon sa bayad',
            'answer' => 'Wala po akong official fee o amount na naka-file sa system. Makipag-ugnayan po sa parish office para makumpirma ang tamang halaga.'
        ],
        'parish priest' => [
            'title' => 'Parish Priest',
            'answer' => $guidance['answer'] ?? ''
        ],
        'parish vicar' => [
            'title' => 'Parish Vicar',
            'answer' => $guidance['answer'] ?? ''
        ],
    ];

    foreach ($maps as $needle => $text) {
        if (strpos($title, $needle) !== false) {
            $localized['title'] = $text['title'];
            $localized['answer'] = $text['answer'];
            return $localized;
        }
    }

    $localized['answer'] = 'Sige po. Ito po ang opisyal na impormasyong naka-file sa TUGON: ' . ($guidance['answer'] ?? '');
    return $localized;
}

function aiTypoGuardCorrection($query) {
    $text = trim((string) $query);
    if ($text === '') {
        return null;
    }

    $phraseCorrections = [
        'parist priest' => 'parish priest',
        'parich priest' => 'parish priest',
        'parrish priest' => 'parish priest'
    ];

    $lowerText = strtolower($text);
    foreach ($phraseCorrections as $typo => $correction) {
        if (preg_match('/\b' . preg_quote($typo, '/') . '\b/i', $lowerText)) {
            return [
                'typo' => $typo,
                'correction' => $correction,
                'message' => 'Did you mean "' . $correction . '"? Just checking before I answer.'
            ];
        }
    }

    $centralCorrections = [
        'pierst' => 'priest',
        'preist' => 'priest',
        'priestt' => 'priest',
        'vicer' => 'vicar',
        'parrish' => 'parish',
        'parich' => 'parish',
        'parist' => 'parish',
        'churhc' => 'church',
        'chruch' => 'church',
        'baptsim' => 'baptism',
        'baptizm' => 'baptism',
        'merriage' => 'marriage',
        'marraige' => 'marriage',
        'mariage' => 'marriage',
        'confrimation' => 'confirmation',
        'confirmaton' => 'confirmation'
    ];

    if (!preg_match_all('/\b[a-zA-Z]{4,}\b/', strtolower($text), $matches)) {
        return null;
    }

    foreach ($matches[0] as $word) {
        if (isset($centralCorrections[$word])) {
            return [
                'typo' => $word,
                'correction' => $centralCorrections[$word],
                'message' => 'Did you mean "' . $centralCorrections[$word] . '"? Just checking before I answer.'
            ];
        }
    }

    return null;
}

function aiUserConfirmedTypo($query) {
    return preg_match('/^\s*(yes|yeah|yep|correct|right|true|oo|opo|sige)\s*[.!?]*\s*$/i', (string) $query) === 1;
}

function aiApplyTypoCorrection($query, $typo, $correction) {
    return preg_replace('/\b' . preg_quote($typo, '/') . '\b/i', $correction, (string) $query, 1);
}

function aiResolveTypoConfirmation($query, array $conversation) {
    if (!aiUserConfirmedTypo($query) || empty($conversation)) {
        return null;
    }

    $lastAssistant = null;
    for ($i = count($conversation) - 1; $i >= 0; $i--) {
        $turn = $conversation[$i];
        if (!is_array($turn)) {
            continue;
        }
        if (($turn['role'] ?? '') === 'assistant') {
            $lastAssistant = trim((string) ($turn['content'] ?? ''));
            break;
        }
    }

    if (!$lastAssistant || !preg_match('/^Did you mean "([^"]+)"\? Just checking before I answer\.$/i', $lastAssistant, $m)) {
        return null;
    }

    $correction = $m[1];
    for ($i = count($conversation) - 1; $i >= 0; $i--) {
        $turn = $conversation[$i];
        if (!is_array($turn) || ($turn['role'] ?? '') !== 'user') {
            continue;
        }
        $previousQuestion = trim((string) ($turn['content'] ?? ''));
        $detected = aiTypoGuardCorrection($previousQuestion);
        if ($detected && strcasecmp($detected['correction'], $correction) === 0) {
            return aiApplyTypoCorrection($previousQuestion, $detected['typo'], $correction);
        }
    }

    return null;
}

function aiContainsAny($query, array $terms) {
    foreach ($terms as $term) {
        if (strpos($query, $term) !== false) {
            return true;
        }
    }
    return false;
}

function aiDetectTopic($query) {
    $q = aiNormalizeText($query);
    $topics = [
        'baptism' => ['baptism', 'baptismal', 'baptize', 'baptizing', 'christening', 'binyag', 'baby baptism', 'pabinyag', 'magpabinyag'],
        'confirmation' => ['confirmation', 'confirmand', 'kumpil', 'pakumpil', 'magpakumpil'],
        'communion' => ['communion', 'first communion', 'holy communion', 'komunyon', 'first holy communion'],
        'marriage' => ['marriage', 'wedding', 'marry', 'kasal', 'pakasal', 'magpakasal'],
        'anointing' => ['anointing', 'sick', 'anointing of the sick', 'pagpapahid', 'may sakit', 'ospital'],
        'funeral' => ['funeral', 'burial', 'libing', 'lamay', 'funeral mass', 'burol'],
        'memorial' => ['memorial', 'death anniversary', 'pa misa', 'pamisa', 'mass intention', 'prayer intention', 'intentions'],
        'house_blessing' => ['house blessing', 'bless my house', 'blessing house', 'pa bless bahay', 'pabless bahay', 'bahay blessing', 'magpa bless ng bahay', 'pabasbas ng bahay'],
        'vehicle_blessing' => ['vehicle blessing', 'car blessing', 'motor blessing', 'bless my car', 'pa bless sasakyan', 'sasakyan blessing', 'pabasbas ng sasakyan'],
        'hall_reservation' => ['hall reservation', 'reserve hall', 'venue', 'function hall', 'reservation', 'booking', 'mag reserve'],
        'certificate' => ['certificate', 'cert', 'certification', 'baptismal certificate', 'confirmation certificate', 'record copy', 'sertipiko', 'kopya ng record'],
        'mass_schedule' => ['mass schedule', 'misa', 'oras ng misa', 'schedule ng misa', 'mass time', 'anong oras ang misa'],
        'office_hours' => ['office hours', 'office schedule', 'parish office', 'oras ng office', 'opisina', 'open ba', 'bukas ba'],
        'announcements' => ['announcement', 'announcements', 'abiso', 'balita', 'news', 'anunsyo'],
        'request_status' => ['status', 'track', 'reference', 'pending', 'approved', 'processing', 'nasaan na', 'kumusta ang request']
    ];
    foreach ($topics as $topic => $terms) {
        if (aiContainsAny($q, $terms)) {
            return $topic;
        }
    }
    return null;
}

function aiHasRequirementIntent($query) {
    $q = aiNormalizeText($query);
    return aiContainsAny($q, [
        'requirement', 'requirements', 'document', 'documents', 'papers', 'needed',
        'need', 'prepare', 'bring', 'submit', 'upload', 'kailangan', 'ano kailangan',
        'requirements para', 'requirements for', 'ano ang kailangan', 'anong kailangan',
        'papeles', 'dokumento', 'ihanda'
    ]);
}

function aiHasProcedureIntent($query) {
    $q = aiNormalizeText($query);
    return aiContainsAny($q, [
        'how', 'how to', 'procedure', 'process', 'steps', 'apply', 'request',
        'i want', 'gusto ko', 'paano', 'magpa', 'pa ', 'schedule', 'saan pupunta',
        'pwede ba', 'puwede ba', 'mag request', 'mag-request', 'humingi', 'kumuha'
    ]);
}

function aiHasFeeIntent($query) {
    $q = aiNormalizeText($query);
    return aiContainsAny($q, ['fee', 'fees', 'cost', 'price', 'amount', 'payment', 'pay', 'magkano', 'bayad', 'halaga']);
}

function aiKnowledgeScore($query, $row) {
    $q = aiNormalizeText(aiExpandBilingualQuery($query));
    $score = 0;
    $topic = aiNormalizeText($row['topic'] ?? '');
    if ($topic !== '' && strpos($q, $topic) !== false) {
        $score += 25;
    }

    $keywords = preg_split('/[,;\r\n]+/', (string) ($row['keywords'] ?? ''));
    foreach ($keywords as $keyword) {
        $keyword = aiNormalizeText($keyword);
        if ($keyword === '') {
            continue;
        }
        if (strpos($q, $keyword) !== false || strpos($keyword, $q) !== false) {
            $score += strlen($keyword) > 8 ? 12 : 7;
        }
    }

    $category = aiNormalizeText($row['category'] ?? '');
    if ($category !== '' && strpos($q, $category) !== false) {
        $score += 5;
    }

    return $score;
}

function aiEnsureBilingualKnowledgeHints($conn) {
    if (!aiTableExists($conn, 'chatbot_knowledge')) {
        return;
    }

    $hints = [
        'Baptism' => 'ano ang requirements sa binyag,paano magpabinyag,pabinyag po,binyag requirements,kailangan sa binyag,magkano ang baptism',
        'Confirmation' => 'ano ang requirements sa kumpil,paano magpakumpil,kumpil requirements,kailangan sa confirmation',
        'Communion' => 'ano ang requirements sa komunyon,first communion requirements,kailangan sa first holy communion',
        'Marriage' => 'ano ang requirements sa kasal,paano magpakasal,kasal requirements,kailangan sa kasal,wedding requirements',
        'Anointing' => 'pahid ng langis,may sakit,ospital,urgent priest visit,anointing request',
        'Funeral' => 'libing,lamay,burol,funeral mass request,pamisa para sa yumao',
        'House Blessing' => 'pabasbas ng bahay,magpa bless ng bahay,house blessing po,pwede ba magpa bless ng bahay',
        'Vehicle Blessing' => 'pabasbas ng sasakyan,pa bless ng sasakyan,car blessing,vehicle blessing po',
        'Certificate' => 'paano kumuha ng certificate,paano mag request ng baptismal certificate,sertipiko,kopya ng record',
        'Mass Schedule' => 'anong oras ang misa,oras ng misa,iskedyul ng misa,may misa ba ngayon',
        'Office Hours' => 'bukas ba ang opisina,oras ng office,office hours po,kailan bukas ang parish office',
        'Reservations' => 'paano mag reserve,reservation po,mag book ng schedule,venue reservation',
        'Emergency' => 'urgent concern,kailangan ng pari,emergency parish contact,sino tatawagan',
        'Parish Priest' => 'sino ang parish priest,pari ng parokya,who is the priest',
        'Parish Vicar' => 'sino ang parish vicar,assistant priest,parochial vicar'
    ];

    foreach ($hints as $topicNeedle => $keywords) {
        $like = '%' . $topicNeedle . '%';
        $stmt = $conn->prepare("UPDATE chatbot_knowledge SET keywords = CONCAT(COALESCE(keywords, ''), ',', ?) WHERE topic LIKE ? AND keywords NOT LIKE ?");
        if (!$stmt) {
            continue;
        }
        $firstKeyword = '%' . strtok($keywords, ',') . '%';
        $stmt->bind_param('sss', $keywords, $like, $firstKeyword);
        $stmt->execute();
        $stmt->close();
    }
}

function aiFindKnowledgeGuidance($conn, $query) {
    if (!ensureChatbotKnowledgeSchema($conn)) {
        return null;
    }
    chatbotKnowledgeSeedDefaults($conn);
    aiEnsureBilingualKnowledgeHints($conn);

    $rows = [];
    $q = trim(aiExpandBilingualQuery($query));
    if ($q !== '') {
        $like = '%' . $q . '%';
        $stmt = $conn->prepare("SELECT * FROM chatbot_knowledge WHERE status = 'active' AND (topic LIKE ? OR keywords LIKE ? OR answer LIKE ?) ORDER BY updated_at DESC LIMIT 12");
        if ($stmt) {
            $stmt->bind_param('sss', $like, $like, $like);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $stmt->close();
        }
    }

    if (!$rows) {
        $result = $conn->query("SELECT * FROM chatbot_knowledge WHERE status = 'active' ORDER BY updated_at DESC LIMIT 100");
        while ($result && $row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    $ranked = [];
    foreach ($rows as $row) {
        $score = aiKnowledgeScore($query, $row);
        if ($score >= 7) {
            $ranked[] = ['score' => $score, 'row' => $row];
        }
    }
    usort($ranked, static function ($left, $right) {
        if ($left['score'] === $right['score']) {
            return strcmp((string) ($right['row']['updated_at'] ?? ''), (string) ($left['row']['updated_at'] ?? ''));
        }
        return $right['score'] <=> $left['score'];
    });
    $ranked = array_slice($ranked, 0, 3);
    if (!$ranked) {
        return null;
    }

    $best = $ranked[0]['row'];
    $guidance = aiGuidanceResponse(
        $best['topic'],
        $best['answer'],
        null,
        chatbotKnowledgeStepsArray($best['steps'] ?? '')
    );
    $guidance['knowledge_sources'] = array_map(static function ($item) {
        $row = $item['row'];
        $content = trim((string) ($row['answer'] ?? ''));
        $steps = trim((string) ($row['steps'] ?? ''));
        if ($steps !== '') {
            $content .= "\nRequirements or steps:\n" . $steps;
        }
        return [
            'title' => (string) ($row['topic'] ?? ''),
            'category' => (string) ($row['category'] ?? 'general'),
            'source' => trim((string) ($row['source'] ?? '')) ?: 'TUGON administrator-managed knowledge base',
            'content' => $content,
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }, $ranked);

    return $guidance;
}

// Context Guardrails - Limits assistant answers to Tugon, parish services, and role-appropriate topics.
function aiQuestionAllowed($query, $role) {
    $q = aiNormalizeText($query);
    if ($q === '') {
        return true;
    }

    $adminOnlyActions = [
        'create announcement', 'post announcement', 'add announcement', 'delete announcement',
        'approve request', 'reject request', 'create schedule', 'delete schedule', 'manage users',
        'make me admin', 'admin password'
    ];

    foreach ($adminOnlyActions as $phrase) {
        if ($role !== 'admin' && strpos($q, $phrase) !== false) {
            return false;
        }
    }

    $blockedPatterns = [
        '/\bwhat is my name\b/',
        '/\bwho am i\b/',
        '/\btell me a joke\b/',
        '/\bjoke\b/',
        '/\bstory\b/',
        '/\bessay\b/',
        '/\bpoem\b/',
        '/\bcode\b/',
        '/\bprogramming\b/',
        '/\bweather\b/',
        '/\brecipe\b/',
        '/\bmovie\b/',
        '/\bgame\b/',
        '/\bmath\b/',
        '/\btranslate\b/'
    ];

    foreach ($blockedPatterns as $pattern) {
        if (preg_match($pattern, $q)) {
            return false;
        }
    }

    $allowedKeywords = [
        'tugon', 'parish', 'church', 'office', 'hours', 'schedule', 'mass', 'calendar',
        'certificate', 'cert', 'baptismal', 'baptism', 'confirmation', 'communion', 'sacramental',
        'blessing', 'announcement', 'notification', 'reservation',
        'funeral', 'marriage', 'wedding', 'anointing', 'patronal', 'fiesta', 'navigate',
        'what time', 'binyag', 'pabinyag', 'kumpil',
        'komunyon', 'kasal', 'pakasal', 'misa', 'pamisa', 'libing', 'lamay',
        'opisina', 'abiso', 'sasakyan', 'bahay', 'magkano', 'bayad', 'halaga',
        'sertipiko', 'iskedyul', 'anunsyo', 'parokya', 'chapel', 'priest', 'pari',
        'vicar', 'celebrant', 'confession', 'godparent', 'sponsor', 'bec'
    ];

    foreach ($allowedKeywords as $keyword) {
        if (strpos($q, $keyword) !== false) {
            return true;
        }
    }

    if (preg_match('/\bPRQ-\d{8}-\d{5}\b/i', $query)) {
        return true;
    }

    $systemWorkflowPatterns = [
        '/\b(?:my|track|check|view)\s+requests?\b/',
        '/\brequest\s+(?:status|reference|tracking)\b/',
        '/\b(?:login|log in|password|register|registration|account)\b/',
        '/\b(?:upload|submit)\b.*\b(?:requirement|document)\b/',
    ];
    foreach ($systemWorkflowPatterns as $pattern) {
        if (preg_match($pattern, $q)) {
            return true;
        }
    }

    return false;
}

function aiIsParishContextFollowUp($query) {
    $q = aiNormalizeText($query);
    if ($q === '') {
        return false;
    }

    $followUpPatterns = [
        '/^(?:and\s+)?(?:what|how)\s+about\s+(?:it|that|this|those|these|them)\b/',
        '/^(?:what|which)\s+(?:requirements?|documents?|papers?|steps?)\b/',
        '/^(?:what|which)\s+(?:do|should)\s+i\s+(?:bring|prepare|submit|upload|need)\b/',
        '/^(?:how|where|when)\b.*\b(?:it|that|this|those|these|them)\b/',
        '/^(?:the\s+)?(?:requirements?|documents?|papers?|steps?|process|procedure|fees?|schedule|status)\??$/',
    ];
    foreach ($followUpPatterns as $pattern) {
        if (preg_match($pattern, $q)) {
            return true;
        }
    }
    return false;
}

// Public Schedule Lookup - Reads approved Mass schedules and events for user-facing assistant answers.
function aiFetchLatestPublicMassSchedules($conn) {
    if (!aiTableExists($conn, 'schedule_events')) {
        return [];
    }

    $rows = [];
    $sql = "SELECT title, event_date, start_time, location
            FROM schedule_events
            WHERE status != 'cancelled'
              AND visibility = 'public'
              AND approval_status = 'approved'
              AND category = 'mass'
              AND event_date >= CURDATE()
            ORDER BY event_date ASC, start_time ASC
            LIMIT 3";
    $result = $conn->query($sql);
    while ($result && $row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}

// Direct Guidance - Answers common workflow questions before falling back to database search.
function aiDirectGuidance($conn, $query, $role, $userId) {
    $q = aiNormalizeText($query);
    $topic = aiDetectTopic($query);
    $wantsRequirements = aiHasRequirementIntent($query);
    $wantsProcedure = aiHasProcedureIntent($query);
    $wantsFees = aiHasFeeIntent($query);

    if ($wantsFees) {
        return aiGuidanceResponse(
            'Parish fee information',
            aiDetectUserLanguage($query) === 'en'
                ? 'I do not have official fee or payment amount information on file. Please contact the parish office directly for the confirmed amount.'
                : 'Wala po akong official fee o amount na naka-file sa system. Makipag-ugnayan po sa parish office para makumpirma ang tamang halaga.',
            null
        );
    }

    if ($topic === 'baptism' && ($wantsRequirements || $wantsProcedure)) {
        return aiGuidanceResponse(
            'Baptism requirements',
            "I'd be glad to assist you. Before submitting a Baptism request, prepare these requirements:",
            $role === 'admin' ? '../admin/manage-requests.php' : '../users/request-service.php',
            [
                'Chapel recommendation.',
                "Parents' latest marriage contract or receipt, if applicable.",
                'Photocopy of marriage certificate, if married.',
                "Photocopy of the child's live birth certificate with registry number.",
                'Two white cards of sponsors.',
                'White cards of parents.',
                'Pre-baptismal investigation sheet, if requested by the parish office.'
            ]
        );
    }

    if ($topic === 'marriage' && ($wantsRequirements || $wantsProcedure)) {
        return aiGuidanceResponse(
            'Marriage requirements',
            'Here is the information you need. Marriage requirements include:',
            $role === 'admin' ? '../admin/manage-requests.php' : '../users/request-service.php',
            [
                'Pre-Cana seminar.',
                'Municipal marriage license.',
                'BEC recommendation.',
                'Baptismal certificate for marriage purpose.',
                'Confirmation certificate.',
                'Permit to marry, if applicable.',
                'Marriage interview.',
                'Confession.',
                'CO permit, if applicable for police or army personnel.'
            ]
        );
    }

    if ($topic === 'confirmation' && ($wantsRequirements || $wantsProcedure)) {
        return aiGuidanceResponse(
            'Confirmation requirements',
            'Thank you for your question. For Confirmation, prepare these requirements:',
            $role === 'admin' ? '../admin/manage-requests.php' : '../users/request-service.php',
            [
                'Accurate personal details of the confirmand.',
                'Supporting parish record requested by the parish office.',
                'PSA or birth certificate copy, if needed for verification.',
                'Clear uploaded documents before submitting the request.'
            ]
        );
    }

    if ($topic === 'communion' && ($wantsRequirements || $wantsProcedure)) {
        return aiGuidanceResponse(
            'First Holy Communion requirements',
            'I can help with First Holy Communion. Please prepare these basic requirements:',
            $role === 'admin' ? '../admin/manage-requests.php' : '../users/request-service.php',
            [
                'Accurate personal details of the communicant.',
                'Baptismal record or parish record if requested for verification.',
                'Parent or guardian information.',
                'Clear supporting document upload before submission.',
                'Parish office confirmation of the schedule or preparation requirements.'
            ]
        );
    }

    if ($topic === 'anointing') {
        return aiGuidanceResponse(
            'Anointing of the Sick request',
            'For Anointing of the Sick, provide the sick person\'s name, location, contact person, preferred date and time, and any urgent details so the parish office can assist properly.',
            $role === 'admin' ? '../admin/manage-requests.php' : '../users/request-service.php',
            [
                'Prepare the name and condition or situation of the sick person.',
                'Provide the exact address or hospital location.',
                'Add a contact number for coordination.',
                'Submit the request or contact the parish office directly if urgent.'
            ]
        );
    }

    if ($topic === 'funeral' || $topic === 'memorial') {
        return aiGuidanceResponse(
            $topic === 'funeral' ? 'Funeral Mass request' : 'Memorial Mass or prayer intention',
            'I can guide you with this. Please provide the name of the deceased, a Death Certificate, preferred Mass date and time, contact person, and any parish office instructions.',
            $role === 'admin' ? '../admin/manage-requests.php' : '../users/request-service.php',
            [
                'Prepare the complete name of the deceased or intention.',
                'Upload a clear copy of the Death Certificate.',
                'Choose the preferred date and time.',
                'Provide the contact person and phone number.',
                'Wait for parish office confirmation of availability.'
            ]
        );
    }

    if ($topic === 'house_blessing' || $topic === 'vehicle_blessing') {
        return aiGuidanceResponse(
            $topic === 'house_blessing' ? 'House blessing request' : 'Vehicle blessing request',
            'For blessing requests, provide the owner or requester name, blessing type, location, preferred schedule, and contact details for parish confirmation.',
            $role === 'admin' ? '../admin/manage-requests.php' : '../users/request-blessing.php',
            [
                'Select the blessing type.',
                'Enter the address or vehicle details.',
                'Choose a preferred date and time.',
                'Submit the request and wait for parish office confirmation.'
            ]
        );
    }

    if ($topic === 'office_hours') {
        return aiGuidanceResponse(
            t('chatbot.office_title', 'Parish office schedule'),
            t('chatbot.office_answer', 'The parish office is open Monday to Saturday, 8:00 AM to 5:00 PM, with lunch break from 12:00 PM to 1:00 PM. The office is closed on Sunday.'),
            null
        );
    }

    if ($topic === 'mass_schedule' || (strpos($q, 'mass') !== false && strpos($q, 'schedule') !== false)) {
        $massRows = aiFetchLatestPublicMassSchedules($conn);
        if (!empty($massRows)) {
            $parts = [];
            foreach ($massRows as $row) {
                $parts[] = $row['title'] . ' on ' . formatDate($row['event_date']) . ' at ' . formatTime($row['start_time']) . ($row['location'] ? ' in ' . $row['location'] : '');
            }
            return aiGuidanceResponse(t('chatbot.mass_title', 'Mass schedule'), implode('; ', $parts) . '.', $role === 'admin' ? '../admin/manage-calendar.php' : '../users/view-schedule.php');
        }

        return aiGuidanceResponse(
            t('chatbot.mass_title', 'Mass schedule'),
            t('chatbot.mass_default', 'The stored Mass schedule is Sunday Mass at 6:00 AM, 8:00 AM, and 5:00 PM; weekday Mass is Monday to Friday at 5:30 PM.'),
            $role === 'admin' ? '../admin/manage-calendar.php' : '../users/view-schedule.php'
        );
    }

    if (preg_match('/\b(PRQ-\d{8}-\d{5})\b/i', $query, $matches)) {
        $reference = strtoupper($matches[1]);
        if ($role === 'admin') {
            $stmt = $conn->prepare("SELECT r.request_id, r.request_type, r.status, u.fullname FROM requests r JOIN users u ON r.user_id = u.id WHERE r.reference_number = ? LIMIT 1");
            $stmt->bind_param('s', $reference);
        } else {
            $stmt = $conn->prepare("SELECT request_id, request_type, status FROM requests WHERE reference_number = ? AND user_id = ? LIMIT 1");
            $stmt->bind_param('si', $reference, $userId);
        }

        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$request) {
            return aiGuidanceResponse(t('chatbot.status_title', 'Request status'), tr('chatbot.no_request', 'No request record was found for reference {reference} in the records available to your account.', ['reference' => $reference]));
        }

        $owner = isset($request['fullname']) ? tr('chatbot.owner_for', ' for {name}', ['name' => $request['fullname']]) : '';
        return aiGuidanceResponse(
            t('chatbot.status_title', 'Request status'),
            tr('chatbot.request_is', 'Request {reference}{owner} is currently {status}.', [
                'reference' => $reference,
                'owner' => $owner,
                'status' => ucfirst($request['status'])
            ]),
            $role === 'admin' ? '../admin/process-request.php?id=' . intval($request['request_id']) : '../users/view-request.php?id=' . intval($request['request_id'])
        );
    }

    if ($topic === 'request_status' || (strpos($q, 'status') !== false && strpos($q, 'request') !== false)) {
        return aiGuidanceResponse(
            t('chatbot.status_title', 'Request status'),
            t('chatbot.status_help', 'Open My Requests to view your request status. Pending means waiting for review, processing means staff are checking it, approved means accepted, completed means finished, and rejected means it was not approved or needs correction.'),
            $role === 'admin' ? '../admin/manage-requests.php' : '../users/my-requests.php'
        );
    }

    if ($wantsRequirements) {
        return aiGuidanceResponse(
            t('chatbot.requirements_title', 'Request requirements'),
            t('chatbot.requirements_answer', 'For certificate and sacramental requests, prepare these basic requirements:'),
            $role === 'admin' ? '../admin/manage-requests.php' : '../users/request-certificate.php',
            [
                'Accurate applicant or parishioner details.',
                'Clear valid ID, if required.',
                'Supporting parish document or record scan.',
                'Other sacrament-specific documents shown on the request form.',
                'Clear uploaded files before submitting.'
            ]
        );
    }

    if ($topic === 'certificate' || strpos($q, 'certificate') !== false || strpos($q, 'baptismal') !== false || strpos($q, 'confirmation') !== false || strpos($q, 'communion') !== false) {
        return aiGuidanceResponse(
            t('chatbot.certificates_title', 'Certificate requests'),
            t('chatbot.certificates_answer', 'To request a certificate, go to Certificates, select the certificate type, enter the needed details, attach your requirements file, and submit the request for parish review.'),
            $role === 'admin' ? '../admin/manage-requests.php' : '../users/request-certificate.php'
        );
    }

    if (strpos($q, 'blessing') !== false) {
        return aiGuidanceResponse(
            'Blessing Request',
            'Follow these steps:',
            $role === 'admin' ? '../admin/manage-requests.php' : '../users/request-blessing.php',
            [
                'Open My Requests, then select Blessings.',
                'Choose House Blessing or Vehicle Blessing.',
                'Enter the requested blessing details, location, and contact information.',
                'Select your preferred date and time.',
                'Submit the request and track its status from My Requests.'
            ]
        );
    }

    if (strpos($q, 'sacramental') !== false || strpos($q, 'funeral') !== false || strpos($q, 'wedding') !== false || strpos($q, 'marriage') !== false || strpos($q, 'anointing') !== false || strpos($q, 'patronal') !== false) {
        return aiGuidanceResponse(
            t('chatbot.service_title', 'Sacramental service requests'),
            t('chatbot.service_answer', 'To request a sacramental service, go to Sacramental Services, select the service, enter the date, time, location, details, attach requirements if available, and submit.'),
            $role === 'admin' ? '../admin/manage-requests.php' : '../users/request-service.php'
        );
    }

    if ($topic === 'announcements' || strpos($q, 'announcement') !== false) {
        if ($role !== 'admin' && (strpos($q, 'create') !== false || strpos($q, 'post') !== false || strpos($q, 'add') !== false)) {
            return aiGuidanceResponse(t('chatbot.outside_title', 'Parish services focus'), aiOutOfContextMessage());
        }

        return aiGuidanceResponse(
            t('chatbot.announcements_title', 'Announcements'),
            t('chatbot.announcements_answer', 'Open Announcements to view active parish announcements, schedules, events, and attached files posted by the parish office.'),
            $role === 'admin' ? '../admin/manage-announcements.php' : '../users/announcements.php'
        );
    }

    if (strpos($q, 'payment') !== false || strpos($q, 'paid') !== false || strpos($q, 'unpaid') !== false || strpos($q, 'pay') !== false) {
        return aiGuidanceResponse(
            t('chatbot.payment_title', 'Payment status'),
            t('chatbot.payment_answer', 'No payment status record is stored for your account in Tugon. Please contact the parish office for payment confirmation.'),
            null
        );
    }

    if (strpos($q, 'register') !== false || strpos($q, 'registration') !== false || strpos($q, 'account') !== false) {
        return aiGuidanceResponse(
            t('chatbot.registration_title', 'Account registration'),
            t('chatbot.registration_answer', 'To register, complete your parishioner details, pass live identity verification, scan your valid ID, and wait for parish administrator approval before logging in.'),
            '../auth/register.php'
        );
    }

    if (strpos($q, 'schedule') !== false || strpos($q, 'calendar') !== false || strpos($q, 'event') !== false) {
        return aiGuidanceResponse(
            t('chatbot.schedule_title', 'Parish schedules'),
            t('chatbot.schedule_answer', 'Open Schedule to view approved public parish schedules, events, Masses, and sacramental service dates.'),
            $role === 'admin' ? '../admin/manage-calendar.php' : '../users/view-schedule.php'
        );
    }

    if (strpos($q, 'login') !== false || strpos($q, 'password') !== false) {
        return aiGuidanceResponse(
            t('chatbot.login_title', 'Login help'),
            t('chatbot.login_answer', 'Use your registered Gmail address and password to log in. For password recovery, open Forgot Password or contact the parish office for account verification.'),
            '../auth/login.php'
        );
    }

    return null;
}

// Static Guidance Library - Maps known parish workflow topics to concise help instructions.
function aiGuidanceForQuery($query, $role) {
    $q = aiNormalizeText($query);

    $guides = [
        'certificate' => [
            'title' => 'Certificate request guidance',
            'answer' => 'To request a parish certificate, open My Requests, choose Certificates, select the certificate type, complete all applicant details, upload valid requirements, then submit for parish review.',
            'steps' => [
                'Open My Requests > Certificates.',
                'Choose Baptismal, Confirmation, or First Communion certificate.',
                'Enter accurate names, dates, and parent or sponsor details.',
                'Upload a valid ID and supporting parish information when available.',
                'Submit and track the status from My Requests.'
            ],
            'link' => $role === 'admin' ? '../admin/manage-requests.php' : '../users/request-certificate.php'
        ],
        'blessing' => [
            'title' => 'Blessing request guidance',
            'answer' => 'For blessing requests, choose the blessing type, provide the location and preferred schedule, then wait for parish confirmation.',
            'steps' => [
                'Open My Requests > Blessings.',
                'Select house, vehicle, business, office, or event blessing.',
                'Add the address, preferred date, and contact details.',
                'Submit the request for schedule review.'
            ],
            'link' => $role === 'admin' ? '../admin/manage-requests.php' : '../users/request-blessing.php'
        ],
        'reservation' => [
            'title' => $role === 'admin' ? 'Reservation support' : 'Sacramental service support',
            'answer' => $role === 'admin'
                ? 'Reservations are reviewed by parish staff based on date, type of event, and schedule availability.'
                : 'Sacramental service requests are reviewed by parish staff based on service type, date, time, location, and schedule availability.',
            'steps' => [
                $role === 'admin' ? 'Open Reservations.' : 'Open My Requests > Sacramental Services.',
                $role === 'admin' ? 'Choose the reservation type and event date.' : 'Choose the service type and service date.',
                'Provide time, location, purpose, and contact information.',
                'Submit and wait for approval or admin follow-up.'
            ],
                'link' => $role === 'admin' ? '../admin/manage-reservations.php' : '../users/request-service.php'
        ],
        'status' => [
            'title' => 'Request status explanation',
            'answer' => 'Pending means waiting for review, processing means staff are working on it, approved means accepted, completed means finished, and rejected means it needs correction or cannot be granted.',
            'steps' => [
                'Open My Requests.',
                'Find the reference number.',
                'Review the status badge and any admin note.',
                'Contact the parish office if the request has been pending for several working days.'
            ],
            'link' => $role === 'admin' ? '../admin/manage-requests.php' : '../users/my-requests.php'
        ],
        'schedule' => [
            'title' => 'Mass and schedule information',
            'answer' => 'You can view approved parish schedules, Masses, services, and events from the Schedule page.',
            'steps' => [
                'Open Schedule.',
                'Review upcoming approved events.',
                'Use the date and category details to plan your visit.',
                'For changes, watch announcements and notifications.'
            ],
            'link' => $role === 'admin' ? '../admin/manage-calendar.php' : '../users/view-schedule.php'
        ],
        'analytics' => [
            'title' => 'AI-assisted analytics',
            'answer' => $role === 'admin'
                ? 'The assistant can summarize requests, reservations, announcements, schedules, and sacramental record activity for administrative reporting.'
                : 'Your dashboard summarizes your requests, sacramental services, notifications, and recent parish activity.',
            'steps' => [
                $role === 'admin' ? 'Ask about pending requests, reservations, announcements, schedules, or records.' : 'Ask about pending requests, sacramental services, announcements, schedules, or records.',
                'Use the search results to open the relevant module.',
                'Admins can export formal reports from Reports.'
            ],
            'link' => $role === 'admin' ? '../admin/reports.php' : '../users/index.php'
        ]
    ];

    $map = [
        'certificate' => ['certificate', 'certification', 'baptismal', 'confirmation', 'communion', 'record copy'],
        'blessing' => ['blessing', 'bless', 'house', 'vehicle', 'business', 'bahay', 'sasakyan', 'pabless'],
        'reservation' => ['reservation', 'reserve', 'booking', 'sacramental service', 'service request', 'schedule a', 'hall', 'venue'],
        'status' => ['status', 'pending', 'approved', 'completed', 'rejected', 'reference', 'track'],
        'schedule' => ['mass', 'misa', 'service', 'schedule', 'calendar', 'event', 'oras'],
        'analytics' => ['analytics', 'report', 'summary', 'trend', 'insight', 'search']
    ];

    foreach ($map as $key => $keywords) {
        foreach ($keywords as $keyword) {
            if (strpos($q, $keyword) !== false) {
                return $guides[$key];
            }
        }
    }

    return [
        'title' => 'Parish inquiry assistance',
        'answer' => $role === 'admin'
            ? 'I can help with certificate requests, blessings, reservations, request status, schedules, announcements, and parish activity summaries.'
            : 'I can help with certificate requests, blessings, sacramental services, request status, schedules, requirements, registration, and announcements.',
        'steps' => [
            $role === 'admin' ? 'Mention the service you need: certificate, blessing, reservation, Mass schedule, or status.' : 'Mention the service you need: certificate, blessing, sacramental service, Mass schedule, or status.',
            'Include a name, reference number, or date if you want search results.',
            'Open the recommended module from the result link.'
        ],
        'link' => $role === 'admin' ? '../admin/ai-assistant.php' : '../users/ai-assistant.php'
    ];
}

// Search Builder - Collects matching records, requests, announcements, and schedules for assistant results.
function aiBuildSearchResults($conn, $query, $role, $userId) {
    $results = [];
    $query = trim($query);
    if (strlen($query) < 2) {
        return $results;
    }

    $like = '%' . $query . '%';

    if (aiTableExists($conn, 'announcements')) {
        $notDeleted = aiNotDeletedSql($conn, 'announcements');
        $rows = aiSearchLike(
            $conn,
            "SELECT title, type, published_date FROM announcements WHERE status = 'active'$notDeleted AND (title LIKE ? OR content LIKE ?) ORDER BY published_date DESC LIMIT 5",
            'ss',
            [$like, $like]
        );
        foreach ($rows as $row) {
            $results[] = [
                'module' => 'Announcement',
                'title' => $row['title'],
                'meta' => ucfirst($row['type']) . ' - ' . formatDate($row['published_date']),
                'url' => $role === 'admin' ? '../admin/manage-announcements.php' : '../users/announcements.php'
            ];
        }
    }

    if (aiTableExists($conn, 'requests')) {
        $notDeleted = aiNotDeletedSql($conn, 'requests', $role === 'admin' ? 'r' : '');
        if ($role === 'admin') {
            $rows = aiSearchLike(
                $conn,
                "SELECT r.request_id, r.reference_number, r.request_type, r.status, u.fullname FROM requests r JOIN users u ON r.user_id = u.id WHERE 1=1$notDeleted AND (r.reference_number LIKE ? OR r.request_type LIKE ? OR u.fullname LIKE ?) ORDER BY r.date_requested DESC LIMIT 6",
                'sss',
                [$like, $like, $like]
            );
        } else {
            $rows = aiSearchLike(
                $conn,
                "SELECT request_id, reference_number, request_type, status FROM requests WHERE user_id = ?$notDeleted AND (reference_number LIKE ? OR request_type LIKE ?) ORDER BY date_requested DESC LIMIT 6",
                'iss',
                [$userId, $like, $like]
            );
        }

        foreach ($rows as $row) {
            $owner = isset($row['fullname']) ? ' - ' . $row['fullname'] : '';
            $results[] = [
                'module' => 'Request',
                'title' => ($row['reference_number'] ?: 'Request') . $owner,
                'meta' => ucfirst(str_replace('_', ' ', $row['request_type'])) . ' - ' . ucfirst($row['status']),
                'url' => $role === 'admin'
                    ? '../admin/process-request.php?id=' . intval($row['request_id'])
                    : '../users/view-request.php?id=' . intval($row['request_id'])
            ];
        }
    }

    if (aiTableExists($conn, 'reservations')) {
        if ($role === 'admin') {
            $rows = aiSearchLike(
                $conn,
                "SELECT reservation_id, reservation_type, event_date, status FROM reservations WHERE reservation_type LIKE ? OR status LIKE ? ORDER BY event_date DESC LIMIT 5",
                'ss',
                [$like, $like]
            );

            foreach ($rows as $row) {
                $results[] = [
                    'module' => 'Reservation',
                    'title' => ucfirst(str_replace('_', ' ', $row['reservation_type'])),
                    'meta' => formatDate($row['event_date']) . ' - ' . ucfirst($row['status']),
                    'url' => '../admin/manage-reservations.php'
                ];
            }
        }
    }

    if (aiTableExists($conn, 'schedule_events')) {
        $visibility = $role === 'admin' ? '' : " AND visibility = 'public' AND approval_status = 'approved'";
        $rows = aiSearchLike(
            $conn,
            "SELECT title, event_date, start_time, location FROM schedule_events WHERE status != 'cancelled'$visibility AND (title LIKE ? OR description LIKE ? OR location LIKE ?) ORDER BY event_date ASC LIMIT 5",
            'sss',
            [$like, $like, $like]
        );
        foreach ($rows as $row) {
            $results[] = [
                'module' => 'Schedule',
                'title' => $row['title'],
                'meta' => formatDate($row['event_date']) . ' ' . formatTime($row['start_time']) . ($row['location'] ? ' - ' . $row['location'] : ''),
                'url' => $role === 'admin' ? '../admin/manage-calendar.php' : '../users/view-schedule.php'
            ];
        }
    }

    return array_slice($results, 0, 12);
}

// Analytics Builder - Summarizes parish activity for dashboard-style assistant responses.
function aiBuildAnalytics($conn, $role, $userId) {
    if ($role === 'admin') {
        $requestNotDeleted = aiNotDeletedSql($conn, 'requests');
        $announcementNotDeleted = aiNotDeletedSql($conn, 'announcements');
        $analytics = [
            'title' => 'Administrative activity summary',
            'metrics' => [
                'Parishioners' => aiFetchOne($conn, "SELECT COUNT(*) AS count FROM users WHERE role = 'user'"),
                'Pending Requests' => aiFetchOne($conn, "SELECT COUNT(*) AS count FROM requests WHERE status = 'pending'$requestNotDeleted"),
                'Open Reservations' => aiFetchOne($conn, "SELECT COUNT(*) AS count FROM reservations WHERE status IN ('pending', 'approved')"),
                'Active Announcements' => aiFetchOne($conn, "SELECT COUNT(*) AS count FROM announcements WHERE status = 'active'$announcementNotDeleted")
            ],
            'insights' => []
        ];

        $pending = $analytics['metrics']['Pending Requests'];
        $reservations = $analytics['metrics']['Open Reservations'];
        if ($pending > 10) {
            $analytics['insights'][] = 'High request queue detected. Prioritize pending certificate reviews and notify applicants with incomplete requirements.';
        } elseif ($pending > 0) {
            $analytics['insights'][] = 'Pending requests are manageable. Review oldest items first to keep turnaround time steady.';
        } else {
            $analytics['insights'][] = 'No pending certificate requests are waiting right now.';
        }

        if ($reservations > 0) {
            $analytics['insights'][] = 'There are open reservations to monitor for schedule conflicts and confirmation messages.';
        }

        return $analytics;
    }

    $pending = aiFetchOne($conn, "SELECT COUNT(*) AS count FROM requests WHERE user_id = " . intval($userId) . " AND status = 'pending'");
    $approved = aiFetchOne($conn, "SELECT COUNT(*) AS count FROM requests WHERE user_id = " . intval($userId) . " AND status = 'approved'");

    $insights = [];
    $insights[] = $pending > 0
        ? 'You have pending requests waiting for parish review. Keep your documents and reference numbers ready.'
        : 'You have no pending certificate requests right now.';
    if ($approved > 0) {
        $insights[] = 'Approved requests may be ready for next steps. Open My Requests to check details.';
    }

    return [
        'title' => 'Your parish activity summary',
        'metrics' => [
            'Pending Requests' => $pending,
            'Approved Requests' => $approved,
            'Sacramental Services' => aiFetchOne($conn, "SELECT COUNT(*) AS count FROM requests WHERE user_id = " . intval($userId) . " AND request_type IN ('baptism_service','marriage_wedding_service','funeral_mass','anointing_of_the_sick','patronal_fiesta')"),
            'Unread Notifications' => getUnreadNotificationCount($conn, $userId)
        ],
        'insights' => $insights
    ];
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON request.', 'message' => 'Unable to process your request.']);
    exit;
}

$query = trim($payload['message'] ?? $payload['q'] ?? '');
$csrfToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload[csrfTokenName()] ?? ''));
if (!verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Your secure session token expired. Please refresh the page and try again.', 'message' => 'Your secure session token expired. Please refresh the page and try again.']);
    exit;
}
if ($query === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Please enter a message.', 'message' => 'Please enter a message.']);
    exit;
}
if (mb_strlen($query) > 2000) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Your message is too long. Please keep it under 2,000 characters.', 'message' => 'Your message is too long. Please keep it under 2,000 characters.']);
    exit;
}

$rateKey = 'tugon_ai_request_times';
$now = time();
$recentRequests = array_values(array_filter((array) ($_SESSION[$rateKey] ?? []), static function ($timestamp) use ($now) {
    return is_numeric($timestamp) && ($now - intval($timestamp)) < 60;
}));
if (count($recentRequests) >= 10) {
    http_response_code(429);
    header('Retry-After: 60');
    echo json_encode(['success' => false, 'error' => 'Too many requests. Please wait a moment and try again.', 'message' => 'Too many requests. Please wait a moment and try again.']);
    exit;
}
$recentRequests[] = $now;
$_SESSION[$rateKey] = $recentRequests;

$originalQuery = $query;
$detectedLanguage = aiDetectUserLanguage($query);
$mode = trim((string) ($payload['mode'] ?? 'chat'));
$mode = in_array($mode, ['chat', 'search', 'analytics'], true) ? $mode : 'chat';
$role = $_SESSION['role'] ?? 'user';
$userId = intval($_SESSION['user_id'] ?? 0);
$conversation = [];
foreach (array_slice((array) ($payload['conversation'] ?? []), -8) as $turn) {
    if (!is_array($turn)) {
        continue;
    }
    $turnRole = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : (($turn['role'] ?? '') === 'user' ? 'user' : '');
    $turnContent = trim(strip_tags((string) ($turn['content'] ?? '')));
    if ($turnRole !== '' && $turnContent !== '') {
        $conversation[] = ['role' => $turnRole, 'content' => mb_strimwidth($turnContent, 0, 500, '...')];
    }
}

$conversationalIntent = TugonConversationalIntent::analyze($originalQuery);
if ($conversationalIntent['intent'] !== null) {
    $answer = $conversationalIntent['response'];
    $guidance = aiGuidanceResponse('TUGON AI Parish Assistant', $answer);
    logChatbotInquiry($conn, $userId, $role, $originalQuery, $answer, $mode, false);
    echo json_encode([
        'success' => true,
        'reply' => $answer,
        'answer' => $answer,
        'guidance' => $guidance,
        'search_results' => [],
        'analytics' => null,
        'mode' => $mode,
        'detected_language' => $conversationalIntent['language'],
        'intent' => $conversationalIntent['intent'],
        'ai_engine' => 'conversational-intent',
        'context_limited' => false,
    ]);
    exit;
}

$confirmedTypoQuery = aiResolveTypoConfirmation($query, $conversation);
if ($confirmedTypoQuery !== null) {
    $query = $confirmedTypoQuery;
    $originalQuery = $query;
} else {
    $typoGuard = aiTypoGuardCorrection($query);
    if ($typoGuard !== null) {
        $answer = $typoGuard['message'];
        $guidance = aiGuidanceResponse('Quick clarification', $answer);
        logChatbotInquiry($conn, $userId, $role, $query, $answer, $mode, true);
        echo json_encode([
            'success' => true,
            'reply' => $answer,
            'answer' => $answer,
            'guidance' => $guidance,
            'search_results' => [],
            'analytics' => null,
            'mode' => $mode,
            'context_limited' => true,
            'typo_guard' => [
                'needs_confirmation' => true,
                'correction' => $typoGuard['correction']
            ]
        ]);
        exit;
    }
}

// Reject unrelated input before previous conversation text can add a parish
// keyword and accidentally turn it into an in-scope question.
if ($query !== ''
    && !aiIsGreeting($query)
    && !aiQuestionAllowed($query, $role)
    && !aiIsParishContextFollowUp($query)) {
    $answer = aiOfflineNoContextMessage();
    $guidance = aiGuidanceResponse('Parish services focus', $answer);
    logChatbotInquiry($conn, $userId, $role, $query, $answer, $mode, true);
    echo json_encode([
        'success' => true,
        'reply' => $answer,
        'answer' => $answer,
        'guidance' => $guidance,
        'search_results' => [],
        'analytics' => null,
        'mode' => $mode,
        'context_limited' => true,
        'ai_engine' => 'scope-guard'
    ]);
    exit;
}

if ($query !== '' && !empty($conversation)) {
    $recentContext = [];
    foreach (array_slice($conversation, -6) as $turn) {
        if (!is_array($turn)) {
            continue;
        }
        $content = trim((string) ($turn['content'] ?? ''));
        if ($content !== '' && strcasecmp($content, $query) !== 0) {
            $recentContext[] = $content;
        }
    }
    $contextText = implode(' ', $recentContext);
    $hasExplicitTopic = preg_match('/\b(baptism|baptismal|baptize|binyag|pabinyag|confirmation|kumpil|communion|komunyon|marriage|wedding|kasal|pakasal|mass|misa|certificate|blessing|bahay|sasakyan|announcement|abiso|office|opisina|registration|login|payment|funeral|libing|anointing)\b/i', $query);
    $looksLikeFollowUp = aiIsParishContextFollowUp($query);
    if (!$hasExplicitTopic && $looksLikeFollowUp && $contextText !== '') {
        $query = trim($contextText . ' ' . $query);
    }
}

if ($query === '') {
    $query = $role === 'admin' ? 'analytics summary and pending requests' : 'help me with parish services';
    $detectedLanguage = 'en';
}

if (!aiIsGreeting($originalQuery) && !aiQuestionAllowed($query, $role)) {
    $guidance = aiGuidanceResponse(
        'Parish services focus',
        aiOfflineNoContextMessage()
    );
    logChatbotInquiry($conn, $userId, $role, $query, $guidance['answer'], $mode, true);
    echo json_encode([
        'success' => true,
        'reply' => $guidance['answer'],
        'answer' => $guidance['answer'],
        'guidance' => $guidance,
        'search_results' => [],
        'analytics' => null,
        'mode' => $mode,
        'context_limited' => true
    ]);
    exit;
}

$greetingGuidance = aiIsGreeting($originalQuery) ? aiGuidanceResponse(
    'TUGON AI Parish Assistant',
    "Welcome the user briefly and explain that you can help with verified parish requirements, certificate requests, blessings, Mass or parish information, and contacting the parish office. Do not state any schedules, fees, or policies in this greeting."
) : null;
$feeGuidance = (!$greetingGuidance && aiHasFeeIntent($originalQuery ?: $query)) ? aiDirectGuidance($conn, $originalQuery ?: $query, $role, $userId) : null;
$knowledgeGuidance = ($greetingGuidance || $feeGuidance) ? null : ($originalQuery !== '' ? aiFindKnowledgeGuidance($conn, $originalQuery) : null);
if (!$feeGuidance && !$knowledgeGuidance && $query !== $originalQuery) {
    $knowledgeGuidance = aiFindKnowledgeGuidance($conn, $query);
}
$workflowGuidance = (!$greetingGuidance && !$feeGuidance && !$knowledgeGuidance)
    ? aiDirectGuidance($conn, $originalQuery ?: $query, $role, $userId)
    : null;

$directGuidance = $greetingGuidance ?: ($feeGuidance ?: ($knowledgeGuidance ?: $workflowGuidance));
if (!$directGuidance) {
    $answer = aiNoContextMessageForLanguage($detectedLanguage);
    logChatbotInquiry($conn, $userId, $role, $query, $answer, $mode, true);
    echo json_encode([
        'success' => true,
        'reply' => $answer,
        'answer' => $answer,
        'guidance' => aiGuidanceResponse($detectedLanguage === 'en' ? 'Parish information unavailable' : 'Walang naka-file na impormasyon', $answer),
        'search_results' => [],
        'analytics' => null,
        'mode' => $mode,
        'detected_language' => $detectedLanguage,
        'ai_engine' => 'rag-no-match'
    ]);
    exit;
}
$guidance = $directGuidance ?: aiGuidanceForQuery($query, $role);
$guidance = aiLocalizedGuidance($guidance, $detectedLanguage);
$searchResults = $role === 'admin' ? aiBuildSearchResults($conn, $query, $role, $userId) : [];
$analytics = null;
$aiEngine = 'offline-rag';

$answer = $guidance['answer'];
if ($mode === 'search' && !empty($searchResults) && !$directGuidance) {
    $answer = t('chatbot.search_found', 'I found related Tugon records. Open the matching result below.');
}

$analyticsAllowed = $role === 'admin' && ($mode === 'analytics' || strpos(strtolower($query), 'analytics') !== false || strpos(strtolower($query), 'report') !== false || strpos(strtolower($query), 'summary') !== false);
if ($analyticsAllowed) {
    $analytics = aiBuildAnalytics($conn, $role, $userId);
    $answer = $analytics['title'] . ': ' . implode(' ', $analytics['insights']);
} elseif ($mode === 'analytics' && $role !== 'admin') {
    $answer = t('chatbot.analytics_limited', 'Open My Requests to view your request status and activity. Analytics summaries are limited to parish administration.');
    $guidance = aiGuidanceResponse(t('chatbot.activity_title', 'Request activity'), $answer, '../users/my-requests.php');
}

$processQuery = $originalQuery ?: $query;
$useVerifiedSteps = !empty($guidance['steps'])
    && (aiHasProcedureIntent($processQuery) || aiHasRequirementIntent($processQuery));

if ($useVerifiedSteps) {
    // Process and requirement answers must remain deterministic. Returning the
    // stored answer plus its steps prevents a model from hiding verified data.
    $geminiResult = ['success' => false, 'error_code' => 'not_required'];
    $modelResult = ['success' => true, 'answer' => $guidance['answer']];
    $selectedEngine = 'verified-knowledge-steps';
} else {
    $geminiResult = aiTryGeminiResponse($guidance, $processQuery, $conversation, $detectedLanguage);
    $modelResult = $geminiResult;
    $selectedEngine = 'gemini';

    if (empty($geminiResult['success'])) {
        $modelResult = aiTryOllamaResponse($guidance, $processQuery, $conversation, $detectedLanguage);
        $selectedEngine = 'ollama';
    }
}

if (!empty($modelResult['success'])) {
    $answer = $modelResult['answer'];
    if (!empty($conversationalIntent['greeting_detected']) && !preg_match('/^(?:hello|hi|hey|good (?:morning|afternoon|evening)|magandang (?:umaga|hapon|gabi)|kumusta|kamusta)/i', ltrim($answer))) {
        $answer = $conversationalIntent['greeting_acknowledgement'] . "\n\n" . $answer;
    }
    if ($selectedEngine !== 'verified-knowledge-steps') {
        $guidance['steps'] = [];
    }
    $aiEngine = $selectedEngine;
} else {
    $geminiErrorCode = $geminiResult['error_code'] ?? 'unavailable';
    $ollamaErrorCode = $modelResult['error_code'] ?? 'unavailable';
    $answer = aiAllProvidersUnavailableMessage($geminiErrorCode, $ollamaErrorCode);
    logChatbotInquiry($conn, $userId, $role, $query, $answer, $mode, true);
    http_response_code($geminiErrorCode === 'rate_limited' ? 429 : 503);
    echo json_encode([
        'success' => false,
        'error' => $answer,
        'message' => $answer,
        'reply' => $answer,
        'status' => $geminiErrorCode === 'rate_limited' ? 'rate_limited' : ($ollamaErrorCode === 'model_unavailable' ? 'model_unavailable' : 'offline')
    ]);
    exit;
}

logChatbotInquiry($conn, $userId, $role, $query, $answer, $mode, true);

echo json_encode([
    'success' => true,
    'reply' => $answer,
    'answer' => $answer,
    'guidance' => $guidance,
    'search_results' => $searchResults,
    'analytics' => $analytics,
    'mode' => $mode,
    'detected_language' => $detectedLanguage,
    'intent' => $conversationalIntent['intent'],
    'ai_engine' => $aiEngine
]);
