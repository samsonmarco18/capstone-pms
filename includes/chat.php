<?php
declare(strict_types=1);

function ensureChatGroupReadTracking(mysqli|PgsqlMysqliCompatConnection $conn): bool
{
    static $ensured = null;

    if ($ensured !== null) {
        return $ensured;
    }

    try {
        if (dbColumnExists($conn, 'chat_group_members', 'last_read_message_id')) {
            $ensured = true;
            return true;
        }

        $columnDefinition = DB_DRIVER === 'pgsql'
            ? 'INT NOT NULL DEFAULT 0'
            : 'INT NOT NULL DEFAULT 0 AFTER left_at';
        $altered = $conn->query(
            'ALTER TABLE chat_group_members ADD COLUMN last_read_message_id ' . $columnDefinition
        );

        if ($altered === false && !dbColumnExists($conn, 'chat_group_members', 'last_read_message_id')) {
            error_log('ensureChatGroupReadTracking failed: ' . ($conn->error ?? 'unknown error'));
            $ensured = false;
            return false;
        }

        if ($altered !== false) {
            $backfilled = $conn->query(
                'UPDATE chat_group_members
                 SET last_read_message_id = COALESCE((
                     SELECT MAX(messages.id)
                     FROM messages
                     WHERE messages.group_id = chat_group_members.group_id
                 ), 0)'
            );

            if ($backfilled === false) {
                error_log('ensureChatGroupReadTracking backfill failed: ' . ($conn->error ?? 'unknown error'));
            }
        }

        $ensured = true;
        return true;
    } catch (Throwable $e) {
        error_log('ensureChatGroupReadTracking error: ' . $e->getMessage());
        $ensured = false;
        return false;
    }
}

function createChatGroupSystemMessage(
    mysqli|PgsqlMysqliCompatConnection $conn,
    int $senderId,
    int $groupId,
    string $message
): ?int {
    $text = trim($message);
    if ($senderId <= 0 || $groupId <= 0 || $text === '') {
        return null;
    }

    $messageType = 'notification';
    $stmt = $conn->prepare(
        'INSERT INTO messages (sender_id, group_id, message, message_type) VALUES (?, ?, ?, ?)'
    );

    if ($stmt === false) {
        return null;
    }

    $stmt->bind_param('iiss', $senderId, $groupId, $text, $messageType);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $messageId = (int) $stmt->insert_id;
    $stmt->close();

    return $messageId > 0 ? $messageId : null;
}
?>
