<?php
declare(strict_types=1);

function ensureChatMessageActions(mysqli|PgsqlMysqliCompatConnection $conn): bool
{
    static $ensured = null;

    if ($ensured !== null) {
        return $ensured;
    }

    $definitions = [
        'reply_to_message_id' => 'INT NULL',
        'forwarded_from_message_id' => 'INT NULL',
        'edited_at' => DB_DRIVER === 'pgsql' ? 'TIMESTAMP NULL' : 'DATETIME NULL',
        'deleted_at' => DB_DRIVER === 'pgsql' ? 'TIMESTAMP NULL' : 'DATETIME NULL',
    ];

    try {
        foreach ($definitions as $column => $definition) {
            if (dbColumnExists($conn, 'messages', $column)) {
                continue;
            }

            $altered = $conn->query("ALTER TABLE messages ADD COLUMN {$column} {$definition}");
            if ($altered === false && !dbColumnExists($conn, 'messages', $column)) {
                error_log("ensureChatMessageActions failed for {$column}: " . ($conn->error ?? 'unknown error'));
                $ensured = false;
                return false;
            }
        }

        $ensured = true;
        return true;
    } catch (Throwable $e) {
        error_log('ensureChatMessageActions error: ' . $e->getMessage());
        $ensured = false;
        return false;
    }
}

function getAccessibleChatMessage(
    mysqli|PgsqlMysqliCompatConnection $conn,
    int $messageId,
    int $userId
): ?array {
    if ($messageId <= 0 || $userId <= 0 || !ensureChatMessageActions($conn)) {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT id, sender_id, receiver_id, group_id, message, message_type,
                reply_to_message_id, forwarded_from_message_id, edited_at, deleted_at
         FROM messages
         WHERE id = ?
         LIMIT 1'
    );
    if ($stmt === false) {
        return null;
    }

    $stmt->bind_param('i', $messageId);
    $stmt->execute();
    $result = $stmt->get_result();
    $message = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$message) {
        return null;
    }

    $groupId = (int) ($message['group_id'] ?? 0);
    if ($groupId > 0) {
        $memberStmt = $conn->prepare(
            'SELECT id
             FROM chat_group_members
             WHERE group_id = ? AND user_id = ? AND left_at IS NULL
             LIMIT 1'
        );
        if ($memberStmt === false) {
            return null;
        }

        $memberStmt->bind_param('ii', $groupId, $userId);
        $memberStmt->execute();
        $memberResult = $memberStmt->get_result();
        $isMember = $memberResult && $memberResult->num_rows > 0;
        $memberStmt->close();

        return $isMember ? $message : null;
    }

    return (int) ($message['sender_id'] ?? 0) === $userId
        || (int) ($message['receiver_id'] ?? 0) === $userId
        ? $message
        : null;
}

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
