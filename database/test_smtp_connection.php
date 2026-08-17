<?php
/**
 * SMTP connectivity test.
 *
 * Run in a browser: http://localhost/ParishSystem/database/test_smtp_connection.php
 * Or from PowerShell: C:\xampp\php\php.exe database\test_smtp_connection.php
 *
 * This reads the project's .env settings and tests connection, TLS, and login.
 * It deliberately does not send an email.
 */

require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: text/plain; charset=UTF-8');

$config = tugonMailConfig();
$host = trim((string) ($config['smtp_host'] ?? ''));
$port = (int) ($config['smtp_port'] ?? 0);
$encryption = strtolower(trim((string) ($config['smtp_encryption'] ?? '')));
$username = trim((string) ($config['smtp_username'] ?? ''));

echo "SMTP connection test\n";
echo "Host: " . ($host ?: '(missing)') . "\n";
echo "Port: " . ($port ?: '(missing)') . "\n";
echo "Encryption: " . ($encryption ?: '(none)') . "\n";
echo "Username configured: " . ($username !== '' ? 'yes' : 'no') . "\n\n";

if ($username !== '' && (string) ($config['smtp_password'] ?? '') === '') {
    http_response_code(500);
    exit("FAIL: MAIL_PASSWORD is empty. Put your 16-character Gmail App Password in .env.\n");
}

if ($host === '' || $port < 1) {
    http_response_code(500);
    exit("FAIL: MAIL_HOST or MAIL_PORT is missing.\n");
}

if ($encryption !== '' && $encryption !== 'tls' && $encryption !== 'ssl') {
    http_response_code(500);
    exit("FAIL: MAIL_ENCRYPTION must be tls, ssl, or empty.\n");
}

$scheme = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
$socket = @stream_socket_client($scheme . $host . ':' . $port, $errno, $errstr, (int) ($config['smtp_timeout'] ?? 10), STREAM_CLIENT_CONNECT, tugonSmtpContext($host));
if (!$socket) {
    http_response_code(502);
    exit('FAIL: Cannot connect to ' . $host . ':' . $port . ' - ' . ($errstr ?: 'unknown connection error') . "\n");
}

try {
    stream_set_timeout($socket, (int) ($config['smtp_timeout'] ?? 10));
    tugonSmtpCommand($socket, '', [220]);
    tugonSmtpCommand($socket, 'EHLO localhost', [250]);

    if ($encryption === 'tls') {
        tugonSmtpCommand($socket, 'STARTTLS', [220]);
        stream_context_set_option($socket, 'ssl', 'peer_name', $host);
        stream_context_set_option($socket, 'ssl', 'SNI_enabled', true);
        if (!stream_socket_enable_crypto($socket, true, tugonSmtpTlsMethod())) {
            throw new RuntimeException('Unable to start TLS encryption.');
        }
        tugonSmtpCommand($socket, 'EHLO localhost', [250]);
    }

    if ($username !== '') {
        tugonSmtpCommand($socket, 'AUTH LOGIN', [334]);
        tugonSmtpCommand($socket, base64_encode($username), [334]);
        tugonSmtpCommand($socket, base64_encode((string) ($config['smtp_password'] ?? '')), [235]);
        echo "PASS: Connected, encrypted, and authenticated successfully.\n";
    } else {
        echo "PASS: Connected successfully. Authentication was skipped because MAIL_USERNAME is empty.\n";
    }

    tugonSmtpCommand($socket, 'QUIT', [221]);
    fclose($socket);
} catch (Throwable $e) {
    @fwrite($socket, "QUIT\r\n");
    @fclose($socket);
    http_response_code(502);
    exit('FAIL: ' . tugonFriendlySmtpError($e->getMessage()) . "\n");
}
