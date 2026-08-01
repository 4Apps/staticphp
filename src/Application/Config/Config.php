<?php

/**
 * StaticPHP main configuration file
 */

use Symfony\Component\Dotenv\Dotenv;
use StaticPHP\Core\Models\Logger;

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

// Skip building the twig environment even when the library is installed. Note that
// staticphp-core only suggests twig/twig - leaving it out of an api only application's
// composer.json is what actually saves the work, because composer's "files" autoload is
// eager and twig plus its symfony polyfills load eight files on every request.
$config['disable_twig'] = false;

/*
|--------------------------------------------------------------------------
| Debug
|--------------------------------------------------------------------------
*/
// Set environment.
// A front controller may pin it - the test probe does, so it can exercise the production
// error pages - and an explicit choice beats a dotfile.
$config['environment'] = (
    defined('SP_ENVIRONMENT')
    ? SP_ENVIRONMENT
    : (!empty($_ENV['APP_ENV']) ? $_ENV['APP_ENV'] : 'unknown')
);
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
    'report_webhook_func' => function ($endpoint, $subject, $message, $type = 'regular') {
        if (function_exists('sendIM')) {
            sendIM($endpoint, $subject, $message, $type);
        }
    },
];

/*
|--------------------------------------------------------------------------
| Error pages
|
| Absolute paths to plain php templates replacing the built in ones. Leave null for the
| framework's own pages.
|
|   'status' - what the public sees: a status code and a sentence, nothing internal
|   'debug'  - what a developer sees when $config['debug'] is on: message, source,
|              stack trace and the whole request
|
| Plain php rather than twig on purpose. An error page that needs the template engine is
| unable to render a broken template engine, which is when it is needed most. The same
| goes for stylesheets and scripts - both pages are one self contained file.
|
| See StaticPHP\Core\Exceptions\ErrorPage for the variables a template receives.
|--------------------------------------------------------------------------
*/
$config['error_pages'] = [
    'status' => null,
    'debug' => null,
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
| Allowed hosts
|
| Hostnames this application answers on. base_url is derived from the Host header,
| and ends up in redirects, emails and cached pages - listing the expected hosts here
| stops a client from pointing those links at a site it controls. Include the port when
| the site is served on a non standard one, e.g. 'localhost:8080'.
|
| Leaving this empty only syntax checks the header, which is weaker but keeps existing
| installs working. Set it in production.
|--------------------------------------------------------------------------
*/
$config['allowed_hosts'] = [];

/*
|--------------------------------------------------------------------------
| Template environment values
|
| Names of $_ENV entries exposed to templates as "env". The whole environment used to
| be handed over, which meant every template could read whatever is in .env, including
| database credentials. List only what the templates actually need.
|--------------------------------------------------------------------------
*/
$config['view_env_keys'] = [];

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
| Module paths
|
| Directories holding modules, addressable by name from Load:: and from the autoload
| lists below. "staticphp" is reserved - it always resolves to the framework's own
| modules, wherever composer installed them, and cannot be listed or overridden here.
|
| Add an entry per application when one repository serves several - each front controller
| defines its own PUBLIC_PATH, so APP_MODULES_PATH already points at the right one; this
| is only needed to reach *another* application's modules.
|
|   'site2' => BASE_PATH . '/site2/Modules',
|
| The value is the directory that contains the modules, not its parent. This replaces the
| old convention where the third path segment named a directory under BASE_PATH, which
| assumed every loadable tree was a sibling of the application.
|--------------------------------------------------------------------------
*/
$config['module_paths'] = [];

/*
|--------------------------------------------------------------------------
| Autoload
|
| Place filenames without ".php" extension here to autoload various files and classes
| Possible formats: ModulePath/Module/Filename, Module/Filename, Filename (only to load
| global config from APP_PATH)
|
| To pull in something the framework ships, name it through "staticphp":
|
|   $config['autoload_helpers'] = ['Bootstrap', 'staticphp/Utils/Helpers'];
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
