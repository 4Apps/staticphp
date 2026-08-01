<?php

namespace System\Modules\Utils\Models\Translation;

/**
 * Finds translatable strings in source files.
 *
 * A heuristic, and honest about it: it matches literal strings passed to the translation
 * calls, so `_($heading)` and `_('Hello ' ~ name)` are invisible to it. That is fine for
 * what it is for - telling you which keys the source has that the database does not, and
 * which keys the database has that nothing references any more. It is not, and cannot be,
 * the thing that decides what gets translated; auto-registration already does that at
 * runtime, from the real call.
 */
final class Scanner
{
    /**
     * @var string[]
     * @access public
     */
    public const EXTENSIONS = ['php', 'twig', 'html'];

    /**
     * A quoted string literal, capturing the quote in group 1 and the body in group 2.
     *
     * @var string
     * @access private
     */
    private const STRING = '([\'"])((?:\\\\.|(?!\1)[^\\\\])*)\1';

    /**
     * Where a translatable literal can appear.
     *
     * @var string[]
     * @access private
     */
    private const PATTERNS = [
        // _('text') and _f('text'), not preceded by something that makes it another symbol
        '/(?<![\w$>-])_f?\(\s*' . self::STRING . '/',
        // i18n::translate('text'), i18n::format('text')
        '/::(?:translate|format)\(\s*' . self::STRING . '/',
        // {{ 'text'|translate }}
        '/' . self::STRING . '\s*\|\s*(?:translate|format)\b/',
    ];

    /**
     * Scan a set of files and directories.
     *
     * @access public
     * @static
     * @param  string[] $paths
     * @param  string[] $extensions
     * @return array<string, string[]> Source text mapped to the "file:line" places it appears
     */
    public static function scan(array $paths, array $extensions = self::EXTENSIONS): array
    {
        $found = [];

        foreach (self::files($paths, $extensions) as $file) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            foreach (self::PATTERNS as $pattern) {
                if (preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
                    continue;
                }

                // Every pattern embeds self::STRING as its first capture, so the quote is
                // always group 1 and the body always group 2
                foreach ($matches as $match) {
                    [$body, $offset] = $match[2];
                    $quote = $match[1][0];

                    $key = self::unescape($body, $quote);
                    if ($key === '') {
                        continue;
                    }

                    $line = substr_count($contents, "\n", 0, $offset) + 1;
                    $found[$key][] = $file . ':' . $line;
                }
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * @access private
     * @static
     * @param  string[] $paths
     * @param  string[] $extensions
     * @return string[]
     */
    private static function files(array $paths, array $extensions): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (is_file($path) === true) {
                $files[] = $path;
                continue;
            }

            if (is_dir($path) === false) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $item) {
                if ($item->isFile() === false) {
                    continue;
                }

                $real = $item->getPathname();

                // Neither is source anybody wrote, and both are large enough to dominate the
                // run if they are walked
                if (str_contains($real, '/vendor/') === true || str_contains($real, '/node_modules/') === true) {
                    continue;
                }

                if (in_array(strtolower($item->getExtension()), $extensions, true) === true) {
                    $files[] = $real;
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Turn a matched literal body back into the string it denotes.
     *
     * @access private
     * @static
     * @param  string $body
     * @param  string $quote
     * @return string
     */
    private static function unescape(string $body, string $quote): string
    {
        if ($quote === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
        }

        return stripcslashes($body);
    }
}
