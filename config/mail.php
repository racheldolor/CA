<?php
/**
 * SMTP configuration. Set these values in a server-only config or environment variables.
 */

$localSecrets = __DIR__ . '/secrets.php';
if (is_file($localSecrets)) {
    require_once $localSecrets;
}

function mailConfig(): array
{
    return [
        'host' => getenv('OCA_SMTP_HOST') ?: (defined('OCA_SMTP_HOST') ? OCA_SMTP_HOST : 'smtp.gmail.com'),
        'port' => (int)(getenv('OCA_SMTP_PORT') ?: (defined('OCA_SMTP_PORT') ? OCA_SMTP_PORT : 587)),
        'username' => getenv('OCA_SMTP_USERNAME') ?: (defined('OCA_SMTP_USERNAME') ? OCA_SMTP_USERNAME : ''),
        'password' => getenv('OCA_SMTP_PASSWORD') ?: (defined('OCA_SMTP_PASSWORD') ? OCA_SMTP_PASSWORD : ''),
        'encryption' => getenv('OCA_SMTP_ENCRYPTION') ?: (defined('OCA_SMTP_ENCRYPTION') ? OCA_SMTP_ENCRYPTION : 'tls'),
        'from_email' => getenv('OCA_MAIL_FROM') ?: (defined('OCA_MAIL_FROM') ? OCA_MAIL_FROM : 'oca.notification@gmail.com'),
        'from_name' => getenv('OCA_MAIL_FROM_NAME') ?: (defined('OCA_MAIL_FROM_NAME') ? OCA_MAIL_FROM_NAME : 'OCA Notification System')
    ];
}

function registrationEmailIsAllowed(string $email): bool
{
    $testRecipient = strtolower(trim(getenv('OCA_TEST_RECIPIENT') ?: (defined('OCA_TEST_RECIPIENT') ? OCA_TEST_RECIPIENT : '')));
    return (bool)preg_match('/@g\.batstate-u\.edu\.ph$/i', $email)
        || ($testRecipient !== '' && strtolower($email) === $testRecipient);
}
