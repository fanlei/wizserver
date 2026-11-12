#!/usr/bin/env python3
"""
Download attachments that exist in the local WizQTClient index but are missing on disk.

WizQTClient downloads attachments on demand, so a local profile usually has only a few
attachment files. Run this BEFORE leaving wiz.cn so all attachments are saved locally.

The script reads the account and saved password from <data>/index.db (WIZ_META),
so no credentials are stored in this repo.

Usage:
    python3 scripts/download_missing_from_wizcn.py ~/.wiznote/<user@example.com>/data [--insecure]

    --insecure   Skip TLS certificate checks (some wiz.cn KB servers had expired certs).
"""
import base64
import io
import json
import os
import sqlite3
import ssl
import sys
import urllib.parse
import urllib.request
import zipfile
from concurrent.futures import ThreadPoolExecutor, as_completed

AS_LOGIN_URL = "https://as.wiz.cn/as/user/login"
CLIENT_PARAMS = "clientType=macos&clientVersion=2.8.7&apiVersion=10"


def main():
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    insecure = "--insecure" in sys.argv
    if not args:
        print(__doc__)
        sys.exit(1)

    data_dir = os.path.expanduser(args[0])
    db_path = os.path.join(data_dir, "index.db")
    att_dir = os.path.join(data_dir, "attachments")
    os.makedirs(att_dir, exist_ok=True)

    ctx = ssl.create_default_context()
    if insecure:
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE

    conn = sqlite3.connect(db_path)
    c = conn.cursor()
    c.execute("SELECT META_KEY, META_VALUE FROM WIZ_META WHERE META_NAME IN ('ACCOUNT', 'DATABASE')")
    meta = dict(c.fetchall())
    user_id = meta["USERID"]
    password = base64.b64decode(meta["PASSWORD"]).decode("utf-8")
    kb_guid = meta["KBGUID"]

    req = urllib.request.Request(
        AS_LOGIN_URL,
        data=urllib.parse.urlencode({"userId": user_id, "password": password}).encode("utf-8"),
        headers={"Content-Type": "application/x-www-form-urlencoded"},
    )
    with urllib.request.urlopen(req, timeout=20, context=ctx) as resp:
        login = json.loads(resp.read().decode("utf-8"))
    if login.get("returnCode") != 200:
        print("Login failed:", login.get("returnMessage"))
        sys.exit(1)
    token = login["result"]["token"]
    kb_server = login["result"]["kbServer"]
    print(f"Logged in as {user_id}, KB server: {kb_server}")

    c.execute("SELECT ATTACHMENT_GUID, DOCUMENT_GUID, ATTACHMENT_NAME FROM WIZ_DOCUMENT_ATTACHMENT")
    rows = c.fetchall()
    conn.close()

    def download_one(row):
        att_guid, doc_guid, att_name = row
        att_name = att_name or "attachment"
        dest = os.path.join(att_dir, f"{{{att_guid}}}{att_name}")
        if os.path.exists(dest) and os.path.getsize(dest) > 0:
            return ("skipped", att_name, "")
        url = (f"{kb_server}/ks/object/download/{kb_guid}/{doc_guid}"
               f"?objType=attachment&objId={att_guid}&{CLIENT_PARAMS}")
        r = urllib.request.Request(url, headers={"X-Wiz-Token": token})
        try:
            with urllib.request.urlopen(r, timeout=60, context=ctx) as resp:
                data = resp.read()
            # The server wraps each attachment in a one-file zip.
            with zipfile.ZipFile(io.BytesIO(data)) as zf:
                raw = zf.read(zf.namelist()[0])
            with open(dest, "wb") as f:
                f.write(raw)
            return ("ok", att_name, "")
        except Exception as e:
            return ("err", att_name, str(e))

    ok = skipped = 0
    errors = []
    with ThreadPoolExecutor(max_workers=6) as ex:
        for fut in as_completed([ex.submit(download_one, r) for r in rows]):
            status, name, msg = fut.result()
            if status == "ok":
                ok += 1
            elif status == "skipped":
                skipped += 1
            else:
                errors.append((name, msg))

    print(f"Downloaded: {ok}, Skipped (already on disk): {skipped}, Errors: {len(errors)}")
    for name, msg in errors:
        print(f"  {name}: {msg}")


if __name__ == "__main__":
    main()
