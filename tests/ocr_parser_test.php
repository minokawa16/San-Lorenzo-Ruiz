<?php

require_once dirname(__DIR__) . '/ocr/IDOCRProcessor.php';

$processor = new IDOCRProcessor(sys_get_temp_dir() . '/tugon_ocr_test');
$parse = new ReflectionMethod(IDOCRProcessor::class, 'parseFields');
$parse->setAccessible(true);
$merge = new ReflectionMethod(IDOCRProcessor::class, 'mergeFieldCandidates');
$merge->setAccessible(true);

$sample = <<<'TEXT'
REPUBLIC OF THE PHILIPPINES
APELYIDO/SURNAME
DELA CRUZ
PANGALAN/GIVEN NAMES
JUAN CARLOS
GITNANG APELYIDO/MIDDLE NAME
SANTOS
DATE OF BIRTH 14 MAY 1998
ADDRESS
PUROK 3, ALEOSAN, COTABATO
PCN 1234-5678-9012-3456
TEXT;

$fields = $parse->invoke($processor, $sample);
$expected = [
    'last_name' => 'DELA CRUZ',
    'first_name' => 'JUAN CARLOS',
    'middle_name' => 'SANTOS',
    'date_of_birth' => '1998-05-14',
];

foreach ($expected as $field => $value) {
    if (($fields[$field] ?? null) !== $value) {
        fwrite(STDERR, "FAIL {$field}: expected {$value}, got " . var_export($fields[$field] ?? null, true) . PHP_EOL);
        exit(1);
    }
}

$passes = [$fields, $fields, array_merge($fields, ['first_name' => 'JUAN CARL0S'])];
$consensus = $merge->invoke($processor, $passes);
if (($consensus['first_name'] ?? null) !== 'JUAN CARLOS' || ($consensus['field_confidence']['first_name'] ?? 0) < 0.8) {
    fwrite(STDERR, 'FAIL consensus: ' . json_encode($consensus) . PHP_EOL);
    exit(1);
}

echo "OCR parser and consensus tests passed." . PHP_EOL;
