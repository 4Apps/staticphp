<?php

namespace System\Modules\Core\Exceptions;

use Throwable;
use System\Modules\Core\Models\Config;
use System\Modules\Core\Models\Router;

/**
 * Renders the two html error pages the framework can show a browser: the debug page a
 * developer gets, and the status page everybody else gets.
 *
 * Both are self contained - a single document with inline css, no fonts, images, styles
 * or scripts fetched from anywhere. The page that has to render when something broke is
 * the worst possible place to depend on the template engine, the asset pipeline or the
 * network, since any of those may be what broke.
 *
 * The built in templates can be replaced per application:
 *
 *     $config['error_pages'] = [
 *         'status' => APP_PATH . '/Views/Errors/status.php',
 *         'debug'  => null,
 *     ];
 *
 * They are plain php templates rather than twig ones for the same reason.
 */
class ErrorPage
{
    /** Lines of source shown either side of the offending one. */
    public const SOURCE_CONTEXT = 8;

    /** Source files larger than this are not read for excerpts. */
    public const MAX_SOURCE_BYTES = 2097152;

    /** Context values longer than this are cut, so one huge post field cannot bury the page. */
    public const MAX_VALUE_LENGTH = 4096;

    /** Keys and substrings whose values never reach the page or the log. */
    public const SENSITIVE_PATTERN = '/(password|passwd|pwd|secret|token|api[ _-]?key|authorization|cookie)/i';

    /**
     * Per request cache of source files already read for excerpts.
     *
     * @var array<string, string[]|null>
     */
    private static array $sources = [];

    private static ?string $requestId = null;

    /**
     * The page shown to the public: a status code, a short human explanation and nothing
     * whatsoever about the internals.
     *
     * @param int $httpStatusCode
     * @param ?string $httpStatusMessage Defaults to the standard reason phrase.
     * @param ?string $description Optional sentence shown under the title. Must be safe to publish.
     * @param ?string $reference Correlation id, so a caller can quote something findable in the log.
     * @return string
     */
    public static function status(
        int $httpStatusCode,
        ?string $httpStatusMessage = null,
        ?string $description = null,
        ?string $reference = null
    ): string {
        $title = (
            empty($httpStatusMessage)
            ? ErrorMessage::httpStatusCodeToMessage($httpStatusCode)
            : $httpStatusMessage
        );

        return self::render(
            self::templateFor('status'),
            [
                'code' => $httpStatusCode,
                'title' => $title,
                'description' => ($description ?? self::defaultDescription($httpStatusCode)),
                'reference' => $reference,
                'tone' => self::toneFor($httpStatusCode),
                'home_url' => Router::$base_url,
                'datetime' => date('Y-m-d H:i:s T'),
            ]
        );
    }

    /**
     * The page shown to a developer: the exception and everything around it that helps
     * explain the exception.
     *
     * Never reachable unless debug is on - see ErrorMessage::outputMessage().
     *
     * @param Throwable $e
     * @param int $httpStatusCode
     * @param ?string $reference
     * @param ?string $description
     * @return string
     */
    public static function debug(
        Throwable $e,
        int $httpStatusCode = 500,
        ?string $reference = null,
        ?string $description = null
    ): string {
        return self::render(
            self::templateFor('debug'),
            [
                'code' => $httpStatusCode,
                'title' => ErrorMessage::httpStatusCodeToMessage($httpStatusCode),
                'description' => $description,
                'tone' => self::toneFor($httpStatusCode),
                'exceptions' => self::chain($e),
                'groups' => self::contextGroups(),
                'reference' => $reference ?? self::requestId(),
                'report' => self::report($e),
                'datetime' => date('Y-m-d H:i:s T'),
            ]
        );
    }

    /**
     * The same exception as plain text, for the clipboard button, a terminal or a log.
     *
     * @param Throwable $e
     * @return string
     */
    public static function report(Throwable $e): string
    {
        $lines = ['Reference: ' . self::requestId(), 'Date: ' . date('Y-m-d H:i:s T')];

        $url = self::currentUrl();
        if ($url !== null) {
            $lines[] = 'Url: ' . ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . $url;
        }

        foreach (self::chain($e) as $index => $exception) {
            $lines[] = '';
            $lines[] = ($index === 0 ? '' : 'Caused by: ')
                . $exception['class']
                . ($exception['code'] !== 0 ? " ({$exception['code']})" : '')
                . ': ' . $exception['message'];
            $lines[] = "  at {$exception['file']}:{$exception['line']}";

            foreach ($exception['frames'] as $frame) {
                $lines[] = "  #{$frame['index']} {$frame['call']}"
                    . ($frame['file'] === null ? '' : " - {$frame['file']}:{$frame['line']}");
            }
        }

        return implode("\n", $lines);
    }

    /**
     * A short id naming this request, shown on both pages and written to the log, so that
     * "something went wrong, reference 7f3a1c" can actually be looked up.
     *
     * A reverse proxy that already stamped one wins, so the page, the application log and
     * the access log all name the same request.
     *
     * @return string
     */
    public static function requestId(): string
    {
        if (self::$requestId !== null) {
            return self::$requestId;
        }

        foreach (['HTTP_X_REQUEST_ID', 'HTTP_X_CORRELATION_ID', 'UNIQUE_ID'] as $key) {
            $candidate = $_SERVER[$key] ?? null;
            if (is_string($candidate) && preg_match('/^[A-Za-z0-9._-]{4,64}$/', $candidate) === 1) {
                return self::$requestId = $candidate;
            }
        }

        return self::$requestId = bin2hex(random_bytes(6));
    }

    /**
     * Replaces anything that looks like a credential with ***.
     *
     * The key is checked whatever the value's type: checking only string values would let
     * a sensitive key holding an array or an int through untouched.
     *
     * @param mixed $data
     * @return mixed
     */
    public static function redact(mixed $data): mixed
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (preg_match(self::SENSITIVE_PATTERN, (string) $key) === 1) {
                    $data[$key] = '***';
                    continue;
                }

                if (is_array($value)) {
                    $data[$key] = self::redact($value);
                }
            }

            return $data;
        }

        if (is_string($data)) {
            return preg_replace(self::SENSITIVE_PATTERN, '***', $data);
        }

        return $data;
    }

    /**
     * Escapes a value for html. Every single thing either template prints goes through
     * here - exception messages, file paths and request data all routinely contain markup.
     *
     * @param mixed $value
     * @return string
     */
    public static function escape(mixed $value): string
    {
        if (is_scalar($value) === false && $value !== null) {
            $value = self::flatten($value);
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }


    // #######################
    // ### Page internals  ###
    // #######################

    /**
     * Runs a template in its own scope and returns what it printed.
     *
     * @param string $template
     * @param array<string, mixed> $data
     * @return string
     */
    private static function render(string $template, array $data): string
    {
        $data['esc'] = static function (mixed $value): string {
            return self::escape($value);
        };

        $level = ob_get_level();

        try {
            ob_start();

            (static function (string $templatePath, array $templateData): void {
                extract($templateData, EXTR_SKIP);
                require $templatePath;
            })($template, $data);

            return (string) ob_get_clean();
        } catch (Throwable $renderFailure) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            // This is already the last thing standing. Nothing downstream is going to
            // catch a second exception, so fall back to markup that cannot fail.
            return self::minimal(
                (int) ($data['code'] ?? 500),
                (string) ($data['title'] ?? 'Error')
            );
        }
    }

    /**
     * Resolves a template name to a file, honouring the per application override.
     *
     * @param string $name status|debug
     * @return string
     */
    private static function templateFor(string $name): string
    {
        $configured = Config::$items['error_pages'][$name] ?? null;
        if (is_string($configured) && is_file($configured)) {
            return $configured;
        }

        return __DIR__ . "/../Views/Errors/{$name}.php";
    }

    /**
     * @param int $httpStatusCode
     * @param string $title
     * @return string
     */
    private static function minimal(int $httpStatusCode, string $title): string
    {
        $code = self::escape($httpStatusCode);
        $safeTitle = self::escape($title);

        return "<!DOCTYPE html>\n<html lang=\"en\"><head><meta charset=\"utf-8\">"
            . "<title>{$code} {$safeTitle}</title></head>"
            . "<body><h1>{$code} {$safeTitle}</h1></body></html>";
    }

    /**
     * A sentence explaining the status code to somebody who is not a developer.
     *
     * Used whenever the thrower did not publish one of its own, so the default page says
     * something useful rather than repeating the reason phrase in a larger font.
     *
     * @param int $httpStatusCode
     * @return ?string
     */
    private static function defaultDescription(int $httpStatusCode): ?string
    {
        $descriptions = [
            400 => 'The request could not be understood, so it was not processed.',
            401 => 'You need to sign in before you can see this page.',
            403 => 'You do not have permission to see this page.',
            404 => 'The page you were looking for does not exist, or it has moved.',
            405 => 'That address does not accept this kind of request.',
            408 => 'The request took too long to arrive and was dropped.',
            410 => 'This page is gone for good.',
            413 => 'What you sent is larger than this site accepts.',
            429 => 'Too many requests arrived from you at once. Please wait a moment.',
            500 => 'Something went wrong on our side. The problem has been recorded.',
            502 => 'A service this site depends on returned something unusable.',
            503 => 'The site is temporarily unavailable. Please try again shortly.',
            504 => 'A service this site depends on took too long to answer.',
        ];

        if (isset($descriptions[$httpStatusCode])) {
            return $descriptions[$httpStatusCode];
        }

        if ($httpStatusCode >= 500) {
            return 'Something went wrong on our side. The problem has been recorded.';
        }

        if ($httpStatusCode >= 400) {
            return 'The request could not be completed.';
        }

        return null;
    }

    /**
     * @param int $httpStatusCode
     * @return string One of server|client|redirect|neutral, used to pick the accent colour.
     */
    private static function toneFor(int $httpStatusCode): string
    {
        if ($httpStatusCode >= 500) {
            return 'server';
        }

        if ($httpStatusCode >= 400) {
            return 'client';
        }

        if ($httpStatusCode >= 300) {
            return 'redirect';
        }

        return 'neutral';
    }


    // ########################
    // ### Exception detail ###
    // ########################

    /**
     * The exception followed by everything it was caused by, each already broken down
     * into what the template prints.
     *
     * @param Throwable $e
     * @return array<int, array<string, mixed>>
     */
    private static function chain(Throwable $e): array
    {
        $chain = [];
        $current = $e;
        $seen = [];

        while ($current !== null) {
            // A cyclic $previous chain is pathological but cheap to guard, and looping
            // forever inside the error handler would take the process with it.
            $id = spl_object_id($current);
            if (isset($seen[$id])) {
                break;
            }
            $seen[$id] = true;

            $file = (string) $current->getFile();
            $line = (int) $current->getLine();

            $chain[] = [
                'class' => get_class($current),
                'message' => (string) $current->getMessage(),
                'code' => $current->getCode(),
                'file' => $file,
                'short_file' => self::shortenPath($file),
                'line' => $line,
                'excerpt' => self::excerpt($file, $line),
                'frames' => self::frames($current),
            ];

            $current = $current->getPrevious();
        }

        return $chain;
    }

    /**
     * @param Throwable $e
     * @return array<int, array<string, mixed>>
     */
    private static function frames(Throwable $e): array
    {
        $frames = [];

        foreach ($e->getTrace() as $index => $frame) {
            $file = isset($frame['file']) ? (string) $frame['file'] : null;
            $line = isset($frame['line']) ? (int) $frame['line'] : null;

            $frames[] = [
                'index' => $index,
                'call' => self::formatCall($frame),
                'file' => $file,
                'short_file' => ($file === null ? null : self::shortenPath($file)),
                'line' => $line,
                'vendor' => ($file !== null && str_contains(str_replace('\\', '/', $file), '/vendor/')),
                'excerpt' => self::excerpt($file, $line),
            ];
        }

        return $frames;
    }

    /**
     * Renders one trace frame as a call signature.
     *
     * Argument values are deliberately reduced to their types. They are as likely to hold
     * a password or a megabyte of html as anything else, and the type is what usually
     * explains the failure anyway.
     *
     * @param array<string, mixed> $frame
     * @return string
     */
    private static function formatCall(array $frame): string
    {
        $call = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '{closure}');

        // zend.exception_ignore_args is on by default, so most of the time there is
        // nothing here to describe.
        if (isset($frame['args']) === false || is_array($frame['args']) === false) {
            return $call . '()';
        }

        $types = [];
        foreach ($frame['args'] as $arg) {
            $types[] = is_object($arg) ? get_class($arg) : get_debug_type($arg);
        }

        return $call . '(' . implode(', ', $types) . ')';
    }

    /**
     * The lines around $line, keyed by line number.
     *
     * @param ?string $file
     * @param ?int $line
     * @return array<int, string>
     */
    private static function excerpt(?string $file, ?int $line): array
    {
        if ($file === null || $line === null || $line < 1) {
            return [];
        }

        $lines = self::sourceLines($file);
        if ($lines === null) {
            return [];
        }

        $first = max(1, $line - self::SOURCE_CONTEXT);
        $last = min(count($lines), $line + self::SOURCE_CONTEXT);

        $excerpt = [];
        for ($number = $first; $number <= $last; $number++) {
            $excerpt[$number] = rtrim($lines[$number - 1], "\r\n");
        }

        return $excerpt;
    }

    /**
     * @param string $file
     * @return string[]|null
     */
    private static function sourceLines(string $file): ?array
    {
        if (array_key_exists($file, self::$sources)) {
            return self::$sources[$file];
        }

        $lines = null;
        if (is_file($file) && is_readable($file) && filesize($file) <= self::MAX_SOURCE_BYTES) {
            $contents = file($file);
            $lines = ($contents === false ? null : $contents);
        }

        return self::$sources[$file] = $lines;
    }

    /**
     * Trims the deploy directory off a path, so the eye lands on the part that differs.
     *
     * @param string $file
     * @return string
     */
    private static function shortenPath(string $file): string
    {
        if (defined('BASE_PATH') === false) {
            return $file;
        }

        $base = rtrim(str_replace('\\', '/', (string) BASE_PATH), '/') . '/';
        $normalised = str_replace('\\', '/', $file);

        if (str_starts_with($normalised, $base)) {
            return substr($normalised, strlen($base));
        }

        return $file;
    }


    // #####################
    // ### Request state ###
    // #####################

    /**
     * The tables shown under the exception, in the order they are worth reading.
     *
     * @return array<string, array<string, string>>
     */
    private static function contextGroups(): array
    {
        $groups = [
            'Request' => self::requestSummary(),
            'Headers' => self::requestHeaders(),
            'Query' => self::redact($_GET ?? []),
            'Body' => self::redact($_POST ?? []),
            'Cookies' => self::redact($_COOKIE ?? []),
            'Session' => self::sessionData(),
            'Server' => self::redact($_SERVER ?? []),
            'Runtime' => self::runtimeSummary(),
        ];

        foreach ($groups as $name => $values) {
            $flat = [];
            foreach ((array) $values as $key => $value) {
                $flat[(string) $key] = self::flatten($value);
            }
            $groups[$name] = $flat;
        }

        return $groups;
    }

    /**
     * @return array<string, mixed>
     */
    private static function requestSummary(): array
    {
        return array_filter(
            [
                'Method' => $_SERVER['REQUEST_METHOD'] ?? null,
                'Url' => self::currentUrl(),
                'Protocol' => $_SERVER['SERVER_PROTOCOL'] ?? null,
                'Client ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'Referer' => $_SERVER['HTTP_REFERER'] ?? null,
                'User agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'Content type' => $_SERVER['CONTENT_TYPE'] ?? null,
                'Route' => self::currentRoute(),
            ],
            static function ($value): bool {
                return $value !== null && $value !== '';
            }
        );
    }

    /**
     * @return array<string, string>
     */
    private static function requestHeaders(): array
    {
        $headers = [];

        foreach (($_SERVER ?? []) as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_') === false) {
                continue;
            }

            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr((string) $key, 5)))));
            $headers[$name] = $value;
        }

        ksort($headers);

        return self::redact($headers);
    }

    /**
     * @return array<string, mixed>
     */
    private static function sessionData(): array
    {
        if (isset($_SESSION) === false || is_array($_SESSION) === false) {
            return [];
        }

        // The application may publish a scrubbed view of its own session
        if (is_callable('formatSession')) {
            return (array) self::redact(call_user_func('formatSession'));
        }

        return (array) self::redact($_SESSION);
    }

    /**
     * @return array<string, string>
     */
    private static function runtimeSummary(): array
    {
        $started = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;

        return array_filter(
            [
                'Php' => PHP_VERSION,
                'Sapi' => PHP_SAPI,
                'Os' => PHP_OS_FAMILY,
                'Environment' => (string) (Config::$items['environment'] ?? ''),
                'Memory' => self::formatBytes(memory_get_usage(true))
                    . ' (peak ' . self::formatBytes(memory_get_peak_usage(true)) . ')',
                'Memory limit' => (string) ini_get('memory_limit'),
                'Elapsed' => (
                    is_numeric($started)
                    ? number_format((microtime(true) - (float) $started) * 1000, 1) . ' ms'
                    : ''
                ),
                'Timezone' => date_default_timezone_get(),
            ],
            static function ($value): bool {
                return $value !== null && $value !== '';
            }
        );
    }

    /**
     * @return ?string
     */
    private static function currentUrl(): ?string
    {
        if (PHP_SAPI === 'cli') {
            return null;
        }

        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? null;
        if (empty($host)) {
            return null;
        }

        $scheme = (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SERVER_PORT'] ?? '') === '443')
            ? 'https'
            : 'http';

        return $scheme . '://' . $host . ($_SERVER['REQUEST_URI'] ?? '');
    }

    /**
     * @return ?string
     */
    private static function currentRoute(): ?string
    {
        if (empty(Router::$class)) {
            return null;
        }

        return trim(Router::$namespace . '\\' . Router::$class, '\\')
            . (empty(Router::$method) ? '' : '::' . Router::$method);
    }

    /**
     * Reduces any value to one printable string, cutting anything absurdly long.
     *
     * @param mixed $value
     * @return string
     */
    private static function flatten(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            $value = (string) $value;
        } elseif (is_array($value)) {
            $value = (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } elseif (is_object($value)) {
            $value = get_class($value)
                . (is_callable([$value, '__toString']) ? ' ' . (string) $value : '');
        } else {
            $value = get_debug_type($value);
        }

        if (strlen($value) > self::MAX_VALUE_LENGTH) {
            $value = substr($value, 0, self::MAX_VALUE_LENGTH) . "\n... truncated";
        }

        return $value;
    }

    /**
     * @param int $bytes
     * @return string
     */
    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes = intdiv($bytes, 1024);
            $index++;
        }

        return $bytes . ' ' . $units[$index];
    }
}
