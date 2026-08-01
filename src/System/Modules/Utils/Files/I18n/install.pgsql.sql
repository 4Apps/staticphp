-- i18n schema for postgres.
--
-- Keys are unique on key_hash rather than on the text itself: the text is a whole source
-- sentence and the same schema has to work on mysql, where a unique index on utf8mb4 tops
-- out at 768 characters.

CREATE TABLE i18n_keys (
    id bigserial NOT NULL,
    key_hash char(64) NOT NULL,
    "key" text NOT NULL,
    created bigint NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    CONSTRAINT i18n_keys_key_hash_key UNIQUE (key_hash)
);

-- The unique constraint is what stops a duplicate row per (key, language). Without it two
-- concurrent registrations both insert and the join that loads a language returns the key
-- twice, last one winning at random.
CREATE TABLE i18n_translations (
    id bigserial NOT NULL,
    key_id bigint NOT NULL,
    language varchar(24) NOT NULL,
    "value" text NOT NULL DEFAULT '',
    created bigint NOT NULL DEFAULT 0,
    updated bigint NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    CONSTRAINT i18n_translations_key_id_language_key UNIQUE (key_id, language),
    CONSTRAINT i18n_translations_key_id_fkey FOREIGN KEY (key_id) REFERENCES i18n_keys (id) ON DELETE CASCADE
);

CREATE INDEX i18n_translations_language_idx ON i18n_translations (language);

-- One row per language whose warmed copy may be trusted. Deleting a row is how a
-- translator's save reaches every application server without any of them being told.
CREATE TABLE i18n_cached (
    id varchar(64) NOT NULL,
    created bigint NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
);
