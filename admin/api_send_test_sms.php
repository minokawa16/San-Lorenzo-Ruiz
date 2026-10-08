<?php
/**
 * Admin API Endpoint: Send Test SMS via TextBee Gateway
 */

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../config/textbee.php';
require_once __DIR__ . '/../config/sms/send_sms.php';

header('Content-Type: application/json; charset=utf-8');

if (!isAdmin() && !hasPermission('requests.manage')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Administrator access required.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

$csrf_err = csrfFailureMessage();
if ($csrf_err) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => $csrf_err]);
    exit;
}

$rawPhone = trim((string)($_POST['phone'] ?? ''));
$customMessage = trim((string)($_POST['message'] ?? ''));

if ($rawPhone === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Recipient mobile phone number is required.']);
    exit;
}

$normalizedPhone = textbeeNormalizePhone($rawPhone);
if (!isValidPhilippineMobile($normalizedPhone)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => "Invalid Philippine phone number: '{$rawPhone}'. Expected 09XXXXXXXXX or +639XXXXXXXXX.",
        'phone' => $rawPhone,
        'normalized_phone' => $normalizedPhone
    ]);
    exit;
}

if (!isTextBeeConfigured()) {
    $cfg = getTextBeeConfig();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'TextBee SMS gateway is not configured in .env. Please set TEXTBEE_API_KEY and TEXTBEE_DEVICE_ID.',
        'config' => $cfg
    ]);
    exit;
}

$message = ($customMessage !== '')
    ? $customMessage
    : "TUGON Parish System: Live SMS test confirmed at " . date('M d, Y h:i A') . ". TextBee gateway is connected!";

// Send SMS via TextBee
$sendRaw = sendSMS($normalizedPhone, $message);
$sendResult = json_decode((string)$sendRaw, true);

// Fetch device status for diagnostic visibility
$deviceInfo = null;
if (function_exists('curl_init') && defined('TEXTBEE_API_KEY') && TEXTBEE_API_KEY !== '') {
    $ch = curl_init('https://api.textbee.dev/api/v1/gateway/devices');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['x-api-key: ' . TEXTBEE_API_KEY]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    $devRes = curl_exec($ch);
    $devCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($devCode === 200 && $devRes) {
        $devDecoded = json_decode((string)$devRes, true);
        if (!empty($devDecoded['data']) && is_array($devDecoded['data'])) {
            foreach ($devDecoded['data'] as $dev) {
                if (($dev['_id'] ?? '') === TEXTBEE_DEVICE_ID) {
                    $sim = $dev['simInfo']['sims'][0] ?? [];
                    $deviceInfo = [
                        'device_id' => $dev['_id'] ?? '',
                        'model' => trim(($dev['brand'] ?? '') . ' ' . ($dev['model'] ?? '')),
                        'carrier' => $sim['carrierName'] ?? ($sim['displayName'] ?? 'Unknown'),
                        'battery' => ($dev['batteryInfo']['percentage'] ?? 0) . '%',
                        'last_heartbeat' => $dev['lastHeartbeat'] ?? null,
                        'battery_optimization_ignored' => $dev['powerInfo']['isIgnoringBatteryOptimizations'] ?? false,
                        'sticky_notification_enabled' => $dev['appStateInfo']['stickyNotificationEnabled'] ?? false
                    ];
                    break;
                }
            }
        }
    }
}

// Log into database
$isSuccess = !empty($sendResult['success']);
$status = $isSuccess ? 'sent' : 'failed';
$batchId = $sendResult['batch_id'] ?? null;
$errorMsg = $isSuccess ? ($batchId ? "Batch: {$batchId}" : 'Queued') : ($sendResult['error'] ?? 'TextBee request failed');
$sentAt = $isSuccess ? date('Y-m-d H:i:s') : null;

$stmt = $conn->prepare("INSERT INTO sms_notification_logs (user_id, phone_number, message, notification_type, delivery_status, error_message, sent_at) VALUES (?, ?, ?, 'admin_test', ?, ?, ?)");
if ($stmt) {
    $adminId = (int)$_SESSION['user_id'];
    $stmt->bind_param('isssss', $adminId, $normalizedPhone, $message, $status, $errorMsg, $sentAt);
    $stmt->execute();
    $stmt->close();
}

if (!$isSuccess) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => $sendResult['error'] ?? 'TextBee rejected the SMS request.',
        'http_status' => $sendResult['http_status'] ?? 0,
        'phone' => $normalizedPhone,
        'device_info' => $deviceInfo,
        'raw_response' => $sendResult['raw_response'] ?? null
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Test SMS dispatched to TextBee Gateway successfully.',
    'phone' => $normalizedPhone,
    'sms_batch_id' => $batchId,
    'http_status' => $sendResult['http_status'] ?? 201,
    'device_info' => $deviceInfo,
    'details' => $sendResult['data'] ?? null
]);
