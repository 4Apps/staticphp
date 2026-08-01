-- i18n schema for mysql.
--
-- Keys are unique on key_hash rather than on the text itself: a unique index on a utf8mb4
-- column tops out at 768 characters in InnoDB, and source text is a whole sentence.

CREATE TABLE i18n_keys (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    key_hash char(64) NOT NULL,
    `key` text NOT NULL,
    created bigint NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY i18n_keys_key_hash_key (key_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The unique key is what stops a duplicate row per (key, language). Without it two
-- concurrent registrations both insert and the join that loads a language returns the key
-- twice, last one winning at random.
CREATE TABLE i18n_translations (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    key_id bigint unsigned NOT NULL,
    language varchar(24) NOT NULL,
    `value` text NOT NULL,
    created bigint NOT NULL DEFAULT 0,
    updated bigint NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY i18n_translations_key_id_language_key (key_id, language),
    KEY i18n_translations_language_idx (language),
    CONSTRAINT i18n_translations_key_id_fkey FOREIGN KEY (key_id) REFERENCES i18n_keys (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per language whose warmed copy may be trusted. Deleting a row is how a
-- translator's save reaches every application server without any of them being told.
CREATE TABLE i18n_cached (
    id varchar(64) NOT NULL,
    created bigint NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
