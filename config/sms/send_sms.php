<?php
/**
 * TextBee SMS Dispatcher
 * https://textbee.dev - Android SMS Gateway
 */

require_once __DIR__ . "/../textbee.php";

/**
 * Normalizes phone number to international Philippine format (+639XXXXXXXXX).
 */
function textbeeNormalizePhone(string $phone): string {
    $digits = preg_replace('/\D/', '', $phone);
    if (preg_match('/^09\d{9}$/', $digits)) {
        return '+63' . substr($digits, 1);
    }
    if (preg_match('/^639\d{9}$/', $digits)) {
        return '+' . $digits;
    }
    if (preg_match('/^9\d{9}$/', $digits)) {
        return '+63' . $digits;
    }
    $trimmed = trim($phone);
    if (str_starts_with($trimmed, '+')) {
        return '+' . $digits;
    }
    return $trimmed;
}

/**
 * Sends an SMS message via the TextBee Android gateway.
 *
 * @param string $phone Recipient mobile number (will be normalized to +639XXXXXXXXX)
 * @param string $message SMS content string
 * @param array $options Additional options: device_id override, timeout, simSubscriptionId
 * @return string JSON-encoded result with success status, batch ID, HTTP status, and full response
 */
function sendSMS($phone, $message, array $options = []): string
{
    $rawPhone = trim((string) $phone);
    $trimmedMessage = trim((string) $message);

    if ($rawPhone === '' || $trimmedMessage === '') {
        return json_encode([
            "success" => false,
            "error" => "Phone number and message are required.",
            "http_status" => 400
        ]);
    }

    $normalizedPhone = textbeeNormalizePhone($rawPhone);

    // Validate international format (+639XXXXXXXXX)
    if (!preg_match('/^\+639\d{9}$/', $normalizedPhone)) {
        return json_encode([
            "success" => false,
            "error" => "Invalid Philippine mobile number format: '{$rawPhone}'. Expected 09XXXXXXXXX or +639XXXXXXXXX.",
            "http_status" => 422,
            "phone" => $rawPhone,
            "normalized_phone" => $normalizedPhone
        ]);
    }

    if (!isTextBeeConfigured()) {
        $cfg = getTextBeeConfig();
        $missing = [];
        if (!$cfg['has_api_key']) $missing[] = 'TEXTBEE_API_KEY';
        if (!$cfg['has_device_id']) $missing[] = 'TEXTBEE_DEVICE_ID';
        $errorMsg = 'TextBee SMS gateway is not configured. Missing: ' . implode(', ', $missing) . ' in environment variables (.env).';
        error_log("[TextBee SMS] " . $errorMsg);
        return json_encode([
            "success" => false,
            "error" => $errorMsg,
            "http_status" => 500,
            "configured" => false
        ]);
    }

    $deviceId = !empty($options['device_id']) ? trim((string)$options['device_id']) : TEXTBEE_DEVICE_ID;
    $url = TEXTBEE_BASE_URL . "/gateway/devices/" . $deviceId . "/send-sms";

    $payload = [
        "recipients" => [$normalizedPhone],
        "message" => $trimmedMessage
    ];

    if (!empty($options['simSubscriptionId'])) {
        $payload['simSubscriptionId'] = (int)$options['simSubscriptionId'];
    }

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $headers = [
        "Content-Type: application/json; charset=utf-8",
        "Accept: application/json",
        "x-api-key: " . TEXTBEE_API_KEY
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $curlErrNo = curl_errno($ch);
    $curlError = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrNo !== 0) {
        $errorMsg = "TextBee connection failed (cURL error {$curlErrNo}): {$curlError}";
        error_log("[TextBee SMS cURL Error] {$errorMsg} | Target: {$normalizedPhone}");
        return json_encode([
            "success" => false,
            "error" => $errorMsg,
            "http_status" => 0,
            "phone" => $normalizedPhone,
            "url" => $url
        ]);
    }

    $decoded = json_decode((string) $response, true);
    $batchId = $decoded['data']['smsBatchId'] ?? ($decoded['smsBatchId'] ?? null);

    // TextBee returns HTTP 200 or 201 on successful queueing
    $isSuccess = ($status >= 200 && $status < 300) && (
        (($decoded['data']['success'] ?? false) === true) ||
        (($decoded['success'] ?? false) === true) ||
        (!empty($batchId))
    );

    if (!$isSuccess) {
        $apiError = $decoded['data']['message']
            ?? ($decoded['message']
            ?? ($decoded['error']
            ?? "TextBee API returned HTTP {$status}"));

        $logMsg = "[TextBee SMS Failure] HTTP {$status}: {$apiError} | Body: {$response} | Recipient: {$normalizedPhone}";
        error_log($logMsg);

        return json_encode([
            "success" => false,
            "error" => $apiError,
            "http_status" => $status,
            "phone" => $normalizedPhone,
            "raw_response" => $response,
            "batch_id" => $batchId
        ]);
    }

    // Success: queued on device gateway
    $successMessage = $decoded['data']['message'] ?? ($decoded['message'] ?? 'SMS added to queue for processing');
    return json_encode([
        "success" => true,
        "message" => $successMessage,
        "batch_id" => $batchId,
        "http_status" => $status,
        "phone" => $normalizedPhone,
        "data" => $decoded['data'] ?? $decoded,
        "raw_response" => $response
    ]);
}
