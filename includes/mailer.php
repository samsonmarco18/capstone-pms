<?php
declare(strict_types=1);

require_once __DIR__ . '/app-settings.php';

function getMailSetting(string $name, ?string $default = null): string
{
    global $localOverrides;

    if (is_array($localOverrides ?? null) && array_key_exists($name, $localOverrides)) {
        $value = $localOverrides[$name];
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) $value);
    }

    $envValue = getenv($name);
    if ($envValue !== false && $envValue !== '') {
        return trim((string) $envValue);
    }

    if (str_starts_with($name, 'MAIL_')) {
        $appSettingKey = 'mail_' . strtolower(substr($name, 5));
        $appValue = getAppSetting($appSettingKey, null);
        if ($appValue !== null && $appValue !== '') {
            return trim((string) $appValue);
        }
    }

    if (function_exists('getEnvOrDefault')) {
        $value = getEnvOrDefault($name, $default ?? '');
        if ($value !== false && $value !== null) {
            $value = (string) $value;
            if ($value !== '' || $default === null) {
                return trim($value);
            }
        }
    }

    if (defined($name)) {
        $value = constant($name);
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) $value);
    }

    return trim((string) ($default ?? ''));
}

function getMailConfig(): array
{
    $companyName = getAppSetting('company_name', 'ERNI PMS') ?? 'ERNI PMS';
    $notificationEmail = getAppSetting('notification_email', '') ?? '';
    $envEnabled = getMailSetting('MAIL_ENABLED', '');
    $envHost = getMailSetting('MAIL_HOST', '');
    $envPort = getMailSetting('MAIL_PORT', '');
    $envEncryption = getMailSetting('MAIL_ENCRYPTION', '');
    $envUsername = getMailSetting('MAIL_USERNAME', '');
    $envPassword = getMailSetting('MAIL_PASSWORD', '');
    $envFromEmail = getMailSetting('MAIL_FROM_EMAIL', '');
    $envFromName = getMailSetting('MAIL_FROM_NAME', '');

    $host = $envHost !== '' ? $envHost : trim((string) getAppSetting('mail_host', 'smtp.gmail.com'));
    $port = (int) ($envPort !== '' ? $envPort : (getAppSetting('mail_port', '587') ?? '587'));
    $encryption = strtolower($envEncryption !== '' ? $envEncryption : trim((string) getAppSetting('mail_encryption', 'tls')));
    $username = $envUsername !== '' ? $envUsername : trim((string) getAppSetting('mail_username', ''));
    $password = $envPassword !== '' ? $envPassword : (string) getAppSetting('mail_password', '');
    $fromEmail = $envFromEmail !== ''
        ? $envFromEmail
        : trim((string) getAppSetting('mail_from_email', $notificationEmail !== '' ? $notificationEmail : $username));
    $fromName = $envFromName !== '' ? $envFromName : trim((string) getAppSetting('mail_from_name', $companyName));
    $enabledRaw = strtolower($envEnabled !== '' ? $envEnabled : trim((string) getAppSetting('mail_enabled', '0')));

    if ($port <= 0) {
        $port = $encryption === 'ssl' ? 465 : 587;
    }

    if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
        $encryption = 'tls';
    }

    $config = [
        'enabled' => in_array($enabledRaw, ['1', 'true', 'yes', 'on'], true),
        'host' => $host,
        'port' => $port,
        'encryption' => $encryption,
        'username' => $username,
        'password' => $password,
        'from_email' => $fromEmail,
        'from_name' => $fromName !== '' ? $fromName : 'ERNI PMS',
    ];

    if (shouldDebugMailConfig()) {
        $debugConfig = $config;
        $debugConfig['password'] = $debugConfig['password'] !== '' ? '***' : '';
        error_log('Mail config: ' . json_encode($debugConfig, JSON_UNESCAPED_SLASHES));
    }

    return $config;
}

function shouldDebugMailConfig(): bool
{
    $debugRaw = getMailSetting('MAIL_DEBUG', '');
    if ($debugRaw !== '') {
        return in_array(strtolower($debugRaw), ['1', 'true', 'yes', 'on'], true);
    }

    return defined('DEBUG_MODE') && DEBUG_MODE;
}

function isMailConfigured(): bool
{
    $config = getMailConfig();

    return $config['enabled']
        && $config['host'] !== ''
        && $config['port'] > 0
        && $config['username'] !== ''
        && $config['password'] !== ''
        && filter_var($config['from_email'], FILTER_VALIDATE_EMAIL);
}

function sendSystemEmail(string|array $to, string $subject, string $htmlBody, ?string $textBody = null): array
{
    $config = getMailConfig();
    if (!$config['enabled']) {
        return ['success' => false, 'error' => 'Mail delivery is disabled in system settings.'];
    }

    if (!isMailConfigured()) {
        return ['success' => false, 'error' => 'Mail settings are incomplete.'];
    }

    $recipients = is_array($to) ? $to : [$to];
    $validRecipients = [];
    foreach ($recipients as $recipient) {
        $address = trim((string) $recipient);
        if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL)) {
            $validRecipients[] = $address;
        }
    }

    if ($validRecipients === []) {
        return ['success' => false, 'error' => 'No valid recipient email address was provided.'];
    }

    $textVersion = trim((string) ($textBody ?? html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], PHP_EOL, $htmlBody)), ENT_QUOTES, 'UTF-8')));
    if ($textVersion === '') {
        $textVersion = 'Please view this message in an HTML-capable email client.';
    }

    $boundary = 'b_' . bin2hex(random_bytes(12));
    $fromName = encodeMailHeader($config['from_name']);
    $fromEmail = $config['from_email'];
    $subjectLine = encodeMailHeader($subject);
    $messageIdHost = parse_url(APP_URL, PHP_URL_HOST) ?: 'localhost';

    $headers = [
        'Date: ' . gmdate('D, d M Y H:i:s O'),
        'From: ' . $fromName . ' <' . $fromEmail . '>',
        'To: ' . implode(', ', $validRecipients),
        'Subject: ' . $subjectLine,
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $messageIdHost . '>',
        'X-Mailer: ERNI PMS SMTP Mailer',
    ];

    $message = implode("\r\n", $headers) . "\r\n\r\n";
    $message .= '--' . $boundary . "\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= normalizeSmtpBody($textVersion) . "\r\n";
    $message .= '--' . $boundary . "\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= normalizeSmtpBody($htmlBody) . "\r\n";
    $message .= '--' . $boundary . "--\r\n";

    return smtpSendMessage($config, $fromEmail, $validRecipients, $message);
}

function smtpSendMessage(array $config, string $fromEmail, array $recipients, string $message): array
{
    $errors = [];

    foreach (smtpConnectionCandidates($config) as $candidate) {
        $result = smtpSendMessageViaConfig($candidate, $fromEmail, $recipients, $message);
        if ($result['success']) {
            return $result;
        }

        $errors[] = $candidate['host'] . ':' . $candidate['port'] . '/' . $candidate['encryption'] . ' - ' . (string) $result['error'];

        if (!smtpErrorLooksLikeConnectionFailure((string) $result['error'])) {
            break;
        }
    }

    return ['success' => false, 'error' => 'SMTP delivery failed: ' . implode('; ', $errors)];
}

function smtpConnectionCandidates(array $config): array
{
    $candidates = [$config];
    $host = strtolower((string) ($config['host'] ?? ''));
    $port = (int) ($config['port'] ?? 0);
    $encryption = strtolower((string) ($config['encryption'] ?? 'tls'));

    if (in_array($host, ['smtp.gmail.com', 'gmail-smtp.l.google.com'], true) && !($port === 465 && $encryption === 'ssl')) {
        $sslConfig = $config;
        $sslConfig['port'] = 465;
        $sslConfig['encryption'] = 'ssl';
        $candidates[] = $sslConfig;
    }

    return $candidates;
}

function smtpErrorLooksLikeConnectionFailure(string $error): bool
{
    $normalized = strtolower($error);

    return str_contains($normalized, 'connection failed')
        || str_contains($normalized, 'timed out')
        || str_contains($normalized, 'connection refused')
        || str_contains($normalized, 'network is unreachable')
        || str_contains($normalized, 'no route to host');
}

function smtpSendMessageViaConfig(array $config, string $fromEmail, array $recipients, string $message): array
{
    $transport = $config['encryption'] === 'ssl' ? 'ssl://' : 'tcp://';
    $context = stream_context_create([
        'ssl' => [
            'peer_name' => $config['host'],
            'verify_peer' => true,
            'verify_peer_name' => true,
            'SNI_enabled' => true,
        ],
    ]);
    $socket = @stream_socket_client(
        $transport . $config['host'] . ':' . $config['port'],
        $errorNumber,
        $errorMessage,
        20,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!is_resource($socket)) {
        return ['success' => false, 'error' => 'SMTP connection failed: ' . $errorMessage . ' (' . $errorNumber . ')'];
    }

    stream_set_timeout($socket, 20);

    try {
        $ehloHost = parse_url(BASE_URL, PHP_URL_HOST) ?: (gethostname() ?: 'localhost');
        smtpExpect($socket, [220]);
        smtpCommand($socket, 'EHLO ' . $ehloHost, [250]);

        if ($config['encryption'] === 'tls') {
            smtpCommand($socket, 'STARTTLS', [220]);
            $cryptoEnabled = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($cryptoEnabled !== true) {
                throw new RuntimeException('Unable to start TLS encryption with Gmail SMTP.');
            }
            smtpCommand($socket, 'EHLO ' . $ehloHost, [250]);
        }

        smtpCommand($socket, 'AUTH LOGIN', [334]);
        smtpCommand($socket, base64_encode($config['username']), [334], false);
        smtpCommand($socket, base64_encode($config['password']), [235], false);

        smtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        foreach ($recipients as $recipient) {
            smtpCommand($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        }

        smtpCommand($socket, 'DATA', [354]);
        fwrite($socket, dotStuffSmtpData($message) . "\r\n.\r\n");
        smtpExpect($socket, [250]);
        smtpCommand($socket, 'QUIT', [221]);

        return ['success' => true, 'error' => null];
    } catch (Throwable $e) {
        error_log('SMTP send failed: ' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    } finally {
        fclose($socket);
    }
}

function smtpCommand($socket, string $command, array $expectedCodes, bool $logCommand = true): string
{
    if ($logCommand) {
        fwrite($socket, $command . "\r\n");
    } else {
        fwrite($socket, $command . "\r\n");
    }

    return smtpExpect($socket, $expectedCodes);
}

function smtpExpect($socket, array $expectedCodes): string
{
    $response = '';

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }

    if ($response === '') {
        throw new RuntimeException('SMTP server returned an empty response.');
    }

    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP error ' . $code . ': ' . trim($response));
    }

    return $response;
}

function normalizeSmtpBody(string $body): string
{
    return str_replace(["\r\n", "\r"], "\n", $body);
}

function dotStuffSmtpData(string $message): string
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $message);
    $lines = explode("\n", $normalized);

    foreach ($lines as &$line) {
        if (isset($line[0]) && $line[0] === '.') {
            $line = '.' . $line;
        }
    }

    return str_replace("\n", "\r\n", implode("\n", $lines));
}

function encodeMailHeader(string $value): string
{
    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($value, 'UTF-8');
    }

    return $value;
}

function notificationTypeAllowsEmail(array $user, string $type): bool
{
    $emailNotificationsEnabled = (int) ($user['email_notifications'] ?? 1) === 1;
    if (!$emailNotificationsEnabled) {
        return false;
    }

    return match ($type) {
        'task', 'deadline' => (int) ($user['task_reminders'] ?? 1) === 1,
        'project' => (int) ($user['project_updates'] ?? 1) === 1,
        default => true,
    };
}

function sendNotificationEmailToUser(int $userId, string $title, string $message, string $type = 'system', ?string $actionUrl = null): bool
{
    if (!isMailConfigured()) {
        return false;
    }

    try {
        $conn = getConnection();
        $stmt = $conn->prepare(
            'SELECT email, full_name, email_notifications, task_reminders, project_updates
             FROM users
             WHERE id = ?
             LIMIT 1'
        );
        if ($stmt === false) {
            return false;
        }

        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }

        $result = $stmt->get_result();
        $user = $result ? ($result->fetch_assoc() ?: null) : null;
        $stmt->close();

        if (!$user || !notificationTypeAllowsEmail($user, $type)) {
            return false;
        }

        $email = trim((string) ($user['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $fullName = trim((string) ($user['full_name'] ?? 'Team Member'));
        $url = buildNotificationUrl($actionUrl);
        $subject = '[ERNI PMS] ' . $title;
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
        $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

        $htmlBody = '<div style="font-family:Arial,sans-serif;max-width:640px;margin:0 auto;padding:24px;background:#f5f7fb;">'
            . '<div style="background:#ffffff;border:1px solid #dbe3ef;border-radius:16px;padding:24px;">'
            . '<h2 style="margin:0 0 12px;color:#1f6392;">' . $safeTitle . '</h2>'
            . '<p style="margin:0 0 12px;color:#334155;">Hello ' . $safeName . ',</p>'
            . '<p style="margin:0 0 16px;color:#475569;line-height:1.6;">' . $safeMessage . '</p>';

        if ($url !== null) {
            $htmlBody .= '<p style="margin:0 0 16px;"><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" '
                . 'style="display:inline-block;background:#1f6392;color:#ffffff;text-decoration:none;padding:10px 16px;border-radius:10px;">Open in ERNI PMS</a></p>';
        }

        $htmlBody .= '<p style="margin:16px 0 0;color:#64748b;font-size:13px;">This email was sent automatically by ERNI PMS.</p>'
            . '</div></div>';

        $textBody = "Hello {$fullName},\n\n{$title}\n\n{$message}";
        if ($url !== null) {
            $textBody .= "\n\nOpen in ERNI PMS: {$url}";
        }

        $result = sendSystemEmail($email, $subject, $htmlBody, $textBody);
        if (!$result['success']) {
            error_log('Notification email failed for user_id=' . $userId . ': ' . (string) $result['error']);
        }

        return $result['success'];
    } catch (Throwable $e) {
        error_log('sendNotificationEmailToUser error: ' . $e->getMessage());
        return false;
    }
}

function buildNotificationUrl(?string $actionUrl): ?string
{
    $path = trim((string) $actionUrl);
    if ($path === '') {
        return null;
    }

    if (preg_match('/^https?:\/\//i', $path) === 1) {
        return $path;
    }

    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}

function getMailConfigDiagnostic(): array
{
    $diagnostic = [];
    
    try {
        $conn = getConnection();
        $keys = ['mail_enabled', 'mail_host', 'mail_port', 'mail_encryption', 'mail_username', 'mail_password', 'mail_from_email', 'mail_from_name'];
        
        foreach ($keys as $key) {
            $stmt = $conn->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('s', $key);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                $stmt->close();
                
                $value = $row ? (string) $row['setting_value'] : null;
                $diagnostic['db_' . $key] = $value;
            }
        }
    } catch (Throwable $e) {
        $diagnostic['db_error'] = $e->getMessage();
    }
    
    $config = getMailConfig();
    $diagnostic['resolved'] = [
        'enabled' => $config['enabled'],
        'host' => $config['host'],
        'port' => $config['port'],
        'encryption' => $config['encryption'],
        'username' => $config['username'],
        'password' => $config['password'] ? '***' : '',
        'from_email' => $config['from_email'],
        'from_name' => $config['from_name'],
    ];
    $diagnostic['is_configured'] = isMailConfigured();
    
    return $diagnostic;
}
