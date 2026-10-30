<?php
declare(strict_types=1);

/** Split a migration file into statements (full-line "--" comments removed). */
function migration_statements(string $sql): array
{
    $lines = array_filter(
        preg_split('/\R/', $sql) ?: [],
        static fn (string $l): bool => !preg_match('/^\s*--/', $l)
    );
    $parts = preg_split('/;\s*(?:\R|$)/', implode("\n", $lines)) ?: [];
    return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
}

/**
 * @return list<string> migration file names (e.g. 001_core.sql) in order. A
 * .php migration returns a function(PDO): void and runs with the app loaded
 * (data upgrades that need PHP, such as matching typed place names).
 */
function migration_files(): array
{
    $files = array_merge(glob(BASE . '/migrations/[0-9][0-9][0-9]_*.sql') ?: [], glob(BASE . '/migrations/[0-9][0-9][0-9]_*.php') ?: []);
    sort($files, SORT_STRING);
    return array_map('basename', $files);
}

/**
 * Apply pending migrations. MySQL DDL commits implicitly, so each file is
 * recorded only after all of its statements succeeded.
 * @return list<string> files applied
 */
function migrate(PDO $pdo, ?callable $log = null): array
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(190) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $done = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $applied = [];
    foreach (migration_files() as $file) {
        if (in_array($file, $done, true)) {
            continue;
        }
        if (str_ends_with($file, '.php')) {
            $fn = require BASE . '/migrations/' . $file;
            if (!is_callable($fn)) {
                throw new RuntimeException("Migration $file must return a function.");
            }
            $fn($pdo);
        } else {
            $sql = (string) file_get_contents(BASE . '/migrations/' . $file);
            foreach (migration_statements($sql) as $statement) {
                $pdo->exec($statement);
            }
        }
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
        $stmt->execute([$file]);
        $applied[] = $file;
        if ($log !== null) {
            $log('applied ' . $file);
        }
    }
    return $applied;
}

/** Drop every table in the connected database (tests only). */
function drop_all_tables(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $pdo->exec('DROP TABLE ' . db_ident((string) $table));
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}
