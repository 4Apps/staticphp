-- i18n schema for sqlite.
--
-- Keys are unique on key_hash rather than on the text itself, matching the other two
-- drivers - sqlite would happily index the text, mysql would not.

CREATE TABLE i18n_keys (
    id integer PRIMARY KEY AUTOINCREMENT,
    key_hash text NOT NULL,
    "key" text NOT NULL,
    created integer NOT NULL DEFAULT 0
);

CREATE UNIQUE INDEX i18n_keys_key_hash_key ON i18n_keys (key_hash);

-- The unique index is what stops a duplicate row per (key, language). Without it two
-- concurrent registrations both insert and the join that loads a language returns the key
-- twice, last one winning at random.
--
-- The foreign key is declared for the schema's sake; sqlite only enforces it when PRAGMA
-- foreign_keys is on, which is off by default, so the application deletes translations
-- explicitly rather than relying on the cascade.
CREATE TABLE i18n_translations (
    id integer PRIMARY KEY AUTOINCREMENT,
    key_id integer NOT NULL REFERENCES i18n_keys (id) ON DELETE CASCADE,
    language text NOT NULL,
    "value" text NOT NULL DEFAULT '',
    created integer NOT NULL DEFAULT 0,
    updated integer NOT NULL DEFAULT 0
);

CREATE UNIQUE INDEX i18n_translations_key_id_language_key ON i18n_translations (key_id, language);
CREATE INDEX i18n_translations_language_idx ON i18n_translations (language);

-- One row per language whose warmed copy may be trusted. Deleting a row is how a
-- translator's save reaches every application server without any of them being told.
CREATE TABLE i18n_cached (
    id text PRIMARY KEY,
    created integer NOT NULL DEFAULT 0
);
