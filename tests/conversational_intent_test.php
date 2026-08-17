<?php
require_once __DIR__ . '/../includes/chatbot/ConversationalIntent.php';

$fixedMorning = new DateTimeImmutable('2026-08-11 09:00:00', new DateTimeZone('Asia/Manila'));
$cases = [
    'Hi' => TugonConversationalIntent::GREETING,
    'Hello' => TugonConversationalIntent::GREETING,
    'Hey' => TugonConversationalIntent::GREETING,
    'Good morning' => TugonConversationalIntent::GREETING,
    'Goodmorning' => TugonConversationalIntent::GREETING,
    'Good afternoon' => TugonConversationalIntent::GREETING,
    'Goodafternoon' => TugonConversationalIntent::GREETING,
    'Good evening' => TugonConversationalIntent::GREETING,
    'Goodevening' => TugonConversationalIntent::GREETING,
    'HELLOOO!!!' => TugonConversationalIntent::GREETING,
    'Kumusta' => TugonConversationalIntent::GREETING,
    'Kumusta po' => TugonConversationalIntent::GREETING,
    'Magandang umaga' => TugonConversationalIntent::GREETING,
    'Magandang hapon' => TugonConversationalIntent::GREETING,
    'Magandang gabi' => TugonConversationalIntent::GREETING,
    'How are you?' => TugonConversationalIntent::HOW_ARE_YOU,
    'Kumusta ka TUGON?' => TugonConversationalIntent::HOW_ARE_YOU,
    'Thanks' => TugonConversationalIntent::THANKS,
    'Thank you po' => TugonConversationalIntent::THANKS,
    'Salamat po' => TugonConversationalIntent::THANKS,
    'Bye' => TugonConversationalIntent::FAREWELL,
    'Goodbye po' => TugonConversationalIntent::FAREWELL,
    'See you' => TugonConversationalIntent::FAREWELL,
    'Good night' => TugonConversationalIntent::FAREWELL,
    'What can you do?' => TugonConversationalIntent::ABOUT_ASSISTANT,
];

$failures = [];
foreach ($cases as $message => $expectedIntent) {
    $actual = TugonConversationalIntent::analyze($message, $fixedMorning);
    if ($actual['intent'] !== $expectedIntent || trim((string) $actual['response']) === '') {
        $failures[] = "$message: expected $expectedIntent, got " . var_export($actual['intent'], true);
    }
}

$ragCases = [
    'Hello, what are the baptism requirements?',
    'Good morning po, how can I request a certificate?',
    'Hi TUGON, how do I make a reservation?',
    'Kumusta po, saan ako magrerequest ng baptismal certificate?',
];
foreach ($ragCases as $message) {
    $actual = TugonConversationalIntent::analyze($message, $fixedMorning);
    if ($actual['intent'] !== null || !$actual['greeting_detected']) {
        $failures[] = "$message: should continue to RAG with a detected greeting";
    }
}

$timeAware = TugonConversationalIntent::analyze('Hello', $fixedMorning);
if (strpos($timeAware['response'], 'morning') === false && strpos($timeAware['response'], 'Hello') === false && strpos($timeAware['response'], 'Good to see you') === false) {
    $failures[] = 'Generic morning greeting did not produce an appropriate response.';
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Conversational intent tests passed: ' . (count($cases) + count($ragCases) + 1) . PHP_EOL;
