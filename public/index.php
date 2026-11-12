<?php
declare(strict_types=1);

/**
 * WizServer Lite — Lightweight WizNote Private Server + Web App (PHP 8.x + SQLite)
 * Compatible with WizQTClient (Desktop), Wiz Mobile, and built-in Web UI.
 */

// Privacy header on every response (blocks search engine indexing)
header('X-Robots-Tag: noindex, nofollow, nosnippet, noarchive');

$dataDir = getenv('WIZ_DATA_DIR') ?: '/home/deploy/wizdata';
$dbPath = $dataDir . '/wizserver.sqlite';
$notesDir = $dataDir . '/notes';
$attDir = $dataDir . '/attachments';

// Determine public base URL dynamically
$proto = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']))
    ? $_SERVER['HTTP_X_FORWARDED_PROTO']
    : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = $proto . '://' . $host;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// 1. Serve robots.txt immediately
if ($uriPath === '/robots.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nDisallow: /\n";
    exit;
}

// 2. Handle WizNote Discovery query parameters (/?p=wiz&...&c=...)
if (isset($_GET['c'])) {
    $cmd = $_GET['c'];
    if ($cmd === 'endpoints') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'as' => $baseUrl,
            'ks' => $baseUrl,
            'message' => $baseUrl,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($cmd === 'oem') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'returnCode' => 200,
            'return_code' => 200,
            'returnMessage' => 'OK',
            'licence' => 'wizserver-lite-private-licence',
            'LogoConfig' => [
                'enable' => false,
            ],
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($cmd === 'sync_https' || $cmd === 'as' || $cmd === 'account_server_url' || $cmd === 'message_server') {
        header('Content-Type: text/plain; charset=utf-8');
        echo $baseUrl;
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo $baseUrl;
    exit;
}

// Open SQLite connection
if (!file_exists($dbPath)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['returnCode' => 500, 'returnMessage' => 'Database not found at ' . $dbPath]);
    exit;
}

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA busy_timeout = 5000;');
$pdo->exec('PRAGMA journal_mode = WAL;');

// Helper functions
function sendJson(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($payload['returnCode'])) {
        $payload['returnCode'] = 200;
    }
    if (!isset($payload['return_code'])) {
        $payload['return_code'] = $payload['returnCode'];
    }
    if (!isset($payload['returnMessage'])) {
        $payload['returnMessage'] = ($payload['returnCode'] === 200) ? 'OK' : 'Error';
    }
    if (!isset($payload['return_message'])) {
        $payload['return_message'] = $payload['returnMessage'];
    }
    if (!isset($payload['externCode'])) {
        $payload['externCode'] = '';
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function getRequestParams(): array {
    $raw = file_get_contents('php://input') ?: '';
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_merge($_GET, $_POST, $decoded);
        }
    }
    if (!empty($_POST)) {
        return array_merge($_GET, $_POST);
    }
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_merge($_GET, $decoded);
        }
        parse_str($raw, $parsed);
        if (is_array($parsed) && !empty($parsed)) {
            return array_merge($_GET, $parsed);
        }
    }
    return $_GET;
}

function getAuthToken(): string {
    if (!empty($_SERVER['HTTP_X_WIZ_TOKEN'])) {
        return trim($_SERVER['HTTP_X_WIZ_TOKEN']);
    }
    if (!empty($_GET['token'])) {
        return trim((string)$_GET['token']);
    }
    if (!empty($_POST['token'])) {
        return trim((string)$_POST['token']);
    }
    if (!empty($_COOKIE['wiz_token'])) {
        return trim((string)$_COOKIE['wiz_token']);
    }
    return '';
}

function requireUser(PDO $pdo): array {
    $token = getAuthToken();
    if ($token !== '') {
        $stmt = $pdo->prepare('SELECT t.token, u.* FROM tokens t JOIN users u ON t.user_guid = u.user_guid WHERE t.token = ?');
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
    }
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (str_starts_with($uriPath, '/ks/object/download/')) {
        $stmt = $pdo->query('SELECT * FROM users LIMIT 1');
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
    }
    sendJson(['returnCode' => 301, 'returnMessage' => 'Invalid token', 'externCode' => 'WizErrorInvalidToken']);
}

function verifyPassword(array $user, string $inputPassword): bool {
    if ($inputPassword === '') {
        return false;
    }
    $sha256 = hash('sha256', $inputPassword);
    if (hash_equals($user['password_hash'], $sha256)) {
        return true;
    }
    if (hash_equals($user['password_plain_md5'], $inputPassword)) {
        return true;
    }
    if (hash_equals($user['password_plain_md5'], 'md5.' . md5($inputPassword))) {
        return true;
    }
    return false;
}

function issueToken(PDO $pdo, array $user): string {
    $token = bin2hex(random_bytes(24));
    $expires = time() + 86400 * 30;
    $stmt = $pdo->prepare('INSERT INTO tokens (token, user_guid, kb_guid, expires_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$token, $user['user_guid'], $user['kb_guid'], $expires]);
    return $token;
}

function buildLoginResponse(array $user, string $token, string $baseUrl): array {
    return [
        'returnCode' => 200,
        'return_code' => 200,
        'returnMessage' => 'OK',
        'return_message' => 'success',
        'externCode' => '',
        'result' => [
            'id' => 1,
            'userGuid' => $user['user_guid'],
            'email' => $user['email'],
            'displayName' => $user['display_name'],
            'displayname' => $user['display_name'],
            'userId' => $user['user_id'],
            'mywizEmail' => $user['mywiz_email'] ?: 'mywiz@localhost',
            'kbGuid' => $user['kb_guid'],
            'kbServer' => $baseUrl,
            'kbType' => 'person',
            'syncType' => 100,
            'token' => $token,
            'maxAge' => 86400,
            'uploadSizeLimit' => 209715200,
            'userType' => 'vip',
            'vip' => true,
            'vipDate' => (time() + 86400 * 3650) * 1000,
            'dtCreated' => (int)$user['created_ms'],
            'created' => (int)$user['created_ms'],
            'userPoints' => 99999,
            'userLevel' => 5,
            'userLevelName' => 'VIP',
            'noticeText' => '',
            'noticeLink' => '',
            'user' => [
                'userId' => $user['user_id'],
                'userGuid' => $user['user_guid'],
                'displayName' => $user['display_name'],
                'created' => (int)$user['created_ms'],
                'email' => $user['email'],
                'emailVerify' => true,
            ],
        ],
    ];
}

function nextVersion(PDO $pdo, string $kbGuid, string $col): int {
    $allowed = ['doc_version', 'att_version', 'tag_version', 'style_version', 'param_version', 'deleted_version'];
    if (!in_array($col, $allowed, true)) {
        throw new InvalidArgumentException('Invalid version column');
    }
    $pdo->exec("UPDATE kb_state SET {$col} = {$col} + 1 WHERE kb_guid = " . $pdo->quote($kbGuid));
    $stmt = $pdo->prepare("SELECT {$col} FROM kb_state WHERE kb_guid = ?");
    $stmt->execute([$kbGuid]);
    return (int)$stmt->fetchColumn();
}

function formatDocRow(array $r): array {
    return [
        'kbGuid' => $r['kb_guid'],
        'docGuid' => $r['doc_guid'],
        'version' => (int)$r['version'],
        'dataMd5' => $r['data_md5'] ?: '00000000000000000000000000000000',
        'dataModified' => (int)$r['data_modified_ms'],
        'infoMd5' => $r['info_md5'] ?: '00000000000000000000000000000000',
        'infoModified' => (int)$r['info_modified_ms'],
        'paramMd5' => $r['param_md5'] ?: '00000000000000000000000000000000',
        'paramModified' => (int)$r['param_modified_ms'],
        'title' => $r['title'],
        'category' => $r['category'],
        'owner' => $r['owner'] ?: '',
        'iconIndex' => (int)$r['icon_index'],
        'protected' => (int)$r['protected'],
        'readCount' => (int)$r['read_count'],
        'attachmentCount' => (int)$r['attachment_count'],
        'type' => $r['type'] ?: 'document',
        'fileType' => $r['file_type'] ?: '',
        'created' => (int)$r['created_ms'],
        'accessed' => (int)$r['accessed_ms'],
        'url' => $r['url'] ?: '',
        'styleGuid' => $r['style_guid'] ?: null,
        'seo' => $r['seo'] ?: '',
        'author' => $r['author'] ?: '',
        'keywords' => $r['keywords'] ?: '',
        'coverImage' => null,
        'dataSize' => (int)$r['data_size'],
        'markers' => null,
        'abstractText' => $r['abstract_text'] ?: '',
        'abstractImage' => 0,
        'tags' => $r['tags_str'] !== '' ? $r['tags_str'] : null,
    ];
}

function stripHtmlText(string $html): string {
    $s = preg_replace('/<(head|style|script)[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return trim($s);
}

// ============================================================================
// ACCOUNT SERVICE (/as/*) & MESSAGES (/messages)
// ============================================================================

if ($uriPath === '/as/user/login' || $uriPath === '/as/user/token') {
    $params = getRequestParams();
    $userId = trim((string)($params['userId'] ?? $params['user_id'] ?? ''));
    $password = (string)($params['password'] ?? '');
    $stmt = $pdo->prepare('SELECT * FROM users WHERE lower(user_id) = lower(?) OR lower(email) = lower(?) LIMIT 1');
    $stmt->execute([$userId, $userId]);
    $user = $stmt->fetch();
    if (!$user || !verifyPassword($user, $password)) {
        sendJson([
            'returnCode' => 301,
            'returnMessage' => 'Invalid user or password',
            'externCode' => 'WizErrorInvalidPassword',
        ]);
    }
    $token = issueToken($pdo, $user);
    sendJson(buildLoginResponse($user, $token, $baseUrl));
}

if ($uriPath === '/as/user/keep') {
    requireUser($pdo);
    sendJson(['returnCode' => 200]);
}

if ($uriPath === '/as/user/logout') {
    $token = getAuthToken();
    if ($token !== '') {
        $stmt = $pdo->prepare('DELETE FROM tokens WHERE token = ?');
        $stmt->execute([$token]);
    }
    sendJson(['returnCode' => 200]);
}

if ($uriPath === '/as/user/groups' || $uriPath === '/as/user/bizs') {
    requireUser($pdo);
    sendJson(['returnCode' => 200, 'result' => []]);
}

if ($uriPath === '/as/user/kb/info/all') {
    $user = requireUser($pdo);
    $stmt = $pdo->prepare('SELECT * FROM kb_state WHERE kb_guid = ?');
    $stmt->execute([$user['kb_guid']]);
    $kb = $stmt->fetch();
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE kb_guid = ?');
    $countStmt->execute([$user['kb_guid']]);
    $noteCount = (int)$countStmt->fetchColumn();
    sendJson([
        'returnCode' => 200,
        'result' => [[
            'kbGuid' => $user['kb_guid'],
            'kbServer' => $baseUrl,
            'name' => 'Personal Notes',
            'type' => 'person',
            'noteCount' => $noteCount,
            'noteCountLimit' => 10000000,
            'uploadSizeLimit' => 209715200,
            'docVersion' => (int)($kb['doc_version'] ?? 1),
            'attVersion' => (int)($kb['att_version'] ?? 1),
            'tagVersion' => (int)($kb['tag_version'] ?? 1),
            'styleVersion' => (int)($kb['style_version'] ?? 0),
            'paramVersion' => (int)($kb['param_version'] ?? 1),
            'deletedVersion' => (int)($kb['deleted_version'] ?? 1),
            'userVersion' => 1,
            'storageLimit' => 1000000000000,
            'storageUsage' => (int)($kb['storage_usage'] ?? 0),
            'trafficLimit' => 100000000000,
            'trafficUsage' => 0,
        ]],
    ]);
}

if ($uriPath === '/as/user/kv/versions') {
    $user = requireUser($pdo);
    $stmt = $pdo->prepare('SELECT key, version FROM kv_store WHERE scope_guid = ?');
    $stmt->execute([$user['user_guid']]);
    $versions = [];
    foreach ($stmt->fetchAll() as $r) {
        $versions[] = [
            'key' => $r['key'],
            'version' => (int)$r['version'],
        ];
    }
    sendJson([
        'returnCode' => 200,
        'result' => [[
            'kbGuid' => $user['kb_guid'],
            'versions' => $versions,
        ]],
    ]);
}

if (preg_match('#^/(?:as|ks)/kv/(version|value)/([^/]+)(?:/(.+))?$#', $uriPath, $m)) {
    $user = requireUser($pdo);
    $action = $m[1];
    $scopeGuid = $m[2];
    $params = getRequestParams();
    $key = isset($m[3]) && $m[3] !== '' ? urldecode($m[3]) : (string)($_GET['key'] ?? $params['key'] ?? '');

    if ($method === 'GET') {
        $stmt = $pdo->prepare('SELECT value, version FROM kv_store WHERE scope_guid = ? AND key = ?');
        $stmt->execute([$scopeGuid, $key]);
        $row = $stmt->fetch();
        if (!$row) {
            $stmt = $pdo->prepare('SELECT value, version FROM kv_store WHERE key = ? LIMIT 1');
            $stmt->execute([$key]);
            $row = $stmt->fetch();
        }
        $ver = $row ? (int)$row['version'] : 0;
        $val = $row ? (string)$row['value'] : '';

        if ($action === 'version') {
            sendJson([
                'returnCode' => 200,
                'result' => $ver,
                'version' => $ver,
            ]);
        } else {
            sendJson([
                'returnCode' => 200,
                'result' => [
                    'version' => $ver,
                    'key' => $key,
                    'value' => $val,
                ],
                'version' => $ver,
                'valueOfKey' => $val,
            ]);
        }
    } else {
        $val = (string)($params['value'] ?? $params['valueOfKey'] ?? '');
        $ver = (int)(microtime(true) * 1000);
        if ($key !== '') {
            foreach ([$scopeGuid, $user['user_guid'], $user['kb_guid']] as $sg) {
                $stmt = $pdo->prepare('INSERT OR REPLACE INTO kv_store (scope_guid, key, value, version) VALUES (?, ?, ?, ?)');
                $stmt->execute([$sg, $key, $val, $ver]);
            }
        }
        sendJson([
            'returnCode' => 200,
            'result' => $ver,
            'version' => $ver,
        ]);
    }
}

if (str_starts_with($uriPath, '/messages')) {
    sendJson(['returnCode' => 200, 'result' => []]);
}

if (str_starts_with($uriPath, '/as/user/avatar/')) {
    header('Content-Type: image/png');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    exit;
}

// ============================================================================
// KNOWLEDGE BASE SYNC SERVICE (/ks/*)
// ============================================================================

if (preg_match('#^/ks/kb/info/([^/]+)$#', $uriPath, $m)) {
    $user = requireUser($pdo);
    $kbGuid = $m[1];
    $stmt = $pdo->prepare('SELECT * FROM kb_state WHERE kb_guid = ?');
    $stmt->execute([$kbGuid]);
    $kb = $stmt->fetch();
    if (!$kb) {
        sendJson(['returnCode' => 404, 'returnMessage' => 'KB not found']);
    }
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE kb_guid = ?');
    $countStmt->execute([$kbGuid]);
    $noteCount = (int)$countStmt->fetchColumn();

    sendJson([
        'returnCode' => 200,
        'result' => [
            'kbGuid' => $kbGuid,
            'noteCount' => $noteCount,
            'noteCountLimit' => 10000000,
            'uploadSizeLimit' => 209715200,
            'uploadSizeLimitString' => '200M',
            'docVersion' => (int)$kb['doc_version'],
            'attVersion' => (int)$kb['att_version'],
            'tagVersion' => (int)$kb['tag_version'],
            'styleVersion' => (int)$kb['style_version'],
            'paramVersion' => (int)$kb['param_version'],
            'deletedVersion' => (int)$kb['deleted_version'],
            'userVersion' => 1,
            'favorVersion' => 0,
            'storageUsage' => (int)$kb['storage_usage'],
            'storageUsageString' => round(((int)$kb['storage_usage']) / 1048576, 1) . ' MB',
            'storageLimit' => 1000000000000,
            'storageLimitString' => '1000G',
            'trafficUsage' => 0,
            'trafficUsageString' => '0 Byte',
            'trafficLimit' => 100000000000,
            'trafficLimitString' => '100G',
            'extra' => new stdClass(),
        ],
    ]);
}

// Deleted GUIDs list & upload
if (preg_match('#^/ks/deleted/list/version/([^/]+)$#', $uriPath, $m)) {
    $user = requireUser($pdo);
    $kbGuid = $m[1];
    $ver = (int)($_GET['version'] ?? 0);
    $count = max(1, min(1000, (int)($_GET['count'] ?? 200)));
    $stmt = $pdo->prepare('SELECT * FROM deleted_guids WHERE kb_guid = ? AND version >= ? ORDER BY version ASC LIMIT ?');
    $stmt->execute([$kbGuid, $ver, $count]);
    $result = [];
    foreach ($stmt->fetchAll() as $r) {
        $result[] = [
            'deletedGuid' => $r['deleted_guid'],
            'kbGuid' => $r['kb_guid'],
            'version' => (int)$r['version'],
            'created' => (int)$r['deleted_ms'],
            'modified' => (int)$r['deleted_ms'],
            'type' => $r['guid_type'],
            'isDeleted' => 1,
        ];
    }
    sendJson(['returnCode' => 200, 'result' => $result]);
}

if (preg_match('#^/ks/deleted/upload/([^/]+)$#', $uriPath, $m)) {
    $user = requireUser($pdo);
    $kbGuid = $m[1];
    $params = getRequestParams();
    $deleteds = $params['deleteds'] ?? [];
    $ver = 0;
    if (!empty($deleteds)) {
        $ver = nextVersion($pdo, $kbGuid, 'deleted_version');
        foreach ($deleteds as $d) {
            $guid = $d['deletedGuid'] ?? '';
            $type = $d['type'] ?? 'document';
            $dtMs = isset($d['created']) ? (strtotime((string)$d['created']) * 1000 ?: (int)(microtime(true) * 1000)) : (int)(microtime(true) * 1000);
            if ($guid === '') {
                continue;
            }
            $stmt = $pdo->prepare('INSERT OR REPLACE INTO deleted_guids (deleted_guid, kb_guid, guid_type, deleted_ms, version) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$guid, $kbGuid, $type, $dtMs, $ver]);
            if ($type === 'document') {
                $pdo->prepare('DELETE FROM documents WHERE doc_guid = ?')->execute([$guid]);
                $pdo->prepare('DELETE FROM document_tags WHERE doc_guid = ?')->execute([$guid]);
            } elseif ($type === 'tag') {
                $pdo->prepare('DELETE FROM tags WHERE tag_guid = ?')->execute([$guid]);
                $pdo->prepare('DELETE FROM document_tags WHERE tag_guid = ?')->execute([$guid]);
            } elseif ($type === 'attachment') {
                $pdo->prepare('DELETE FROM attachments WHERE att_guid = ?')->execute([$guid]);
                @unlink($attDir . '/' . basename($guid));
            } elseif ($type === 'style') {
                $pdo->prepare('DELETE FROM styles WHERE style_guid = ?')->execute([$guid]);
            }
        }
    } else {
        $stmt = $pdo->prepare('SELECT deleted_version FROM kb_state WHERE kb_guid = ?');
        $stmt->execute([$kbGuid]);
        $ver = (int)$stmt->fetchColumn();
    }
    sendJson(['returnCode' => 200, 'version' => $ver]);
}

// Tags list & upload
if (preg_match('#^/ks/tag/list/version/([^/]+)$#', $uriPath, $m)) {
    $user = requireUser($pdo);
    $kbGuid = $m[1];
    $ver = (int)($_GET['version'] ?? 0);
    $count = max(1, min(1000, (int)($_GET['count'] ?? 200)));
    $stmt = $pdo->prepare('SELECT * FROM tags WHERE kb_guid = ? AND version >= ? ORDER BY version ASC LIMIT ?');
    $stmt->execute([$kbGuid, $ver, $count]);
    $result = [];
    foreach ($stmt->fetchAll() as $r) {
        $result[] = [
            'kbGuid' => $r['kb_guid'],
            'tagGuid' => $r['tag_guid'],
            'parentTagGuid' => $r['parent_tag_guid'] !== '' ? $r['parent_tag_guid'] : null,
            'version' => (int)$r['version'],
            'name' => $r['name'],
            'description' => $r['description'] ?: '',
            'modified' => (int)$r['modified_ms'],
            'pos' => (int)$r['pos'],
        ];
    }
    sendJson(['returnCode' => 200, 'result' => $result]);
}

if (preg_match('#^/ks/tag/upload/([^/]+)$#', $uriPath, $m)) {
    $user = requireUser($pdo);
    $kbGuid = $m[1];
    $params = getRequestParams();
    $tags = $params['tags'] ?? [];
    $ver = nextVersion($pdo, $kbGuid, 'tag_version');
    foreach ($tags as $t) {
        $tagGuid = $t['tagGuid'] ?? '';
        if ($tagGuid === '') {
            continue;
        }
        $modMs = isset($t['modified']) ? (strtotime((string)$t['modified']) * 1000 ?: (int)(microtime(true) * 1000)) : (int)(microtime(true) * 1000);
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO tags (tag_guid, kb_guid, parent_tag_guid, name, description, modified_ms, version, pos) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $tagGuid,
            $kbGuid,
            $t['parentTagGuid'] ?? '',
            $t['name'] ?? '',
            $t['description'] ?? '',
            $modMs,
            $ver,
            (int)($t['pos'] ?? 0),
        ]);
    }
    sendJson(['returnCode' => 200, 'version' => $ver]);
}

// Styles list & upload
if (preg_match('#^/ks/style/list/version/([^/]+)$#', $uriPath, $m)) {
    $user = requireUser($pdo);
    $kbGuid = $m[1];
    $ver = (int)($_GET['version'] ?? 0);
    $count = max(1, min(1000, (int)($_GET['count'] ?? 200)));
    $stmt = $pdo->prepare('SELECT * FROM styles WHERE kb_guid = ? AND version >= ? ORDER BY version ASC LIMIT ?');
    $stmt->execute([$kbGuid, $ver, $count]);
    $result = [];
    foreach ($stmt->fetchAll() as $r) {
        $result[] = [
            'kbGuid' => $r['kb_guid'],
            'styleGuid' => $r['style_guid'],
            'name' => $r['name'],
            'description' => $r['description'],
            'textColor' => $r['text_color'],
            'backColor' => $r['back_color'],
            'textBold' => (int)$r['text_bold'],
            'flagIndex' => (int)$r['flag_index'],
            'modified' => (int)$r['modified_ms'],
            'version' => (int)$r['version'],
        ];
    }
    sendJson(['returnCode' => 200, 'result' => $result]);
}

if (preg_match('#^/ks/style/upload/([^/]+)$#', $uriPath, $m)) {
    $user = requireUser($pdo);
    $kbGuid = $m[1];
    $ver = nextVersion($pdo, $kbGuid, 'style_version');
    sendJson(['returnCode' => 200, 'version' => $ver]);
}

// Params list & upload
if (preg_match('#^/ks/param/list/version/([^/]+)$#', $uriPath, $m)) {
    $user = requireUser($pdo);
    $kbGuid = $m[1];
    $ver = (int)($_GET['version'] ?? 0);
    $count = max(1, min(1000, (int)($_GET['count'] ?? 200)));
    $stmt = $pdo->prepare('SELECT * FROM params WHERE kb_guid = ? AND version >= ? ORDER BY version ASC LIMIT ?');
    $stmt->execute([$kbGuid, $ver, $count]);
    $result = [];
    foreach ($stmt->fetchAll() as $r) {
        $result[] = [
            'kbGuid' => $r['kb_guid'],
            'docGuid' => $r['doc_guid'],
            'name' => $r['name'],
            'value' => $r['value'],
            'version' => (int)$r['version'],
        ];
    }
    sendJson(['returnCode' => 200, 'result' => $result]);
}

if (preg_match('#^/ks/param/upload/([^/]+)$#', $uriPath, $m)) {
    $user = requireUser($pdo);
    $kbGuid = $m[1];
    $params = getRequestParams();
    $docGuid = $params['docGuid'] ?? '';
    $ver = nextVersion($pdo, $kbGuid, 'param_version');
    foreach (($params['params'] ?? []) as $p) {
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO params (kb_guid, doc_guid, name, value, version) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$kbGuid, $docGuid, $p['name'] ?? '', $p['value'] ?? '', $ver]);
    }
    sendJson(['returnCode' => 200, 'version' => $ver]);
}

// Notes list by version & by guids
if (preg_match('#^/ks/note/list/version/([^/]+)$#', $uriPath, $m)) {
    $user = requireUser($pdo);
    $kbGuid = $m[1];
    $ver = (int)($_GET['version'] ?? 0);
    $count = max(1, min(1000, (int)($_GET['count'] ?? 200)));
    $stmt = $pdo->prepare('SELECT * FROM documents WHERE kb_guid = ? AND version >= ? ORDER BY version ASC LIMIT ?');
    $stmt->execute([$kbGuid, $ver, $count]);
    $result = [];
    foreach ($stmt->fetchAll() as $r) {
        $result[] = formatDocRow($r);
    }
    sendJson(['returnCode' => 200, 'result' => $result]);
}

if (preg_match('#^/ks/note/list/guids/([^/]+)$#', $uriPath, $m)) {
    $user = requireUser($pdo);
    $kbGuid = $m[1];
    $params = getRequestParams();
    $guids = $params['guids'] ?? $_GET['guids'] ?? [];
    if (is_string($guids)) {
        $guids = explode(',', $guids);
    }
    $result = [];
    if (!empty($guids)) {
        $placeholders = implode(',', array_fill(0, count($guids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM documents WHERE kb_guid = ? AND doc_guid IN ({$placeholders})");
        $stmt->execute(array_merge([$kbGuid], array_values($guids)));
        foreach ($stmt->fetchAll() as $r) {
            $result[] = formatDocRow($r);
        }
    }
    sendJson(['returnCode' => 200, 'result' => $result]);
}

// Note download: /ks/note/download/{kbGuid}/{docGuid}
if (preg_match('#^/ks/note/download/([^/]+)/([^/]+)$#', $uriPath, $m)) {
    $user = requireUser($pdo);
    $kbGuid = $m[1];
    $docGuid = basename($m[2]);
    $stmt = $pdo->prepare('SELECT * FROM documents WHERE kb_guid = ? AND doc_guid = ?');
    $stmt->execute([$kbGuid, $docGuid]);
    $doc = $stmt->fetch();
    if (!$doc) {
        sendJson(['returnCode' => 404, 'returnMessage' => 'Document not found']);
    }

    $docFolder = $notesDir . '/' . $docGuid;
    $indexPath = $docFolder . '/index.html';
    $html = file_exists($indexPath) ? (file_get_contents($indexPath) ?: '') : '';
    if ($html === '') {
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($doc['title']) . '</title></head><body></body></html>';
    }

    $resources = [];
    $resDir = $docFolder . '/index_files';
    if (is_dir($resDir)) {
        foreach (scandir($resDir) ?: [] as $fn) {
            if ($fn === '.' || $fn === '..') {
                continue;
            }
            $fp = $resDir . '/' . $fn;
            if (is_file($fp)) {
                $size = filesize($fp) ?: 0;
                $mtime = (filemtime($fp) ?: time()) * 1000;
                $resources[] = [
                    'name' => $fn,
                    'size' => (string)$size,
                    'time' => $mtime,
                    'url' => $baseUrl . '/ks/object/download/' . rawurlencode($kbGuid) . '/' . rawurlencode($docGuid)
                        . '?objType=resource&objId=' . rawurlencode($fn),
                ];
            }
        }
    }

    sendJson([
        'returnCode' => 200,
        'info' => formatDocRow($doc),
        'html' => $html,
        'resources' => $resources,
    ]);
}

// Note upload: /ks/note/upload/{kbGuid}/{docGuid}
if (preg_match('#^/ks/note/upload/([^/]+)/([^/]+)$#', $uriPath, $m)) {
    $user = requireUser($pdo);
    $kbGuid = $m[1];
    $docGuid = basename($m[2]);
    $params = getRequestParams();

    $stmt = $pdo->prepare('SELECT * FROM documents WHERE doc_guid = ?');
    $stmt->execute([$docGuid]);
    $existing = $stmt->fetch();

    $ver = nextVersion($pdo, $kbGuid, 'doc_version');
    $nowMs = (int)(microtime(true) * 1000);

    $title = (string)($params['title'] ?? ($existing['title'] ?? 'Untitled'));
    $category = (string)($params['category'] ?? ($existing['category'] ?? '/My Notes/'));
    $dataMd5 = (string)($params['dataMd5'] ?? ($existing['data_md5'] ?? ''));
    $infoMd5 = (string)($params['infoMd5'] ?? ($existing['info_md5'] ?? ''));
    $paramMd5 = (string)($params['paramMd5'] ?? ($existing['param_md5'] ?? ''));
    $tagsStr = (string)($params['tags'] ?? ($existing['tags_str'] ?? ''));

    $createdMs = isset($params['created']) ? (strtotime((string)$params['created']) * 1000 ?: $nowMs) : (int)($existing['created_ms'] ?? $nowMs);
    $accessedMs = isset($params['accessed']) ? (strtotime((string)$params['accessed']) * 1000 ?: $nowMs) : (int)($existing['accessed_ms'] ?? $nowMs);
    $infoModMs = isset($params['infoModified']) ? (strtotime((string)$params['infoModified']) * 1000 ?: $nowMs) : (int)($existing['info_modified_ms'] ?? $nowMs);
    $dataModMs = isset($params['dataModified']) ? (strtotime((string)$params['dataModified']) * 1000 ?: $nowMs) : (int)($existing['data_modified_ms'] ?? $nowMs);
    $paramModMs = isset($params['paramModified']) ? (strtotime((string)$params['paramModified']) * 1000 ?: $nowMs) : (int)($existing['param_modified_ms'] ?? $nowMs);

    $docFolder = $notesDir . '/' . $docGuid;
    if (!is_dir($docFolder)) {
        mkdir($docFolder, 0775, true);
    }

    $abstractText = $existing['abstract_text'] ?? '';
    $searchText = $existing['search_text'] ?? $title;
    $dataSize = (int)($existing['data_size'] ?? 0);

    if (isset($params['html']) && is_string($params['html']) && $params['html'] !== '') {
        $htmlContent = $params['html'];
        file_put_contents($docFolder . '/index.html', $htmlContent);
        $dataSize = strlen($htmlContent);
        $plain = stripHtmlText($htmlContent);
        $abstractText = mb_substr($plain, 0, 200);
        $searchText = $title . ' ' . $plain;
        if ($dataMd5 === '') {
            $dataMd5 = md5($htmlContent);
        }
    }

    // Update document_tags
    $pdo->prepare('DELETE FROM document_tags WHERE doc_guid = ?')->execute([$docGuid]);
    if ($tagsStr !== '') {
        foreach (explode('*', $tagsStr) as $tg) {
            $tg = trim($tg);
            if ($tg !== '') {
                $pdo->prepare('INSERT OR IGNORE INTO document_tags (doc_guid, tag_guid) VALUES (?, ?)')->execute([$docGuid, $tg]);
            }
        }
    }

    $stmt = $pdo->prepare('
        INSERT OR REPLACE INTO documents (
            doc_guid, kb_guid, title, category, name, seo, url, author, keywords,
            type, owner, file_type, style_guid,
            created_ms, modified_ms, accessed_ms,
            icon_index, protected, read_count, attachment_count,
            info_modified_ms, info_md5, data_modified_ms, data_md5,
            param_modified_ms, param_md5, version, data_size,
            tags_str, abstract_text, search_text
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $docGuid,
        $kbGuid,
        $title,
        $category,
        (string)($params['name'] ?? ($existing['name'] ?? '')),
        (string)($params['seo'] ?? ($existing['seo'] ?? '')),
        (string)($params['url'] ?? ($existing['url'] ?? '')),
        (string)($params['author'] ?? ($existing['author'] ?? '')),
        (string)($params['keywords'] ?? ($existing['keywords'] ?? '')),
        (string)($params['type'] ?? ($existing['type'] ?? 'document')),
        (string)($params['owner'] ?? ($existing['owner'] ?? $user['user_id'])),
        (string)($params['fileType'] ?? ($existing['file_type'] ?? '')),
        (string)($params['styleGuid'] ?? ($existing['style_guid'] ?? '')),
        $createdMs,
        $dataModMs,
        $accessedMs,
        (int)($params['iconIndex'] ?? ($existing['icon_index'] ?? 0)),
        (int)($params['protected'] ?? ($existing['protected'] ?? 0)),
        (int)($params['readCount'] ?? ($existing['read_count'] ?? 0)),
        (int)($params['attachmentCount'] ?? ($existing['attachment_count'] ?? 0)),
        $infoModMs,
        $infoMd5 ?: md5($title . $category),
        $dataModMs,
        $dataMd5 ?: md5($docGuid),
        $paramModMs,
        $paramMd5,
        $ver,
        $dataSize,
        $tagsStr,
        $abstractText,
        $searchText,
    ]);

    if (isset($params['resources']) && is_array($params['resources'])) {
        $keep = array_flip(array_map('strval', $params['resources']));
        $resDir = $docFolder . '/index_files';
        if (is_dir($resDir)) {
            foreach (scandir($resDir) ?: [] as $fn) {
                if ($fn === '.' || $fn === '..') {
                    continue;
                }
                if (!isset($keep[$fn])) {
                    @unlink($resDir . '/' . $fn);
                }
            }
        }
    }

    sendJson(['returnCode' => 200, 'version' => $ver]);
}

// Attachments list & metadata upload
if (preg_match('#^/ks/attachment/list/version/([^/]+)$#', $uriPath, $m)) {
    $user = requireUser($pdo);
    $kbGuid = $m[1];
    $ver = (int)($_GET['version'] ?? 0);
    $count = max(1, min(1000, (int)($_GET['count'] ?? 200)));
    $stmt = $pdo->prepare('SELECT * FROM attachments WHERE kb_guid = ? AND version >= ? ORDER BY version ASC LIMIT ?');
    $stmt->execute([$kbGuid, $ver, $count]);
    $result = [];
    foreach ($stmt->fetchAll() as $r) {
        $result[] = [
            'attGuid' => $r['att_guid'],
            'docGuid' => $r['doc_guid'],
            'kbGuid' => $r['kb_guid'],
            'version' => (int)$r['version'],
            'dataSize' => (int)$r['data_size'],
            'infoMd5' => $r['info_md5'],
            'dataMd5' => $r['data_md5'],
            'name' => $r['name'],
            'url' => $r['url'] ?: null,
            'description' => $r['description'] ?: '',
            'created' => (int)$r['info_modified_ms'],
            'infoModified' => (int)$r['info_modified_ms'],
            'dataModified' => (int)$r['data_modified_ms'],
        ];
    }
    sendJson(['returnCode' => 200, 'result' => $result]);
}

if (preg_match('#^/ks/attachment/upload/([^/]+)/([^/]+)/([^/]+)$#', $uriPath, $m)) {
    $user = requireUser($pdo);
    $kbGuid = $m[1];
    $docGuid = basename($m[2]);
    $attGuid = basename($m[3]);
    $params = getRequestParams();

    $ver = nextVersion($pdo, $kbGuid, 'att_version');
    $nowMs = (int)(microtime(true) * 1000);
    $infoModMs = isset($params['infoModified']) ? (strtotime((string)$params['infoModified']) * 1000 ?: $nowMs) : $nowMs;
    $dataModMs = isset($params['dataModified']) ? (strtotime((string)$params['dataModified']) * 1000 ?: $nowMs) : $nowMs;

    $attFile = $attDir . '/' . $attGuid;
    $dataSize = file_exists($attFile) ? (filesize($attFile) ?: 0) : 0;

    $stmt = $pdo->prepare('
        INSERT OR REPLACE INTO attachments (
            att_guid, doc_guid, kb_guid, name, url, description,
            info_modified_ms, info_md5, data_modified_ms, data_md5, version, data_size
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $attGuid,
        $docGuid,
        $kbGuid,
        (string)($params['name'] ?? 'attachment'),
        '',
        (string)($params['description'] ?? ''),
        $infoModMs,
        (string)($params['infoMd5'] ?? ''),
        $dataModMs,
        (string)($params['dataMd5'] ?? ''),
        $ver,
        $dataSize,
    ]);

    $pdo->prepare('UPDATE documents SET attachment_count = (SELECT COUNT(*) FROM attachments WHERE doc_guid = ?) WHERE doc_guid = ?')
        ->execute([$docGuid, $docGuid]);

    sendJson([
        'returnCode' => 200,
        'version' => $ver,
        'key' => $attGuid,
    ]);
}

// Object upload & download (resources & attachments)
if (preg_match('#^/ks/object/upload/([^/]+)/([^/]+)$#', $uriPath, $m)) {
    $user = requireUser($pdo);
    $kbGuid = $m[1];
    $docGuid = basename($m[2]);
    $objType = $_POST['objType'] ?? $_GET['objType'] ?? 'resource';
    $objId = basename((string)($_POST['objId'] ?? $_GET['objId'] ?? ''));

    if (!isset($_FILES['data']) || $_FILES['data']['error'] !== UPLOAD_ERR_OK) {
        sendJson(['returnCode' => 400, 'returnMessage' => 'Missing uploaded file data']);
    }
    $tmpFile = $_FILES['data']['tmp_name'];

    if ($objType === 'resource') {
        $resDir = $notesDir . '/' . $docGuid . '/index_files';
        if (!is_dir($resDir)) {
            mkdir($resDir, 0775, true);
        }
        move_uploaded_file($tmpFile, $resDir . '/' . $objId);
        sendJson(['returnCode' => 200]);
    } elseif ($objType === 'attachment') {
        if (!is_dir($attDir)) {
            mkdir($attDir, 0775, true);
        }
        $destPath = $attDir . '/' . $objId;
        $za = new ZipArchive();
        if ($za->open($tmpFile) === true && $za->numFiles === 1) {
            $stream = $za->getStream($za->getNameIndex(0));
            if ($stream) {
                $out = fopen($destPath, 'wb');
                stream_copy_to_stream($stream, $out);
                fclose($out);
                fclose($stream);
            } else {
                move_uploaded_file($tmpFile, $destPath);
            }
            $za->close();
        } else {
            move_uploaded_file($tmpFile, $destPath);
        }
        $ver = nextVersion($pdo, $kbGuid, 'att_version');
        $size = file_exists($destPath) ? (filesize($destPath) ?: 0) : 0;
        $pdo->prepare('UPDATE attachments SET data_size = ?, version = ? WHERE att_guid = ?')
            ->execute([$size, $ver, $objId]);
        sendJson(['returnCode' => 200, 'version' => $ver]);
    }
    sendJson(['returnCode' => 200]);
}

if (preg_match('#^/ks/object/download/([^/]+)/([^/]+)$#', $uriPath, $m)) {
    $user = requireUser($pdo);
    $kbGuid = $m[1];
    $docGuid = basename($m[2]);
    $objType = $_GET['objType'] ?? 'resource';
    $objId = basename((string)($_GET['objId'] ?? ''));

    if ($objType === 'resource') {
        $fp = $notesDir . '/' . $docGuid . '/index_files/' . $objId;
        if (!is_file($fp)) {
            http_response_code(404);
            exit('Resource not found');
        }
        $mime = mime_content_type($fp) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($fp));
        readfile($fp);
        exit;
    } elseif ($objType === 'attachment') {
        $fp = $attDir . '/' . $objId;
        if (!is_file($fp)) {
            http_response_code(404);
            exit('Attachment not found');
        }
        $stmt = $pdo->prepare('SELECT name FROM attachments WHERE att_guid = ?');
        $stmt->execute([$objId]);
        $attName = $stmt->fetchColumn() ?: 'attachment';

        if (!empty($_GET['raw'])) {
            $mime = mime_content_type($fp) ?: 'application/octet-stream';
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . rawurlencode((string)$attName) . '"');
            header('Content-Length: ' . filesize($fp));
            readfile($fp);
            exit;
        }

        $tmpZip = tempnam(sys_get_temp_dir(), 'wizatt_');
        $za = new ZipArchive();
        $za->open($tmpZip, ZipArchive::OVERWRITE);
        $za->addFile($fp, (string)$attName);
        $za->close();

        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($tmpZip));
        readfile($tmpZip);
        @unlink($tmpZip);
        exit;
    }
}

// ============================================================================
// BUILT-IN WEB APP API (/web/api/*) & STATIC RESOURCE SERVING
// ============================================================================

if (preg_match('#^/web/res/([^/]+)/index_files/(.+)$#', $uriPath, $m)) {
    $user = requireUser($pdo);
    $docGuid = basename($m[1]);
    $resName = basename(urldecode($m[2]));
    $fp = $notesDir . '/' . $docGuid . '/index_files/' . $resName;
    if (!is_file($fp)) {
        http_response_code(404);
        exit('Not found');
    }
    $mime = mime_content_type($fp) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=3600');
    readfile($fp);
    exit;
}

if ($uriPath === '/web/api/bootstrap') {
    $user = requireUser($pdo);
    $kbGuid = $user['kb_guid'];

    $stmt = $pdo->prepare('SELECT category, COUNT(*) as cnt FROM documents WHERE kb_guid = ? GROUP BY category ORDER BY category ASC');
    $stmt->execute([$kbGuid]);
    $folders = $stmt->fetchAll();

    $stmt = $pdo->prepare('
        SELECT t.tag_guid, t.name, t.parent_tag_guid, COUNT(dt.doc_guid) as cnt
        FROM tags t
        LEFT JOIN document_tags dt ON t.tag_guid = dt.tag_guid
        WHERE t.kb_guid = ?
        GROUP BY t.tag_guid
        ORDER BY t.name ASC
    ');
    $stmt->execute([$kbGuid]);
    $tags = $stmt->fetchAll();

    $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE kb_guid = ?');
    $totalStmt->execute([$kbGuid]);
    $totalNotes = (int)$totalStmt->fetchColumn();

    sendJson([
        'returnCode' => 200,
        'user' => [
            'userId' => $user['user_id'],
            'displayName' => $user['display_name'],
            'kbGuid' => $kbGuid,
        ],
        'totalNotes' => $totalNotes,
        'folders' => $folders,
        'tags' => $tags,
    ]);
}

if ($uriPath === '/web/api/notes') {
    $user = requireUser($pdo);
    $kbGuid = $user['kb_guid'];
    $category = $_GET['category'] ?? '';
    $tagGuid = $_GET['tag'] ?? '';
    $q = trim((string)($_GET['q'] ?? ''));

    $sql = 'SELECT doc_guid, title, category, created_ms, data_modified_ms, attachment_count, abstract_text, tags_str FROM documents WHERE kb_guid = ?';
    $args = [$kbGuid];

    if ($category !== '') {
        $sql .= ' AND (category = ? OR category LIKE ?)';
        $args[] = $category;
        $args[] = rtrim($category, '/') . '/%';
    }
    if ($tagGuid !== '') {
        $sql .= ' AND doc_guid IN (SELECT doc_guid FROM document_tags WHERE tag_guid = ?)';
        $args[] = $tagGuid;
    }
    if ($q !== '') {
        $sql .= ' AND (title LIKE ? OR search_text LIKE ?)';
        $args[] = '%' . $q . '%';
        $args[] = '%' . $q . '%';
    }
    $sql .= ' ORDER BY data_modified_ms DESC LIMIT 500';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    sendJson(['returnCode' => 200, 'notes' => $stmt->fetchAll()]);
}

if (preg_match('#^/web/api/note/([^/]+)$#', $uriPath, $m)) {
    $user = requireUser($pdo);
    $kbGuid = $user['kb_guid'];
    $docGuid = basename($m[1]);

    if ($method === 'GET') {
        $stmt = $pdo->prepare('SELECT * FROM documents WHERE kb_guid = ? AND doc_guid = ?');
        $stmt->execute([$kbGuid, $docGuid]);
        $doc = $stmt->fetch();
        if (!$doc) {
            sendJson(['returnCode' => 404, 'returnMessage' => 'Note not found'], 404);
        }
        $indexPath = $notesDir . '/' . $docGuid . '/index.html';
        $html = file_exists($indexPath) ? (file_get_contents($indexPath) ?: '') : '';

        $attStmt = $pdo->prepare('SELECT att_guid, name, data_size, data_modified_ms FROM attachments WHERE doc_guid = ? ORDER BY name ASC');
        $attStmt->execute([$docGuid]);
        $attachments = $attStmt->fetchAll();

        sendJson([
            'returnCode' => 200,
            'note' => formatDocRow($doc),
            'html' => $html,
            'attachments' => $attachments,
        ]);
    } elseif ($method === 'POST') {
        $params = getRequestParams();
        $title = trim((string)($params['title'] ?? 'Untitled'));
        $category = trim((string)($params['category'] ?? '/My Notes/'));
        if (!str_starts_with($category, '/')) {
            $category = '/' . $category;
        }
        if (!str_ends_with($category, '/')) {
            $category .= '/';
        }
        $html = (string)($params['html'] ?? '');

        $stmt = $pdo->prepare('SELECT * FROM documents WHERE doc_guid = ?');
        $stmt->execute([$docGuid]);
        $existing = $stmt->fetch();

        $ver = nextVersion($pdo, $kbGuid, 'doc_version');
        $nowMs = (int)(microtime(true) * 1000);
        $createdMs = $existing ? (int)$existing['created_ms'] : $nowMs;

        $docFolder = $notesDir . '/' . $docGuid;
        if (!is_dir($docFolder)) {
            mkdir($docFolder, 0775, true);
        }
        file_put_contents($docFolder . '/index.html', $html);
        $plain = stripHtmlText($html);
        $abstract = mb_substr($plain, 0, 200);
        $searchText = $title . ' ' . $plain;
        $dataMd5 = md5($html);
        $infoMd5 = md5($title . $category . $nowMs);

        $stmt = $pdo->prepare('
            INSERT OR REPLACE INTO documents (
                doc_guid, kb_guid, title, category, name, seo, url, author, keywords,
                type, owner, file_type, style_guid,
                created_ms, modified_ms, accessed_ms,
                icon_index, protected, read_count, attachment_count,
                info_modified_ms, info_md5, data_modified_ms, data_md5,
                param_modified_ms, param_md5, version, data_size,
                tags_str, abstract_text, search_text
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $docGuid,
            $kbGuid,
            $title,
            $category,
            $existing['name'] ?? '',
            $existing['seo'] ?? '',
            $existing['url'] ?? '',
            $existing['author'] ?? '',
            $existing['keywords'] ?? '',
            $existing['type'] ?? 'document',
            $user['user_id'],
            $existing['file_type'] ?? '',
            $existing['style_guid'] ?? '',
            $createdMs,
            $nowMs,
            $nowMs,
            (int)($existing['icon_index'] ?? 0),
            (int)($existing['protected'] ?? 0),
            (int)($existing['read_count'] ?? 0),
            (int)($existing['attachment_count'] ?? 0),
            $nowMs,
            $infoMd5,
            $nowMs,
            $dataMd5,
            (int)($existing['param_modified_ms'] ?? $nowMs),
            $existing['param_md5'] ?? '',
            $ver,
            strlen($html),
            $existing['tags_str'] ?? '',
            $abstract,
            $searchText,
        ]);

        sendJson(['returnCode' => 200, 'version' => $ver, 'docGuid' => $docGuid]);
    } elseif ($method === 'DELETE') {
        $ver = nextVersion($pdo, $kbGuid, 'deleted_version');
        $nowMs = (int)(microtime(true) * 1000);
        $pdo->prepare('INSERT OR REPLACE INTO deleted_guids (deleted_guid, kb_guid, guid_type, deleted_ms, version) VALUES (?, ?, "document", ?, ?)')
            ->execute([$docGuid, $kbGuid, $nowMs, $ver]);
        $pdo->prepare('DELETE FROM documents WHERE doc_guid = ?')->execute([$docGuid]);
        $pdo->prepare('DELETE FROM document_tags WHERE doc_guid = ?')->execute([$docGuid]);
        sendJson(['returnCode' => 200, 'version' => $ver]);
    }
}

// Catch-all for any unimplemented API path under /as/, /ks/, or /a/
if (str_starts_with($uriPath, '/as/') || str_starts_with($uriPath, '/ks/') || str_starts_with($uriPath, '/a/')) {
    sendJson(['returnCode' => 200, 'result' => []]);
}

// ============================================================================
// BUILT-IN RESPONSIVE WEB APP UI (GET /)
// ============================================================================
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<title>WizNote Private Server</title>
<style>
  :root {
    --bg: #0f172a;
    --panel: #1e293b;
    --panel-alt: #334155;
    --text: #f8fafc;
    --muted: #94a3b8;
    --accent: #38bdf8;
    --border: #334155;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    background: var(--bg);
    color: var(--text);
    height: 100vh;
    overflow: hidden;
  }
  #login-view {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100vh;
  }
  .login-card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 32px;
    width: 100%;
    max-width: 380px;
    box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5);
  }
  .login-card h1 { margin: 0 0 8px; font-size: 22px; }
  .login-card p { margin: 0 0 20px; color: var(--muted); font-size: 14px; }
  .form-group { margin-bottom: 16px; }
  .form-group label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 6px; }
  .form-group input, .form-group select {
    width: 100%;
    padding: 10px 12px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: var(--bg);
    color: var(--text);
    font-size: 14px;
  }
  .btn {
    background: var(--accent);
    color: #0f172a;
    border: none;
    border-radius: 8px;
    padding: 9px 14px;
    font-weight: 600;
    cursor: pointer;
    font-size: 13px;
  }
  .btn-secondary {
    background: var(--panel-alt);
    color: var(--text);
  }
  #app-view {
    display: none;
    grid-template-columns: 250px 330px 1fr;
    height: 100vh;
  }
  .sidebar {
    background: var(--bg);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }
  .sidebar-header {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: 600;
  }
  .sidebar-scroll {
    flex: 1;
    overflow-y: auto;
    padding: 8px;
  }
  .section-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--muted);
    padding: 10px 8px 4px;
  }
  .nav-item {
    padding: 7px 10px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: #e2e8f0;
  }
  .nav-item:hover, .nav-item.active {
    background: var(--panel);
    color: var(--accent);
  }
  .nav-count {
    font-size: 11px;
    color: var(--muted);
  }
  .note-list-pane {
    background: var(--panel);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }
  .search-bar {
    padding: 12px;
    border-bottom: 1px solid var(--border);
    display: flex;
    gap: 8px;
  }
  .search-bar input {
    flex: 1;
    padding: 8px 10px;
    border-radius: 6px;
    border: 1px solid var(--border);
    background: var(--bg);
    color: var(--text);
    font-size: 13px;
  }
  .note-list {
    flex: 1;
    overflow-y: auto;
  }
  .note-card {
    padding: 12px 14px;
    border-bottom: 1px solid var(--border);
    cursor: pointer;
  }
  .note-card:hover, .note-card.active {
    background: rgba(56, 189, 248, 0.1);
  }
  .note-card-title {
    font-weight: 600;
    font-size: 14px;
    margin-bottom: 4px;
    word-break: break-word;
  }
  .note-card-meta {
    font-size: 11px;
    color: var(--muted);
    margin-bottom: 4px;
  }
  .note-card-abstract {
    font-size: 12px;
    color: #cbd5e1;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .viewer-pane {
    display: flex;
    flex-direction: column;
    background: #ffffff;
    color: #0f172a;
    overflow: hidden;
  }
  .viewer-header {
    background: var(--panel);
    color: var(--text);
    padding: 12px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
  }
  .viewer-title {
    font-size: 17px;
    font-weight: 600;
    margin: 0;
  }
  .viewer-sub {
    font-size: 12px;
    color: var(--muted);
    margin-top: 3px;
  }
  .att-bar {
    background: #f1f5f9;
    border-bottom: 1px solid #cbd5e1;
    padding: 8px 20px;
    display: none;
    flex-wrap: wrap;
    gap: 8px;
  }
  .att-chip {
    background: #e2e8f0;
    color: #0f172a;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 12px;
    text-decoration: none;
    font-weight: 500;
  }
  .att-chip:hover { background: #cbd5e1; }
  #note-iframe {
    flex: 1;
    border: none;
    width: 100%;
    background: #fff;
  }
  #editor-modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.7);
    align-items: center;
    justify-content: center;
    z-index: 50;
  }
  .editor-box {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 10px;
    width: 90%;
    max-width: 860px;
    height: 85vh;
    display: flex;
    flex-direction: column;
    padding: 20px;
    gap: 12px;
  }
  .editor-row {
    display: flex;
    gap: 10px;
  }
  .editor-row input {
    padding: 9px 12px;
    border-radius: 6px;
    border: 1px solid var(--border);
    background: var(--bg);
    color: var(--text);
    font-size: 14px;
  }
  #edit-body {
    flex: 1;
    width: 100%;
    border-radius: 6px;
    border: 1px solid var(--border);
    background: #ffffff;
    color: #0f172a;
    padding: 14px;
    font-family: monospace;
    font-size: 14px;
    overflow-y: auto;
  }
  @media (max-width: 900px) {
    #app-view { grid-template-columns: 1fr; }
    .sidebar, .note-list-pane { height: auto; max-height: 30vh; }
  }
</style>
</head>
<body>

<div id="login-view">
  <form class="login-card" onsubmit="doLogin(event)">
    <h1>WizNote Private</h1>
    <p>Sign in to your private note server</p>
    <div id="login-err" style="color:#f87171;font-size:13px;margin-bottom:12px;display:none;"></div>
    <div class="form-group">
      <label>Email / User ID</label>
      <input type="text" id="login-user" required autocomplete="username">
    </div>
    <div class="form-group">
      <label>Password</label>
      <input type="password" id="login-pass" required autocomplete="current-password">
    </div>
    <button type="submit" class="btn" style="width:100%;">Sign In</button>
  </form>
</div>

<div id="app-view">
  <div class="sidebar">
    <div class="sidebar-header">
      <span>WizNote</span>
      <div style="display:flex;gap:6px;">
        <button class="btn" onclick="openEditor(true)">+ New</button>
        <button class="btn btn-secondary" onclick="doLogout()">Exit</button>
      </div>
    </div>
    <div class="sidebar-scroll">
      <div class="nav-item active" id="nav-all" onclick="selectFilter('', '')">
        <span>All Notes</span>
        <span class="nav-count" id="total-count">0</span>
      </div>
      <div class="section-label">Folders</div>
      <div id="folder-list"></div>
      <div class="section-label">Tags</div>
      <div id="tag-list"></div>
    </div>
  </div>

  <div class="note-list-pane">
    <div class="search-bar">
      <input type="search" id="search-input" placeholder="Search title or content..." oninput="debounceSearch()">
    </div>
    <div class="note-list" id="note-list"></div>
  </div>

  <div class="viewer-pane">
    <div class="viewer-header">
      <div>
        <h2 class="viewer-title" id="view-title">Select a note</h2>
        <div class="viewer-sub" id="view-meta"></div>
      </div>
      <div id="view-actions" style="display:none;gap:8px;">
        <button class="btn btn-secondary" onclick="openEditor(false)">Edit</button>
      </div>
    </div>
    <div class="att-bar" id="att-bar"></div>
    <iframe id="note-iframe" sandbox="allow-same-origin allow-popups"></iframe>
  </div>
</div>

<div id="editor-modal">
  <div class="editor-box">
    <div class="editor-row">
      <input type="text" id="edit-title" placeholder="Note title (use .md suffix for Markdown)" style="flex:2;">
      <input type="text" id="edit-category" placeholder="/My Notes/" style="flex:1;">
    </div>
    <div id="edit-body" contenteditable="true"></div>
    <div style="display:flex;justify-content:flex-end;gap:10px;">
      <button class="btn btn-secondary" onclick="closeEditor()">Cancel</button>
      <button class="btn" onclick="saveNote()">Save & Sync</button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script>
let token = localStorage.getItem('wiz_token') || '';
let kbGuid = '';
let currentCategory = '';
let currentTag = '';
let currentNote = null;
let currentHtml = '';
let isNewNote = false;
let searchTimer = null;

function setTokenCookie(t) {
  document.cookie = 'wiz_token=' + encodeURIComponent(t) + '; path=/; max-age=2592000; SameSite=Lax';
}

async function api(path, opts = {}) {
  opts.headers = Object.assign({'X-Wiz-Token': token}, opts.headers || {});
  const res = await fetch(path, opts);
  return res.json();
}

async function doLogin(e) {
  e.preventDefault();
  const userId = document.getElementById('login-user').value.trim();
  const password = document.getElementById('login-pass').value;
  const data = await api('/as/user/login', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({userId, password})
  });
  if (data.returnCode === 200 && data.result && data.result.token) {
    token = data.result.token;
    localStorage.setItem('wiz_token', token);
    setTokenCookie(token);
    initApp();
  } else {
    const err = document.getElementById('login-err');
    err.textContent = data.returnMessage || 'Login failed';
    err.style.display = 'block';
  }
}

function doLogout() {
  localStorage.removeItem('wiz_token');
  document.cookie = 'wiz_token=; path=/; max-age=0';
  location.reload();
}

async function initApp() {
  if (!token) return;
  setTokenCookie(token);
  const boot = await api('/web/api/bootstrap');
  if (boot.returnCode !== 200) {
    localStorage.removeItem('wiz_token');
    return;
  }
  kbGuid = boot.user.kbGuid;
  document.getElementById('login-view').style.display = 'none';
  document.getElementById('app-view').style.display = 'grid';
  document.getElementById('total-count').textContent = boot.totalNotes;

  const fl = document.getElementById('folder-list');
  fl.innerHTML = '';
  for (const f of boot.folders) {
    const div = document.createElement('div');
    div.className = 'nav-item';
    div.innerHTML = `<span>${escapeHtml(f.category)}</span><span class="nav-count">${f.cnt}</span>`;
    div.onclick = () => selectFilter(f.category, '', div);
    fl.appendChild(div);
  }

  const tl = document.getElementById('tag-list');
  tl.innerHTML = '';
  for (const t of boot.tags) {
    const div = document.createElement('div');
    div.className = 'nav-item';
    div.innerHTML = `<span>#${escapeHtml(t.name)}</span><span class="nav-count">${t.cnt}</span>`;
    div.onclick = () => selectFilter('', t.tag_guid, div);
    tl.appendChild(div);
  }

  loadNotes();
}

function selectFilter(cat, tag, el) {
  currentCategory = cat;
  currentTag = tag;
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  if (el) el.classList.add('active');
  else document.getElementById('nav-all').classList.add('active');
  loadNotes();
}

function debounceSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(loadNotes, 220);
}

async function loadNotes() {
  const q = document.getElementById('search-input').value.trim();
  const params = new URLSearchParams({category: currentCategory, tag: currentTag, q});
  const data = await api('/web/api/notes?' + params.toString());
  const list = document.getElementById('note-list');
  list.innerHTML = '';
  if (!data.notes || !data.notes.length) {
    list.innerHTML = '<div style="padding:20px;color:#94a3b8;font-size:13px;">No notes found.</div>';
    return;
  }
  for (const n of data.notes) {
    const card = document.createElement('div');
    card.className = 'note-card';
    const dt = new Date(Number(n.data_modified_ms)).toLocaleDateString();
    const attBadge = Number(n.attachment_count) > 0 ? ` · 📎 ${n.attachment_count}` : '';
    card.innerHTML = `
      <div class="note-card-title">${escapeHtml(n.title)}</div>
      <div class="note-card-meta">${escapeHtml(n.category)} · ${dt}${attBadge}</div>
      <div class="note-card-abstract">${escapeHtml(n.abstract_text || '')}</div>
    `;
    card.onclick = () => openNote(n.doc_guid, card);
    list.appendChild(card);
  }
}

async function openNote(docGuid, cardEl) {
  document.querySelectorAll('.note-card').forEach(c => c.classList.remove('active'));
  if (cardEl) cardEl.classList.add('active');

  const data = await api('/web/api/note/' + encodeURIComponent(docGuid));
  if (data.returnCode !== 200) return;
  currentNote = data.note;
  currentHtml = data.html || '';

  document.getElementById('view-title').textContent = currentNote.title;
  document.getElementById('view-meta').textContent =
    `${currentNote.category} · Updated ${new Date(Number(currentNote.dataModified)).toLocaleString()} · v${currentNote.version}`;
  document.getElementById('view-actions').style.display = 'flex';

  const attBar = document.getElementById('att-bar');
  attBar.innerHTML = '';
  if (data.attachments && data.attachments.length > 0) {
    attBar.style.display = 'flex';
    for (const a of data.attachments) {
      const link = document.createElement('a');
      link.className = 'att-chip';
      link.href = `/ks/object/download/${encodeURIComponent(kbGuid)}/${encodeURIComponent(docGuid)}?objType=attachment&objId=${encodeURIComponent(a.att_guid)}&raw=1`;
      link.target = '_blank';
      link.textContent = `📎 ${a.name} (${Math.round(a.data_size / 1024)} KB)`;
      attBar.appendChild(link);
    }
  } else {
    attBar.style.display = 'none';
  }

  renderNoteInIframe(docGuid, currentNote.title, currentHtml);
}

function renderNoteInIframe(docGuid, title, rawHtml) {
  const iframe = document.getElementById('note-iframe');
  const baseHref = `${location.origin}/web/res/${encodeURIComponent(docGuid)}/`;

  let finalHtml = rawHtml;
  if (title.toLowerCase().endsWith('.md') && window.marked) {
    const tmp = document.createElement('div');
    let bodyMatch = rawHtml.match(/<body[^>]*>([\s\S]*?)<\/body>/i);
    tmp.innerHTML = bodyMatch ? bodyMatch[1] : rawHtml;
    tmp.querySelectorAll('div, p, br').forEach(el => {
      if (el.tagName === 'BR') el.replaceWith('\n');
      else el.append('\n');
    });
    const mdSource = tmp.textContent || '';
    const rendered = marked.parse(mdSource);
    finalHtml = `<!DOCTYPE html><html><head><meta charset="utf-8"><base href="${baseHref}">
      <style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;line-height:1.65;padding:24px;max-width:860px;margin:0 auto;color:#0f172a;}pre{background:#f1f5f9;padding:12px;border-radius:6px;overflow-x:auto;}code{font-family:monospace;}</style>
      </head><body>${rendered}</body></html>`;
  } else if (finalHtml.match(/<head[^>]*>/i)) {
    finalHtml = finalHtml.replace(/<head[^>]*>/i, match => `${match}<base href="${baseHref}">`);
  } else {
    finalHtml = `<base href="${baseHref}">` + finalHtml;
  }

  iframe.srcdoc = finalHtml;
}

function openEditor(isNew) {
  isNewNote = isNew;
  document.getElementById('editor-modal').style.display = 'flex';
  if (isNew) {
    document.getElementById('edit-title').value = '';
    document.getElementById('edit-category').value = currentCategory || '/My Notes/';
    document.getElementById('edit-body').innerHTML = '<div><br></div>';
  } else if (currentNote) {
    document.getElementById('edit-title').value = currentNote.title;
    document.getElementById('edit-category').value = currentNote.category;
    const bodyMatch = currentHtml.match(/<body[^>]*>([\s\S]*?)<\/body>/i);
    document.getElementById('edit-body').innerHTML = bodyMatch ? bodyMatch[1] : currentHtml;
  }
}

function closeEditor() {
  document.getElementById('editor-modal').style.display = 'none';
}

function uuidv4() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
    const r = Math.random() * 16 | 0;
    return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
  });
}

async function saveNote() {
  const title = document.getElementById('edit-title').value.trim() || 'Untitled';
  const category = document.getElementById('edit-category').value.trim() || '/My Notes/';
  const bodyHtml = document.getElementById('edit-body').innerHTML;
  const docGuid = isNewNote ? uuidv4() : currentNote.docGuid;
  const fullHtml = `<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"><title>${escapeHtml(title)}</title></head><body class="wiz-editor-body">${bodyHtml}</body></html>`;

  const res = await api('/web/api/note/' + encodeURIComponent(docGuid), {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({title, category, html: fullHtml})
  });
  if (res.returnCode === 200) {
    closeEditor();
    await initApp();
    openNote(docGuid);
  }
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

initApp();
</script>
</body>
</html>
