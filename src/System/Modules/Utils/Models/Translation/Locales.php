<?php

namespace System\Modules\Utils\Models\Translation;

/**
 * The configured set of country and language pairings.
 */
final class Locales
{
    /**
     * Every configured pairing, in configuration order.
     *
     * @var Locale[]
     * @access private
     */
    private array $locales = [];

    /**
     * @param Locale[] $locales
     * @access private
     */
    private function __construct(array $locales)
    {
        $this->locales = $locales;
    }

    /**
     * Build the set from config['i18n'].
     *
     * @access public
     * @static
     * @param  array $config
     * @return self
     * @throws TranslationError When the configuration cannot produce a single pairing
     */
    public static function fromConfig(array $config): self
    {
        $format = (string) ($config['url_format'] ?? '{{country}}-{{language}}');
        $available = $config['available'] ?? [];

        if (is_array($available) === false || $available === []) {
            throw new TranslationError('config[\'i18n\'][\'available\'] is empty');
        }

        $locales = [];
        $seen = [];
        foreach ($available as $country) {
            $code = (string) ($country['code'] ?? '');
            $languages = $country['languages'] ?? [];

            if ($code === '' || is_array($languages) === false || $languages === []) {
                throw new TranslationError(
                    'Every entry of config[\'i18n\'][\'available\'] needs a "code" and a non-empty "languages"'
                );
            }

            $first = true;
            foreach ($languages as $language) {
                $language = (string) $language;
                $prefix = self::prefix($format, $code, $language);

                // Two countries resolving to one prefix would make the url ambiguous, and
                // whichever came second would simply never be reachable
                if (isset($seen[$prefix]) === true) {
                    throw new TranslationError("Duplicate i18n url prefix \"{$prefix}\"");
                }
                $seen[$prefix] = true;

                $locales[] = new Locale(
                    $code,
                    (string) ($country['name'] ?? $code),
                    $language,
                    $prefix,
                    (string) ($country['locale'] ?? ($language . '_' . strtoupper($code))),
                    $first,
                );

                $first = false;
            }
        }

        return new self($locales);
    }

    /**
     * Render a url prefix from the configured format.
     *
     * @access public
     * @static
     * @param  string $format
     * @param  string $countryCode
     * @param  string $language
     * @return string
     */
    public static function prefix(string $format, string $countryCode, string $language): string
    {
        return str_replace(['{{country}}', '{{language}}'], [$countryCode, $language], $format);
    }

    /**
     * @access public
     * @return Locale[]
     */
    public function all(): array
    {
        return $this->locales;
    }

    /**
     * The first configured pairing.
     *
     * @access public
     * @return Locale
     */
    public function default(): Locale
    {
        return $this->locales[0];
    }

    /**
     * @access public
     * @param  string $countryCode
     * @param  string $language
     * @return ?Locale
     */
    public function find(string $countryCode, string $language): ?Locale
    {
        foreach ($this->locales as $locale) {
            if ($locale->countryCode === $countryCode && $locale->language === $language) {
                return $locale;
            }
        }

        return null;
    }

    /**
     * @access public
     * @param  string $prefix
     * @return ?Locale
     */
    public function byPrefix(string $prefix): ?Locale
    {
        foreach ($this->locales as $locale) {
            if ($locale->urlPrefix === $prefix) {
                return $locale;
            }
        }

        return null;
    }

    /**
     * @access public
     * @param  string $key As produced by Locale::key(), e.g. "lv_en"
     * @return ?Locale
     */
    public function byKey(string $key): ?Locale
    {
        foreach ($this->locales as $locale) {
            if ($locale->key() === $key) {
                return $locale;
            }
        }

        return null;
    }

    /**
     * Every pairing served for one country, in configuration order.
     *
     * @access public
     * @param  string $countryCode
     * @return Locale[]
     */
    public function forCountry(string $countryCode): array
    {
        return array_values(array_filter(
            $this->locales,
            fn(Locale $locale): bool => $locale->countryCode === $countryCode
        ));
    }

    /**
     * Where to look for a string, in order, when the requested pairing does not have it.
     *
     * The country's own default comes before the global one so that a Latvian site missing
     * a Russian string shows Latvian rather than jumping to another country's language.
     *
     * @access public
     * @param  Locale $locale
     * @return Locale[] Always starts with $locale itself
     */
    public function fallbackChain(Locale $locale): array
    {
        $chain = [$locale];

        foreach ($this->forCountry($locale->countryCode) as $candidate) {
            if ($candidate->isDefault === true) {
                $chain[] = $candidate;
                break;
            }
        }

        $chain[] = $this->default();

        $unique = [];
        foreach ($chain as $candidate) {
            $unique[$candidate->key()] = $candidate;
        }

        return array_values($unique);
    }
}
