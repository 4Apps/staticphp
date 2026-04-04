<?php

use System\Modules\Core\Exceptions\RouterException;
use System\Modules\Core\Exceptions\SpErrorException;
use System\Modules\Core\Models\Config;
use System\Modules\Core\Models\Logger;
use System\Modules\Core\Models\Router;

/**
 * StaticPHP's error handler. Turns errors into exceptions and passes on to sp_exception_handler().
 *
 * Stops on @ suppressed errors.
 *
 * @see sp_exception_handler()
 * @access public
 * @param int $errno
 * @param string $errstr
 * @param ?string $errfile
 * @param ?int $errline
 * @param ?array $errcontext
 * @return bool Returns whether the error was handled or not.
 */
function sp_error_handler(int $errno, string $errstr, ?string $errfile, ?int $errline, ?array $errcontext = null): void
{
    // @ used
    if (error_reporting() === 0) {
        return;
    }

    // Throw all the errors as exceptions, so they can be handled as they should
    throw new SpErrorException($errstr, 0, $errno, $errfile, $errline);
}

/**
 * StaticPHP's exception handler.
 *
 * If debug mode is on, sends formatted error to browser, otherwise sends error email,
 * if debug email is provided in <i>Config/Config.php</i> file.
 *
 * @access public
 * @param Throwable $exception
 * @return void
 */
function sp_exception_handler(Throwable $exception)
{
    // RouterException is a special case
    if ($exception instanceof RouterException) {
        Router::error(
            '500',
            'Internal Server Error',
            !empty(Config::$items['debug'])
                ? $exception->getMessage()
                : ''
        );
    }

    if (function_exists('http_response_code') && headers_sent() === false) {
        http_response_code(500);
    }

    if (Logger::contains(Config::$items['logging']['display_level'], 'error')) {
        $errorMsg = sp_format_exception($exception, false, true);
        echo $errorMsg;
    }

    if (Logger::contains(Config::$items['logging']['log_level'], 'error')) {
        sp_log_error($exception);
    }

    if (Logger::contains(Config::$items['logging']['report_level'], 'error')) {
        sp_send_error_email($exception);
    }

    exit(10);
}

/**
 * Logs error messages.
 *
 * @see sp_format_exception()
 * @access public
 * @param Throwable $e
 * @return void
 */
function sp_log_error(Throwable $e)
{
    $e_formatted = sp_format_exception($e, true, false);
    error_log($e_formatted);
}


/**
 * Sends error messages.
 *
 * @see sp_format_exception()
 * @access public
 * @param Throwable $e
 * @return void
 */
function sp_send_error_email(Throwable $e)
{
    static $last_error = ['time' => 0];

    $e_formatted = sp_format_exception($e, true, true);

    if (time() - $last_error['time'] < 30 && ($last_error['exception'] ?? '') == $e_formatted) {
        return;
    }

    $subject = 'PHP ERROR: "' . ($_SERVER['HTTP_HOST'] ?? 'cli') . '"';

    $debug_email = Config::$items['logging']['report_email'];
    $email_func = Config::$items['logging']['report_email_func'];
    if (!empty($debug_email) && is_callable($email_func)) {
        $email_func(
            $debug_email,
            $subject,
            $e_formatted,
            "Content-Type: text/html; charset=utf-8",
            'error'
        );
    }

    $webhook = Config::$items['logging']['report_webhook'] ?? null;
    $webhook_func = Config::$items['logging']['report_webhook_func'] ?? null;
    if (!empty($webhook) && is_callable($webhook_func)) {
        $webhook_func($webhook, $subject, $e_formatted, 'error');
    }

    $last_error['time'] = time();
    $last_error['exception'] = $e_formatted;
}

/**
 * Remove sensitive data from output
 *
 * @access public
 * @param mixed $data
 * @return mixed
 * */
function sp_remove_sensitive_data($data)
{
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = sp_remove_sensitive_data($value);
            } elseif (is_string($value)) {
                if (preg_match('/(password|passwd|pwd|secret|token|api_key|api secret)/i', $key)) {
                    $data[$key] = '***';
                }
            }
        }
    } elseif (is_string($data)) {
        $data = preg_replace('/(password|passwd|pwd|secret|token|api_key|api secret)/i', '***', $data);
    }

    return $data;
}

/**
 * Format exception and add session, server and post information for easier debugging.
 *
 * If $full is set to false, only string containing formatted message is returned.
 *
 * @access public
 * @param Throwable $e
 * @param bool $full (default: false)
 * @return string Returns formatted string of the $e exception
 */
function sp_format_exception(Throwable $e, bool $full = false, bool $markup = true)
{
    // Current time
    $datetime = date('d.m.Y H:i:s');

    // Current url
    $url  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' ? 'https://' : 'http://');
    $url .= (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '[unknown host name]');
    $url .= (!empty($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '[unknown url]');

    $stackTrace = $e->getTraceAsString();
    $previous = $e->getPrevious();
    while ($previous) {
        $stackTrace .= "\n\Trace:\n" . $previous->getTraceAsString();

        $previous = $previous->getPrevious();
    }

    // Message
    $message = '';
    if ($markup === true) {
        $message  = $e->getCode() . ' ';
        $message .= str_replace("\n", "<br />", $e->getMessage());
        $message .= '<br /><strong>File:</strong> ' . str_replace("\n", "<br />", $e->getFile());
        $message .= '<br /><strong>Line:</strong> ' . str_replace("\n", "<br />", $e->getLine());
        $message .= '<br /><br /><strong>Trace:</strong><br />';
        $message .= '<table border="0" cellspacing="0" cellpadding="5" style="border: 1px #DADADA solid;">';
        $message .= '<tr><td style="border-bottom: 1px #DADADA solid;">';
        $message .= str_replace(
            "\n",
            '</td></tr><tr><td style="border-bottom: 1px #DADADA solid;">',
            $stackTrace
        ) . '</td></tr></table>';
    } else {
        $message = $e->getCode() . " " . $e->getMessage();
        $message .= "\nFile: " . $e->getFile();
        $message .= "\nLine: " . $e->getLine();
        $message .= "\nTrace:\n\n";
        $message .= $stackTrace;
    }

    // Session
    $session = [];
    if (is_callable('formatSession')) {
        $session = sp_remove_sensitive_data(formatSession());
    } elseif (isset($_SESSION)) {
        $session = sp_remove_sensitive_data($_SESSION);
    }
    $session = json_encode($session, (defined('JSON_PRETTY_PRINT') ? JSON_PRETTY_PRINT : null));

    // Server
    $server = json_encode(
        sp_remove_sensitive_data($_SERVER),
        defined('JSON_PRETTY_PRINT')
            ? JSON_PRETTY_PRINT
            : null
    );

    // Post
    $post = !empty($_POST) ? json_encode($_POST, (defined('JSON_PRETTY_PRINT') ? JSON_PRETTY_PRINT : null)) : '{}';

    // Format message
    if ($markup === true) {
        $session = str_replace([" ", "\n"], ['&nbsp;', '<br />'], $session);
        $server = str_replace([" ", "\n"], ['&nbsp;', '<br />'], $server);
        $post = str_replace([" ", "\n"], ['&nbsp;', '<br />'], $post);
    }

    $msg = '';
    if ($full === true) {
        if ($markup === true) {
            $msg = "<pre><strong>Error:</strong> {$message}<br /><br />";
            $msg .= "<strong>URL: </strong>{$url}<br />";
            $msg .= "<strong>Datetime:</strong> {$datetime}<br /><br />";
            $msg .= "<strong>Sesssion Info</strong><br />{$session}<br /><br />";
            $msg .= "<strong>Post Info</strong><br />{$post}<br /><br /><strong>Server</strong><br />{$server}</pre>";
        } else {
            $msg = "Error: {$message}\n\n";
            $msg .= "URL: {$url}\n";
            $msg .= "Datetime: {$datetime}\n\n";
            $msg .= "Sesssion Info:\n{$session}\n\n";
            $msg .= "Post Info\n{$post}\n\n";
            $msg .= "Server\n{$server}";
        }
    } else {
        if ($markup === true) {
            $msg = "<pre><strong>Error:</strong> {$message}<br /></pre>";
        } else {
            $msg = "Error: {$message}\n";
        }
    }

    return $msg;
}
