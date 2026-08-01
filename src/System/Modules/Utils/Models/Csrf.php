<?php

namespace System\Modules\Utils\Models;

use System\Modules\Core\Models\Config;

/**
 * CSRF token generation and verification.
 *
 * The framework does not apply this for you - it cannot know which of an application's
 * routes change state. Wire it up yourself:
 *
 *   1. Start a session before using any of this; the token is stored in $_SESSION.
 *   2. Put the token in every state changing form:
 *        <input type="hidden" name="{{ csrfField() }}" value="{{ csrfToken() }}">
 *      or, for fetch/XHR, send it as the X-CSRF-Token header.
 *   3. Reject requests that fail the check, ideally from a 'before_controller' hook:
 *        if (Fv::isPost() && Csrf::validateRequest() === false) {
 *            throw new ErrorMessage(message: 'Invalid CSRF token', httpStatusCode: 403);
 *        }
 *
 * Tokens are per session rather than per form, which is the usual trade off: it keeps
 * multiple tabs and the back button working, at the cost of not binding a token to one
 * specific form submission.
 */
class Csrf
{
    /**
     * Session key the token is stored under.
     *
     * @var string
     * @access public
     */
    public const SESSION_KEY = '__csrf_token';

    /**
     * Form field and header name carrying the token.
     *
     * @var string
     * @access public
     */
    public const FIELD_NAME = '__csrf';
    public const HEADER_NAME = 'HTTP_X_CSRF_TOKEN';

    /**
     * Get the current token, creating one if this session does not have it yet.
     *
     * @access public
     * @static
     * @return string
     */
    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new \RuntimeException('A session must be started before using CSRF tokens');
        }

        if (empty($_SESSION[self::SESSION_KEY]) || is_string($_SESSION[self::SESSION_KEY]) === false) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Name to use for the hidden form field.
     *
     * @access public
     * @static
     * @return string
     */
    public static function fieldName(): string
    {
        return self::FIELD_NAME;
    }

    /**
     * Ready made hidden input for a form.
     *
     * @access public
     * @static
     * @return string
     */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD_NAME . '" value="'
            . htmlspecialchars(self::token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
    }

    /**
     * Compare a submitted token against the one held in the session.
     *
     * @access public
     * @static
     * @param  ?string $token
     * @return bool
     */
    public static function validate(?string $token): bool
    {
        if (empty($token) || session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $expected = $_SESSION[self::SESSION_KEY] ?? null;
        if (empty($expected) || is_string($expected) === false) {
            return false;
        }

        // hash_equals compares in constant time, so the result does not leak how much of
        // the token was correct
        return hash_equals($expected, $token);
    }

    /**
     * Validate the token carried by the current request.
     *
     * Looks in $_POST first, then the X-CSRF-Token header for fetch/XHR callers.
     *
     * @access public
     * @static
     * @return bool
     */
    public static function validateRequest(): bool
    {
        $token = $_POST[self::FIELD_NAME] ?? null;
        if (is_string($token) === false) {
            $token = $_SERVER[self::HEADER_NAME] ?? null;
        }

        return self::validate(is_string($token) ? $token : null);
    }

    /**
     * Discard the current token so the next call to token() issues a new one.
     *
     * Worth doing on login and logout, alongside regenerating the session id.
     *
     * @access public
     * @static
     * @return void
     */
    public static function reset(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /*
    |-------------------------------------------------------------------------------------------------------------------
    | Register Twig functions
    |-------------------------------------------------------------------------------------------------------------------
    */

    /**
     * Register csrfToken(), csrfField() and csrfFieldName() with the view engine.
     *
     * @access public
     * @static
     * @return void
     */
    public static function registerTwig(): void
    {
        $engine = Config::get('view_engine');
        if (empty($engine)) {
            return;
        }

        $engine->addFunction(new \Twig\TwigFunction('csrfToken', function () {
            return self::token();
        }));

        $engine->addFunction(new \Twig\TwigFunction('csrfFieldName', function () {
            return self::fieldName();
        }));

        $engine->addFunction(new \Twig\TwigFunction(
            'csrfField',
            function () {
                return self::field();
            },
            ['is_safe' => ['html']]
        ));
    }
}
