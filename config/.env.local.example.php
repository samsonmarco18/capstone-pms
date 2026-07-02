<?php

return [
    'DB_HOST' => 'localhost',
    'DB_USER' => 'root',
    'DB_PASS' => '',
    'DB_NAME' => 'erni_pms',
    'APP_URL' => 'http://localhost/erni-pms',
    'APP_BASE_PATH' => 'erni-pms',

    // SMTP mailer. For Gmail SMTP, use a Google App Password, not your normal password.
    'MAIL_ENABLED' => '1',
    'MAIL_DELIVERY_METHOD' => 'smtp',
    'MAIL_HOST' => 'smtp.gmail.com',
    'MAIL_PORT' => '587',
    'MAIL_ENCRYPTION' => 'tls',
    'MAIL_USERNAME' => 'altheapalentinos@gmail.com',
    'MAIL_PASSWORD' => 'your-google-app-password',
    'MAIL_FROM_EMAIL' => 'altheapalentinos@gmail.com',
    'MAIL_FROM_NAME' => 'ERNI PMS',
    'MAIL_VERIFY_PEER' => '1',
    'MAIL_TIMEOUT' => '8',
    'MAIL_DEBUG' => '0',

    // Optional Render-friendly Gmail API fallback.
    // Required only when MAIL_DELIVERY_METHOD is gmail_api.
    'GMAIL_CLIENT_ID' => '',
    'GMAIL_CLIENT_SECRET' => '',
    'GMAIL_REFRESH_TOKEN' => '',
    'GMAIL_USER' => '',
];
