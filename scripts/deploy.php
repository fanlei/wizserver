<?php
declare(strict_types=1);

/**
 * Deploy or update WizServer Lite on a remote server using settings from .env.
 *
 * Run this script from your local machine inside the repo.
 *
 * Workflows:
 *   1. First-time deploy of a new server:
 *        php scripts/deploy.php --init
 *      Uploads public/, uploads ./wizdata/ to WIZ_DATA_DIR, writes the Nginx site
 *      config from config/nginx-wizserver.conf.example, sets permissions, and reloads services.
 *
 *   2. Update code on an existing server (never touches WIZ_DATA_DIR):
 *        php scripts/deploy.php
 *
 *   3. Update Nginx config as well on an existing server:
 *        php scripts/deploy.php --nginx
 *
 * Other flags:
 *   --dry-run      Print commands and generated Nginx config without executing them.
 *   --skip-data    With --init, skip uploading ./wizdata/ (only app + Nginx).
 *   --force-data   With --init, allow overwriting an existing remote wizserver.sqlite.
 */

require __DIR__ . '/../src/cli_common.php';

[$args, $flags] = parseArgs($argv);
if (isset($flags['help'])) {
    echo "Usage:\n"
        . "  php scripts/deploy.php           Update public/index.php only (safe for live servers)\n"
        . "  php scripts/deploy.php --init    First-time deploy: app + wizdata + Nginx + permissions\n"
        . "  php scripts/deploy.php --nginx   Also regenerate and reload the Nginx site config\n"
        . "  php scripts/deploy.php --dry-run Show commands without running them\n";
    exit(0);
}

$dryRun = isset($flags['dry-run']);
$isInit = isset($flags['init']);
$updateNginx = $isInit || isset($flags['nginx']);
$uploadData = $isInit && !isset($flags['skip-data']);
$forceData = isset($flags['force-data']);

$domain = requireEnv('WIZ_DOMAIN');
$sshHost = requireEnv('WIZ_SSH_HOST');
$dataDir = rtrim(requireEnv('WIZ_DATA_DIR'), '/');
$webRoot = rtrim(requireEnv('WIZ_WEB_ROOT'), '/');
$nginxSite = envValue('WIZ_NGINX_SITE', 'wiz');

// Validate Nginx site filename so it stays a simple config name
if (!preg_match('/^[A-Za-z0-9._-]+$/', $nginxSite)) {
    fail("Invalid WIZ_NGINX_SITE value: {$nginxSite}");
}

$root = repoRoot();
$localPublic = $root . '/public';
$localData = $root . '/wizdata';
$nginxTemplate = $root . '/config/nginx-wizserver.conf.example';

if (!is_file($localPublic . '/index.php')) {
    fail("Missing {$localPublic}/index.php");
}

function runCmd(string $cmd, bool $dryRun): int
{
    echo "+ {$cmd}\n";
    if ($dryRun) {
        return 0;
    }
    passthru($cmd, $code);
    return $code;
}

function mustRun(string $cmd, bool $dryRun): void
{
    $code = runCmd($cmd, $dryRun);
    if ($code !== 0) {
        fail("Command failed (exit {$code}): {$cmd}", $code);
    }
}

/**
 * Render Nginx site config from the example template using .env values.
 */
function renderNginxConfig(string $templatePath, string $domain, string $webRoot, string $dataDir): string
{
    $raw = file_get_contents($templatePath);
    if ($raw === false) {
        fail("Cannot read {$templatePath}");
    }
    return strtr($raw, [
        'notes.example.com' => $domain,
        '/var/www/wizserver/public' => $webRoot . '/public',
        '/home/deploy/wizdata' => $dataDir,
    ]);
}

// ---------------------------------------------------------------------------
// 1. Pre-flight check for --init data upload (protect existing live database)
// ---------------------------------------------------------------------------
if ($uploadData) {
    if (!is_file($localData . '/wizserver.sqlite')) {
        fail("Missing {$localData}/wizserver.sqlite. Run `php scripts/import_from_local.php` first.");
    }
    if (!$forceData) {
        $checkCmd = sprintf(
            'ssh %s %s',
            escapeshellarg($sshHost),
            escapeshellarg('test -e ' . escapeshellarg($dataDir . '/wizserver.sqlite'))
        );
        echo "+ {$checkCmd}\n";
        if (!$dryRun) {
            exec($checkCmd, $out, $existsCode);
            if ($existsCode === 0) {
                fail(
                    "Refusing to overwrite existing {$dataDir}/wizserver.sqlite on {$sshHost}.\n"
                    . "Run without --init to update only the PHP code, or pass --force-data if you really want to replace the database."
                );
            }
        }
    }
}

// ---------------------------------------------------------------------------
// 2. Upload public/ (and wizdata/ on --init)
// ---------------------------------------------------------------------------
// Use relative paths for remote staging so SSH puts them in $HOME without relying on ~ expansion.
$remotePublicStage = 'wizserver-public-stage';
mustRun(sprintf(
    'rsync -az --delete %s %s:%s/',
    escapeshellarg($localPublic . '/'),
    escapeshellarg($sshHost),
    escapeshellarg($remotePublicStage)
), $dryRun);

if ($uploadData) {
    mustRun(sprintf(
        'ssh %s %s',
        escapeshellarg($sshHost),
        escapeshellarg('mkdir -p ' . escapeshellarg($dataDir))
    ), $dryRun);

    mustRun(sprintf(
        'rsync -az %s %s:%s/',
        escapeshellarg($localData . '/'),
        escapeshellarg($sshHost),
        escapeshellarg($dataDir)
    ), $dryRun);
}

// ---------------------------------------------------------------------------
// 3. Optional Nginx config upload
// ---------------------------------------------------------------------------
$tmpNginxLocal = null;
$remoteNginxStage = 'wizserver-nginx-stage.conf';
if ($updateNginx) {
    $nginxContent = renderNginxConfig($nginxTemplate, $domain, $webRoot, $dataDir);
    if ($dryRun) {
        echo "\n--- Generated Nginx config (/etc/nginx/sites-available/{$nginxSite}) ---\n";
        echo $nginxContent;
        echo "--- End Nginx config ---\n\n";
    } else {
        $tmpNginxLocal = tempnam(sys_get_temp_dir(), 'wiznginx_');
        if ($tmpNginxLocal === false || file_put_contents($tmpNginxLocal, $nginxContent) === false) {
            fail('Failed to write temporary Nginx config');
        }
    }
    mustRun(sprintf(
        'scp %s %s:%s',
        escapeshellarg($tmpNginxLocal ?? '/tmp/wizserver-nginx.conf'),
        escapeshellarg($sshHost),
        escapeshellarg($remoteNginxStage)
    ), $dryRun);
    if ($tmpNginxLocal !== null) {
        @unlink($tmpNginxLocal);
    }
}

// ---------------------------------------------------------------------------
// 4. Install files, set permissions, and reload services on the remote server
// ---------------------------------------------------------------------------
$remoteSteps = [
    'set -e',
    'sudo mkdir -p ' . escapeshellarg($webRoot . '/public'),
    'sudo rsync -a --delete "$HOME/wizserver-public-stage/" ' . escapeshellarg($webRoot . '/public/'),
    'sudo chown -R www-data:www-data ' . escapeshellarg($webRoot),
    'rm -rf "$HOME/wizserver-public-stage"',
];

if ($isInit) {
    // Allow www-data to traverse /home/<user> when WIZ_DATA_DIR lives under a home directory
    if (preg_match('#^(/home/[^/]+)/#', $dataDir, $m)) {
        $homeBase = $m[1];
        $remoteSteps[] = 'sudo usermod -aG "$(id -gn)" www-data';
        $remoteSteps[] = 'sudo chmod g+x ' . escapeshellarg($homeBase);
    }
    $remoteSteps[] = 'sudo mkdir -p ' . escapeshellarg($dataDir);
    $remoteSteps[] = 'sudo chown -R www-data:"$(id -gn)" ' . escapeshellarg($dataDir);
    $remoteSteps[] = 'sudo chmod -R ug+rwX ' . escapeshellarg($dataDir);
}

if ($updateNginx) {
    $avail = '/etc/nginx/sites-available/' . $nginxSite;
    $enabled = '/etc/nginx/sites-enabled/' . $nginxSite;
    $remoteSteps[] = 'sudo mv "$HOME/wizserver-nginx-stage.conf" ' . escapeshellarg($avail);
    $remoteSteps[] = 'sudo ln -sf ' . escapeshellarg($avail) . ' ' . escapeshellarg($enabled);
    $remoteSteps[] = 'sudo nginx -t';
    $remoteSteps[] = 'sudo systemctl reload nginx';
}

$remoteSteps[] = '{ sudo systemctl reload php8.3-fpm 2>/dev/null || sudo systemctl restart php8.3-fpm; }';

$remoteScript = implode(' && ', $remoteSteps);
mustRun(sprintf(
    'ssh -t %s %s',
    escapeshellarg($sshHost),
    escapeshellarg($remoteScript)
), $dryRun);

echo "\nDone!\n";
if ($isInit) {
    echo "Next (first-time HTTPS setup on the server, if not configured yet):\n";
    echo "  ssh -t {$sshHost} \"sudo certbot --nginx -d {$domain}\"\n";
} else {
    echo "Updated https://{$domain} without touching {$dataDir}.\n";
}
