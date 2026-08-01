<?php

namespace System\Modules\Utils\Models\Translation;

/**
 * Picks a locale from an Accept-Language header.
 *
 * Only ever consulted for a request that carried no language prefix of its own, so it can
 * suggest but never override what the visitor explicitly asked for.
 */
final class Negotiator
{
    /**
     * Best configured pairing for the header, or null when nothing matches.
     *
     * Matching is deliberately loose about region: a browser sending "en-GB" should land on
     * the site's english, whatever country the site is scoped to. An exact country match is
     * still preferred when one exists.
     *
     * @access public
     * @static
     * @param  ?string $header  Raw Accept-Language value
     * @param  Locales $locales
     * @return ?Locale
     */
    public static function best(?string $header, Locales $locales): ?Locale
    {
        foreach (self::parse((string) $header) as $tag) {
            [$language, $region] = $tag;

            if ($region !== null) {
                $exact = $locales->find($region, $language);
                if ($exact !== null) {
                    return $exact;
                }
            }

            foreach ($locales->all() as $locale) {
                if ($locale->language === $language) {
                    return $locale;
                }
            }
        }

        return null;
    }

    /**
     * Split the header into [language, region] pairs, best quality first.
     *
     * @access private
     * @static
     * @param  string $header
     * @return array<int, array{0: string, 1: ?string}>
     */
    private static function parse(string $header): array
    {
        $candidates = [];

        foreach (explode(',', $header) as $index => $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $quality = 1.0;
            $pieces = explode(';', $part);
            $tag = strtolower(trim($pieces[0]));

            foreach (array_slice($pieces, 1) as $parameter) {
                $parameter = trim($parameter);
                if (stripos($parameter, 'q=') === 0) {
                    $quality = (float) substr($parameter, 2);
                }
            }

            // "*" means "anything", which is what the caller already does when this returns
            // null - answering it here would just pick the first configured language twice
            if ($quality <= 0.0 || $tag === '*') {
                continue;
            }

            if (preg_match('/^([a-z]{2,3})(?:-([a-z]{2}|[0-9]{3}))?/', $tag, $matches) !== 1) {
                continue;
            }

            $candidates[] = [
                'language' => $matches[1],
                'region' => isset($matches[2]) === true && $matches[2] !== '' ? strtolower($matches[2]) : null,
                'quality' => $quality,
                // Equal q values keep header order, which is the order of preference the
                // browser wrote them in
                'order' => $index,
            ];
        }

        usort(
            $candidates,
            fn(array $a, array $b): int => $b['quality'] <=> $a['quality'] ?: $a['order'] <=> $b['order']
        );

        return array_map(fn(array $item): array => [$item['language'], $item['region']], $candidates);
    }
}
