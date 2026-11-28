<?php

/**
 * SessionCookie.php
 *
 * Session of the web portal in a cookie that its scripts cannot read
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

use Carbure\Resources\TrustedBrowser;

/**
 * The JWT of the portal is kept by the browser in an HttpOnly cookie (out of reach of a
 * script injected in the page), SameSite=Strict (not sent by the requests of another
 * site), Secure over HTTPS, sent only to the API. The mobile apps, the MCP server and the
 * API clients keep the Authorization header.
 */
final class SessionCookie {

    /**
     * Name of the cookie
     */
    public const NAME = 'carbure_session';

    /**
     * Last cookie sent in this request: the token, '' when cleared, null if none (tests)
     */
    private static ?string $sent = null;

    /**
     * Send the session to the browser: the cookie expires with the token.
     *
     * @param string $token  The JWT
     * @param int    $expire Expiration of the token (timestamp)
     * @return void
     */
    public static function send(string $token, int $expire): void
    {
        self::$sent = $token;
        self::write($token, $expire);
    }

    /**
     * Close the session of the browser (sign out): the cookie is removed.
     *
     * @return void
     */
    public static function clear(): void
    {
        self::$sent = '';
        self::write('', time() - 86400);
    }

    /**
     * The token sent by the last send() or clear() of this request (tests).
     *
     * @return string|null The token, '' after clear(), null if none
     */
    public static function sent(): ?string
    {
        return self::$sent;
    }

    /**
     * Forget the cookie sent (tests).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$sent = null;
    }

    /**
     * The token of the cookie of the request, null without one.
     *
     * @return string|null
     */
    public static function token(): ?string
    {
        $token = $_COOKIE[self::NAME] ?? null;
        return is_string($token) && preg_match('/^[\w-]+\.[\w-]+\.[\w-]+$/', $token) ? $token : null;
    }

    /**
     * Whether a request authenticated by the cookie may run (protection against the
     * requests forged by another site, on top of SameSite=Strict): a read always; a write
     * only when the browser says it comes from the portal itself (Sec-Fetch-Site:
     * same-origin), or when its Origin is the address of the server.
     *
     * @return bool
     */
    public static function originAllowed(): bool
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }
        $site = Webservice::getHeader('Sec-Fetch-Site');
        if ($site !== null) {
            return strtolower($site) === 'same-origin';
        }
        $origin = Webservice::getHeader('Origin');
        $host = $origin === null ? null : parse_url($origin, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        foreach (self::serverHosts() as $server) {
            if (strcasecmp($host, $server) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Names of the server: the host of the request, the one given by a reverse proxy and
     * the one of the public address.
     *
     * @return list<string>
     */
    private static function serverHosts(): array
    {
        $hosts = [(string)($_SERVER['HTTP_HOST'] ?? ''), (string)Webservice::getHeader('X-Forwarded-Host')];
        if (Config::has('public_url')) {
            $hosts[] = (string)Config::get('public_url');
        }
        $names = [];
        foreach ($hosts as $host) {
            $host = trim(explode(',', $host)[0]);
            $name = parse_url(str_contains($host, '://') ? $host : 'http://' . $host, PHP_URL_HOST);
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * Path of the cookie: the API (/api), also when Carbure is served under a sub-path.
     *
     * @return string
     */
    public static function path(): string
    {
        $path = (string)strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');
        $position = strpos($path . '/', '/api/');
        return $position === false ? '/' : substr($path, 0, $position) . '/api';
    }

    /**
     * Write the cookie.
     *
     * @param string $token  The value
     * @param int    $expire Expiration (timestamp)
     * @return void
     */
    private static function write(string $token, int $expire): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::NAME, $token, [
            'expires' => $expire,
            'path' => self::path(),
            'secure' => TrustedBrowser::isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
}
