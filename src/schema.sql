-- WizServer Lite database schema (SQLite).
-- Used by scripts/import_from_local.php to create wizserver.sqlite.
-- public/index.php expects exactly these tables and columns.

CREATE TABLE users (
    user_guid TEXT PRIMARY KEY,
    user_id TEXT UNIQUE NOT NULL,
    email TEXT NOT NULL,
    password_hash TEXT NOT NULL,          -- sha256 of the plain password
    password_plain_md5 TEXT NOT NULL,     -- "md5." + md5(password), sent by some Wiz clients
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
    scope_guid TEXT NOT NULL,             -- user GUID or KB GUID
    key TEXT NOT NULL,                    -- folders, folders_pos, favorites, ...
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
    tags_str TEXT DEFAULT '',             -- tag GUIDs joined with "*"
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
    guid_type TEXT NOT NULL,              -- document, tag, attachment, style
    deleted_ms INTEGER NOT NULL,
    version INTEGER NOT NULL
);
CREATE INDEX idx_deleted_version ON deleted_guids(kb_guid, version);
