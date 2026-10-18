<?php
/**
 * Test Suite: Fixed Facts for Parish Priest and Assistant Priest
 */

require_once __DIR__ . '/../includes/chatbot/ConversationalIntent.php';
require_once __DIR__ . '/../services/ParishKnowledgeBase.php';
require_once __DIR__ . '/../services/AiAssistantService.php';

echo "=== STARTING PRIEST FIXED FACTS TEST SUITE ===\n\n";

$passCount = 0;
$failCount = 0;

function assertCondition($testName, $condition, $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        echo "[PASS] $testName\n";
        $passCount++;
    } else {
        echo "[FAIL] $testName\n";
        if ($details) echo "       Details: $details\n";
        $failCount++;
    }
}

// 1. Test ConversationalIntent Layer
$intentCases = [
    'who is the parish priest' => [
        'intent' => TugonConversationalIntent::WHO_IS_PARISH_PRIEST,
        'exact' => 'The Parish Priest is REV. FR. ALBERTO G. CAHILIG, OMI.'
    ],
    'kura paroko' => [
        'intent' => TugonConversationalIntent::WHO_IS_PARISH_PRIEST,
        'exact' => 'The Parish Priest is REV. FR. ALBERTO G. CAHILIG, OMI.'
    ],
    'pari priest' => [
        'intent' => TugonConversationalIntent::WHO_IS_PARISH_PRIEST,
        'exact' => 'The Parish Priest is REV. FR. ALBERTO G. CAHILIG, OMI.'
    ],
    'head priest' => [
        'intent' => TugonConversationalIntent::WHO_IS_PARISH_PRIEST,
        'exact' => 'The Parish Priest is REV. FR. ALBERTO G. CAHILIG, OMI.'
    ],
    'who is the assistant priest' => [
        'intent' => TugonConversationalIntent::WHO_IS_ASSISTANT_PRIEST,
        'exact' => 'The Assistant Priest is REV. FR. ALVIN VICENTE C. BARRETTO, OMI.'
    ],
    'assistant parish priest' => [
        'intent' => TugonConversationalIntent::WHO_IS_ASSISTANT_PRIEST,
        'exact' => 'The Assistant Priest is REV. FR. ALVIN VICENTE C. BARRETTO, OMI.'
    ],
    'associate priest' => [
        'intent' => TugonConversationalIntent::WHO_IS_ASSISTANT_PRIEST,
        'exact' => 'The Assistant Priest is REV. FR. ALVIN VICENTE C. BARRETTO, OMI.'
    ],
];

foreach ($intentCases as $query => $expected) {
    $analysis = TugonConversationalIntent::analyze($query);
    assertCondition(
        "ConversationalIntent recognizes '{$query}' as {$expected['intent']}",
        $analysis['intent'] === $expected['intent'] && $analysis['response'] === $expected['exact'],
        "Got intent " . var_export($analysis['intent'], true) . " with response " . var_export($analysis['response'], true)
    );
}

// 2. Test ParishKnowledgeBase Normalization & Search
$dummyDb = mysqli_init();
$kb = new ParishKnowledgeBase($dummyDb);

$normParishPriest = $kb->normalizeQuery('who is the parish priest');
assertCondition(
    "Query normalization does NOT replace 'parish priest' with 'priest signatory'",
    strpos($normParishPriest['normalized'], 'priest signatory') === false,
    "Got normalized: " . $normParishPriest['normalized']
);

$searchResults = $kb->search('who is the parish priest', 'parishioner');
assertCondition(
    "Search for 'who is the parish priest' does not return admin change signatory as top result",
    empty($searchResults) || ($searchResults[0]['id'] ?? '') !== 'admin_change_priest_signatory',
    "Top result was: " . ($searchResults[0]['title'] ?? 'none')
);

// 3. Test AiAssistantService (Synthesized / Grounded Fallback)
// Use reflection to test synthesizeGroundedAnswer directly
$reflection = new ReflectionClass(AiAssistantService::class);
$synthMethod = $reflection->getMethod('synthesizeGroundedAnswer');
$synthMethod->setAccessible(true);

$service = $reflection->newInstanceWithoutConstructor();
$kbProp = $reflection->getProperty('kb');
$kbProp->setAccessible(true);
$kbProp->setValue($service, $kb);

// 3a. Who is parish priest -> exact answer
$answerParishPriest = $synthMethod->invoke($service, 'who is the parish priest', [], null, [], 'en', 'parishioner');
assertCondition(
    "synthesizeGroundedAnswer for 'who is the parish priest' gives exact required answer",
    $answerParishPriest === 'The Parish Priest is REV. FR. ALBERTO G. CAHILIG, OMI.',
    "Got: " . $answerParishPriest
);

$answerKuraParoko = $synthMethod->invoke($service, 'kura paroko', [], null, [], 'fil', 'parishioner');
assertCondition(
    "synthesizeGroundedAnswer for 'kura paroko' gives exact required answer",
    $answerKuraParoko === 'The Parish Priest is REV. FR. ALBERTO G. CAHILIG, OMI.',
    "Got: " . $answerKuraParoko
);

$answerPariPriest = $synthMethod->invoke($service, 'pari priest', [], null, [], 'fil', 'parishioner');
assertCondition(
    "synthesizeGroundedAnswer for 'pari priest' gives exact required answer",
    $answerPariPriest === 'The Parish Priest is REV. FR. ALBERTO G. CAHILIG, OMI.',
    "Got: " . $answerPariPriest
);

$answerHeadPriest = $synthMethod->invoke($service, 'head priest', [], null, [], 'en', 'parishioner');
assertCondition(
    "synthesizeGroundedAnswer for 'head priest' gives exact required answer",
    $answerHeadPriest === 'The Parish Priest is REV. FR. ALBERTO G. CAHILIG, OMI.',
    "Got: " . $answerHeadPriest
);

// 3b. Who is assistant priest -> exact answer
$answerAssistantPriest = $synthMethod->invoke($service, 'who is the assistant priest', [], null, [], 'en', 'parishioner');
assertCondition(
    "synthesizeGroundedAnswer for 'who is the assistant priest' gives exact required answer",
    $answerAssistantPriest === 'The Assistant Priest is REV. FR. ALVIN VICENTE C. BARRETTO, OMI.',
    "Got: " . $answerAssistantPriest
);

$answerAssistantParishPriest = $synthMethod->invoke($service, 'assistant parish priest', [], null, [], 'en', 'parishioner');
assertCondition(
    "synthesizeGroundedAnswer for 'assistant parish priest' gives exact required answer",
    $answerAssistantParishPriest === 'The Assistant Priest is REV. FR. ALVIN VICENTE C. BARRETTO, OMI.',
    "Got: " . $answerAssistantParishPriest
);

$answerAssociatePriest = $synthMethod->invoke($service, 'associate priest', [], null, [], 'en', 'parishioner');
assertCondition(
    "synthesizeGroundedAnswer for 'associate priest' gives exact required answer",
    $answerAssociatePriest === 'The Assistant Priest is REV. FR. ALVIN VICENTE C. BARRETTO, OMI.',
    "Got: " . $answerAssociatePriest
);

// 3c. Asking how to change signatory -> returns admin guide
$answerChangeSignatory = $synthMethod->invoke($service, 'how to change the priest signatory on certificates in admin', [], null, [], 'en', 'admin');
assertCondition(
    "synthesizeGroundedAnswer for changing signatory returns the admin guide",
    strpos($answerChangeSignatory, 'Certificate Signatory') !== false,
    "Got: " . $answerChangeSignatory
);

// 3d. Asking 'who is the parish priest' does NOT return the admin signatory guide
assertCondition(
    "Asking 'who is the parish priest' does NOT contain admin change signatory guide",
    strpos($answerParishPriest, 'Certificate Signatory') === false && strpos($answerParishPriest, 'Log in to your Admin') === false,
    "Got: " . $answerParishPriest
);

// 3e. Test Mass Schedule: Sunday 6:30 AM only, no Saturday Mass, no 8 AM, no 5 PM
$schedToolOutput = [
    'tool' => 'get_schedule',
    'data' => [
        'events' => [],
        'regular_mass_schedule' => [
            'Sunday' => '6:30 AM',
            'Wednesday (Novena)' => '5:30 PM',
            'Friday' => '5:30 PM'
        ]
    ]
];

$massAnswerEn = $synthMethod->invoke($service, 'what is the mass schedule', [], $schedToolOutput, [], 'en', 'parishioner');
assertCondition(
    "Mass schedule contains 'Sunday: 6:30 AM'",
    strpos($massAnswerEn, '• **Sunday**: 6:30 AM') !== false,
    "Got: " . $massAnswerEn
);
assertCondition(
    "Mass schedule does NOT contain Saturday Anticipated Mass",
    strpos($massAnswerEn, 'Saturday') === false && strpos($massAnswerEn, 'Anticipated') === false,
    "Got: " . $massAnswerEn
);
assertCondition(
    "Mass schedule does NOT contain 8:00 AM or 5:00 PM",
    strpos($massAnswerEn, '8:00 AM') === false && strpos($massAnswerEn, '5:00 PM') === false,
    "Got: " . $massAnswerEn
);
assertCondition(
    "Mass schedule contains Wednesday Novena and Friday 5:30 PM",
    strpos($massAnswerEn, '• **Wednesday (Novena)**: 5:30 PM') !== false && strpos($massAnswerEn, '• **Friday**: 5:30 PM') !== false,
    "Got: " . $massAnswerEn
);

echo "\n============================================\n";
echo "Results: {$passCount} passed, {$failCount} failed\n";
echo "============================================\n";

if ($failCount > 0) {
    exit(1);
}
