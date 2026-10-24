<?php
declare(strict_types=1);

/*
 * Backups (§7, super admin; §10 cron). Pure PHP, no shell commands, so they
 * work on hosts where exec() is disabled (aaPanel's default).
 *
 * - Database: backup_dump_db() streams SQL (DROP/CREATE + batched INSERTs)
 *   from a consistent InnoDB snapshot on its own unbuffered connection, so
 *   memory stays flat however big the tables are. Optional gzip.
 * - Uploads: ZipStream writes a ZIP (stored, no compression: images and
 *   audio are already compressed) straight to the output; Zip64 records are
 *   added when a file or the archive passes 4 GiB.
 * - Restore: backup_restore() reads .sql or .sql.gz back statement by
 *   statement (used by bin/restore and the tests).
 * - Scheduled: the cron job writes both into storage/backups and keeps the
 *   newest N of each kind.
 */

const BACKUP_DIR = '/storage/backups';
/** Folders that hold uploaded files, relative to BASE. */
const BACKUP_UPLOAD_DIRS = ['public/uploads', 'storage/originals', 'storage/variants'];

/** @return array{enabled:bool, time:string, keep:int, uploads:bool} */
function backup_settings(): array
{
    $s = (array) setting('backups', []);
    $time = (string) ($s['time'] ?? '03:00');
    return [
        'enabled' => (bool) ($s['enabled'] ?? false),
        'time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : '03:00',
        'keep' => max(1, min(60, (int) ($s['keep'] ?? 7))),
        'uploads' => (bool) ($s['uploads'] ?? false),
    ];
}

function backup_dir(): string
{
    return BASE . BACKUP_DIR;
}

/** Writer that optionally gzips; returns [write(string), finish()] closures. */
function backup_writer($out, bool $gzip): array
{
    $ctx = $gzip ? deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]) : null;
    $write = static function (string $s) use ($out, $ctx): void {
        $data = $ctx !== null ? deflate_add($ctx, $s, ZLIB_NO_FLUSH) : $s;
        if ($data !== '' && fwrite($out, $data) === false) {
            throw new RuntimeException('Could not write the backup.');
        }
    };
    $finish = static function () use ($out, $ctx): void {
        if ($ctx !== null) {
            fwrite($out, deflate_add($ctx, '', ZLIB_FINISH));
        }
        fflush($out);
    };
    return [$write, $finish];
}

/** A second connection for dumping (unbuffered, consistent snapshot). */
function backup_connection(?array $dbConfig = null): PDO
{
    $pdo = db_connect($dbConfig ?? (array) config('db', []));
    $attr = class_exists('Pdo\\Mysql') && defined('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY')
        ? constant('Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY')
        : constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY');
    $pdo->setAttribute($attr, false);
    return $pdo;
}

/**
 * Stream an SQL dump of every base table to $out.
 * @return array{tables:int, rows:int}
 */
function backup_dump_db($out, bool $gzip = false, ?PDO $pdo = null): array
{
    $pdo ??= backup_connection();
    [$write, $finish] = backup_writer($out, $gzip);
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    $tables = array_map(static fn (array $r): string => (string) $r[0], $tables);
    sort($tables);
    $write("-- Gather database backup\n-- Created " . gmdate('Y-m-d H:i:s') . " UTC from `" . str_replace(["\n", '`'], '', $dbName) . "` (server " . preg_replace('/[^\w.\-]/', '', $version) . ")\n"
        . "-- Restore with: php bin/restore <file>   (or: mysql <database> < file.sql)\n"
        . "SET NAMES utf8mb4;\nSET time_zone = '+00:00';\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
    $rowsTotal = 0;
    foreach ($tables as $table) {
        $ident = db_ident($table);
        $create = $pdo->query('SHOW CREATE TABLE ' . $ident)->fetch(PDO::FETCH_NUM);
        $write("\n-- Table $ident\nDROP TABLE IF EXISTS $ident;\n" . str_replace(["\r\n", "\n"], ' ', (string) $create[1]) . ";\n");
        $colStmt = $pdo->prepare('SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $colStmt->execute([$table]);
        $cols = $colStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $colStmt->closeCursor();
        $binary = [];
        foreach ($cols as $name => $type) {
            $binary[$name] = in_array(strtolower((string) $type), ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit'], true);
        }
        $prefix = 'INSERT INTO ' . $ident . ' (' . implode(',', array_map('db_ident', array_keys($cols))) . ') VALUES ';
        $stmt = $pdo->query('SELECT * FROM ' . $ident);
        $batch = [];
        $size = 0;
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $vals = [];
            foreach ($row as $name => $v) {
                $vals[] = match (true) {
                    $v === null => 'NULL',
                    $binary[$name] ?? false => $v === '' ? "''" : '0x' . bin2hex((string) $v),
                    is_int($v) => (string) $v,
                    is_float($v) => var_export($v, true),
                    default => $pdo->quote((string) $v),
                };
            }
            $tuple = '(' . implode(',', $vals) . ')';
            $batch[] = $tuple;
            $size += strlen($tuple);
            $rowsTotal++;
            if ($size > 512 * 1024 || count($batch) >= 500) {
                $write($prefix . implode(',', $batch) . ";\n");
                $batch = [];
                $size = 0;
            }
        }
        $stmt->closeCursor();
        if ($batch !== []) {
            $write($prefix . implode(',', $batch) . ";\n");
        }
    }
    $pdo->exec('COMMIT');
    $write("\nSET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n-- End of backup\n");
    $finish();
    return ['tables' => count($tables), 'rows' => $rowsTotal];
}

/**
 * Split an SQL stream into statements (quotes, backticks, escapes and
 * "--" / "#" line comments understood). Reads .sql or gzip transparently.
 * @return Generator<int,string>
 */
function backup_sql_statements(string $path): Generator
{
    $in = gzopen($path, 'rb');
    if ($in === false) {
        throw new RuntimeException('Cannot open ' . basename($path));
    }
    $buf = '';
    $quote = '';
    try {
        while (($line = gzgets($in, 1 << 20)) !== false) {
            if ($quote === '' && trim($buf) === '' && preg_match('/^\s*(--(\s|$)|#|$)/', $line)) {
                continue;
            }
            $len = strlen($line);
            $start = 0;
            for ($i = 0; $i < $len; $i++) {
                $c = $line[$i];
                if ($quote !== '') {
                    if ($c === '\\' && $quote !== '`') {
                        $i++;
                    } elseif ($c === $quote) {
                        $quote = '';
                    }
                    continue;
                }
                if ($c === "'" || $c === '"' || $c === '`') {
                    $quote = $c;
                } elseif ($c === ';') {
                    $stmt = trim($buf . substr($line, $start, $i - $start));
                    $buf = '';
                    $start = $i + 1;
                    if ($stmt !== '') {
                        yield $stmt;
                    }
                }
            }
            $buf .= substr($line, $start);
        }
    } finally {
        gzclose($in);
    }
    if (trim($buf) !== '') {
        yield trim($buf);
    }
}

/** Run every statement of a dump against $pdo. Returns the statement count. */
function backup_restore(string $path, PDO $pdo): int
{
    $n = 0;
    foreach (backup_sql_statements($path) as $stmt) {
        $pdo->exec($stmt);
        $n++;
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    return $n;
}

/** Every uploaded file: archive path => absolute path. */
function backup_upload_files(): Generator
{
    foreach (BACKUP_UPLOAD_DIRS as $dir) {
        $root = BASE . '/' . $dir;
        if (!is_dir($root)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile() && !$file->isLink() && !str_starts_with($file->getFilename(), '._')) {
                yield $dir . '/' . substr($file->getPathname(), strlen($root) + 1) => $file->getPathname();
            }
        }
    }
}

/** @return array{files:int, bytes:int} */
function backup_uploads_size(): array
{
    $n = 0;
    $bytes = 0;
    foreach (backup_upload_files() as $abs) {
        $n++;
        $bytes += (int) filesize($abs);
    }
    return ['files' => $n, 'bytes' => $bytes];
}

/** Stream a ZIP of every uploaded file to $out. Returns the file count. */
function backup_zip_uploads($out, bool $forceZip64 = false): int
{
    $zip = new ZipStream($out, $forceZip64);
    foreach (backup_upload_files() as $name => $abs) {
        $zip->addFile($name, $abs);
    }
    $zip->finish();
    return $zip->count();
}

/** Stored backups, newest first. @return list<array{name:string,size:int,time:int,kind:string}> */
function backup_list(): array
{
    $out = [];
    foreach (glob(backup_dir() . '/gather-*') ?: [] as $f) {
        $name = basename($f);
        if (!preg_match('/^gather-\d{8}-\d{6}-(db\.sql\.gz|db\.sql|uploads\.zip)$/', $name, $m)) {
            continue;
        }
        $out[] = ['name' => $name, 'size' => (int) filesize($f), 'time' => (int) filemtime($f), 'kind' => str_starts_with($m[1], 'db') ? 'db' : 'uploads'];
    }
    usort($out, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));
    return $out;
}

/** Absolute path of a stored backup by its exact name, or null. */
function backup_path(string $name): ?string
{
    if (!preg_match('/^gather-\d{8}-\d{6}-(db\.sql\.gz|db\.sql|uploads\.zip)$/', $name)) {
        return null;
    }
    $path = backup_dir() . '/' . $name;
    $real = realpath($path);
    return $real !== false && str_starts_with($real, (string) realpath(backup_dir()) . DIRECTORY_SEPARATOR) ? $real : null;
}

/**
 * Write a backup into storage/backups (the cron job and "Back up now").
 * @return list<string> file names written
 */
function backup_run(bool $withUploads, ?int $now = null): array
{
    $dir = backup_dir();
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create storage/backups');
    }
    $stamp = gmdate('Ymd-His', $now ?? time());
    $gzip = function_exists('deflate_init');
    $written = [];
    $jobs = ['db' . ($gzip ? '.sql.gz' : '.sql') => static fn ($fh) => backup_dump_db($fh, $gzip)];
    if ($withUploads) {
        $jobs['uploads.zip'] = static fn ($fh) => backup_zip_uploads($fh);
    }
    foreach ($jobs as $suffix => $fn) {
        $name = "gather-$stamp-$suffix";
        $part = $dir . '/.' . $name . '.part';
        $fh = fopen($part, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Cannot write ' . $name);
        }
        try {
            $fn($fh);
        } catch (Throwable $e) {
            fclose($fh);
            unlink($part);
            throw $e;
        }
        fclose($fh);
        chmod($part, 0640);
        rename($part, $dir . '/' . $name);
        $written[] = $name;
    }
    backup_prune(backup_settings()['keep']);
    return $written;
}

/** Keep the newest $keep backups of each kind. Returns how many were deleted. */
function backup_prune(int $keep): int
{
    $seen = ['db' => 0, 'uploads' => 0];
    $deleted = 0;
    foreach (backup_list() as $b) {
        if (++$seen[$b['kind']] > $keep) {
            $p = backup_path($b['name']);
            if ($p !== null && unlink($p)) {
                $deleted++;
            }
        }
    }
    return $deleted;
}

/** Cron job: daily at the configured time when switched on. */
function cron_backups(): string
{
    $s = backup_settings();
    if (!$s['enabled']) {
        return 'off';
    }
    $files = backup_run($s['uploads']);
    return 'wrote ' . implode(', ', $files);
}

/** Send a stream download: no buffering, no time limit, private. */
function backup_send_headers(string $filename, string $type): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (function_exists('set_time_limit')) {
        set_time_limit(0);
    }
    ignore_user_abort(false);
    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, private');
    header('X-Accel-Buffering: no');
}

/**
 * Minimal streaming ZIP writer (store method, UTF-8 names, Zip64 when
 * needed). CRC is computed per file before its header is written, so no
 * data descriptors and no temporary files are needed.
 */
final class ZipStream
{
    private int $offset = 0;
    /** @var list<array{name:string,crc:int,size:int,offset:int,time:int,date:int}> */
    private array $entries = [];

    /** @param resource $out */
    public function __construct(private $out, private bool $forceZip64 = false)
    {
    }

    public function count(): int
    {
        return count($this->entries);
    }

    private function put(string $bytes): void
    {
        if (fwrite($this->out, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Could not write the archive.');
        }
        $this->offset += strlen($bytes);
    }

    public function addFile(string $name, string $path): void
    {
        $size = (int) filesize($path);
        $crc = (int) hexdec((string) hash_file('crc32b', $path));
        $mtime = (int) filemtime($path);
        $d = getdate($mtime);
        $time = ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2);
        $date = ((max(1980, $d['year']) - 1980) << 9) | ($d['mon'] << 5) | $d['mday'];
        $zip64 = $this->forceZip64 || $size >= 0xFFFFFFFF;
        $extra = $zip64 ? pack('vvPP', 0x0001, 16, $size, $size) : '';
        $entry = ['name' => $name, 'crc' => $crc, 'size' => $size, 'offset' => $this->offset, 'time' => $time, 'date' => $date];
        $this->put(pack('VvvvvvVVVvv', 0x04034b50, $zip64 ? 45 : 20, 0x0800, 0, $time, $date, $crc,
            $zip64 ? 0xFFFFFFFF : $size, $zip64 ? 0xFFFFFFFF : $size, strlen($name), strlen($extra)) . $name . $extra);
        $in = fopen($path, 'rb');
        if ($in === false) {
            throw new RuntimeException('Cannot read ' . $name);
        }
        $copied = stream_copy_to_stream($in, $this->out);
        fclose($in);
        if ($copied !== $size) {
            throw new RuntimeException('File changed while archiving: ' . $name);
        }
        $this->offset += $size;
        $this->entries[] = $entry;
    }

    public function finish(): void
    {
        $cdStart = $this->offset;
        foreach ($this->entries as $e) {
            $bigSize = $this->forceZip64 || $e['size'] >= 0xFFFFFFFF;
            $bigOffset = $this->forceZip64 || $e['offset'] >= 0xFFFFFFFF;
            $extraData = ($bigSize ? pack('PP', $e['size'], $e['size']) : '') . ($bigOffset ? pack('P', $e['offset']) : '');
            $extra = $extraData !== '' ? pack('vv', 0x0001, strlen($extraData)) . $extraData : '';
            $this->put(pack('VvvvvvvVVVvvvvvVV', 0x02014b50, (3 << 8) | 45, $extra !== '' ? 45 : 20, 0x0800, 0, $e['time'], $e['date'], $e['crc'],
                $bigSize ? 0xFFFFFFFF : $e['size'], $bigSize ? 0xFFFFFFFF : $e['size'], strlen($e['name']), strlen($extra), 0, 0, 0,
                (0100644 << 16), $bigOffset ? 0xFFFFFFFF : $e['offset']) . $e['name'] . $extra);
        }
        $cdSize = $this->offset - $cdStart;
        $n = count($this->entries);
        $zip64 = $this->forceZip64 || $n >= 0xFFFF || $cdStart >= 0xFFFFFFFF || $cdSize >= 0xFFFFFFFF;
        if ($zip64) {
            $eocd64 = $this->offset;
            $this->put(pack('VPvvVVPPPP', 0x06064b50, 44, (3 << 8) | 45, 45, 0, 0, $n, $n, $cdSize, $cdStart));
            $this->put(pack('VVPV', 0x07064b50, 0, $eocd64, 1));
        }
        $this->put(pack('VvvvvVVv', 0x06054b50, 0, 0, $zip64 ? 0xFFFF : $n, $zip64 ? 0xFFFF : $n,
            $zip64 ? 0xFFFFFFFF : $cdSize, $zip64 ? 0xFFFFFFFF : $cdStart, 0));
        fflush($this->out);
    }
}
