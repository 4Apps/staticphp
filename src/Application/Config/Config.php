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

// Who else may see debug output - the query log, the timing panel, stack traces. Only
// consulted when 'debug' above is false, and it is the application's decision rather than
// the framework's: staticphp deliberately makes no trust decision of its own here.
//
// It runs during bootstrap, before sessions, the database and routing exist, because
// query logging has to be armed before the first query runs. So it can read $_SERVER,
// $_COOKIE and config, and nothing else - keep it cheap and keep it honest. Anything it
// throws is treated as "no".
//
// A signed cookie is the natural fit: real authentication, no session needed, and it
// works from any network. Hand yourself one out of a controller behind your own login.
//
// php's variables_order is GPCS here, so $_ENV holds what dotenv put there - the .env
// file - and not the process environment. getenv() is the other way round, since dotenv
// does not putenv() by default. Read both, or the gate silently stays shut depending on
// where the secret was set.
//
// $config['debug_check'] = function (): bool {
//     $secret = $_ENV['DEBUG_SECRET'] ?? getenv('DEBUG_SECRET') ?: '';
//     $token = $_COOKIE['sp_debug'] ?? '';
//     if ($token === '' || $secret === '') {
//         return false;
//     }
//
//     return hash_equals(hash_hmac('sha256', 'debug', $secret), $token);
// };
//
// An address list is what this used to be, and is available if you still want it - but
// note it is only as trustworthy as trust_proxy_headers and the proxy in front:
//
// $config['debug_check'] = fn(): bool => in_array(
//     StaticPHP\Core\Models\Router::clientIp(),
//     ['::1', '127.0.0.1'],
//     true
// );
$config['debug_check'] = null;

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

// NULL for auto detect: REMOTE_ADDR, or the client behind a proxy when trust_proxy_headers
// is on - see below. Bind it to $_SERVER['REMOTE_ADDR'] by reference to pin it to the
// connection regardless of what any header claims.
$config['client_ip']    = null;

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
| Trust proxy headers
|
| Enable when a reverse proxy terminates tls or listens on a different port than this
| application does. X-Forwarded-Proto then decides whether the request counts as
| encrypted, instead of the connection this process sees - which behind a proxy describes
| the internal hop. Without it a container on plain http port 8080 behind an https proxy
| advertises itself as https://example.com:8080/, and its session cookie loses the Secure
| flag on exactly the deployment where it matters most.
|
| X-Forwarded-Port is honoured too, but is rarely needed: nginx and traefik set the proto
| header by default and the port one only when asked, so the scheme's own default port is
| assumed when it is absent.
|
| It also decides where client_ip comes from: REMOTE_ADDR is the proxy's own address, so
| without this every request appears to come from the proxy - in the logs, in audit
| trails, and in anything an application does per client.
|
| Off by default because all of these headers are client supplied unless the proxy
| overwrites them. Only turn it on when the proxy is the sole route to the application.
|--------------------------------------------------------------------------
*/
$config['trust_proxy_headers'] = false;

/*
|--------------------------------------------------------------------------
| Trusted proxy hops
|
| How many proxies sit in front. Only consulted when trust_proxy_headers is on.
|
| X-Forwarded-For is appended to rather than overwritten - nginx's
| $proxy_add_x_forwarded_for tacks the peer it saw onto whatever the client sent - so the
| leftmost entry is whatever the client felt like claiming. Entries are counted from the
| right instead, one per hop: with a single proxy the rightmost is the address it saw
| itself and cannot be forged.
|
| 1 for one reverse proxy. 2 when something else fronts that, a cdn or a load balancer.
| Counting too few is safe and reports the nearest proxy; counting too many walks back
| into the part of the header a client can write.
|--------------------------------------------------------------------------
*/
$config['trusted_proxy_hops'] = 1;

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
