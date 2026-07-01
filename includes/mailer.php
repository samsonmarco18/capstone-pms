<?php
declare(strict_types=1);

require_once __DIR__ . '/app-settings.php';

function getMailSetting(string $name, ?string $default = null): string
{
    global $localOverrides;

    if (is_array($localOverrides ?? null) && array_key_exists($name, $localOverrides)) {
        $value = normalizeMailSettingValue($name, $localOverrides[$name]);
        if ($value !== null) {
            return $value;
        }
    }

    $envValue = getenv($name);
    $envValue = normalizeMailSettingValue($name, $envValue);
    if ($envValue !== null) {
        return $envValue;
    }

    if (str_starts_with($name, 'MAIL_')) {
        $appSettingKey = 'mail_' . strtolower(substr($name, 5));
        $appValue = normalizeMailSettingValue($name, getAppSetting($appSettingKey, null));
        if ($appValue !== null) {
            return $appValue;
        }
    }

    if (str_starts_with($name, 'GMAIL_')) {
        $appSettingKey = 'gmail_' . strtolower(substr($name, 6));
        $appValue = normalizeMailSettingValue($name, getAppSetting($appSettingKey, null));
        if ($appValue !== null) {
            return $appValue;
        }
    }

    if (function_exists('getEnvOrDefault')) {
        $value = normalizeMailSettingValue($name, getEnvOrDefault($name, $default ?? ''));
        if ($value !== null) {
            return $value;
        }
    }

    if (defined($name)) {
        $value = normalizeMailSettingValue($name, constant($name));
        if ($value !== null) {
            return $value;
        }
    }

    return trim((string) ($default ?? ''));
}

function normalizeMailSettingValue(string $name, mixed $value): ?string
{
    if ($value === false || $value === null) {
        return null;
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    $trimmed = trim((string) $value);
    if ($trimmed === '') {
        return null;
    }

    if (strlen($trimmed) >= 2) {
        $first = $trimmed[0];
        $last = $trimmed[strlen($trimmed) - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $trimmed = trim(substr($trimmed, 1, -1));
        }
    }

    $normalizedName = strtoupper($name);
    $lowerValue = strtolower($trimmed);
    $placeholderValues = [
        'your-google-app-password',
        'your-app-password',
        'google-app-password',
        'app-password',
        'changeme',
        'change-me',
        'your-password',
    ];

    if ($normalizedName === 'MAIL_PASSWORD' && in_array($lowerValue, $placeholderValues, true)) {
        return null;
    }

    if (in_array($normalizedName, ['MAIL_USERNAME', 'MAIL_FROM_EMAIL'], true)
        && in_array($lowerValue, ['your-email@example.com', 'your-email@gmail.com', 'example@gmail.com'], true)
    ) {
        return null;
    }

    return $trimmed;
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
    $verifyPeerRaw = strtolower(getMailSetting('MAIL_VERIFY_PEER', '1'));
    $timeout = (int) getMailSetting('MAIL_TIMEOUT', '8');
    $deliveryMethod = strtolower(getMailSetting('MAIL_DELIVERY_METHOD', 'smtp'));

    $host = $envHost !== '' ? $envHost : trim((string) getAppSetting('mail_host', 'smtp.gmail.com'));
    $port = (int) ($envPort !== '' ? $envPort : (getAppSetting('mail_port', '587') ?? '587'));
    $encryption = strtolower($envEncryption !== '' ? $envEncryption : trim((string) getAppSetting('mail_encryption', 'tls')));
    $username = $envUsername !== '' ? $envUsername : trim((string) getAppSetting('mail_username', ''));
    $password = $envPassword !== '' ? $envPassword : (string) getAppSetting('mail_password', '');
    $configuredFromEmail = trim((string) getAppSetting('mail_from_email', ''));
    $fromEmail = $envFromEmail !== ''
        ? $envFromEmail
        : ($configuredFromEmail !== ''
            ? $configuredFromEmail
            : (filter_var($username, FILTER_VALIDATE_EMAIL) ? $username : $notificationEmail));
    $fromName = $envFromName !== '' ? $envFromName : trim((string) getAppSetting('mail_from_name', $companyName));
    $enabledRaw = strtolower($envEnabled !== '' ? $envEnabled : trim((string) getAppSetting('mail_enabled', '0')));

    if ($port <= 0) {
        $port = $encryption === 'ssl' ? 465 : 587;
    }

    if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
        $encryption = 'tls';
    }

    if (!in_array($deliveryMethod, ['smtp', 'gmail_api'], true)) {
        $deliveryMethod = 'smtp';
    }

    if ($timeout < 5) {
        $timeout = 5;
    } elseif ($timeout > 10) {
        $timeout = 10;
    }

    if (in_array($host, ['smtp.gmail.com', 'gmail-smtp.l.google.com'], true)) {
        if ($port === 465 && $encryption === 'tls') {
            $encryption = 'ssl';
        } elseif ($port === 587 && $encryption === 'ssl') {
            $encryption = 'tls';
        }

        if (filter_var($username, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = $username;
        }
    }

    $config = [
        'enabled' => in_array($enabledRaw, ['1', 'true', 'yes', 'on'], true),
        'host' => $host,
        'port' => $port,
        'encryption' => $encryption,
        'username' => $username,
        'password' => normalizeMailPassword($host, $password),
        'from_email' => $fromEmail,
        'from_name' => $fromName !== '' ? $fromName : 'ERNI PMS',
        'verify_peer' => !in_array($verifyPeerRaw, ['0', 'false', 'no', 'off'], true),
        'timeout' => $timeout,
        'delivery_method' => $deliveryMethod,
        'gmail_api' => getGmailApiConfig($username),
    ];

    if (shouldDebugMailConfig()) {
        $debugConfig = $config;
        $debugConfig['password'] = $debugConfig['password'] !== '' ? '***' : '';
        error_log('Mail config: ' . json_encode($debugConfig, JSON_UNESCAPED_SLASHES));
    }

    return $config;
}

function normalizeMailPassword(string $host, string $password): string
{
    $normalizedHost = strtolower(trim($host));

    if (in_array($normalizedHost, ['smtp.gmail.com', 'gmail-smtp.l.google.com'], true)) {
        return preg_replace('/\s+/', '', $password) ?? $password;
    }

    return $password;
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

    return getMailConfigIssues($config) === [];
}

function getMailConfigIssues(?array $config = null): array
{
    $config ??= getMailConfig();
    $issues = [];
    $deliveryMethod = (string) ($config['delivery_method'] ?? 'smtp');

    if (!$config['enabled']) {
        $issues[] = 'email delivery is disabled';
    }

    if ($deliveryMethod === 'gmail_api') {
        $gmailConfig = $config['gmail_api'] ?? [];

        if (trim((string) ($gmailConfig['client_id'] ?? '')) === '') {
            $issues[] = 'Gmail API client ID is missing';
        }

        if (trim((string) ($gmailConfig['client_secret'] ?? '')) === '') {
            $issues[] = 'Gmail API client secret is missing';
        }

        if (trim((string) ($gmailConfig['refresh_token'] ?? '')) === '') {
            $issues[] = 'Gmail API refresh token is missing';
        }

        if (trim((string) ($gmailConfig['user'] ?? '')) === '') {
            $issues[] = 'Gmail API user is missing';
        }
    } else {
        if (trim((string) ($config['host'] ?? '')) === '') {
            $issues[] = 'SMTP host is missing';
        }

        if ((int) ($config['port'] ?? 0) <= 0) {
            $issues[] = 'SMTP port is missing';
        }

        if (trim((string) ($config['username'] ?? '')) === '') {
            $issues[] = 'SMTP username is missing';
        }

        if (trim((string) ($config['password'] ?? '')) === '') {
            $issues[] = 'SMTP app password is missing';
        }

        if (($config['encryption'] ?? 'tls') !== 'none' && !extension_loaded('openssl')) {
            $issues[] = 'PHP OpenSSL extension is missing';
        }
    }

    if (!filter_var((string) ($config['from_email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        $issues[] = 'sender email is invalid';
    }

    return $issues;
}

function getGmailApiConfig(string $smtpUsername = ''): array
{
    $user = getMailSetting('GMAIL_USER', '');
    if ($user === '') {
        $user = $smtpUsername;
    }

    return [
        'client_id' => getMailSetting('GMAIL_CLIENT_ID', ''),
        'client_secret' => getMailSetting('GMAIL_CLIENT_SECRET', ''),
        'refresh_token' => getMailSetting('GMAIL_REFRESH_TOKEN', ''),
        'user' => $user,
    ];
}

function sendSystemEmail(string|array $to, string $subject, string $htmlBody, ?string $textBody = null): array
{
    $config = getMailConfig();
    $configIssues = getMailConfigIssues($config);
    if (in_array('email delivery is disabled', $configIssues, true)) {
        return ['success' => false, 'error' => 'Mail delivery is disabled in system settings.'];
    }

    if ($configIssues !== []) {
        return ['success' => false, 'error' => 'Mail settings are incomplete: ' . implode(', ', $configIssues) . '.'];
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

    if (($config['delivery_method'] ?? 'smtp') === 'gmail_api') {
        return gmailApiSendMessage($config['gmail_api'], $message);
    }

    return smtpSendMessage($config, $fromEmail, $validRecipients, $message);
}

function gmailApiSendMessage(array $gmailConfig, string $message): array
{
    $tokenResult = gmailApiFetchAccessToken($gmailConfig);
    if (!$tokenResult['success']) {
        return $tokenResult;
    }

    $rawMessage = rtrim(strtr(base64_encode($message), '+/', '-_'), '=');
    $sendResult = httpJsonRequest(
        'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
        ['raw' => $rawMessage],
        [
            'Authorization: Bearer ' . $tokenResult['access_token'],
            'Content-Type: application/json',
        ],
        15
    );

    if ($sendResult['success']) {
        return ['success' => true, 'error' => null];
    }

    return [
        'success' => false,
        'error' => 'Gmail API send failed: ' . $sendResult['error'],
    ];
}

function gmailApiFetchAccessToken(array $gmailConfig): array
{
    $response = httpFormRequest(
        'https://oauth2.googleapis.com/token',
        [
            'client_id' => (string) ($gmailConfig['client_id'] ?? ''),
            'client_secret' => (string) ($gmailConfig['client_secret'] ?? ''),
            'refresh_token' => (string) ($gmailConfig['refresh_token'] ?? ''),
            'grant_type' => 'refresh_token',
        ],
        15
    );

    if (!$response['success']) {
        return [
            'success' => false,
            'error' => 'Gmail API token request failed: ' . $response['error'],
        ];
    }

    $accessToken = trim((string) ($response['json']['access_token'] ?? ''));
    if ($accessToken === '') {
        return [
            'success' => false,
            'error' => 'Gmail API token response did not include an access token.',
        ];
    }

    return [
        'success' => true,
        'access_token' => $accessToken,
        'error' => null,
    ];
}

function httpFormRequest(string $url, array $fields, int $timeout = 15): array
{
    return httpRequest($url, http_build_query($fields), ['Content-Type: application/x-www-form-urlencoded'], $timeout);
}

function httpJsonRequest(string $url, array $payload, array $headers, int $timeout = 15): array
{
    return httpRequest($url, json_encode($payload, JSON_UNESCAPED_SLASHES), $headers, $timeout);
}

function httpRequest(string $url, string|false $body, array $headers, int $timeout = 15): array
{
    if ($body === false) {
        return ['success' => false, 'error' => 'Unable to encode HTTP request body.'];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => max(5, min(30, $timeout)),
            'ignore_errors' => true,
        ],
    ]);

    $responseBody = @file_get_contents($url, false, $context);
    $statusLine = (string) ($http_response_header[0] ?? '');
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);
    $statusCode = isset($matches[1]) ? (int) $matches[1] : 0;

    if ($responseBody === false) {
        $lastError = error_get_last();
        return [
            'success' => false,
            'error' => (string) ($lastError['message'] ?? 'HTTP request failed without a detailed error.'),
        ];
    }

    $decoded = json_decode($responseBody, true);
    $json = is_array($decoded) ? $decoded : [];

    if ($statusCode < 200 || $statusCode >= 300) {
        $apiError = $json['error']['message'] ?? $json['error_description'] ?? $json['error'] ?? trim($responseBody);
        return [
            'success' => false,
            'error' => 'HTTP ' . ($statusCode ?: 'unknown') . ': ' . (string) $apiError,
            'json' => $json,
        ];
    }

    return [
        'success' => true,
        'error' => null,
        'json' => $json,
    ];
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
    $candidates = [];
    $seen = [];
    $addCandidate = static function (array $candidate) use (&$candidates, &$seen): void {
        $key = strtolower((string) ($candidate['host'] ?? '')) . ':' . (int) ($candidate['port'] ?? 0) . '/' . strtolower((string) ($candidate['encryption'] ?? 'tls'));
        if (isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $candidates[] = $candidate;
    };

    $addCandidate($config);
    $host = strtolower((string) ($config['host'] ?? ''));
    $port = (int) ($config['port'] ?? 0);
    $encryption = strtolower((string) ($config['encryption'] ?? 'tls'));

    if (in_array($host, ['smtp.gmail.com', 'gmail-smtp.l.google.com'], true)) {
        $sslConfig = $config;
        $sslConfig['port'] = 465;
        $sslConfig['encryption'] = 'ssl';
        $tlsConfig = $config;
        $tlsConfig['port'] = 587;
        $tlsConfig['encryption'] = 'tls';

        $addCandidate($sslConfig);
        $addCandidate($tlsConfig);
    }

    return array_values($candidates);
}

function smtpErrorLooksLikeConnectionFailure(string $error): bool
{
    $normalized = strtolower($error);

    return str_contains($normalized, 'connection failed')
        || str_contains($normalized, 'timed out')
        || str_contains($normalized, 'connection refused')
        || str_contains($normalized, 'network is unreachable')
        || str_contains($normalized, 'no route to host')
        || str_contains($normalized, 'empty response')
        || str_contains($normalized, 'unable to start tls')
        || str_contains($normalized, 'failed to enable crypto');
}

function smtpSendMessageViaConfig(array $config, string $fromEmail, array $recipients, string $message): array
{
    $transport = $config['encryption'] === 'ssl' ? 'ssl://' : 'tcp://';
    $verifyPeer = (bool) ($config['verify_peer'] ?? true);
    $timeout = (int) ($config['timeout'] ?? 20);
    $context = stream_context_create([
        'ssl' => [
            'peer_name' => $config['host'],
            'verify_peer' => $verifyPeer,
            'verify_peer_name' => $verifyPeer,
            'SNI_enabled' => true,
        ],
    ]);
    $socket = @stream_socket_client(
        $transport . $config['host'] . ':' . $config['port'],
        $errorNumber,
        $errorMessage,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!is_resource($socket)) {
        $detail = trim((string) $errorMessage);
        if ($detail === '') {
            $detail = 'No socket error was returned. The host may be blocking outbound SMTP or the connection timed out before Gmail responded.';
        }

        return ['success' => false, 'error' => 'SMTP connection failed: ' . $detail . ' (' . $errorNumber . ')'];
    }

    stream_set_timeout($socket, $timeout);

    try {
        $ehloHost = parse_url(BASE_URL, PHP_URL_HOST) ?: (gethostname() ?: 'localhost');
        smtpExpect($socket, [220]);
        smtpCommand($socket, 'EHLO ' . $ehloHost, [250]);

        if ($config['encryption'] === 'tls') {
            smtpCommand($socket, 'STARTTLS', [220]);
            $cryptoMethod = 0;
            foreach (['STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT', 'STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT', 'STREAM_CRYPTO_METHOD_TLS_CLIENT'] as $constantName) {
                if (defined($constantName)) {
                    $cryptoMethod |= constant($constantName);
                }
            }
            if ($cryptoMethod === 0) {
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            }
            $cryptoEnabled = @stream_socket_enable_crypto($socket, true, $cryptoMethod);
            if ($cryptoEnabled !== true) {
                $opensslError = function_exists('openssl_error_string') ? (openssl_error_string() ?: '') : '';
                throw new RuntimeException('Unable to start TLS encryption with SMTP server.' . ($opensslError !== '' ? ' ' . $opensslError : ''));
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
        if (fwrite($socket, dotStuffSmtpData($message) . "\r\n.\r\n") === false) {
            throw new RuntimeException('SMTP write failed while sending message body.');
        }
        smtpExpect($socket, [250]);
        smtpCommand($socket, 'QUIT', [221]);

        return ['success' => true, 'error' => null];
    } catch (Throwable $e) {
        $message = trim($e->getMessage());
        if ($message === '') {
            $message = 'SMTP failed without a detailed server message.';
        }

        error_log('SMTP send failed: ' . $message);
        return ['success' => false, 'error' => $message];
    } finally {
        fclose($socket);
    }
}

function smtpCommand($socket, string $command, array $expectedCodes, bool $logCommand = true): string
{
    if (fwrite($socket, $command . "\r\n") === false) {
        throw new RuntimeException('SMTP write failed.');
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

    $meta = stream_get_meta_data($socket);
    if (($meta['timed_out'] ?? false) === true) {
        throw new RuntimeException('SMTP server response timed out.');
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
    $configIssues = getMailConfigIssues();
    if ($configIssues !== []) {
        error_log('Notification email skipped for user_id=' . $userId . ': ' . implode(', ', $configIssues));
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
        $keys = [
            'mail_enabled',
            'mail_delivery_method',
            'mail_host',
            'mail_port',
            'mail_encryption',
            'mail_username',
            'mail_password',
            'mail_from_email',
            'mail_from_name',
            'gmail_client_id',
            'gmail_client_secret',
            'gmail_refresh_token',
            'gmail_user',
        ];
        
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
        'verify_peer' => $config['verify_peer'],
        'timeout' => $config['timeout'],
        'delivery_method' => $config['delivery_method'],
        'gmail_api' => [
            'client_id' => ($config['gmail_api']['client_id'] ?? '') !== '' ? 'set' : '',
            'client_secret' => ($config['gmail_api']['client_secret'] ?? '') !== '' ? '***' : '',
            'refresh_token' => ($config['gmail_api']['refresh_token'] ?? '') !== '' ? '***' : '',
            'user' => $config['gmail_api']['user'] ?? '',
        ],
    ];
    $diagnostic['is_configured'] = isMailConfigured();
    $diagnostic['issues'] = getMailConfigIssues($config);
    
    return $diagnostic;
}
