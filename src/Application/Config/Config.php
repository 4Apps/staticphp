<?php

/**
 * StaticPHP main configuration file
 */

use Symfony\Component\Dotenv\Dotenv;
use System\Modules\Core\Models\Logger;

/**
 * We are gonna start with loading of the .env files
 */
$dotenv = new Dotenv();
if (file_exists(BASE_PATH . '/.env')) {
    $dotenv->load(BASE_PATH . '/.env');
}
if (file_exists(APP_PATH . '/.env')) {
    $dotenv->overload(APP_PATH . '/.env');
}


/**
 * Default config array
 *
 * (default value: [])
 *
 * @var mixed[]
 * @access public
 */
$config = [];

/*
|--------------------------------------------------------------------------
| General
|--------------------------------------------------------------------------
*/
$config['base_url'] = null; // NULL for auto detect
$config['disable_twig'] = false; // Option to disable twig template engine

/*
|--------------------------------------------------------------------------
| Debug
|--------------------------------------------------------------------------
*/
// Set environment
$config['environment'] = !empty($_ENV['APP_ENV']) ? $_ENV['APP_ENV'] : 'unknown';
$config['debug']       = ($config['environment'] !== 'dev' ? false : true);
$config['debug_ips']   = ['::1', '127.0.0.1'];

/*
| Logging
*/
$config['logging'] = [
    'display_level' => !empty($_ENV['LOGGING_DISPLAY_LEVEL']) ? $_ENV['LOGGING_DISPLAY_LEVEL'] : Logger::ERROR,
    'log_level' => !empty($_ENV['LOGGING_LOG_LEVEL']) ? $_ENV['LOGGING_LOG_LEVEL'] : Logger::ERROR,
    'report_level' => !empty($_ENV['LOGGING_REPORT_LEVEL']) ? $_ENV['LOGGING_REPORT_LEVEL'] : Logger::ERROR,

    'report_email' => !empty($_ENV['LOGGING_REPORT_EMAIL']) ? $_ENV['LOGGING_REPORT_EMAIL'] : null,
    'report_webhook' => !empty($_ENV['LOGGING_REPORT_WEBHOOK']) ? $_ENV['LOGGING_REPORT_WEBHOOK'] : null,

/*
| Send email function
|
| * Will pass arguments - $to, $subject, $message, $headers in that order
| * Can be inline function or string for a function name
| * default - php built-in "mail"

  Example:
'report_email_func' => function($to, $subject, $message, $headers = '', $type = 'regular'){
    if (function_exists('sendEmail')) {
        sendEmail($to, $subject, $message, [], $type);

        $message = str_replace(
            ['&nbsp;', '<br />', '<strong>', '</strong>'],
            [' ', "\n", '**', '**'],
            $message
        );
        $message = preg_replace('/<[^>]*>/', '', $message);
        sendIM('**'.$subject."**\n".substr($message, 0, 400)."...");
    } else {
        mail($to, $subject, $message, $headers);
    }
};
*/
    'report_email_func' => 'mail',
    'report_webhook_func' => function($endpoint, $subject, $message, $type = 'regular'){
        if (function_exists('sendIM')) {
            sendIM($endpoint, $subject, $message, $type);
        }
    },
];

/*
|--------------------------------------------------------------------------
| Web server variables
|
| Set where various variables will be taken from
| In most cases these should work by default
|--------------------------------------------------------------------------
*/
$config['request_uri']  = & $_SERVER['REQUEST_URI'];
$config['query_string'] = & $_SERVER['QUERY_STRING'];
$config['script_name']  = & $_SERVER['SCRIPT_NAME'];
$config['client_ip']    = & $_SERVER['REMOTE_ADDR'];

/*
|--------------------------------------------------------------------------
| Uris
|
| URL prefixes can be useful when for example identifying ajax requests,
| they will be stored in config variable and can be checked with Router::hasPrefix('ajax') === true
| they must be in correct sequence. For example, if url is /ajax/test/en/open/29, where ajax and test is prefixes,
| then array should look something like this - ['ajax', 'test']. In this case /test/en/open/29 will also work.
|--------------------------------------------------------------------------
*/
$config['url_prefixes'] = [];

/*
|--------------------------------------------------------------------------
| Autoload
|
| Place filenames without ".php" extension here to autoload various files and classes
| Possible formats: Application/Module/Filename, Module/Filename, Filename (only to load global config)
|--------------------------------------------------------------------------
*/
$config['autoload_configs'] = ['App'];
$config['autoload_helpers'] = ['Bootstrap'];

/*
|--------------------------------------------------------------------------
| Hooks
|
| Currently only "before controller" hook is supported and will be called right
| before including controller file. It passes three parametrs as references - $file,
| $module, $class and $method, meaning callback can override current controller.
|--------------------------------------------------------------------------
*/
$config['before_controller'] = [];
