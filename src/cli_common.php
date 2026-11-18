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

function repoRoot(): string
{
    return dirname(__DIR__);
}

/**
 * Load key=value pairs from .env in the repo root (if present).
 * @return array<string, string>
 */
function loadDotEnv(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    $envFile = repoRoot() . '/.env';
    if (!is_file($envFile)) {
        return $cache;
    }
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $cache;
    }
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        if (str_starts_with($trimmed, 'export ')) {
            $trimmed = trim(substr($trimmed, 7));
        }
        $pos = strpos($trimmed, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($trimmed, 0, $pos));
        $val = trim(substr($trimmed, $pos + 1));
        if (strlen($val) >= 2) {
            $first = $val[0];
            $last = $val[strlen($val) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $val = substr($val, 1, -1);
            }
        }
        $cache[$key] = $val;
    }
    return $cache;
}

/**
 * Get a setting from the process environment or .env, or return $default.
 */
function envValue(string $key, string $default = ''): string
{
    $v = getenv($key);
    if ($v !== false && $v !== '') {
        return $v;
    }
    $dotEnv = loadDotEnv();
    if (isset($dotEnv[$key]) && $dotEnv[$key] !== '') {
        return $dotEnv[$key];
    }
    return $default;
}

function requireEnv(string $key): string
{
    $val = envValue($key);
    if ($val === '') {
        fail("Missing {$key}. Copy .env.example to .env and set {$key}.");
    }
    return $val;
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

/**
 * Resolve local WizQTClient data directory from CLI argument or WIZ_LOCAL_DATA in .env.
 */
function resolveLocalDataDir(?string $cliArg): string
{
    $dir = $cliArg ?? envValue('WIZ_LOCAL_DATA');
    if ($dir === '') {
        fail("Missing local data dir. Pass <data_dir> as an argument or set WIZ_LOCAL_DATA in .env.");
    }
    return rtrim(expandHome($dir), '/');
}

/**
 * Resolve server URL from CLI argument or WIZ_DOMAIN in .env.
 */
function resolveServerUrl(?string $cliArg): string
{
    $url = $cliArg ?? '';
    if ($url === '') {
        $domain = envValue('WIZ_DOMAIN');
        if ($domain === '') {
            fail("Missing server URL. Pass <server_url> as an argument or set WIZ_DOMAIN in .env.");
        }
        $url = str_starts_with($domain, 'http://') || str_starts_with($domain, 'https://')
            ? $domain
            : 'https://' . $domain;
    }
    $url = rtrim($url, '/');
    if (!preg_match('#^https?://#', $url)) {
        fail("Server URL must start with http:// or https://");
    }
    return $url;
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
