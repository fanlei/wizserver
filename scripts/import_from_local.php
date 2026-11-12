<?php
declare(strict_types=1);

/**
 * Build a WizServer Lite data folder from a local WizQTClient profile.
 *
 * Output:
 *   <out>/wizserver.sqlite
 *   <out>/notes/<docGuid>/index.html  (+ index_files/*)
 *   <out>/attachments/<attGuid>
 *
 * The login password for the server is taken from the profile's saved password.
 * The source profile is only read, never changed.
 *
 * Usage:
 *   php scripts/import_from_local.php ~/.wiznote/<account>/data ./wizdata
 */

require __DIR__ . '/../src/cli_common.php';

[$args] = parseArgs($argv);
if (count($args) < 2) {
    fail("Usage: php scripts/import_from_local.php <local_wiznote_data_dir> <output_wizdata_dir>");
}

useLocalTimezone();

$srcDir = rtrim(expandHome($args[0]), '/');
$dstDir = rtrim(expandHome($args[1]), '/');
$dstNotesDir = $dstDir . '/notes';
$dstAttDir = $dstDir . '/attachments';
foreach ([$dstDir, $dstNotesDir, $dstAttDir] as $d) {
    if (!is_dir($d) && !mkdir($d, 0775, true)) {
        fail("Cannot create {$d}");
    }
}

$dstDbPath = $dstDir . '/wizserver.sqlite';
foreach (['', '-wal', '-shm'] as $suffix) {
    if (is_file($dstDbPath . $suffix)) {
        unlink($dstDbPath . $suffix);
    }
}

$src = openClientIndexDb($srcDir);
$dst = openSqlite($dstDbPath);
$dst->exec('PRAGMA journal_mode = WAL;');
$dst->exec('PRAGMA synchronous = NORMAL;');

$schema = file_get_contents(__DIR__ . '/../src/schema.sql');
if ($schema === false) {
    fail('Cannot read src/schema.sql');
}
$dst->exec($schema);

$dst->beginTransaction();

// ---------------------------------------------------------------------------
// 1. Account
// ---------------------------------------------------------------------------
$meta = readWizMeta($src);
$metaInt = static fn (string $name, string $key, int $default = 1): int
    => (int)(($meta[$name][$key] ?? '') !== '' ? $meta[$name][$key] : $default);

$userId = $meta['ACCOUNT']['USERID'] ?? 'user@example.com';
$userGuid = $meta['ACCOUNT']['GUID'] ?? '00000000-0000-0000-0000-000000000001';
$kbGuid = $meta['DATABASE']['KBGUID'] ?? '00000000-0000-0000-0000-000000000002';
$displayName = $meta['ACCOUNT']['DISPLAYNAME'] ?? 'User';
$mywizEmail = $meta['ACCOUNT']['MYWIZMAIL'] ?? '';
$rawPassword = base64_decode($meta['ACCOUNT']['PASSWORD'] ?? '', true);
if ($rawPassword === false || $rawPassword === '') {
    $rawPassword = 'changeme';
    fwrite(STDERR, "Warning: no saved password in the profile. Server password set to 'changeme'.\n");
}

$dst->prepare('
    INSERT INTO users (user_guid, user_id, email, password_hash, password_plain_md5, display_name, kb_guid, mywiz_email, created_ms)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
')->execute([
    $userGuid,
    $userId,
    $userId,
    hash('sha256', $rawPassword),
    'md5.' . md5($rawPassword),
    $displayName,
    $kbGuid,
    $mywizEmail,
    (int)(microtime(true) * 1000),
]);

/**
 * Rows with version <= 0 were never synced. Give them new versions above the max.
 */
$assignVersion = static function ($raw, int &$max): int {
    $ver = (int)($raw ?? 1);
    if ($ver <= 0) {
        $max++;
        $ver = $max;
    }
    $max = max($max, $ver);
    return $ver;
};

// ---------------------------------------------------------------------------
// 2. Tags and document-tag links
// ---------------------------------------------------------------------------
$maxTagVer = $metaInt('SYNC_INFO', 'TAG');
$insTag = $dst->prepare('
    INSERT INTO tags (tag_guid, kb_guid, parent_tag_guid, name, description, modified_ms, version, pos)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
');
foreach ($src->query('SELECT * FROM WIZ_TAG') as $r) {
    $insTag->execute([
        $r['TAG_GUID'],
        $kbGuid,
        $r['TAG_GROUP_GUID'] ?? '',
        $r['TAG_NAME'] ?? '',
        $r['TAG_DESCRIPTION'] ?? '',
        tsToMs($r['DT_MODIFIED']),
        $assignVersion($r['WIZ_VERSION'], $maxTagVer),
        (int)($r['TAG_POS'] ?? 0),
    ]);
}

$docTags = [];
$insDocTag = $dst->prepare('INSERT OR IGNORE INTO document_tags (doc_guid, tag_guid) VALUES (?, ?)');
foreach ($src->query('SELECT DOCUMENT_GUID, TAG_GUID FROM WIZ_DOCUMENT_TAG') as $r) {
    $insDocTag->execute([$r['DOCUMENT_GUID'], $r['TAG_GUID']]);
    $docTags[$r['DOCUMENT_GUID']][] = $r['TAG_GUID'];
}

// ---------------------------------------------------------------------------
// 3. Document params
// ---------------------------------------------------------------------------
$maxParamVer = $metaInt('SYNC_INFO', 'PARAM');
$insParam = $dst->prepare('INSERT OR REPLACE INTO params (kb_guid, doc_guid, name, value, version) VALUES (?, ?, ?, ?, ?)');
foreach ($src->query('SELECT * FROM WIZ_DOCUMENT_PARAM') as $r) {
    $insParam->execute([
        $kbGuid,
        $r['DOCUMENT_GUID'],
        $r['PARAM_NAME'],
        $r['PARAM_VALUE'] ?? '',
        $assignVersion($r['WIZ_VERSION'], $maxParamVer),
    ]);
}

// ---------------------------------------------------------------------------
// 4. Attachments (client file name: {attGuid}name  ->  server: attachments/attGuid)
// ---------------------------------------------------------------------------
$maxAttVer = $metaInt('SYNC_INFO', 'ATTACHMENT');
$srcAttDir = $srcDir . '/attachments';
$totalStorage = 0;
$attCount = 0;
$attMissing = [];
$insAtt = $dst->prepare('
    INSERT INTO attachments (
        att_guid, doc_guid, kb_guid, name, url, description,
        info_modified_ms, info_md5, data_modified_ms, data_md5, version, data_size
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
');
foreach ($src->query('SELECT * FROM WIZ_DOCUMENT_ATTACHMENT') as $r) {
    $attGuid = (string)$r['ATTACHMENT_GUID'];
    $attName = (string)($r['ATTACHMENT_NAME'] ?: 'attachment');
    $srcFile = $srcAttDir . '/{' . $attGuid . '}' . $attName;
    $dstFile = $dstAttDir . '/' . $attGuid;

    $size = 0;
    if (is_file($srcFile)) {
        if (!copy($srcFile, $dstFile)) {
            fail("Cannot copy {$srcFile}");
        }
        $size = (int)filesize($dstFile);
        $totalStorage += $size;
        $attCount++;
    } else {
        $attMissing[] = $attName;
    }

    $insAtt->execute([
        $attGuid,
        $r['DOCUMENT_GUID'],
        $kbGuid,
        $attName,
        $r['ATTACHMENT_URL'] ?? '',
        $r['ATTACHMENT_DESCRIPTION'] ?? '',
        tsToMs($r['DT_INFO_MODIFIED']),
        $r['ATTACHMENT_INFO_MD5'] ?? '',
        tsToMs($r['DT_DATA_MODIFIED']),
        $r['ATTACHMENT_DATA_MD5'] ?? '',
        $assignVersion($r['WIZ_VERSION'], $maxAttVer),
        $size,
    ]);
}

// ---------------------------------------------------------------------------
// 5. Documents (client: notes/{docGuid} zip  ->  server: notes/docGuid/ unpacked)
// ---------------------------------------------------------------------------
$maxDocVer = $metaInt('SYNC_INFO', 'DOCUMENT');
$srcNotesDir = $srcDir . '/notes';
$docCount = 0;
$docMissing = 0;
$categories = [];
$insDoc = $dst->prepare('
    INSERT INTO documents (
        doc_guid, kb_guid, title, category, name, seo, url, author, keywords,
        type, owner, file_type, style_guid,
        created_ms, modified_ms, accessed_ms,
        icon_index, protected, read_count, attachment_count,
        info_modified_ms, info_md5, data_modified_ms, data_md5,
        param_modified_ms, param_md5, version, data_size,
        tags_str, abstract_text, search_text
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
');

foreach ($src->query('SELECT * FROM WIZ_DOCUMENT') as $r) {
    $docGuid = (string)$r['DOCUMENT_GUID'];
    $category = (string)($r['DOCUMENT_LOCATION'] ?: '/My Notes/');
    $categories[$category] = true;

    $zipPath = $srcNotesDir . '/{' . $docGuid . '}';
    $outDir = $dstNotesDir . '/' . $docGuid;
    if (!is_dir($outDir)) {
        mkdir($outDir, 0775, true);
    }

    $size = 0;
    $rawHtml = '';
    if (is_file($zipPath)) {
        $size = (int)filesize($zipPath);
        $totalStorage += $size;
        $za = new ZipArchive();
        if ($za->open($zipPath) !== true) {
            fail("Cannot open note zip {$zipPath}");
        }
        for ($i = 0; $i < $za->numFiles; $i++) {
            $member = (string)$za->getNameIndex($i);
            $clean = ltrim(str_replace('\\', '/', $member), '/');
            // Skip unsafe paths (zip slip).
            if ($clean === '' || in_array('..', explode('/', $clean), true)) {
                continue;
            }
            $target = $outDir . '/' . $clean;
            if (str_ends_with($clean, '/')) {
                if (!is_dir($target)) {
                    mkdir($target, 0775, true);
                }
                continue;
            }
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }
            $content = $za->getFromIndex($i);
            if ($content === false) {
                continue;
            }
            file_put_contents($target, $content);
            if ($clean === 'index.html') {
                $rawHtml = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
            }
        }
        $za->close();
    } else {
        $docMissing++;
    }

    $title = (string)($r['DOCUMENT_TITLE'] ?: 'Untitled');
    $plain = stripHtmlText($rawHtml);

    $insDoc->execute([
        $docGuid,
        $kbGuid,
        $title,
        $category,
        $r['DOCUMENT_NAME'] ?? '',
        $r['DOCUMENT_SEO'] ?? '',
        $r['DOCUMENT_URL'] ?? '',
        $r['DOCUMENT_AUTHOR'] ?? '',
        $r['DOCUMENT_KEYWORDS'] ?? '',
        $r['DOCUMENT_TYPE'] ?: 'document',
        $r['DOCUMENT_OWNER'] ?: $userId,
        $r['DOCUMENT_FILE_TYPE'] ?? '',
        $r['STYLE_GUID'] ?? '',
        tsToMs($r['DT_CREATED']),
        tsToMs($r['DT_MODIFIED']),
        tsToMs($r['DT_ACCESSED']),
        (int)($r['DOCUMENT_ICON_INDEX'] ?? 0),
        (int)($r['DOCUMENT_PROTECT'] ?? 0),
        (int)($r['DOCUMENT_READ_COUNT'] ?? 0),
        (int)($r['DOCUMENT_ATTACHEMENT_COUNT'] ?? 0),
        tsToMs($r['DT_INFO_MODIFIED']),
        $r['DOCUMENT_INFO_MD5'] ?? '',
        tsToMs($r['DT_DATA_MODIFIED']),
        $r['DOCUMENT_DATA_MD5'] ?? '',
        tsToMs($r['DT_PARAM_MODIFIED']),
        $r['DOCUMENT_PARAM_MD5'] ?? '',
        $assignVersion($r['WIZ_VERSION'], $maxDocVer),
        $size,
        implode('*', $docTags[$docGuid] ?? []),
        mb_substr($plain, 0, 200),
        $title . ' ' . $plain,
    ]);
    $docCount++;
}

// ---------------------------------------------------------------------------
// 6. Settings (kv_store): folders, folder order, favorites
// ---------------------------------------------------------------------------
foreach (explode('\\', $meta['FOLDERS']['EXTRA'] ?? '') as $folder) {
    $folder = trim($folder);
    if ($folder !== '') {
        $categories[$folder] = true;
    }
}
$folders = array_keys($categories);
sort($folders, SORT_STRING);

$kv = [
    'folders' => [json_encode($folders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $metaInt('SYNC_INFO', 'KEY_FOLDERS_VERSION')],
    'folders_pos' => [$meta['SYNC_INFO']['FOLDERS_POS'] ?? '{}', $metaInt('SYNC_INFO', 'KEY_FOLDERS_POS_VERSION')],
    'favorites' => ['[]', $metaInt('SYNC_INFO', 'KEY_FAVORITES_VERSION')],
    'biz_users' => ['[]', 1],
];
$insKv = $dst->prepare('INSERT OR REPLACE INTO kv_store (scope_guid, key, value, version) VALUES (?, ?, ?, ?)');
foreach ([$userGuid, $kbGuid] as $scope) {
    foreach ($kv as $key => [$value, $version]) {
        $insKv->execute([$scope, $key, $value, $version]);
    }
}

// ---------------------------------------------------------------------------
// 7. Knowledge base version counters
// ---------------------------------------------------------------------------
$dst->prepare('
    INSERT INTO kb_state (kb_guid, doc_version, att_version, tag_version, style_version, param_version, deleted_version, storage_usage)
    VALUES (?, ?, ?, ?, 0, ?, ?, ?)
')->execute([
    $kbGuid,
    $maxDocVer,
    $maxAttVer,
    $maxTagVer,
    $maxParamVer,
    $metaInt('SYNC_INFO', 'DELETED_GUID'),
    $totalStorage,
]);

$dst->commit();

printf("Import complete!\n");
printf("  User: %s (kbGuid: %s)\n", $userId, $kbGuid);
printf("  Documents imported: %d (max version: %d)%s\n", $docCount, $maxDocVer,
    $docMissing ? ", {$docMissing} without a local note file" : '');
printf("  Attachments imported: %d (max version: %d)\n", $attCount, $maxAttVer);
printf("  Total storage size: %.2f MB\n", $totalStorage / 1048576);
printf("  Output directory: %s\n", $dstDir);
if ($attMissing) {
    printf("  Warning: %d attachment file(s) missing locally. Run download_missing_from_wizcn.php first.\n", count($attMissing));
}
