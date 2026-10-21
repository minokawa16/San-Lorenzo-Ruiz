<?php
declare(strict_types=1);

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../services/AiAssistantService.php';
require_once __DIR__ . '/../services/ParishKnowledgeBase.php';

echo "=== RUNNING TUGON AI SACRAMENT CLARIFICATION & ISOLATION TESTS ===\n\n";

$ai = new AiAssistantService($conn);
$kb = new ParishKnowledgeBase($conn);

$failed = 0;
$passed = 0;

function assertTest(string $name, bool $condition, string $details = '') {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] {$name}\n";
        $passed++;
    } else {
        echo " [FAIL] {$name}: {$details}\n";
        $failed++;
    }
}

// 1. Topic Detection Unit Tests
echo "--- 1. detectTopic() Tests ---\n";

$cases = [
    'baptism' => ['clarify', 'baptism'],
    'kumpil' => ['clarify', 'confirmation'],
    'kasal' => ['clarify', 'marriage'],
    'wedding' => ['clarify', 'marriage'],
    'bunyag' => ['clarify', 'baptism'],
    'binyag' => ['clarify', 'baptism'],
    'confirmation' => ['clarify', 'confirmation'],
    'communion' => ['clarify', 'communion'],
    'funeral' => ['clarify', 'funeral'],
    'baptism please' => ['clarify', 'baptism'],
    'baptism requirements' => ['sacraments', 'baptism'],
    'kasal requirements' => ['sacraments', 'marriage'],
    'kumpil requirements' => ['sacraments', 'confirmation'],
    'pabunyag' => ['sacraments', 'baptism'],
    'magpabinyag' => ['sacraments', 'baptism'],
    'papakasal' => ['sacraments', 'marriage'],
    'pakumpil' => ['sacraments', 'confirmation'],
    'baptismal certificate' => ['certificates', 'baptism'],
    'baptism cert' => ['certificates', 'baptism'],
    'sertipiko ng binyag' => ['certificates', 'baptism'],
    'sertipiko sa bunyag' => ['certificates', 'baptism'],
    'sertipiko ng kumpil' => ['certificates', 'confirmation'],
    'marriage certificate' => ['certificates', 'marriage'],
    'house blessing' => ['blessings', null],
    'mass schedule' => ['schedule', null],
];

foreach ($cases as $input => $expected) {
    $res = $ai->detectTopic($input);
    $topicMatch = $res['topic'] === $expected[0];
    $typeMatch = $res['type'] === $expected[1];
    assertTest(
        "detectTopic('{$input}') -> {$expected[0]}, " . ($expected[1] ?? 'null'),
        $topicMatch && $typeMatch,
        "Got topic={$res['topic']}, type=" . ($res['type'] ?? 'null')
    );
}

// 2. Language Detection for Sacrament Terms
echo "\n--- 2. detectLanguage() Tests ---\n";
$langCases = [
    'baptism' => 'en',
    'kumpil' => 'fil',
    'bunyag' => 'ceb',
    'binyag' => 'fil',
    'kasal' => 'fil',
];

foreach ($langCases as $input => $expectedLang) {
    $norm = $kb->normalizeQuery($input);
    assertTest(
        "detectLanguage('{$input}') -> {$expectedLang}",
        $norm['language'] === $expectedLang,
        "Got language={$norm['language']}"
    );
}

// 3. respond() Bare Sacrament Clarification Tests
echo "\n--- 3. Bare Sacrament Clarification (No Guessing) ---\n";

// Test 3a: Bare "baptism"
$res = $ai->respond(1, ['parishioner' => true], 'baptism', 'ask', []);
assertTest(
    "Bare 'baptism' triggers clarify",
    $res['detected_intent'] === 'CLARIFY_SACRAMENT',
    "Got intent=" . ($res['detected_intent'] ?? '')
);
assertTest(
    "Bare 'baptism' returns English clarify question with service and certificate",
    str_contains($res['answer'], 'Baptism service') && str_contains($res['answer'], 'Baptism Certificate'),
    "Got answer: " . $res['answer']
);
assertTest(
    "Bare 'baptism' returns 2 prompt buttons",
    ($res['suggested_prompts'] ?? []) === ['Baptism service requirements', 'Baptism certificate requirements'],
    "Got prompts: " . json_encode($res['suggested_prompts'] ?? [])
);

// Test 3b: Bare "kumpil" (Filipino)
$res = $ai->respond(1, ['parishioner' => true], 'kumpil', 'ask', []);
assertTest(
    "Bare 'kumpil' triggers clarify",
    $res['detected_intent'] === 'CLARIFY_SACRAMENT',
    "Got intent=" . ($res['detected_intent'] ?? '')
);
assertTest(
    "Bare 'kumpil' returns Filipino clarify question",
    str_contains($res['answer'], 'Ang nais po ba ninyo ay ang') && str_contains($res['answer'], 'Confirmation service'),
    "Got answer: " . $res['answer']
);
assertTest(
    "Bare 'kumpil' returns Confirmation buttons",
    ($res['suggested_prompts'] ?? []) === ['Confirmation service requirements', 'Confirmation certificate requirements'],
    "Got prompts: " . json_encode($res['suggested_prompts'] ?? [])
);

// Test 3c: Bare "bunyag" (Cebuano)
$res = $ai->respond(1, ['parishioner' => true], 'bunyag', 'ask', []);
assertTest(
    "Bare 'bunyag' triggers clarify in Cebuano",
    str_contains($res['answer'], 'Ang imong gipasabot ba ang') && str_contains($res['answer'], 'Baptism service'),
    "Got answer: " . $res['answer']
);

// 4. Topic-Isolated Retrieval Tests
echo "\n--- 4. Topic-Isolated Retrieval (Never Mix) ---\n";

// Service search
$serviceSources = $kb->search('baptism service requirements', 'parishioner', 4, 'sacraments', 'baptism');
assertTest(
    "Service search returns sources",
    !empty($serviceSources),
    "No sources returned"
);
$hasService = false;
$hasCert = false;
foreach ($serviceSources as $s) {
    if (($s['category'] ?? '') === 'sacraments') $hasService = true;
    if (($s['category'] ?? '') === 'certificates') $hasCert = true;
}
assertTest("Service search has sacramental service item", $hasService);
assertTest("Service search NEVER has certificate item", !$hasCert, "Found certificate in sacramental service search!");

// Certificate search
$certSources = $kb->search('baptism certificate requirements', 'parishioner', 4, 'certificates', 'baptism');
assertTest(
    "Certificate search returns sources",
    !empty($certSources),
    "No sources returned"
);
$hasService = false;
$hasCert = false;
foreach ($certSources as $s) {
    if (($s['category'] ?? '') === 'sacraments') $hasService = true;
    if (($s['category'] ?? '') === 'certificates') $hasCert = true;
}
assertTest("Certificate search has certificate item", $hasCert);
assertTest("Certificate search NEVER has sacramental service item", !$hasService, "Found sacrament in certificate search!");

// 5. Service vs Certificate Responses
echo "\n--- 5. Service vs Certificate Responses ---\n";

// "Baptism service requirements"
$resService = $ai->respond(1, ['parishioner' => true], 'Baptism service requirements', 'ask', []);
assertTest(
    "'Baptism service requirements' answers with Baptism service (PSA birth cert / godparents / seminar)",
    (str_contains($resService['answer'], 'Baptism') || str_contains($resService['answer'], 'binyag'))
    && (str_contains($resService['answer'], 'seminar') || str_contains($resService['answer'], 'godparents') || str_contains($resService['answer'], 'Contact parish office')),
    "Got answer: " . $resService['answer']
);
assertTest(
    "'Baptism service requirements' does NOT mention ₱100 certificate fee",
    !str_contains($resService['answer'], '₱100'),
    "Got answer: " . $resService['answer']
);

// "Baptism certificate requirements"
$resCert = $ai->respond(1, ['parishioner' => true], 'Baptism certificate requirements', 'ask', []);
assertTest(
    "'Baptism certificate requirements' answers with Baptismal Certificate requirements",
    str_contains($resCert['answer'], 'Baptismal Certificate') || str_contains($resCert['answer'], 'Valid Government-issued ID'),
    "Got answer: " . $resCert['answer']
);
assertTest(
    "'Baptism certificate requirements' includes ₱100 fee",
    str_contains($resCert['answer'], '₱100') || str_contains($resCert['answer'], '100'),
    "Got answer: " . $resCert['answer']
);

// 6. Multi-Turn Follow-Ups
echo "\n--- 6. Multi-Turn Follow-Ups ('how much?') ---\n";

// Multi-turn after service
$convoService = [
    ['role' => 'user', 'content' => 'Baptism service requirements'],
    ['role' => 'assistant', 'content' => $resService['answer']]
];
$resServiceHowMuch = $ai->respond(1, ['parishioner' => true], 'how much?', 'ask', $convoService);
assertTest(
    "Follow-up 'how much?' after service does NOT trigger clarify",
    $resServiceHowMuch['detected_intent'] !== 'CLARIFY_SACRAMENT',
    "Triggered clarify: " . $resServiceHowMuch['answer']
);
assertTest(
    "Follow-up 'how much?' after service does NOT return ₱100 certificate fee",
    !str_contains($resServiceHowMuch['answer'], '₱100'),
    "Got answer: " . $resServiceHowMuch['answer']
);

// Multi-turn after certificate
$convoCert = [
    ['role' => 'user', 'content' => 'Baptism certificate requirements'],
    ['role' => 'assistant', 'content' => $resCert['answer']]
];
$resCertHowMuch = $ai->respond(1, ['parishioner' => true], 'how much?', 'ask', $convoCert);
assertTest(
    "Follow-up 'how much?' after certificate does NOT trigger clarify",
    $resCertHowMuch['detected_intent'] !== 'CLARIFY_SACRAMENT',
    "Triggered clarify: " . $resCertHowMuch['answer']
);
assertTest(
    "Follow-up 'how much?' after certificate returns ₱100 certificate fee",
    str_contains($resCertHowMuch['answer'], '₱100') || str_contains($resCertHowMuch['answer'], '100'),
    "Got answer: " . $resCertHowMuch['answer']
);

// Natural language choice answering clarification
echo "\n--- 7. Answering Clarification with 'service' or 'certificate' ---\n";
$convoClarify = [
    ['role' => 'user', 'content' => 'baptism'],
    ['role' => 'assistant', 'content' => 'Do you mean the Baptism service (booking the ceremony) or the Baptism Certificate (a copy of the record)?']
];
$resUserChoseService = $ai->respond(1, ['parishioner' => true], 'the service please', 'ask', $convoClarify);
assertTest(
    "User replies 'the service please' -> receives Baptism service info",
    str_contains($resUserChoseService['answer'], 'Baptism') && !str_contains($resUserChoseService['answer'], '₱100'),
    "Got answer: " . $resUserChoseService['answer']
);

$resUserChoseCert = $ai->respond(1, ['parishioner' => true], 'certificate', 'ask', $convoClarify);
assertTest(
    "User replies 'certificate' -> receives Baptismal Certificate info",
    str_contains($resUserChoseCert['answer'], 'Baptismal Certificate') || str_contains($resUserChoseCert['answer'], '100'),
    "Got answer: " . $resUserChoseCert['answer']
);

echo "\n=== SUMMARY: Passed: {$passed}, Failed: {$failed} ===\n";
if ($failed > 0) {
    exit(1);
}
echo "ALL TESTS PASSED PERFECTLY!\n";
