# WizServer Lite

A small, self-hosted WizNote server written in one PHP file with SQLite.

- Works with **WizQTClient** (desktop) sync, using the WizNote REST API v10.
- Includes a simple **web app** (folders, tags, search, view, edit, attachments).
- Runs on a tiny VPS (tested on 1 vCPU / 512 MB RAM) with Nginx + PHP-FPM. No Docker, no extra daemon.
- Blocks search engines (`X-Robots-Tag` header and `robots.txt`).
- Single user. There is no sign-up endpoint.

## Layout

```text
.env.example                             Template for your local deployment settings
public/index.php                         Server: sync API + web app (single file)
src/schema.sql                           SQLite database schema
src/cli_common.php                       Shared helpers and .env loader for CLI scripts
scripts/download_missing_from_wizcn.php  Save all attachments from wiz.cn to the local client folder
scripts/import_from_local.php            Build a wizdata/ folder from a local WizQTClient profile
scripts/deploy.php                       Deploy or update a remote server from your local machine
scripts/switch_local_client.php          Point local WizQTClient at the private server (or back to wiz.cn)
config/nginx-wizserver.conf.example      Nginx site template
```

## Configuration (`.env`)

Copy `.env.example` to `.env` once and fill in your real values (`.env` is in `.gitignore` and never committed):

```bash
cp .env.example .env
```

Settings in `.env`:

| Variable | Where it points | Used for |
|---|---|---|
| `WIZ_DOMAIN` | Your domain (without `https://`) | Nginx `server_name` and client switch URL (`https://<WIZ_DOMAIN>`) |
| `WIZ_SSH_HOST` | Remote SSH target (`user@ip` or SSH alias) | Uploading files and running deploy commands from your local machine |
| `WIZ_DATA_DIR` | Folder **on the server** (e.g. `/home/deploy/wizdata`) | Live `wizserver.sqlite`, unpacked `notes/`, and `attachments/` |
| `WIZ_WEB_ROOT` | Folder **on the server** (e.g. `/var/www/wizserver`) | Where `public/index.php` is served by Nginx |
| `WIZ_NGINX_SITE` | Nginx site name (default: `wiz`) | `/etc/nginx/sites-available/<name>` |
| `WIZ_LOCAL_DATA` | Folder **on this computer** (e.g. `~/.wiznote/<account>/data`) | Local **WizQTClient** profile for migration and client switching |

All scripts read defaults from `.env`. You can still override them with CLI arguments if needed.

## Data folder (`WIZ_DATA_DIR`)

```text
wizdata/
  wizserver.sqlite        users, tokens, documents, tags, attachments, params, kv settings
  notes/<docGuid>/index.html
  notes/<docGuid>/index_files/*      images and other note resources
  attachments/<attGuid>              raw attachment files
```

Notes are stored unpacked (not as zip files). The sync API sends `html` as JSON and
each resource as a separate download, so unpacked files are simpler and faster.
WizQTClient packs them back into zip files on the desktop side.

`wizdata/` holds private notes and a password hash. **Never commit it.** It is in `.gitignore`.

## Requirements (local computer)

PHP 8.3 CLI with `pdo_sqlite`, `zip`, `curl`, and `mbstring`, plus `rsync` and `ssh`:

```bash
sudo apt install -y php8.3-cli php8.3-sqlite3 php8.3-zip php8.3-curl php8.3-mbstring rsync
```

---

## Workflow 1: Deploy a new WizServer (fresh VPS)

1. **Install server packages** (once on the new Ubuntu VPS):
   ```bash
   sudo apt update && sudo apt install -y nginx certbot python3-certbot-nginx php8.3-fpm php8.3-sqlite3 php8.3-zip php8.3-mbstring
   ```
2. **Prepare local notes** (close WizQTClient first):
   ```bash
   # Back up your local profile first
   tar -czf ~/wiznote-backup-$(date +%Y%m%d).tar.gz -C ~ .wiznote

   # Download any attachments that exist only on wiz.cn
   php scripts/download_missing_from_wizcn.php --insecure

   # Build ./wizdata in this repo (uses WIZ_LOCAL_DATA from .env)
   php scripts/import_from_local.php
   ```
   *(Make sure WizQTClient synced once with `wiz.cn` before running `import_from_local.php`. The login password on your new server is copied from your local profile.)*
3. **Deploy app, data, and Nginx config** from your local machine:
   ```bash
   php scripts/deploy.php --init
   ```
   This uploads `public/`, uploads `./wizdata/` to `WIZ_DATA_DIR`, generates `/etc/nginx/sites-available/<WIZ_NGINX_SITE>` from the template, sets `www-data` permissions, and reloads Nginx + PHP-FPM.
   *(Safety check: `--init` refuses to run if `wizserver.sqlite` already exists on the server unless you pass `--force-data`.)*
4. **Enable HTTPS** (once on the server):
   ```bash
   sudo certbot --nginx -d your-domain
   ```
5. **Switch desktop WizQTClient** to your new server (close WizQTClient first):
   ```bash
   php scripts/switch_local_client.php private
   ```

---

## Workflow 2: Update an existing WizServer

After editing `public/index.php` (server API or web UI fixes), run **one command from your local machine**:

```bash
php scripts/deploy.php
```

- Updates **only** `<WIZ_WEB_ROOT>/public/index.php` and reloads PHP-FPM.
- **Never touches `WIZ_DATA_DIR`**, so your live `wizserver.sqlite`, notes, and attachments stay safe.

If you also changed `config/nginx-wizserver.conf.example` and want to update the server's Nginx config:

```bash
php scripts/deploy.php --nginx
```

To preview exact commands and the generated Nginx config before running either workflow:

```bash
php scripts/deploy.php --dry-run
php scripts/deploy.php --init --dry-run
```

---

## Connect clients

**Desktop (WizQTClient):** close the app, then:

```bash
php scripts/switch_local_client.php private
```

Open WizQTClient and click Sync. To switch back to `wiz.cn`:

```bash
php scripts/switch_local_client.php wizcn
```

**Web:** open `https://<WIZ_DOMAIN>` and sign in.

**Mobile app:** log out, choose "Private / Enterprise server" on the login screen,
enter `https://<WIZ_DOMAIN>` and your account. (Mobile sync is less tested than desktop.)

## Backup

```bash
# On the server
sqlite3 /home/deploy/wizdata/wizserver.sqlite ".backup '/tmp/wizserver.sqlite'"
tar -czf ~/wizdata-$(date +%Y%m%d).tar.gz -C /home/deploy wizdata
```

## Known limits

- Single user, single knowledge base. No groups or business accounts.
- Encrypted notes are not supported.
- `/ks/object/download/` works without a token so the client can fetch resource URLs.
  GUIDs are hard to guess, but treat the server as private.
- Unknown `/as/`, `/ks/`, `/a/` API paths return an empty success result.

## Debugging sync

WizQTClient writes a log to `~/.wiznote/log/wiznote.log`. Errors like
`Can't parse result` usually mean an API path fell through to the web app HTML.
Add or fix the route in `public/index.php`.
