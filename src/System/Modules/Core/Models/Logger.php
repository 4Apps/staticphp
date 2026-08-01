<?php

namespace System\Modules\Core\Models;

use System\Modules\Core\Models\Timers;

/**
 * Core logger class.
 */
class Logger
{
    public const ERROR_LEVELS = [
        'none' => 1000,
        'emergency' => 800,
        'alert' => 700,
        'critical' => 600,
        'error' => 500,
        'warning' => 400,
        'notice' => 300,
        'info' => 200,
        'debug' => 100,
    ];

    public const NONE = 'none';
    public const EMERGENCY = 'emergency';
    public const ALERT = 'alert';
    public const CRITICAL = 'critical';
    public const ERROR = 'error';
    public const WARNING = 'warning';
    public const NOTICE = 'notice';
    public const INFO = 'info';
    public const DEBUG = 'debug';

    /**
     * Array for log entries.
     *
     * (default value: [])
     *
     * @var array
     * @access protected
     * @static
     */
    protected static array $logs = [];

    /*
    |-------------------------------------------------------------------------------------------------------------------
    | Helpers
    |-------------------------------------------------------------------------------------------------------------------
    */

    /**
     * Compare string error levels
     *
     * @param  string $errorLevel1 Set error level
     * @param  string $errorLevel2 Error level to compare to
     * @return bool
     */
    public static function contains(string $errorLevel1, string $errorLevel2): bool
    {
        // These returned null against a `: bool` signature, so a mistyped log level in
        // config raised a TypeError from inside the error handler rather than simply not
        // logging. An unknown level means "do not log at this level".
        if (empty(self::ERROR_LEVELS[$errorLevel1]) || empty(self::ERROR_LEVELS[$errorLevel2])) {
            return false;
        }

        return (self::ERROR_LEVELS[$errorLevel1] <= self::ERROR_LEVELS[$errorLevel2]);
    }

    /*
    |-------------------------------------------------------------------------------------------------------------------
    | Logger methods
    |-------------------------------------------------------------------------------------------------------------------
    */

    /**
     * System is unusable.
     *
     * @param  string $message
     * @param  array  $context
     * @return void
     */
    public static function emergency(string $message, array $context = []): void
    {
        self::log(Logger::EMERGENCY, $message, $context);
    }

    /**
     * Action must be taken immediately.
     *
     * Example: Entire website down, database unavailable, etc. This should
     * trigger the SMS alerts and wake you up.
     *
     * @param  string $message
     * @param  array  $context
     * @return void
     */
    public static function alert(string $message, array $context = []): void
    {
        self::log(Logger::ALERT, $message, $context);
    }

    /**
     * Critical conditions.
     *
     * Example: Application component unavailable, unexpected exception.
     *
     * @param  string $message
     * @param  array  $context
     * @return void
     */
    public static function critical(string $message, array $context = []): void
    {
        self::log(Logger::CRITICAL, $message, $context);
    }

    /**
     * Runtime errors that do not require immediate action but should typically
     * be logged and monitored.
     *
     * @param  string $message
     * @param  array  $context
     * @return void
     */
    public static function error(string $message, array $context = []): void
    {
        self::log(Logger::ERROR, $message, $context);
    }

    /**
     * Exceptional occurrences that are not errors.
     *
     * Example: Use of deprecated APIs, poor use of an API, undesirable things
     * that are not necessarily wrong.
     *
     * @param  string $message
     * @param  array  $context
     * @return void
     */
    public static function warning(string $message, array $context = []): void
    {
        self::log(Logger::WARNING, $message, $context);
    }

    /**
     * Normal but significant events.
     *
     * @param  string $message
     * @param  array  $context
     * @return void
     */
    public static function notice(string $message, array $context = []): void
    {
        self::log(Logger::NOTICE, $message, $context);
    }

    /**
     * Interesting events.
     *
     * Example: User logs in, SQL logs.
     *
     * @param  string $message
     * @param  array  $context
     * @return void
     */
    public static function info(string $message, array $context = []): void
    {
        self::log(Logger::INFO, $message, $context);
    }

    /**
     * Detailed debug information.
     *
     * @param  string $message
     * @param  array  $context
     * @return void
     */
    public static function debug(string $message, array $context = []): void
    {
        self::log(Logger::DEBUG, $message, $context);
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param  mixed  $level
     * @param  string $message
     * @param  array  $context
     * @return void
     */
    public static function log(string $level, $message, array $context = []): void
    {
        self::$logs[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }

    /*
    |-------------------------------------------------------------------------------------------------------------------
    | Debug Output
    |-------------------------------------------------------------------------------------------------------------------
    */

    /**
     * Generate debug output.
     *
     * @see Load::emergency()
     * @see Load::alert()
     * @see Load::critical()
     * @see Load::error()
     * @see Load::warning()
     * @see Load::notice()
     * @see Load::info()
     * @access public
     * @static
     * @return string Returns formatted html string of debug information, including timers,
     *          but also custom messages logged using logger interface.
     */
    public static function debugOutput(): string
    {
        // Log execution time
        Timers::logTimers();

        // Generate debug output
        $output = '';
        foreach (self::$logs as $item) {
            $class = '';
            switch ($item['level']) {
                case Logger::EMERGENCY:
                case Logger::ALERT:
                case Logger::CRITICAL:
                    $class = 'danger';
                    break;

                case Logger::ERROR:
                case Logger::WARNING:
                    $class = 'warning';
                    break;

                case Logger::NOTICE:
                case Logger::INFO:
                case Logger::DEBUG:
                    $class = 'info';
                    break;
            }

            $output .= '<span class="text-' . $class . '">' . strtoupper($item['level']) . ': </span>';
            $output .= $item['message'];
            $output .= (!empty($item['context']) ? " [" . implode(',', $item['context']) . "]\n" : "\n");
        }

        // Return it
        return $output;
    }
}
