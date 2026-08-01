<?php

namespace System\Modules\Core\Models;

class Request
{
    public static function internal(
        string $url,
        ?array $post = null,
        ?array $query = null,
        bool $https = false
    ): string {
        // Create command array
        $cmd_arr = ['php', PUBLIC_PATH . '/index.php'];

        if (!empty($post) && is_array($post)) {
            array_push($cmd_arr, '--post');
            array_push($cmd_arr, http_build_query($post));
        }

        if (!empty($query) && is_array($query)) {
            array_push($cmd_arr, '--query');
            array_push($cmd_arr, http_build_query($query));
        }

        if (!empty($https)) {
            array_push($cmd_arr, '--https');
        }

        array_push($cmd_arr, $url);
        $cmd_arr = array_map('escapeshellarg', $cmd_arr);

        // Prepend script
        array_unshift($cmd_arr, 'LC_ALL=lv_LV.utf8');

        // Implode the command and execute it
        $cmd = implode(' ', $cmd_arr);
        exec($cmd, $output, $return_code);

        return implode("\n", $output);
    }

    /**
     * Check whether a response body looks like an error page.
     *
     * The return type used to be declared as string, so php coerced the boolean on the
     * way out - false became "" and true became "1", and every strict comparison against
     * the result was wrong.
     *
     * @access public
     * @static
     * @param  string $data Response body
     * @return bool
     */
    public static function httpErrorInData(string $data): bool
    {
        $error = stripos($data, '403 Forbidden') !== false;
        $error = $error || stripos($data, '404 Not Found') !== false;
        $error = $error || stripos($data, '500 Internal Server Error') !== false;
        $error = $error || stripos($data, 'syntax error') !== false;

        return $error;
    }

    /**
     * Populate the request superglobals from command line arguments.
     *
     * Request::internal() runs the front controller as a cli process and passes the url,
     * post data and query string as arguments, but nothing read them back - so every
     * internal request was served as if it had been made against "/" with no input.
     * Called from the bootstrap before the configuration binds its references to $_SERVER.
     *
     * Recognised arguments: [--post <urlencoded>] [--query <urlencoded>] [--https] <url>
     *
     * @access public
     * @static
     * @param  ?array $argv (default: null, meaning $_SERVER['argv'])
     * @return void
     */
    public static function populateFromCli(?array $argv = null): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }

        $argv = ($argv === null ? ($_SERVER['argv'] ?? []) : $argv);

        // Drop the script name
        array_shift($argv);

        $url = '';
        $post = [];
        $query = [];
        $https = false;

        for ($i = 0; $i < count($argv); ++$i) {
            switch ($argv[$i]) {
                case '--post':
                    parse_str((string) ($argv[++$i] ?? ''), $post);
                    break;

                case '--query':
                    parse_str((string) ($argv[++$i] ?? ''), $query);
                    break;

                case '--https':
                    $https = true;
                    break;

                default:
                    $url = (string) $argv[$i];
                    break;
            }
        }

        $url = '/' . ltrim($url, '/');
        $queryString = http_build_query($query);

        $_GET = $query;
        $_POST = $post;
        $_REQUEST = $query + $post;

        $_SERVER['REQUEST_URI'] = $url . (empty($queryString) ? '' : "?{$queryString}");
        $_SERVER['QUERY_STRING'] = $queryString;
        $_SERVER['REQUEST_METHOD'] = (empty($post) ? 'GET' : 'POST');
        $_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '';
        $_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $_SERVER['SERVER_PORT'] = $_SERVER['SERVER_PORT'] ?? ($https ? 443 : 80);

        if ($https === true) {
            $_SERVER['HTTPS'] = 'on';
        }

        if (empty($post)) {
            return;
        }

        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
    }
}
