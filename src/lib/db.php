<?php
declare(strict_types=1);

/** Shared PDO connection. Pass a PDO to replace it (tests, installer). */
function db(?PDO $replace = null): PDO
{
    static $pdo = null;
    if ($replace !== null) {
        $pdo = $replace;
    }
    if ($pdo === null) {
        $pdo = db_connect((array) config('db', []));
    }
    return $pdo;
}

/** @param array<string,mixed> $db */
function db_connect(array $db): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        (string) ($db['host'] ?? '127.0.0.1'),
        (int) ($db['port'] ?? 3306),
        (string) ($db['name'] ?? '')
    );
    $pdo = new PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);
    // Every DATETIME column holds UTC.
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

/** Run a prepared statement with bound values. */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** @return list<array<string,mixed>> */
function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** @return array<string,mixed>|null */
function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function q_val(string $sql, array $params = []): mixed
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** Identifiers (tables, columns) only ever come from code; this guards against mistakes. */
function db_ident(string $name): string
{
    if (!preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
        throw new InvalidArgumentException('Bad identifier: ' . $name);
    }
    return '`' . $name . '`';
}

/** @param array<string,mixed> $data */
function db_insert(string $table, array $data): int
{
    $cols = array_map('db_ident', array_keys($data));
    $sql = 'INSERT INTO ' . db_ident($table) . ' (' . implode(',', $cols) . ') VALUES ('
        . implode(',', array_fill(0, count($data), '?')) . ')';
    q($sql, array_values($data));
    return (int) db()->lastInsertId();
}

/** @param array<string,mixed> $data */
function db_update(string $table, int $id, array $data): void
{
    if ($data === []) {
        return;
    }
    $sets = [];
    foreach (array_keys($data) as $col) {
        $sets[] = db_ident($col) . ' = ?';
    }
    $params = array_values($data);
    $params[] = $id;
    q('UPDATE ' . db_ident($table) . ' SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
}

/** Run $fn in a transaction; rolls back and rethrows on any error. */
function db_tx(callable $fn): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** "?, ?, ?" for IN lists. */
function db_placeholders(int $n): string
{
    return implode(',', array_fill(0, max(1, $n), '?'));
}
