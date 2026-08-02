<?php

namespace StaticPHP\Skeleton;

/**
 * The commands this skeleton adds to ./staticphp.
 *
 * Same shape as the framework's own registry - a name mapped to a class exposing
 * `run(array $arguments, string $basePath): int` - so the entry point merges the two lists
 * and dispatches them identically. Adding a command here needs no change to ./staticphp,
 * and an application can still override one by name through $localCommands.
 *
 * These resolve before the framework's, and before the check that the framework is even
 * installed, because none of them touch it. Upgrading in particular has to keep working
 * when the installed core is broken or missing: that is one of the reasons to reach for it.
 */
class Cli
{
    /**
     * @return array<string, class-string>
     */
    public static function commands(): array
    {
        return [
            'app' => App\Cli::class,
            'upgrade' => Upgrade\Cli::class,
        ];
    }

    /**
     * The top level help, listing whatever is registered rather than a hardcoded list - so a
     * command added here, by the framework, or by an application shows up without anyone
     * remembering to update this text.
     *
     * @param array<string, class-string> $commands The merged registry
     */
    public static function help(array $commands): string
    {
        ksort($commands);

        $list = '';
        foreach ($commands as $name => $class) {
            $list .= rtrim(sprintf('  %-10s %s', $name, self::describe($class))) . "\n";
        }

        return <<<TXT
        StaticPHP command line entry point.

        Usage:
          staticphp <command> [options]   run one of the commands below
          staticphp <url> [options]       dispatch a url as though it were a request

        Commands:
        {$list}
          staticphp <command> --help      what that command takes

        Dispatching a url is how scheduled work runs: the controller sees an ordinary
        request, so nothing has to be written twice.

          staticphp /defaults/console/refresh
          staticphp /defaults/console/refresh --query "since=yesterday"

        Options, when dispatching a url:
          --project <Name>   Application under src/ to use (default: Application)
          --query <string>   Fill \$_GET from a query string
          --post <string>    Fill \$_POST from a query string, and make it a POST
          --https            Present the request as https
          --help             This text

        TXT;
    }

    /**
     * A command's one line summary, when it offers one.
     *
     * Read off optional constants rather than a second registry, so that framework commands
     * list correctly without the framework having to know this exists, and a command that
     * declares neither is simply blank rather than an error.
     *
     * @param class-string $class
     */
    private static function describe(string $class): string
    {
        $described = self::constantString($class, 'DESCRIPTION');
        if ($described !== '') {
            return $described;
        }

        return self::opening(self::constantString($class, 'USAGE'));
    }

    /**
     * The first sentence of a usage text, for commands that declare only that.
     *
     * A command whose usage goes straight into a "Commands:" table has no summary to take,
     * and gets none - deriving one from the command's name would read like documentation
     * while saying nothing. Declaring DESCRIPTION is what fills this column properly.
     *
     * Public because it is pure text handling with more edge cases than the rest of this
     * class put together, and testing it through a set of fixture command classes would say
     * less about it than handing it the strings directly.
     */
    public static function opening(string $usage): string
    {
        $paragraph = [];
        foreach (explode("\n", $usage) as $line) {
            $line = trim($line);

            if ($line === '') {
                // A blank line before the prose is the gap under the synopsis; one after it
                // is the end of the paragraph
                if ($paragraph !== []) {
                    break;
                }

                continue;
            }

            if (str_starts_with($line, 'Usage:')) {
                continue;
            }

            // A heading means a table follows, not a description
            if (str_ends_with($line, ':')) {
                break;
            }

            $paragraph[] = $line;
        }

        if ($paragraph === []) {
            return '';
        }

        $sentence = implode(' ', $paragraph);
        $stop = strpos($sentence, '. ');
        if ($stop !== false) {
            $sentence = substr($sentence, 0, $stop + 1);
        }

        // Keeps the whole line inside 80 columns once the name column is allowed for
        return self::shorten($sentence, 62);
    }

    private static function shorten(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space === false ? $cut : mb_substr($cut, 0, $space), ' ,.') . '...';
    }

    /**
     * @param class-string $class
     */
    private static function constantString(string $class, string $name): string
    {
        $constant = $class . '::' . $name;
        if (defined($constant) === false) {
            return '';
        }

        $value = constant($constant);

        return (is_string($value) ? $value : '');
    }
}
