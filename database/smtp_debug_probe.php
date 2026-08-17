<?php
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: text/plain; charset=UTF-8');

$config = tugonMailConfig();
$host = trim((string) ($config['smtp_host'] ?? ''));
$port = (int) ($config['smtp_port'] ?? 0);
$encryption = strtolower(trim((string) ($config['smtp_encryption'] ?? '')));
$username = trim((string) ($config['smtp_username'] ?? ''));
$password = (string) ($config['smtp_password'] ?? '');

function probeRead($socket) {
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }
    return $response;
}

function probeCommand($socket, $label, $command, array $expected) {
    echo '[' . $label . "]\n";
    if ($command !== '') {
        fwrite($socket, $command . "\r\n");
    }

    $response = probeRead($socket);
    $meta = stream_get_meta_data($socket);
    echo ($response !== '' ? trim($response) : '(empty response)') . "\n";
    echo 'Timed out: ' . (!empty($meta['timed_out']) ? 'yes' : 'no') . "\n\n";

    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expected, true)) {
        throw new RuntimeException('Failed at ' . $label);
    }
}

echo "SMTP debug probe\n";
echo "Host: " . ($host ?: '(missing)') . "\n";
echo "Port: " . ($port ?: '(missing)') . "\n";
echo "Encryption: " . ($encryption ?: '(none)') . "\n";
echo "Username: " . ($username !== '' ? $username : '(missing)') . "\n";
echo "Password configured: " . ($password !== '' ? 'yes' : 'no') . "\n\n";

$scheme = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
$socket = @stream_socket_client($scheme . $host . ':' . $port, $errno, $errstr, (int) ($config['smtp_timeout'] ?? 10), STREAM_CLIENT_CONNECT, tugonSmtpContext($host));
if (!$socket) {
    exit('Connect failed: ' . $errno . ' ' . ($errstr ?: 'unknown error') . "\n");
}

try {
    stream_set_timeout($socket, (int) ($config['smtp_timeout'] ?? 10));
    probeCommand($socket, 'greeting', '', [220]);
    probeCommand($socket, 'ehlo-before-tls', 'EHLO localhost', [250]);

    if ($encryption === 'tls') {
        probeCommand($socket, 'starttls', 'STARTTLS', [220]);
        stream_context_set_option($socket, 'ssl', 'peer_name', $host);
        stream_context_set_option($socket, 'ssl', 'SNI_enabled', true);
        $tls = @stream_socket_enable_crypto($socket, true, tugonSmtpTlsMethod());
        echo "[enable-crypto]\n";
        echo ($tls ? "TLS enabled\n\n" : "TLS failed\n\n");
        if (!$tls) {
            throw new RuntimeException('Failed at enable-crypto');
        }
        probeCommand($socket, 'ehlo-after-tls', 'EHLO localhost', [250]);
    }

    probeCommand($socket, 'auth-login', 'AUTH LOGIN', [334]);
    probeCommand($socket, 'auth-username', base64_encode($username), [334]);
    probeCommand($socket, 'auth-password', base64_encode($password), [235]);
    probeCommand($socket, 'quit', 'QUIT', [221]);
} catch (Throwable $e) {
    echo 'Result: ' . $e->getMessage() . "\n";
    @fwrite($socket, "QUIT\r\n");
}

@fclose($socket);
