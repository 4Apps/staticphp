<?php

namespace System\Modules\Utils\Models\Translation;

/**
 * Everything that turns a translated string into what the page shows.
 *
 * Plurals go through ICU MessageFormat rather than a hand rolled ngettext, because the
 * languages this framework was written for are exactly the ones a two-form ngettext gets
 * wrong: latvian has three plural categories and russian four, and which one applies is a
 * property of the language, not of the call site.
 */
final class Formatter
{
    /**
     * Built formatters, keyed by what they were built for.
     *
     * Constructing one loads an ICU locale bundle, which is the expensive part - so a page
     * that formats fifty numbers pays for it once.
     *
     * @var array<string, mixed>
     * @access private
     */
    private array $formatters = [];

    /**
     * @var bool
     * @access private
     */
    private bool $warned = false;

    /**
     * @access public
     * @param string $icuLocale
     * @param bool   $strict    Throw instead of falling back when ICU rejects something
     */
    public function __construct(
        private readonly string $icuLocale = 'en_US',
        private readonly bool $strict = false,
    ) {
    }

    /**
     * Substitute placeholders in a translated string.
     *
     * strtr() rather than str_replace(): str_replace() walks the array and applies each
     * pair to the result of the last, so ['a' => 'b', 'b' => 'c'] turned an "a" into a "c",
     * and a short key that is a prefix of a longer one corrupted it. strtr() replaces in one
     * pass and prefers the longest matching key.
     *
     * @access public
     * @static
     * @param  string $text
     * @param  array  $replace Placeholder mapped to its value
     * @return string
     */
    public static function replace(string $text, array $replace): string
    {
        if ($replace === []) {
            return $text;
        }

        $pairs = [];
        foreach ($replace as $search => $value) {
            $pairs[(string) $search] = $value === null ? '' : (string) $value;
        }

        return strtr($text, $pairs);
    }

    /**
     * Escape a string for the context it is about to be written into.
     *
     * @access public
     * @static
     * @param  string  $text
     * @param  ?string $mode One of: html, attr, input, js, url. Null leaves it alone
     * @return string
     */
    public static function escape(string $text, ?string $mode): string
    {
        return match ($mode) {
            // json_encode produces a complete javascript string literal, quotes included;
            // trimming them leaves a correctly escaped body. The str_replace this replaces
            // handled ' \r and \n and nothing else, so a backslash or a </script> in a
            // translation walked straight out of the literal
            'js' => substr(
                (string) json_encode(
                    $text,
                    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
                ),
                1,
                -1
            ),
            'html', 'attr', 'input' => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'url' => rawurlencode($text),
            default => $text,
        };
    }

    /**
     * Format an ICU MessageFormat pattern.
     *
     * @example $formatter->message('{n, plural, zero{# failu} one{# fails} other{# faili}}', ['n' => 21]);
     * @access public
     * @param  string $pattern
     * @param  array  $arguments
     * @return string The pattern itself when ICU rejects it and strict mode is off
     * @throws TranslationError In strict mode
     */
    public function message(string $pattern, array $arguments = []): string
    {
        $key = 'message:' . $pattern;

        if (isset($this->formatters[$key]) === false) {
            $formatter = \MessageFormatter::create($this->icuLocale, $pattern);
            if ($formatter === null) {
                return $this->fail(
                    "Invalid ICU pattern \"{$pattern}\": " . intl_get_error_message(),
                    $pattern
                );
            }

            $this->formatters[$key] = $formatter;
        }

        $formatted = $this->formatters[$key]->format($arguments);
        if ($formatted === false) {
            return $this->fail(
                "Could not format \"{$pattern}\": " . $this->formatters[$key]->getErrorMessage(),
                $pattern
            );
        }

        return $formatted;
    }

    /**
     * @access public
     * @param  int|float $value
     * @param  ?int      $decimals Null for the locale's own default
     * @return string
     */
    public function number(int|float $value, ?int $decimals = null): string
    {
        $formatter = $this->numberFormatter(\NumberFormatter::DECIMAL, $decimals);
        if ($formatter === null) {
            return number_format((float) $value, $decimals ?? 2);
        }

        $formatted = $formatter->format($value);

        return $formatted === false ? number_format((float) $value, $decimals ?? 2) : $formatted;
    }

    /**
     * @access public
     * @param  int|float $value
     * @param  string    $currency ISO 4217 code, e.g. "EUR"
     * @return string
     */
    public function currency(int|float $value, string $currency = 'EUR'): string
    {
        $formatter = $this->numberFormatter(\NumberFormatter::CURRENCY, null);
        if ($formatter === null) {
            return number_format((float) $value, 2) . ' ' . $currency;
        }

        $formatted = $formatter->formatCurrency((float) $value, $currency);

        return $formatted === false ? number_format((float) $value, 2) . ' ' . $currency : $formatted;
    }

    /**
     * @access public
     * @param  int|float $value    1.0 being one hundred percent
     * @param  int       $decimals
     * @return string
     */
    public function percent(int|float $value, int $decimals = 0): string
    {
        $formatter = $this->numberFormatter(\NumberFormatter::PERCENT, $decimals);
        if ($formatter === null) {
            return number_format((float) $value * 100, $decimals) . '%';
        }

        $formatted = $formatter->format($value);

        return $formatted === false ? number_format((float) $value * 100, $decimals) . '%' : $formatted;
    }

    /**
     * @access public
     * @param  \DateTimeInterface|int|string $value
     * @param  int                           $dateType One of the IntlDateFormatter constants
     * @param  int                           $timeType One of the IntlDateFormatter constants
     * @param  ?string                       $pattern  Explicit ICU pattern, overriding the types
     * @return string
     */
    public function date(
        \DateTimeInterface|int|string $value,
        int $dateType = \IntlDateFormatter::MEDIUM,
        int $timeType = \IntlDateFormatter::NONE,
        ?string $pattern = null
    ): string {
        if (is_string($value) === true) {
            $value = new \DateTimeImmutable($value);
        }

        $key = "date:{$dateType}:{$timeType}:" . ($pattern ?? '');
        if (isset($this->formatters[$key]) === false) {
            $this->formatters[$key] = new \IntlDateFormatter(
                $this->icuLocale,
                $dateType,
                $timeType,
                null,
                null,
                $pattern
            );
        }

        $formatted = $this->formatters[$key]->format($value);
        if ($formatted === false) {
            $timestamp = $value instanceof \DateTimeInterface ? $value->getTimestamp() : (int) $value;

            return $this->fail('Could not format a date: ' . intl_get_error_message(), date('c', $timestamp));
        }

        return $formatted;
    }

    /**
     * @access private
     * @param  int  $style
     * @param  ?int $decimals
     * @return ?\NumberFormatter
     */
    private function numberFormatter(int $style, ?int $decimals): ?\NumberFormatter
    {
        $key = "number:{$style}:" . ($decimals ?? 'default');
        if (isset($this->formatters[$key]) === true) {
            return $this->formatters[$key];
        }

        $formatter = \NumberFormatter::create($this->icuLocale, $style);
        if ($formatter === null) {
            $this->fail("No ICU number formatter for \"{$this->icuLocale}\"", '');

            return null;
        }

        if ($decimals !== null) {
            $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
        }

        return $this->formatters[$key] = $formatter;
    }

    /**
     * @access private
     * @param  string $message
     * @param  string $fallback
     * @return string
     * @throws TranslationError In strict mode
     */
    private function fail(string $message, string $fallback): string
    {
        if ($this->strict === true) {
            throw new TranslationError($message);
        }

        if ($this->warned === false) {
            $this->warned = true;
            error_log('i18n: ' . $message);
        }

        return $fallback;
    }
}
