<?php
/**
 * Mail Configuration - Defines sender details and delivery settings for system notifications.
 *
 * Localhost-friendly options:
 * - smtp_host=127.0.0.1 and smtp_port=1025 works with Mailpit/MailHog.
 * - smtp_host=smtp.mailtrap.io works with Mailtrap sandbox credentials.
 * - Leave smtp_host empty to fall back to PHP mail().
 * - GMAIL_USER and GMAIL_APP_PASSWORD may be used as Gmail-specific aliases.
 */
$gmail_user = trim((string) (getenv('GMAIL_USER') ?: ''));
$gmail_app_password = (string) (getenv('GMAIL_APP_PASSWORD') ?: '');
$smtp_username = (string) (getenv('MAIL_USERNAME') ?: $gmail_user);
$smtp_password = (string) (getenv('MAIL_PASSWORD') ?: $gmail_app_password);
$configured_host = trim((string) (getenv('MAIL_HOST') ?: ''));
// A copied local-development template uses 127.0.0.1:1025; Gmail variables should replace it.
$using_gmail_aliases = $gmail_user !== '' && ($configured_host === '' || $configured_host === '127.0.0.1' || $configured_host === 'localhost');

return [
    'enabled' => getenv('MAIL_ENABLED') !== 'false',
    'mailer' => getenv('MAIL_MAILER') ?: 'smtp',
    'from_email' => getenv('MAIL_FROM_ADDRESS') ?: ($gmail_user ?: 'no-reply@tugon.local'),
    'from_name' => getenv('MAIL_FROM_NAME') ?: 'TUGON Parish System',
    'reply_to' => getenv('MAIL_REPLY_TO') ?: '',
    'smtp_host' => $using_gmail_aliases ? 'smtp.gmail.com' : ($configured_host ?: '127.0.0.1'),
    'smtp_port' => $using_gmail_aliases ? 587 : intval(getenv('MAIL_PORT') ?: 1025),
    'smtp_username' => $smtp_username,
    'smtp_password' => $smtp_password,
    'smtp_encryption' => getenv('MAIL_ENCRYPTION') ?: ($using_gmail_aliases ? 'tls' : ''),
    'smtp_timeout' => intval(getenv('MAIL_TIMEOUT') ?: 10),
    'http_endpoint' => trim((string) (getenv('MAIL_HTTP_ENDPOINT') ?: '')),
    'http_token' => (string) (getenv('MAIL_HTTP_TOKEN') ?: ''),
];
