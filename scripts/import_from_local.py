#!/usr/bin/env python3
"""
Import local WizNote client data (~/.wiznote/<account>/data/) into a wizdata directory
suitable for WizServer Lite (wizserver.sqlite + notes/ + attachments/).

Usage:
    python3 scripts/import_from_local.py ~/.wiznote/<user@example.com>/data ./wizdata
"""
import base64
import hashlib
import html as html_lib
import json
import os
import re
import shutil
import sqlite3
import sys
import time
import zipfile
from datetime import datetime


def ts_to_ms(dt_str):
    if not dt_str:
        return int(time.time() * 1000)
    try:
        dt = datetime.strptime(str(dt_str)[:19], "%Y-%m-%d %H:%M:%S")
        return max(0, int(dt.timestamp() * 1000))
    except Exception:
        return int(time.time() * 1000)


def strip_html_text(raw_html):
    if not raw_html:
        return ""
    s = re.sub(r"<(head|style|script)[^>]*>.*?</\1>", " ", raw_html, flags=re.I | re.S)
    s = re.sub(r"<[^>]+>", " ", s)
    s = html_lib.unescape(s)
    s = re.sub(r"\s+", " ", s).strip()
    return s


def main():
    if len(sys.argv) < 3:
        print("Usage: python3 scripts/import_from_local.py <local_wiznote_data_dir> <output_wizdata_dir>")
        sys.exit(1)

    src_dir = os.path.expanduser(sys.argv[1])
    dst_dir = os.path.expanduser(sys.argv[2])

    os.makedirs(dst_dir, exist_ok=True)
    dst_notes_dir = os.path.join(dst_dir, "notes")
    dst_att_dir = os.path.join(dst_dir, "attachments")
    os.makedirs(dst_notes_dir, exist_ok=True)
    os.makedirs(dst_att_dir, exist_ok=True)

    src_db_path = os.path.join(src_dir, "index.db")
    dst_db_path = os.path.join(dst_dir, "wizserver.sqlite")
    if os.path.exists(dst_db_path):
        os.remove(dst_db_path)

    src_conn = sqlite3.connect(src_db_path)
    src_conn.row_factory = sqlite3.Row
    sc = src_conn.cursor()

    dst_conn = sqlite3.connect(dst_db_path)
    dc = dst_conn.cursor()

    dc.executescript("""
    PRAGMA journal_mode = WAL;
    PRAGMA synchronous = NORMAL;

    CREATE TABLE users (
        user_guid TEXT PRIMARY KEY,
        user_id TEXT UNIQUE NOT NULL,
        email TEXT NOT NULL,
        password_hash TEXT NOT NULL,
        password_plain_md5 TEXT NOT NULL,
        display_name TEXT NOT NULL,
        kb_guid TEXT NOT NULL,
        mywiz_email TEXT DEFAULT '',
        created_ms INTEGER NOT NULL
    );

    CREATE TABLE tokens (
        token TEXT PRIMARY KEY,
        user_guid TEXT NOT NULL,
        kb_guid TEXT NOT NULL,
        expires_at INTEGER NOT NULL
    );

    CREATE TABLE kv_store (
        scope_guid TEXT NOT NULL,
        key TEXT NOT NULL,
        value TEXT NOT NULL,
        version INTEGER NOT NULL,
        PRIMARY KEY (scope_guid, key)
    );

    CREATE TABLE kb_state (
        kb_guid TEXT PRIMARY KEY,
        doc_version INTEGER NOT NULL DEFAULT 1,
        att_version INTEGER NOT NULL DEFAULT 1,
        tag_version INTEGER NOT NULL DEFAULT 1,
        style_version INTEGER NOT NULL DEFAULT 0,
        param_version INTEGER NOT NULL DEFAULT 1,
        deleted_version INTEGER NOT NULL DEFAULT 1,
        storage_usage INTEGER NOT NULL DEFAULT 0
    );

    CREATE TABLE documents (
        doc_guid TEXT PRIMARY KEY,
        kb_guid TEXT NOT NULL,
        title TEXT NOT NULL,
        category TEXT NOT NULL,
        name TEXT DEFAULT '',
        seo TEXT DEFAULT '',
        url TEXT DEFAULT '',
        author TEXT DEFAULT '',
        keywords TEXT DEFAULT '',
        type TEXT DEFAULT 'document',
        owner TEXT DEFAULT '',
        file_type TEXT DEFAULT '',
        style_guid TEXT DEFAULT '',
        created_ms INTEGER NOT NULL,
        modified_ms INTEGER NOT NULL,
        accessed_ms INTEGER NOT NULL,
        icon_index INTEGER DEFAULT 0,
        protected INTEGER DEFAULT 0,
        read_count INTEGER DEFAULT 0,
        attachment_count INTEGER DEFAULT 0,
        info_modified_ms INTEGER NOT NULL,
        info_md5 TEXT NOT NULL,
        data_modified_ms INTEGER NOT NULL,
        data_md5 TEXT NOT NULL,
        param_modified_ms INTEGER NOT NULL,
        param_md5 TEXT DEFAULT '',
        version INTEGER NOT NULL,
        data_size INTEGER DEFAULT 0,
        tags_str TEXT DEFAULT '',
        abstract_text TEXT DEFAULT '',
        search_text TEXT DEFAULT ''
    );
    CREATE INDEX idx_documents_version ON documents(kb_guid, version);
    CREATE INDEX idx_documents_category ON documents(kb_guid, category);

    CREATE TABLE tags (
        tag_guid TEXT PRIMARY KEY,
        kb_guid TEXT NOT NULL,
        parent_tag_guid TEXT DEFAULT '',
        name TEXT NOT NULL,
        description TEXT DEFAULT '',
        modified_ms INTEGER NOT NULL,
        version INTEGER NOT NULL,
        pos INTEGER DEFAULT 0
    );
    CREATE INDEX idx_tags_version ON tags(kb_guid, version);

    CREATE TABLE document_tags (
        doc_guid TEXT NOT NULL,
        tag_guid TEXT NOT NULL,
        PRIMARY KEY (doc_guid, tag_guid)
    );

    CREATE TABLE attachments (
        att_guid TEXT PRIMARY KEY,
        doc_guid TEXT NOT NULL,
        kb_guid TEXT NOT NULL,
        name TEXT NOT NULL,
        url TEXT DEFAULT '',
        description TEXT DEFAULT '',
        info_modified_ms INTEGER NOT NULL,
        info_md5 TEXT NOT NULL,
        data_modified_ms INTEGER NOT NULL,
        data_md5 TEXT NOT NULL,
        version INTEGER NOT NULL,
        data_size INTEGER DEFAULT 0
    );
    CREATE INDEX idx_attachments_version ON attachments(kb_guid, version);
    CREATE INDEX idx_attachments_doc ON attachments(doc_guid);

    CREATE TABLE params (
        kb_guid TEXT NOT NULL,
        doc_guid TEXT NOT NULL,
        name TEXT NOT NULL,
        value TEXT NOT NULL,
        version INTEGER NOT NULL,
        PRIMARY KEY (doc_guid, name)
    );
    CREATE INDEX idx_params_version ON params(kb_guid, version);

    CREATE TABLE styles (
        style_guid TEXT PRIMARY KEY,
        kb_guid TEXT NOT NULL,
        name TEXT NOT NULL,
        description TEXT DEFAULT '',
        text_color TEXT DEFAULT '#000000',
        back_color TEXT DEFAULT '#ffffff',
        text_bold INTEGER DEFAULT 0,
        flag_index INTEGER DEFAULT 0,
        modified_ms INTEGER NOT NULL,
        version INTEGER NOT NULL
    );

    CREATE TABLE deleted_guids (
        deleted_guid TEXT PRIMARY KEY,
        kb_guid TEXT NOT NULL,
        guid_type TEXT NOT NULL,
        deleted_ms INTEGER NOT NULL,
        version INTEGER NOT NULL
    );
    CREATE INDEX idx_deleted_version ON deleted_guids(kb_guid, version);
    """)

    sc.execute("SELECT META_NAME, META_KEY, META_VALUE FROM WIZ_META")
    meta = {}
    for row in sc.fetchall():
        meta[(row["META_NAME"], row["META_KEY"])] = row["META_VALUE"]

    user_id = meta.get(("ACCOUNT", "USERID"), "user@example.com")
    user_guid = meta.get(("ACCOUNT", "GUID"), "00000000-0000-0000-0000-000000000001")
    kb_guid = meta.get(("DATABASE", "KBGUID"), "00000000-0000-0000-0000-000000000002")
    display_name = meta.get(("ACCOUNT", "DISPLAYNAME"), "User")
    mywiz_email = meta.get(("ACCOUNT", "MYWIZMAIL"), "")
    pwd_b64 = meta.get(("ACCOUNT", "PASSWORD"), "")
    raw_password = base64.b64decode(pwd_b64).decode("utf-8") if pwd_b64 else "changeme"

    pwd_sha256 = hashlib.sha256(raw_password.encode("utf-8")).hexdigest()
    pwd_md5 = "md5." + hashlib.md5(raw_password.encode("utf-8")).hexdigest()

    dc.execute("""
        INSERT INTO users (user_guid, user_id, email, password_hash, password_plain_md5, display_name, kb_guid, mywiz_email, created_ms)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    """, (user_guid, user_id, user_id, pwd_sha256, pwd_md5, display_name, kb_guid, mywiz_email, int(time.time() * 1000)))

    # Import tags & document_tags
    sc.execute("SELECT * FROM WIZ_TAG")
    max_tag_ver = int(meta.get(("SYNC_INFO", "TAG"), "1") or 1)
    for r in sc.fetchall():
        ver = int(r["WIZ_VERSION"] or 1)
        if ver <= 0:
            max_tag_ver += 1
            ver = max_tag_ver
        max_tag_ver = max(max_tag_ver, ver)
        dc.execute("""
            INSERT INTO tags (tag_guid, kb_guid, parent_tag_guid, name, description, modified_ms, version, pos)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        """, (
            r["TAG_GUID"],
            kb_guid,
            r["TAG_GROUP_GUID"] or "",
            r["TAG_NAME"] or "",
            r["TAG_DESCRIPTION"] or "",
            ts_to_ms(r["DT_MODIFIED"]),
            ver,
            int(r["TAG_POS"] or 0)
        ))

    sc.execute("SELECT DOCUMENT_GUID, TAG_GUID FROM WIZ_DOCUMENT_TAG")
    doc_tags_map = {}
    for r in sc.fetchall():
        dg = r["DOCUMENT_GUID"]
        tg = r["TAG_GUID"]
        dc.execute("INSERT OR IGNORE INTO document_tags (doc_guid, tag_guid) VALUES (?, ?)", (dg, tg))
        doc_tags_map.setdefault(dg, []).append(tg)

    # Import params
    sc.execute("SELECT * FROM WIZ_DOCUMENT_PARAM")
    max_param_ver = int(meta.get(("SYNC_INFO", "PARAM"), "1") or 1)
    for r in sc.fetchall():
        ver = int(r["WIZ_VERSION"] or 1)
        if ver <= 0:
            max_param_ver += 1
            ver = max_param_ver
        max_param_ver = max(max_param_ver, ver)
        dc.execute("""
            INSERT OR REPLACE INTO params (kb_guid, doc_guid, name, value, version)
            VALUES (?, ?, ?, ?, ?)
        """, (kb_guid, r["DOCUMENT_GUID"], r["PARAM_NAME"], r["PARAM_VALUE"] or "", ver))

    # Import attachments
    sc.execute("SELECT * FROM WIZ_DOCUMENT_ATTACHMENT")
    max_att_ver = int(meta.get(("SYNC_INFO", "ATTACHMENT"), "1") or 1)
    src_att_dir = os.path.join(src_dir, "attachments")
    total_storage = 0
    att_count = 0

    for r in sc.fetchall():
        att_guid = r["ATTACHMENT_GUID"]
        doc_guid = r["DOCUMENT_GUID"]
        att_name = r["ATTACHMENT_NAME"] or "attachment"
        ver = int(r["WIZ_VERSION"] or 1)
        if ver <= 0:
            max_att_ver += 1
            ver = max_att_ver
        max_att_ver = max(max_att_ver, ver)

        src_file = os.path.join(src_att_dir, f"{{{att_guid}}}{att_name}")
        dst_file = os.path.join(dst_att_dir, att_guid)
        data_size = 0
        if os.path.exists(src_file):
            shutil.copy2(src_file, dst_file)
            data_size = os.path.getsize(dst_file)
            total_storage += data_size
            att_count += 1

        dc.execute("""
            INSERT INTO attachments (
                att_guid, doc_guid, kb_guid, name, url, description,
                info_modified_ms, info_md5, data_modified_ms, data_md5, version, data_size
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        """, (
            att_guid,
            doc_guid,
            kb_guid,
            att_name,
            r["ATTACHMENT_URL"] or "",
            r["ATTACHMENT_DESCRIPTION"] or "",
            ts_to_ms(r["DT_INFO_MODIFIED"]),
            r["ATTACHMENT_INFO_MD5"] or "",
            ts_to_ms(r["DT_DATA_MODIFIED"]),
            r["ATTACHMENT_DATA_MD5"] or "",
            ver,
            data_size
        ))

    # Import documents & extract note zips
    sc.execute("SELECT * FROM WIZ_DOCUMENT")
    max_doc_ver = int(meta.get(("SYNC_INFO", "DOCUMENT"), "1") or 1)
    src_notes_dir = os.path.join(src_dir, "notes")
    doc_count = 0
    all_categories = set()

    for r in sc.fetchall():
        doc_guid = r["DOCUMENT_GUID"]
        ver = int(r["WIZ_VERSION"] or 1)
        if ver <= 0:
            max_doc_ver += 1
            ver = max_doc_ver
        max_doc_ver = max(max_doc_ver, ver)

        cat = r["DOCUMENT_LOCATION"] or "/My Notes/"
        all_categories.add(cat)

        zip_path = os.path.join(src_notes_dir, f"{{{doc_guid}}}")
        doc_out_dir = os.path.join(dst_notes_dir, doc_guid)
        os.makedirs(doc_out_dir, exist_ok=True)

        data_size = 0
        raw_html = ""
        if os.path.exists(zip_path):
            data_size = os.path.getsize(zip_path)
            total_storage += data_size
            with zipfile.ZipFile(zip_path, "r") as zf:
                for member in zf.namelist():
                    clean_member = os.path.normpath(member).lstrip("/\\")
                    if clean_member.startswith(".."):
                        continue
                    target_path = os.path.join(doc_out_dir, clean_member)
                    if member.endswith("/"):
                        os.makedirs(target_path, exist_ok=True)
                    else:
                        os.makedirs(os.path.dirname(target_path), exist_ok=True)
                        content = zf.read(member)
                        with open(target_path, "wb") as f:
                            f.write(content)
                        if clean_member == "index.html":
                            raw_html = content.decode("utf-8", errors="replace")

        plain_text = strip_html_text(raw_html)
        abstract_text = plain_text[:200]
        tags_str = "*".join(doc_tags_map.get(doc_guid, []))

        dc.execute("""
            INSERT INTO documents (
                doc_guid, kb_guid, title, category, name, seo, url, author, keywords,
                type, owner, file_type, style_guid,
                created_ms, modified_ms, accessed_ms,
                icon_index, protected, read_count, attachment_count,
                info_modified_ms, info_md5, data_modified_ms, data_md5,
                param_modified_ms, param_md5, version, data_size,
                tags_str, abstract_text, search_text
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        """, (
            doc_guid,
            kb_guid,
            r["DOCUMENT_TITLE"] or "Untitled",
            cat,
            r["DOCUMENT_NAME"] or "",
            r["DOCUMENT_SEO"] or "",
            r["DOCUMENT_URL"] or "",
            r["DOCUMENT_AUTHOR"] or "",
            r["DOCUMENT_KEYWORDS"] or "",
            r["DOCUMENT_TYPE"] or "document",
            r["DOCUMENT_OWNER"] or user_id,
            r["DOCUMENT_FILE_TYPE"] or "",
            r["STYLE_GUID"] or "",
            ts_to_ms(r["DT_CREATED"]),
            ts_to_ms(r["DT_MODIFIED"]),
            ts_to_ms(r["DT_ACCESSED"]),
            int(r["DOCUMENT_ICON_INDEX"] or 0),
            int(r["DOCUMENT_PROTECT"] or 0),
            int(r["DOCUMENT_READ_COUNT"] or 0),
            int(r["DOCUMENT_ATTACHEMENT_COUNT"] or 0),
            ts_to_ms(r["DT_INFO_MODIFIED"]),
            r["DOCUMENT_INFO_MD5"] or "",
            ts_to_ms(r["DT_DATA_MODIFIED"]),
            r["DOCUMENT_DATA_MD5"] or "",
            ts_to_ms(r["DT_PARAM_MODIFIED"]),
            r["DOCUMENT_PARAM_MD5"] or "",
            ver,
            data_size,
            tags_str,
            abstract_text,
            (r["DOCUMENT_TITLE"] or "") + " " + plain_text
        ))
        doc_count += 1

    # Import KV store
    extra_folders_raw = meta.get(("FOLDERS", "EXTRA"), "")
    if extra_folders_raw:
        for f in extra_folders_raw.split("\\"):
            if f.strip():
                all_categories.add(f.strip())
    folders_list = sorted(all_categories)
    folders_ver = int(meta.get(("SYNC_INFO", "KEY_FOLDERS_VERSION"), "1") or 1)
    folders_pos_ver = int(meta.get(("SYNC_INFO", "KEY_FOLDERS_POS_VERSION"), "1") or 1)
    folders_pos_val = meta.get(("SYNC_INFO", "FOLDERS_POS"), "{}")
    fav_ver = int(meta.get(("SYNC_INFO", "KEY_FAVORITES_VERSION"), "1") or 1)

    for scope in (user_guid, kb_guid):
        dc.execute("INSERT OR REPLACE INTO kv_store (scope_guid, key, value, version) VALUES (?, 'folders', ?, ?)",
                   (scope, json.dumps(folders_list, ensure_ascii=False), folders_ver))
        dc.execute("INSERT OR REPLACE INTO kv_store (scope_guid, key, value, version) VALUES (?, 'folders_pos', ?, ?)",
                   (scope, folders_pos_val, folders_pos_ver))
        dc.execute("INSERT OR REPLACE INTO kv_store (scope_guid, key, value, version) VALUES (?, 'favorites', ?, ?)",
                   (scope, "[]", fav_ver))
        dc.execute("INSERT OR REPLACE INTO kv_store (scope_guid, key, value, version) VALUES (?, 'biz_users', ?, ?)",
                   (scope, "[]", 1))

    deleted_ver = int(meta.get(("SYNC_INFO", "DELETED_GUID"), "1") or 1)
    dc.execute("""
        INSERT INTO kb_state (
            kb_guid, doc_version, att_version, tag_version,
            style_version, param_version, deleted_version, storage_usage
        ) VALUES (?, ?, ?, ?, 0, ?, ?, ?)
    """, (kb_guid, max_doc_ver, max_att_ver, max_tag_ver, max_param_ver, deleted_ver, total_storage))

    dst_conn.commit()
    dst_conn.close()
    src_conn.close()

    print(f"Import complete!")
    print(f"  User: {user_id} (kbGuid: {kb_guid})")
    print(f"  Documents imported: {doc_count} (max version: {max_doc_ver})")
    print(f"  Attachments imported: {att_count} (max version: {max_att_ver})")
    print(f"  Total storage size: {total_storage / (1024*1024):.2f} MB")
    print(f"  Output directory: {dst_dir}")


if __name__ == "__main__":
    main()
