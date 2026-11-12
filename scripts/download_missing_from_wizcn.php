<?php
declare(strict_types=1);

/**
 * Download attachments that are listed in the local WizQTClient index but missing on disk.
 *
 * WizQTClient downloads attachments on demand, so a local profile usually has only a few
 * attachment files. Run this BEFORE leaving wiz.cn so all attachments are saved locally.
 *
 * The account and saved password are read from <data>/index.db (WIZ_META),
 * so no credentials are stored in this repo.
 *
 * Usage:
 *   php scripts/download_missing_from_wizcn.php ~/.wiznote/<account>/data [--insecure]
 *
 *   --insecure   Skip TLS certificate checks (some wiz.cn KB servers had expired certs).
 */

require __DIR__ . '/../src/cli_common.php';

const AS_LOGIN_URL = 'https://as.wiz.cn/as/user/login';
const CLIENT_PARAMS = 'clientType=macos&clientVersion=2.8.7&apiVersion=10';
const PARALLEL = 6;

[$args, $flags] = parseArgs($argv);
if (count($args) < 1) {
    fail("Usage: php scripts/download_missing_from_wizcn.php <data_dir> [--insecure]");
}
$insecure = isset($flags['insecure']);
$dataDir = rtrim(expandHome($args[0]), '/');
$attDir = $dataDir . '/attachments';
if (!is_dir($attDir) && !mkdir($attDir, 0775, true)) {
    fail("Cannot create {$attDir}");
}

function curlBase(string $url, bool $insecure): CurlHandle
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 120,
    ]);
    if ($insecure) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }
    return $ch;
}

// 1. Read account from the local client profile.
$clientDb = openClientIndexDb($dataDir);
$meta = readWizMeta($clientDb);
$userId = $meta['ACCOUNT']['USERID'] ?? '';
$password = base64_decode($meta['ACCOUNT']['PASSWORD'] ?? '', true);
$kbGuid = $meta['DATABASE']['KBGUID'] ?? '';
if ($userId === '' || $password === false || $password === '' || $kbGuid === '') {
    fail('Account, saved password, or KB GUID not found in WIZ_META. Log in once with "remember password".');
}

// 2. Log in to wiz.cn.
$ch = curlBase(AS_LOGIN_URL, $insecure);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['userId' => $userId, 'password' => $password]));
$body = curl_exec($ch);
if ($body === false) {
    fail('Login request failed: ' . curl_error($ch));
}
$login = json_decode((string)$body, true);
if (($login['returnCode'] ?? 0) !== 200) {
    fail('Login failed: ' . ($login['returnMessage'] ?? 'unknown error'));
}
$token = (string)$login['result']['token'];
$kbServer = rtrim((string)$login['result']['kbServer'], '/');
echo "Logged in as {$userId}, KB server: {$kbServer}\n";

// 3. Build the list of missing attachments.
$jobs = [];
$skipped = 0;
$rows = $clientDb->query('SELECT ATTACHMENT_GUID, DOCUMENT_GUID, ATTACHMENT_NAME FROM WIZ_DOCUMENT_ATTACHMENT');
foreach ($rows as $r) {
    $name = (string)($r['ATTACHMENT_NAME'] ?: 'attachment');
    $dest = $attDir . '/{' . $r['ATTACHMENT_GUID'] . '}' . $name;
    if (is_file($dest) && filesize($dest) > 0) {
        $skipped++;
        continue;
    }
    $jobs[] = [
        'name' => $name,
        'dest' => $dest,
        'url' => "{$kbServer}/ks/object/download/{$kbGuid}/{$r['DOCUMENT_GUID']}"
            . "?objType=attachment&objId={$r['ATTACHMENT_GUID']}&" . CLIENT_PARAMS,
    ];
}
echo count($jobs) . " attachment(s) to download, {$skipped} already on disk.\n";

/**
 * The server wraps each attachment in a one-file zip. Save the inner file.
 */
function saveUnzipped(string $zipBytes, string $dest): ?string
{
    $tmp = tempnam(sys_get_temp_dir(), 'wizatt_');
    file_put_contents($tmp, $zipBytes);
    $za = new ZipArchive();
    $opened = $za->open($tmp);
    if ($opened !== true) {
        @unlink($tmp);
        return 'response is not a zip file (code ' . $opened . ')';
    }
    $raw = $za->getFromIndex(0);
    $za->close();
    @unlink($tmp);
    if ($raw === false) {
        return 'zip file is empty';
    }
    if (file_put_contents($dest, $raw) === false) {
        return "cannot write {$dest}";
    }
    return null;
}

// 4. Download with a small rolling window of parallel requests.
$ok = 0;
$errors = [];
$mh = curl_multi_init();
$active = [];   // (int)handle id => job
$queue = $jobs;

$startNext = function () use (&$queue, &$active, $mh, $token, $insecure): void {
    while (count($active) < PARALLEL && $queue) {
        $job = array_shift($queue);
        $ch = curlBase($job['url'], $insecure);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Wiz-Token: ' . $token]);
        curl_multi_add_handle($mh, $ch);
        $active[spl_object_id($ch)] = ['job' => $job, 'ch' => $ch];
    }
};

$startNext();
while ($active) {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh, 1.0);
    while ($info = curl_multi_info_read($mh)) {
        $ch = $info['handle'];
        $id = spl_object_id($ch);
        $job = $active[$id]['job'];
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $content = curl_multi_getcontent($ch);

        if ($info['result'] !== CURLE_OK) {
            $errors[] = [$job['name'], curl_error($ch)];
        } elseif ($status !== 200) {
            $errors[] = [$job['name'], "HTTP {$status}"];
        } else {
            $err = saveUnzipped((string)$content, $job['dest']);
            if ($err === null) {
                $ok++;
            } else {
                $errors[] = [$job['name'], $err];
            }
        }

        curl_multi_remove_handle($mh, $ch);
        unset($active[$id]);
        $startNext();
    }
}
curl_multi_close($mh);

echo "Downloaded: {$ok}, Skipped (already on disk): {$skipped}, Errors: " . count($errors) . "\n";
foreach ($errors as [$name, $msg]) {
    echo "  {$name}: {$msg}\n";
}
exit($errors ? 1 : 0);
