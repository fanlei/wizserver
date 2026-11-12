#!/usr/bin/env python3
"""
Point a local WizQTClient profile at a private WizServer Lite server, or back to wiz.cn.

Close WizQTClient before running this script.

Usage:
    python3 scripts/switch_local_client.py private https://notes.example.com ~/.wiznote/<user@example.com>/data
    python3 scripts/switch_local_client.py wizcn ~/.wiznote/<user@example.com>/data

How it works:
    WizQTClient keeps server settings in WIZ_META inside <data>/index.db.
    - SERVERTYPE 199 = enterprise/private server, 99 = official wiz.cn
    - ENTERPRISESERVERIP must be set, or the login dialog refuses to switch an
      existing wiz.cn profile to a private server.
"""
import os
import sqlite3
import sys

WIZCN_KB_SERVER = "https://hk01.wiz.cn"


def usage():
    print(__doc__)
    sys.exit(1)


def main():
    if len(sys.argv) < 3:
        usage()

    mode = sys.argv[1]
    if mode == "private":
        if len(sys.argv) < 4:
            usage()
        server_url = sys.argv[2].rstrip("/")
        data_dir = sys.argv[3]
    elif mode == "wizcn":
        server_url = None
        data_dir = sys.argv[2]
    else:
        usage()

    db_path = os.path.join(os.path.expanduser(data_dir), "index.db")
    if not os.path.exists(db_path):
        print(f"index.db not found: {db_path}")
        sys.exit(1)

    conn = sqlite3.connect(db_path)
    c = conn.cursor()

    if mode == "private":
        c.execute("""
            INSERT OR REPLACE INTO WIZ_META (META_NAME, META_KEY, META_VALUE, DT_MODIFIED)
            VALUES ('QT_WIZNOTE', 'SERVERTYPE', '199', datetime('now'))
        """)
        c.execute("""
            INSERT OR REPLACE INTO WIZ_META (META_NAME, META_KEY, META_VALUE, DT_MODIFIED)
            VALUES ('QT_WIZNOTE', 'ENTERPRISESERVERIP', ?, datetime('now'))
        """, (server_url,))
        c.execute("""
            UPDATE WIZ_META SET META_VALUE = ? WHERE META_NAME = 'ACCOUNT' AND META_KEY = 'KBSERVER'
        """, (server_url,))
        print(f"Switched local WizQTClient profile to private server: {server_url}")
    else:
        c.execute("""
            UPDATE WIZ_META SET META_VALUE = '99' WHERE META_NAME = 'QT_WIZNOTE' AND META_KEY = 'SERVERTYPE'
        """)
        c.execute("""
            DELETE FROM WIZ_META WHERE META_NAME = 'QT_WIZNOTE' AND META_KEY = 'ENTERPRISESERVERIP'
        """)
        c.execute("""
            UPDATE WIZ_META SET META_VALUE = ? WHERE META_NAME = 'ACCOUNT' AND META_KEY = 'KBSERVER'
        """, (WIZCN_KB_SERVER,))
        print("Reverted local WizQTClient profile to wiz.cn")

    conn.commit()
    conn.close()


if __name__ == "__main__":
    main()
