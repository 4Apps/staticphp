<?php

namespace StaticPHP\Skeleton\App;

/**
 * Choosing a preset, interactively when that is possible and silently when it is not.
 *
 * Shared by `staticphp app add` and the composer post-create-project hook, because the
 * question is the same one and the fallback has to behave identically in CI.
 *
 * Composer passes its own stdin through to post-create-project-cmd scripts, and detaches
 * it when --no-interaction is given - verified, not assumed. So stream_isatty() alone
 * answers "may I ask a question here", with no environment sniffing, and there is no path
 * on which an automated run blocks waiting for an answer.
 */
class Presets
{
    /**
     * Order of preference: an explicit SP_PRESET, then the prompt, then the default.
     */
    public static function choose(Scaffolder $scaffolder, string $default): string
    {
        $available = $scaffolder->presets();
        if ($available === []) {
            return $default;
        }

        $fromEnv = trim((string) (getenv('SP_PRESET') ?: ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        if (self::canAsk() === false) {
            return $default;
        }

        echo "\nWhich starting point?\n";
        foreach ($available as $index => $preset) {
            printf(
                "  [%d] %-10s %s%s\n",
                $index + 1,
                $preset,
                $scaffolder->presetDescription($preset),
                ($preset === $default ? ' (default)' : '')
            );
        }

        $answer = trim((string) self::readLine('> '));
        if ($answer === '') {
            return $default;
        }

        // Accept either the number or the name
        if (ctype_digit($answer)) {
            $picked = $available[((int) $answer) - 1] ?? null;
            if ($picked !== null) {
                return $picked;
            }
        } elseif (in_array($answer, $available, true)) {
            return $answer;
        }

        echo "Not one of the options - using {$default}.\n";

        return $default;
    }

    private static function canAsk(): bool
    {
        return defined('STDIN') && stream_isatty(STDIN);
    }

    private static function readLine(string $prompt): string
    {
        echo $prompt;

        $line = fgets(STDIN);

        return ($line === false ? '' : $line);
    }
}
