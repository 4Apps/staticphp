<?php

namespace System\Modules\Utils\Models\Translation;

/**
 * One country and language pairing, and everything derived from it.
 *
 * Immutable on purpose. The class this replaced bound its statics to live references into
 * the config array (self::$country_code = &self::$current_country['code']) inside a
 * foreach-by-reference, so the active language could be rewritten by an unrelated loop
 * elsewhere in the request.
 */
final class Locale
{
    /**
     * @access public
     * @param string $countryCode Country code as configured, e.g. "lv"
     * @param string $countryName Human readable country name
     * @param string $language    Language code as configured, e.g. "en"
     * @param string $urlPrefix   Url segment identifying this pairing, e.g. "lv-en"
     * @param string $icuLocale   Locale handed to ICU, e.g. "en_LV"
     * @param bool   $isDefault   Whether this is its country's default language
     */
    public function __construct(
        public readonly string $countryCode,
        public readonly string $countryName,
        public readonly string $language,
        public readonly string $urlPrefix,
        public readonly string $icuLocale,
        public readonly bool $isDefault,
    ) {
    }

    /**
     * Identifier this pairing's translations are stored under.
     *
     * Country scoped rather than language scoped, so the same english string can be worded
     * differently for two countries.
     *
     * @access public
     * @return string
     */
    public function key(): string
    {
        return $this->countryCode . '_' . $this->language;
    }

    /**
     * Value for an hreflang attribute.
     *
     * @access public
     * @return string
     */
    public function hreflang(): string
    {
        return $this->language . '-' . strtoupper($this->countryCode);
    }
}
