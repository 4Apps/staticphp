<?php

/*
|--------------------------------------------------------------------------
| PDO backed up session class
|
| For table structure look for table_sessions_*.sql file.
|--------------------------------------------------------------------------
*/

namespace System\Modules\Utils\Models\Sessions;

class Sessions implements \SessionHandlerInterface
{
    protected ?Sessions $backupHandler = null;

    protected int $expire = 0;
    protected string $sessionName = '';
    protected string $salt = '';

    /**
     * Cookie parameters applied in register().
     *
     * @var array
     */
    protected array $cookieParams = [];

    public function __construct(
        $sessionName = 'S',
        ?Sessions $backupHandler = null,
        int $lifetime = 86400,
        string $sameSite = 'Lax'
    ) {
        ini_set('session.use_only_cookies', true);
        ini_set('session.use_strict_mode', true);

        ini_set('session.gc_probability', 1);
        ini_set('session.gc_divisor', 100);

        ini_set('session.gc_maxlifetime', $lifetime);

        // session.entropy_file, session.hash_function and session.hash_bits_per_character
        // were removed in PHP 7.1 and had no effect here. Session id generation is handled
        // by session.sid_length / session.sid_bits_per_character, whose defaults are fine.

        $this->sessionName = $sessionName;
        $this->backupHandler = $backupHandler;

        // Binds a session record to the client that created it. This is a weak check -
        // the header is client controlled and an attacker replaying a stolen cookie can
        // replay the header with it - so it is a speed bump, not an authentication factor.
        // Truncated to 40 characters because that is the width of the salt column in
        // table_sessions_*.sql; widening it would need a migration on existing installs.
        // ?? '' keeps a request without a User-Agent header from raising a warning.
        $this->salt = substr(hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 40);
        $this->expire = session_cache_expire() * 60;

        $this->cookieParams = [
            'lifetime' => $lifetime,
            'path' => '/',
            'domain' => '',
            // Only send the cookie over https when the request itself arrived over https,
            // so that plain http development setups keep working
            'secure' => (isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) === 'on'),
            'httponly' => true,
            'samesite' => $sameSite,
        ];
    }

    public function register(): void
    {
        session_name($this->sessionName);
        session_set_cookie_params($this->cookieParams);
        session_set_save_handler($this, true);
    }

    public function start(): void
    {
        session_start();
    }

    /**
     * Issue a new session id, keeping the session data.
     *
     * Call this immediately after any privilege change - most importantly after a
     * successful login - so that a session id an attacker planted beforehand stops being
     * valid. Without it the framework has no session fixation defence.
     */
    public function regenerate(bool $deleteOldSession = true): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        return session_regenerate_id($deleteOldSession);
    }

    public function id(string $id)
    {
        return "{$this->sessionName}_{$id}";
    }

    public function open(string $path, string $name): bool
    {
        if (!empty($this->backupHandler)) {
            return $this->backupHandler->open($path, $name);
        }
        return true;
    }

    public function close(): bool
    {
        if (!empty($this->backupHandler)) {
            return $this->backupHandler->close();
        }

        return true;
    }

    public function read(string $id): string|false
    {
        if (!empty($this->backupHandler)) {
            return $this->backupHandler->read($id);
        }

        return '';
    }

    public function write(string $id, string $data): bool
    {
        if (!empty($this->backupHandler)) {
            return $this->backupHandler->write($id, $data);
        }

        return true;
    }

    public function destroy(string $id): bool
    {
        // Delete cookie, if possible. Clearing it has to repeat the flags it was set with,
        // otherwise the browser may keep the original cookie in place.
        if (headers_sent() == false) {
            // setcookie() takes "expires", not the "lifetime" key session_set_cookie_params
            // uses, and warns on any key it does not recognise - so build it explicitly
            setcookie($this->sessionName, '', [
                'expires' => time() - 1,
                'path' => $this->cookieParams['path'] ?? '/',
                'domain' => $this->cookieParams['domain'] ?? '',
                'secure' => $this->cookieParams['secure'] ?? false,
                'httponly' => $this->cookieParams['httponly'] ?? true,
                'samesite' => $this->cookieParams['samesite'] ?? 'Lax',
            ]);
        }

        if (!empty($this->backupHandler)) {
            return $this->backupHandler->destroy($id);
        }

        return true;
    }

    public function gc(int $maxLifetime): int|false
    {
        if (!empty($this->backupHandler)) {
            return $this->backupHandler->gc($maxLifetime);
        }

        return false;
    }
}
