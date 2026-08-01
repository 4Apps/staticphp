<?php

/*
| All available countries and the languages each one is served in.
|
| The first country is the default, and each country's first language is that country's
| default - which is also what an untranslated string falls back to.
|
| "code" is an ISO 3166-1 country code and the entries in "languages" are ISO 639-1
| language codes. Keep them honest: the ICU locale used for plural rules, number and date
| formatting is derived as <language>_<COUNTRY>, so an English page on the Latvian site
| formats as en_LV - english words, latvian conventions. Set "locale" on a country to
| override the derivation for every one of its languages.
*/
$config['i18n']['available'] = [
    [
        'name' => 'Latvia',
        'code' => 'lv',
        'languages' => ['lv', 'en', 'ru'],
    ],
    [
        'name' => 'Estonia',
        'code' => 'ee',
        'languages' => ['et', 'en', 'ru'],
    ],
];

// Url segment identifying the country and language, e.g. /lv-en/some/page
$config['i18n']['url_format'] = '{{country}}-{{language}}';

/*
| Language prefixes are derived from "available" rather than listed by hand, because the
| two lists drifting apart is silent: the prefix stops being recognised as a prefix and
| becomes the first controller segment instead.
|
| They are prepended because Router matches prefixes in configured order against the url
| in url order, and /lv-en/... puts the language first. Move them if your application
| puts something ahead of the language.
*/
$i18nPrefixes = [];
foreach ($config['i18n']['available'] as $i18nCountry) {
    foreach ($i18nCountry['languages'] as $i18nLanguage) {
        $i18nPrefixes[] = str_replace(
            ['{{country}}', '{{language}}'],
            [$i18nCountry['code'], $i18nLanguage],
            $config['i18n']['url_format']
        );
    }
}

$config['url_prefixes'] = array_values(array_unique(array_merge($i18nPrefixes, $config['url_prefixes'] ?? [])));
unset($i18nPrefixes, $i18nCountry, $i18nLanguage);

// Redirect to the default language when the url carries no recognised prefix
$config['i18n']['redirect'] = true;

/*
| Pick the redirect target from the request's Accept-Language header instead of always
| sending everyone to the first configured language. Only ever applies to a request that
| had no prefix of its own, so it can never override an explicit choice.
*/
$config['i18n']['negotiate'] = true;

/*
| Insert a key into the database the first time it is asked for, storing the source text
| with "missing_suffix" appended so untranslated strings are visible on the page and show
| up in the translation backend without a separate extraction step.
|
| Turn it off on a read-only replica, or anywhere a page render must not write.
*/
$config['i18n']['auto_register'] = true;
$config['i18n']['missing_suffix'] = '*';

// Fall back to the country's default language when a string has no translation
$config['i18n']['fallback'] = true;

/*
| What to do when the database or ICU refuses to cooperate. false returns the source
| string and logs, so a translation outage degrades the page instead of ending it. true
| rethrows - which is what you want in tests and development. null follows config['debug'].
*/
$config['i18n']['strict'] = null;

/*
| Call setlocale() with the active ICU locale on init.
|
| Off by default: everything this class formats goes through ICU, which carries its own
| locale data, whereas setlocale() silently does nothing unless that locale is generated
| in the container. Turn it on only if third-party code reads localeconv() or strftime().
*/
$config['i18n']['set_locale'] = false;

// Where warmed language tables live. Possible values: external (Cache class), internal
// (generated php file under the app's cache dir) or none
$config['i18n']['cache'] = 'external';

// Prefix for cache keys and for the generated file names
$config['i18n']['cache_prefix'] = 'language_';

// Subdirectory of the application's Cache directory used by the internal cache
$config['i18n']['cache_subdir'] = 'i18n';

// Seconds to keep a warmed language table, or null for the backend's own default
$config['i18n']['cache_ttl'] = null;

// Entry of config['db']['pdo'] holding the translations
$config['i18n']['db_config'] = 'default';

/*
| Schema to qualify the tables with, or an empty string to leave them unqualified.
|
| Empty is right for all three drivers by default - postgres already has "public" on its
| search_path, and mysql and sqlite resolve against the connected database.
*/
$config['i18n']['db_scheme'] = '';

// Table names, in case they collide with something the application already owns
$config['i18n']['tables'] = [
    'keys' => 'i18n_keys',
    'translations' => 'i18n_translations',
    'cached' => 'i18n_cached',
];
