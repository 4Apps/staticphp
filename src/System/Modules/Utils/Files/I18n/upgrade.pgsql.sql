-- Upgrade an existing postgres i18n schema to the one this framework now expects.
--
-- Only postgres has an upgrade path because the old schema only ever shipped for postgres
-- (System/Modules/Utils/Files/i18n_pg.sql). A mysql or sqlite install starts from
-- install.<driver>.sql instead.
--
-- Read this before applying it: the dedupe below deletes rows. Take a dump first.

-- 1. Translations that point at a key that is gone, or at nothing at all. key_id used to be
--    nullable with no foreign key behind it, so both are possible.
DELETE FROM i18n_translations
WHERE key_id IS NULL
   OR key_id NOT IN (SELECT id FROM i18n_keys);

-- 2. Duplicate (key_id, language) rows, keeping the newest. These accumulated because
--    translate() re-inserted on every request whenever a translation was an empty string,
--    and because two concurrent registrations of the same key both inserted.
DELETE FROM i18n_translations a
    USING i18n_translations b
WHERE a.key_id = b.key_id
  AND a.language = b.language
  AND a.id < b.id;

-- 3. The constraint that stops it happening again.
ALTER TABLE i18n_translations ALTER COLUMN key_id SET NOT NULL;
ALTER TABLE i18n_translations ADD CONSTRAINT i18n_translations_key_id_language_key UNIQUE (key_id, language);
ALTER TABLE i18n_translations
    ADD CONSTRAINT i18n_translations_key_id_fkey FOREIGN KEY (key_id) REFERENCES i18n_keys (id) ON DELETE CASCADE;

CREATE INDEX i18n_translations_language_idx ON i18n_translations (language);

-- 4. Track when a translation last changed.
ALTER TABLE i18n_translations ADD COLUMN updated bigint NOT NULL DEFAULT 0;
UPDATE i18n_translations SET updated = created;

-- 5. Address keys by hash so the source text is no longer length limited. sha256() is a
--    core function since postgres 11 and matches what hash('sha256', $key) produces in php.
ALTER TABLE i18n_keys ADD COLUMN key_hash char(64);
UPDATE i18n_keys SET key_hash = encode(sha256(convert_to("key", 'UTF8')), 'hex');
ALTER TABLE i18n_keys ALTER COLUMN key_hash SET NOT NULL;
ALTER TABLE i18n_keys ADD CONSTRAINT i18n_keys_key_hash_key UNIQUE (key_hash);

-- 6. Every warmed copy predates the schema change.
DELETE FROM i18n_cached WHERE 1 = 1;
