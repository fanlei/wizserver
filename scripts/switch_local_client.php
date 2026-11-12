<?php
declare(strict_types=1);

/**
 * Point a local WizQTClient profile at a private WizServer Lite server, or back to wiz.cn.
 *
 * Close WizQTClient before running this script.
 *
 * Usage:
 *   php scripts/switch_local_client.php private https://notes.example.com ~/.wiznote/<account>/data
 *   php scripts/switch_local_client.php wizcn ~/.wiznote/<account>/data
 *
 * How it works:
 *   WizQTClient keeps server settings in WIZ_META inside <data>/index.db.
 *   - SERVERTYPE 199 = enterprise/private server, 99 = official wiz.cn
 *   - ENTERPRISESERVERIP must be set, or the login dialog refuses to switch an
 *     existing wiz.cn profile to a private server.
 */

require __DIR__ . '/../src/cli_common.php';

const WIZCN_KB_SERVER = 'https://hk01.wiz.cn';

function usage(): never
{
    fail(
        "Usage:\n"
        . "  php scripts/switch_local_client.php private <server_url> <data_dir>\n"
        . "  php scripts/switch_local_client.php wizcn <data_dir>"
    );
}

[$args] = parseArgs($argv);
$mode = $args[0] ?? '';

if ($mode === 'private') {
    if (count($args) < 3) {
        usage();
    }
    $serverUrl = rtrim($args[1], '/');
    if (!preg_match('#^https?://#', $serverUrl)) {
        fail("Server URL must start with http:// or https://");
    }
    $db = openClientIndexDb($args[2]);

    $upsert = $db->prepare("
        INSERT OR REPLACE INTO WIZ_META (META_NAME, META_KEY, META_VALUE, DT_MODIFIED)
        VALUES ('QT_WIZNOTE', ?, ?, datetime('now'))
    ");
    $db->beginTransaction();
    $upsert->execute(['SERVERTYPE', '199']);
    $upsert->execute(['ENTERPRISESERVERIP', $serverUrl]);
    $db->prepare("UPDATE WIZ_META SET META_VALUE = ? WHERE META_NAME = 'ACCOUNT' AND META_KEY = 'KBSERVER'")
        ->execute([$serverUrl]);
    $db->commit();

    echo "Switched local WizQTClient profile to private server: {$serverUrl}\n";
} elseif ($mode === 'wizcn') {
    if (count($args) < 2) {
        usage();
    }
    $db = openClientIndexDb($args[1]);

    $db->beginTransaction();
    $db->exec("UPDATE WIZ_META SET META_VALUE = '99' WHERE META_NAME = 'QT_WIZNOTE' AND META_KEY = 'SERVERTYPE'");
    $db->exec("DELETE FROM WIZ_META WHERE META_NAME = 'QT_WIZNOTE' AND META_KEY = 'ENTERPRISESERVERIP'");
    $db->prepare("UPDATE WIZ_META SET META_VALUE = ? WHERE META_NAME = 'ACCOUNT' AND META_KEY = 'KBSERVER'")
        ->execute([WIZCN_KB_SERVER]);
    $db->commit();

    echo "Reverted local WizQTClient profile to wiz.cn\n";
} else {
    usage();
}
