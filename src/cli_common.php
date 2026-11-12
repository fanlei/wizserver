<?php
declare(strict_types=1);

/**
 * Shared helpers for the command-line tools in scripts/.
 */

if (PHP_SAPI !== 'cli') {
    exit("This script must be run from the command line.\n");
}

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

/**
 * Split argv into positional arguments and --flags.
 * @return array{0: list<string>, 1: array<string, true>}
 */
function parseArgs(array $argv): array
{
    $positional = [];
    $flags = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--')) {
            $flags[substr($arg, 2)] = true;
        } else {
            $positional[] = $arg;
        }
    }
    return [$positional, $flags];
}

function expandHome(string $path): string
{
    if ($path === '~' || str_starts_with($path, '~/')) {
        $home = getenv('HOME') ?: '';
        return $home . substr($path, 1);
    }
    return $path;
}

function openSqlite(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000;');
    return $pdo;
}

/**
 * Open <data>/index.db of a local WizQTClient profile.
 */
function openClientIndexDb(string $dataDir): PDO
{
    $dbPath = rtrim(expandHome($dataDir), '/') . '/index.db';
    if (!is_file($dbPath)) {
        fail("index.db not found: {$dbPath}");
    }
    return openSqlite($dbPath);
}

/**
 * Read WIZ_META as [META_NAME][META_KEY] => META_VALUE.
 * @return array<string, array<string, string>>
 */
function readWizMeta(PDO $clientDb): array
{
    $meta = [];
    foreach ($clientDb->query('SELECT META_NAME, META_KEY, META_VALUE FROM WIZ_META') as $row) {
        $meta[(string)$row['META_NAME']][(string)$row['META_KEY']] = (string)($row['META_VALUE'] ?? '');
    }
    return $meta;
}

/**
 * WizQTClient stores times as local time "YYYY-MM-DD HH:MM:SS".
 * Use the system time zone so the converted times match what the client shows.
 */
function useLocalTimezone(): void
{
    $tz = getenv('TZ') ?: '';
    if ($tz === '' && is_file('/etc/timezone')) {
        $tz = trim((string)file_get_contents('/etc/timezone'));
    }
    if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
        date_default_timezone_set($tz);
    }
}

function tsToMs(?string $dt): int
{
    if ($dt === null || $dt === '') {
        return (int)(microtime(true) * 1000);
    }
    $t = DateTime::createFromFormat('Y-m-d H:i:s', substr($dt, 0, 19));
    if ($t === false) {
        return (int)(microtime(true) * 1000);
    }
    return max(0, $t->getTimestamp() * 1000);
}

function stripHtmlText(string $html): string
{
    $s = preg_replace('/<(head|style|script)[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return trim($s);
}
