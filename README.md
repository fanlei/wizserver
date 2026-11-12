# WizServer Lite

A small, self-hosted WizNote server written in one PHP file with SQLite.

- Works with **WizQTClient** (desktop) sync, using the WizNote REST API v10.
- Includes a simple **web app** (folders, tags, search, view, edit, attachments).
- Runs on a tiny VPS (tested on 1 vCPU / 512 MB RAM) with Nginx + PHP-FPM. No Docker, no extra daemon.
- Blocks search engines (`X-Robots-Tag` header and `robots.txt`).
- Single user. There is no sign-up endpoint.

## Layout

```text
public/index.php                      Server: sync API + web app (single file)
scripts/download_missing_from_wizcn.py  Save all attachments from wiz.cn to the local client folder
scripts/import_from_local.py          Build a wizdata/ folder from a local WizQTClient profile
scripts/switch_local_client.py        Point local WizQTClient at the private server (or back to wiz.cn)
config/nginx-wizserver.conf.example   Nginx site template
```

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

## Migrate from wiz.cn

Run these on the computer that has WizQTClient. Replace `<data>` with
`~/.wiznote/<your-account>/data`. Close WizQTClient first.

1. Back up the local profile:
   ```bash
   tar -czf ~/wiznote-backup-$(date +%Y%m%d).tar.gz -C ~ .wiznote
   ```
2. Download all attachments (the client only downloads them on demand):
   ```bash
   python3 scripts/download_missing_from_wizcn.py <data> --insecure
   ```
3. Make sure the client is fully synced with wiz.cn (click Sync once).
4. Build the server data folder:
   ```bash
   python3 scripts/import_from_local.py <data> ./wizdata
   ```
   The login password is taken from the local client profile.

## Deploy (Ubuntu + Nginx + PHP 8.3)

```bash
# On the server
sudo apt install -y php8.3-fpm php8.3-sqlite3 php8.3-zip php8.3-mbstring

# From your computer
rsync -a public/ deploy@SERVER:/tmp/wizserver-public/
rsync -a wizdata/ deploy@SERVER:/home/deploy/wizdata/

# On the server
sudo mkdir -p /var/www/wizserver/public
sudo rsync -a /tmp/wizserver-public/ /var/www/wizserver/public/
sudo chown -R www-data:www-data /var/www/wizserver

# If the data folder is under /home/deploy, let PHP-FPM reach it
sudo usermod -aG deploy www-data
sudo chmod g+x /home/deploy
sudo chown -R www-data:deploy /home/deploy/wizdata
sudo chmod -R ug+rwX /home/deploy/wizdata
sudo systemctl restart php8.3-fpm

# Nginx + HTTPS (edit server_name first)
sudo cp config/nginx-wizserver.conf.example /etc/nginx/sites-available/wiz
sudo ln -sf /etc/nginx/sites-available/wiz /etc/nginx/sites-enabled/wiz
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d notes.example.com
```

### Update only the server code

```bash
scp public/index.php deploy@SERVER:~/index.php
# on the server:
sudo mv ~/index.php /var/www/wizserver/public/index.php
sudo chown www-data:www-data /var/www/wizserver/public/index.php
```

## Connect clients

**Desktop (WizQTClient):** close the app, then

```bash
python3 scripts/switch_local_client.py private https://notes.example.com <data>
```

Open WizQTClient and click Sync. To go back:
`python3 scripts/switch_local_client.py wizcn <data>`.

**Web:** open `https://notes.example.com` and sign in.

**Mobile app:** log out, choose "Private / Enterprise server" on the login screen,
enter the server URL and your account. (Mobile sync is less tested than desktop.)

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
