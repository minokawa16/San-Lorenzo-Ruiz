<?php

require_once __DIR__ . "/../textbee.php";

function sendSMS($phone, $message)
{
    $phone = trim((string) $phone);
    $message = trim((string) $message);

    if ($phone === '' || $message === '') {
        return json_encode([
            "success" => false,
            "error" => "Phone number and message are required."
        ]);
    }

    if (!defined('TEXTBEE_API_KEY') || TEXTBEE_API_KEY === '' || !defined('TEXTBEE_DEVICE_ID') || TEXTBEE_DEVICE_ID === '') {
        return json_encode([
            "success" => false,
            "error" => "TextBee API key or device ID is missing."
        ]);
    }

    $url = TEXTBEE_BASE_URL .
        "/gateway/devices/" .
        TEXTBEE_DEVICE_ID .
        "/send-sms";

    $data = [
        "recipients" => [$phone],
        "message" => $message
    ];

    $headers = [
        "Content-Type: application/json",
        "x-api-key: " . TEXTBEE_API_KEY
    ];

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_POST, true);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);

    $response = curl_exec($ch);

    if(curl_errno($ch))
    {
        $error = curl_error($ch);
        curl_close($ch);
        return json_encode([
            "success" => false,
            "error" => $error
        ]);
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string) $response, true);

    if ($status < 200 || $status >= 300) {
        $errorMessage = is_array($decoded) && !empty($decoded['message'])
            ? $decoded['message']
            : (is_array($decoded) && !empty($decoded['error']) ? $decoded['error'] : "TextBee HTTP status " . $status);

        return json_encode([
            "success" => false,
            "error" => $errorMessage,
            "http_status" => $status,
            "response" => $response
        ]);
    }

    if (is_array($decoded)) {
        $decoded["success"] = $decoded["success"] ?? true;
        $decoded["http_status"] = $status;
        return json_encode($decoded);
    }

    return json_encode([
        "success" => true,
        "http_status" => $status,
        "response" => $response
    ]);

}
