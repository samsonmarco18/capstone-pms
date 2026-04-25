<?php
declare(strict_types=1);

final class PgsqlMysqliCompatResult
{
    /** @var array<int, array<string, mixed>> */
    private array $rows;
    private int $pointer = 0;
    public int $num_rows;

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = array_values($rows);
        $this->num_rows = count($this->rows);
    }

    public function fetch_assoc(): ?array
    {
        if (!isset($this->rows[$this->pointer])) {
            return null;
        }

        return $this->rows[$this->pointer++];
    }

    public function fetch_row(): ?array
    {
        $row = $this->fetch_assoc();
        if ($row === null) {
            return null;
        }

        return array_values($row);
    }

    public function fetch_all(int $mode = MYSQLI_ASSOC): array
    {
        if ($mode === MYSQLI_ASSOC) {
            return $this->rows;
        }

        return array_map('array_values', $this->rows);
    }

    public function data_seek(int $offset): bool
    {
        if ($offset < 0 || $offset > $this->num_rows) {
            return false;
        }

        $this->pointer = $offset;
        return true;
    }
}

final class PgsqlMysqliCompatStatement
{
    private PgsqlMysqliCompatConnection $connection;
    private PDOStatement $statement;
    private string $sql;
    /** @var array<int, mixed> */
    private array $boundValues = [];
    private ?PgsqlMysqliCompatResult $result = null;
    public int $insert_id = 0;

    public function __construct(PgsqlMysqliCompatConnection $connection, PDOStatement $statement, string $sql)
    {
        $this->connection = $connection;
        $this->statement = $statement;
        $this->sql = $sql;
    }

    public function bind_param(string $types, ...$vars): bool
    {
        $this->boundValues = array_values($vars);
        return true;
    }

    public function execute(?array $params = null): bool
    {
        $values = $params ?? $this->boundValues;
        $this->result = null;

        try {
            $executed = $this->statement->execute($values);
            $this->connection->error = '';
            $this->connection->affected_rows = $this->statement->rowCount();

            if ($this->statement->columnCount() > 0) {
                $rows = $this->statement->fetchAll(PDO::FETCH_ASSOC);
                $this->result = new PgsqlMysqliCompatResult($rows);
                $this->connection->affected_rows = $this->result->num_rows;
            }

            $this->insert_id = $this->connection->resolveLastInsertId($this->sql);
            $this->connection->insert_id = $this->insert_id;

            return $executed;
        } catch (Throwable $e) {
            $this->connection->error = $e->getMessage();
            return false;
        }
    }

    public function get_result(): PgsqlMysqliCompatResult|false
    {
        return $this->result ?? false;
    }

    public function store_result(): bool
    {
        return true;
    }

    public function close(): bool
    {
        $this->statement->closeCursor();
        return true;
    }
}

final class PgsqlMysqliCompatConnection
{
    private PDO $pdo;
    public string $connect_error = '';
    public string $error = '';
    public int $insert_id = 0;
    public int $affected_rows = 0;

    public function __construct(string $host, string $user, string $pass, string $database, int $port = 5432)
    {
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database);

        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => true,
            ]);
        } catch (Throwable $e) {
            $this->connect_error = $e->getMessage();
            throw $e;
        }
    }

    public function prepare(string $query): PgsqlMysqliCompatStatement|false
    {
        try {
            $translated = PgsqlQueryTranslator::translate($query);
            $statement = $this->pdo->prepare($translated);
            $this->error = '';

            return new PgsqlMysqliCompatStatement($this, $statement, $translated);
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function query(string $query): PgsqlMysqliCompatResult|bool
    {
        try {
            $translated = PgsqlQueryTranslator::translate($query);
            $statement = $this->pdo->query($translated);
            $this->error = '';

            if ($statement === false) {
                return false;
            }

            if ($statement->columnCount() === 0) {
                $this->affected_rows = $statement->rowCount();
                $this->insert_id = $this->resolveLastInsertId($translated);
                return true;
            }

            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $result = new PgsqlMysqliCompatResult($rows);
            $this->affected_rows = $result->num_rows;

            return $result;
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function set_charset(string $charset): bool
    {
        return true;
    }

    public function real_escape_string(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    public function begin_transaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollback(): bool
    {
        return $this->pdo->rollBack();
    }

    public function close(): bool
    {
        return true;
    }

    public function resolveLastInsertId(string $query): int
    {
        if (!preg_match('/^\s*INSERT\s+INTO\s+([a-zA-Z0-9_]+)/i', $query, $matches)) {
            return 0;
        }

        $table = strtolower($matches[1]);

        try {
            return (int) $this->pdo->lastInsertId($table . '_id_seq');
        } catch (Throwable) {
            try {
                return (int) $this->pdo->lastInsertId();
            } catch (Throwable) {
                return 0;
            }
        }
    }
}

final class PgsqlQueryTranslator
{
    public static function translate(string $query): string
    {
        $sql = trim($query);

        if (preg_match('/^SHOW\s+TABLES$/i', $sql) === 1) {
            return "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() ORDER BY table_name";
        }

        $sql = preg_replace_callback(
            '/SHOW\s+COLUMNS\s+FROM\s+([a-zA-Z0-9_]+)\s+LIKE\s+\'([^\']+)\'/i',
            static fn(array $matches): string =>
                "SELECT column_name AS Field
                 FROM information_schema.columns
                 WHERE table_schema = current_schema()
                   AND table_name = '" . strtolower($matches[1]) . "'
                   AND column_name LIKE '" . str_replace("'", "''", $matches[2]) . "'",
            $sql
        ) ?? $sql;

        $sql = self::translateInsertIgnore($sql);
        $sql = self::translateFunctionCalls($sql, 'DATE_SUB', static function (array $args): ?string {
            if (count($args) !== 2) {
                return null;
            }

            if (!preg_match('/^INTERVAL\s+(\d+)\s+(MINUTE|HOUR|DAY|MONTH|YEAR)$/i', trim($args[1]), $matches)) {
                return null;
            }

            return '(' . trim($args[0]) . " - INTERVAL '" . $matches[1] . ' ' . strtolower($matches[2]) . "')";
        });
        $sql = self::translateFunctionCalls($sql, 'DATE_ADD', static function (array $args): ?string {
            if (count($args) !== 2) {
                return null;
            }

            if (!preg_match('/^INTERVAL\s+(\d+)\s+(MINUTE|HOUR|DAY|MONTH|YEAR)$/i', trim($args[1]), $matches)) {
                return null;
            }

            return '(' . trim($args[0]) . " + INTERVAL '" . $matches[1] . ' ' . strtolower($matches[2]) . "')";
        });
        $sql = self::translateFunctionCalls($sql, 'DATEDIFF', static function (array $args): ?string {
            if (count($args) !== 2) {
                return null;
            }

            return 'CAST((DATE(' . trim($args[0]) . ') - DATE(' . trim($args[1]) . ')) AS INTEGER)';
        });
        $sql = self::translateFunctionCalls($sql, 'TIMESTAMPDIFF', static function (array $args): ?string {
            if (count($args) !== 3) {
                return null;
            }

            $unit = strtoupper(trim($args[0], " \t\n\r\0\x0B'\""));
            $seconds = match ($unit) {
                'MINUTE' => 60,
                'HOUR' => 3600,
                'DAY' => 86400,
                default => null,
            };

            if ($seconds === null) {
                return null;
            }

            return '(EXTRACT(EPOCH FROM ((' . trim($args[2]) . ') - (' . trim($args[1]) . '))) / ' . $seconds . ')';
        });
        $sql = self::translateFunctionCalls($sql, 'DATE_FORMAT', static function (array $args): ?string {
            if (count($args) !== 2) {
                return null;
            }

            $format = trim($args[1], " \t\n\r\0\x0B'\"");
            $mappedFormat = strtr($format, [
                '%Y' => 'YYYY',
                '%m' => 'MM',
                '%d' => 'DD',
                '%H' => 'HH24',
                '%i' => 'MI',
                '%s' => 'SS',
            ]);

            return "TO_CHAR(" . trim($args[0]) . ", '" . str_replace("'", "''", $mappedFormat) . "')";
        });

        $sql = preg_replace('/\bCURDATE\(\)/i', 'CURRENT_DATE', $sql) ?? $sql;
        $sql = preg_replace('/\bLONGTEXT\b/i', 'TEXT', $sql) ?? $sql;
        $sql = preg_replace('/\bDATETIME\b/i', 'TIMESTAMP', $sql) ?? $sql;
        $sql = preg_replace('/\bTINYINT\s*\(\s*1\s*\)/i', 'SMALLINT', $sql) ?? $sql;
        $sql = preg_replace('/\bBOOLEAN\b/i', 'SMALLINT', $sql) ?? $sql;
        $sql = preg_replace('/\bDEFAULT\s+TRUE\b/i', 'DEFAULT 1', $sql) ?? $sql;
        $sql = preg_replace('/\bDEFAULT\s+FALSE\b/i', 'DEFAULT 0', $sql) ?? $sql;
        $sql = preg_replace('/=\s*TRUE\b/i', '= 1', $sql) ?? $sql;
        $sql = preg_replace('/=\s*FALSE\b/i', '= 0', $sql) ?? $sql;
        $sql = preg_replace('/\bENUM\s*\((?:[^()]|(?R))*\)/i', 'TEXT', $sql) ?? $sql;

        return $sql;
    }

    private static function translateInsertIgnore(string $sql): string
    {
        if (preg_match('/^\s*INSERT\s+IGNORE\s+INTO\b/i', $sql) !== 1) {
            return $sql;
        }

        $translated = preg_replace('/^\s*INSERT\s+IGNORE\s+INTO\b/i', 'INSERT INTO', $sql, 1) ?? $sql;
        $trimmed = rtrim($translated);

        if (str_ends_with($trimmed, ';')) {
            return substr($trimmed, 0, -1) . ' ON CONFLICT DO NOTHING;';
        }

        return $trimmed . ' ON CONFLICT DO NOTHING';
    }

    /**
     * @param callable(array<int, string>): ?string $callback
     */
    private static function translateFunctionCalls(string $sql, string $functionName, callable $callback): string
    {
        $needle = strtoupper($functionName) . '(';
        $offset = 0;

        while (($position = stripos($sql, $needle, $offset)) !== false) {
            $openParen = strpos($sql, '(', $position + strlen($functionName));
            if ($openParen === false) {
                break;
            }

            $closeParen = self::findMatchingParen($sql, $openParen);
            if ($closeParen === null) {
                break;
            }

            $arguments = self::splitTopLevelArguments(substr($sql, $openParen + 1, $closeParen - $openParen - 1));
            $replacement = $callback($arguments);

            if ($replacement === null) {
                $offset = $closeParen + 1;
                continue;
            }

            $sql = substr($sql, 0, $position) . $replacement . substr($sql, $closeParen + 1);
            $offset = $position + strlen($replacement);
        }

        return $sql;
    }

    private static function findMatchingParen(string $sql, int $openParen): ?int
    {
        $depth = 0;
        $length = strlen($sql);

        for ($i = $openParen; $i < $length; $i++) {
            $character = $sql[$i];

            if ($character === '(') {
                $depth++;
                continue;
            }

            if ($character === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private static function splitTopLevelArguments(string $input): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $length = strlen($input);

        for ($i = 0; $i < $length; $i++) {
            $character = $input[$i];

            if ($character === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }

            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
            }

            $current .= $character;
        }

        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }
}
