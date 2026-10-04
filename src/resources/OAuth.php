<?php

/**
 * OAuth.php
 *
 * OAuth 2.1 authorization server of the MCP server, for the AI agents that only take
 * the URL of the server (Claude web, Desktop and mobile connectors), as the MCP
 * specification describes it:
 *
 * 1. the agent calls /api/mcp, gets 401 with the URL of the metadata of the resource
 *    (RFC 9728), then the metadata of the authorization server (RFC 8414);
 * 2. it registers itself (dynamic client registration, RFC 7591);
 * 3. it opens /api/oauth/authorize in the browser of the user: the portal asks the
 *    user to log in and to allow the agent to read the data of the household;
 * 4. it exchanges the authorization code for an access token (PKCE S256 required),
 *    then refreshes it with the refresh token.
 *
 * The access tokens are API tokens (api_tokens): they are listed in the AI agents tab
 * of the portal, where the user revokes them, and are accepted by the MCP server only.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

final class OAuth {

    /** Prefix of the client ids */
    private const CLIENT_PREFIX = 'cbc_';

    /** Lifetime of an authorization code (seconds) */
    private const CODE_LIFETIME = 300;

    /** Lifetime of an access token (days); the refresh token gives a new one */
    private const TOKEN_DAYS = 30;

    /** Registered clients kept at most (the oldest unused ones are deleted beyond) */
    private const MAX_CLIENTS = 100;

    /** Methods of authentication of a client at the token endpoint */
    private const AUTH_METHODS = ['none', 'client_secret_post', 'client_secret_basic'];

    /** Fields of an authorization request kept between the authorize endpoint and the portal */
    private const REQUEST_FIELDS = ['response_type', 'client_id', 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method', 'scope', 'resource'];

    /**
     * Metadata of the protected resource, the MCP server (RFC 9728).
     *
     * @return string JSON
     */
    #[ApiRoute('/.well-known/oauth-protected-resource', method: 'GET', public: true, raw: true)]
    public static function protectedResource()
    {
        return self::json([
            'resource' => self::baseUrl() . '/api/mcp',
            'authorization_servers' => [self::baseUrl()],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Carbure',
        ]);
    }

    /**
     * Metadata of the protected resource at the path of the MCP server, where some
     * clients look for it first (RFC 9728, section 3.1).
     *
     * @return string JSON
     */
    #[ApiRoute('/.well-known/oauth-protected-resource/api/mcp', method: 'GET', public: true, raw: true)]
    public static function protectedResourceOfMcp()
    {
        return self::protectedResource();
    }

    /**
     * Metadata of the authorization server (RFC 8414).
     *
     * @return string JSON
     */
    #[ApiRoute('/.well-known/oauth-authorization-server', method: 'GET', public: true, raw: true)]
    public static function authorizationServer()
    {
        $base = self::baseUrl();
        return self::json([
            'issuer' => $base,
            'authorization_endpoint' => "$base/api/oauth/authorize",
            'token_endpoint' => "$base/api/oauth/token",
            'registration_endpoint' => "$base/api/oauth/register",
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => self::AUTH_METHODS,
        ]);
    }

    /**
     * Register a client (RFC 7591). The other fields of the metadata are ignored.
     *
     * @param array|null  $redirect_uris              Redirection URIs: https, or http on the loopback
     * @param string|null $client_name                Name shown to the user (and given to the token)
     * @param string      $token_endpoint_auth_method none (public client, PKCE), client_secret_post or client_secret_basic
     * @param mixed       ...$metadata                Other fields of the metadata (ignored)
     * @return string JSON: the client, 201
     */
    #[ApiRoute('/oauth/register', method: 'POST', public: true, raw: true)]
    public static function register($redirect_uris = null, $client_name = null, $token_endpoint_auth_method = 'none', ...$metadata)
    {
        if (!Mcp::enabled()) {
            return self::error(403, 'access_denied', 'The MCP server is disabled');
        }
        if (!is_array($redirect_uris) || !$redirect_uris || count($redirect_uris) > 10) {
            return self::error(400, 'invalid_redirect_uri', 'redirect_uris must list 1 to 10 URIs');
        }
        foreach ($redirect_uris as $uri) {
            if (!self::validRedirectUri($uri)) {
                return self::error(400, 'invalid_redirect_uri', 'Redirection URIs must use https, or http on the loopback, without fragment');
            }
        }
        if (!in_array($token_endpoint_auth_method, self::AUTH_METHODS, true)) {
            return self::error(400, 'invalid_client_metadata', 'Unsupported token_endpoint_auth_method');
        }
        $name = trim((string)$client_name);
        $name = $name === '' ? 'AI agent' : mb_substr($name, 0, 100);

        // Registration is open: the number of clients is bounded
        Db::execute("DELETE FROM oauth_clients WHERE client_id NOT IN (SELECT client_id FROM api_tokens WHERE client_id IS NOT NULL)
                     AND created_at < NOW() - INTERVAL 1 DAY", "");
        if (Db::queryOne("SELECT COUNT(*) AS n FROM oauth_clients", "")['n'] >= self::MAX_CLIENTS) {
            return self::error(400, 'invalid_client_metadata', 'Too many registered clients, try again tomorrow');
        }

        $clientId = self::CLIENT_PREFIX . bin2hex(random_bytes(16));
        $secret = $token_endpoint_auth_method === 'none' ? null : bin2hex(random_bytes(32));
        Db::execute("INSERT INTO oauth_clients (client_id, client_secret_hash, name, redirect_uris) VALUES (?, ?, ?, ?)",
            "ssss", $clientId, $secret === null ? null : hash('sha256', $secret), $name, json_encode(array_values($redirect_uris)));

        $client = [
            'client_id' => $clientId,
            'client_id_issued_at' => time(),
            'client_name' => $name,
            'redirect_uris' => array_values($redirect_uris),
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $token_endpoint_auth_method,
        ];
        if ($secret !== null) {
            $client += ['client_secret' => $secret, 'client_secret_expires_at' => 0];
        }
        return self::json($client, 201);
    }

    /**
     * Authorization endpoint: checks the request, then sends the browser to the portal,
     * where the user logs in and allows the agent (or to the agent with an error).
     *
     * @return string Empty body, 302 (JSON error when the agent cannot be trusted)
     */
    #[ApiRoute('/oauth/authorize', method: 'GET', public: true, raw: true)]
    public static function authorize($response_type = null, $client_id = null, $redirect_uri = null, $state = null,
        $code_challenge = null, $code_challenge_method = null, $scope = null, $resource = null, ...$other)
    {
        try {
            $location = self::authorizationRedirect(compact(self::REQUEST_FIELDS));
        } catch (Error $e) {
            return self::error(400, 'invalid_request', $e->getMessage());
        }
        http_response_code(302);
        header("Location: $location");
        return '';
    }

    /**
     * Where the authorize endpoint sends the browser: the consent page of the portal, or
     * the agent with an error.
     *
     * @param array $request Fields of the authorization request
     * @return string The URL
     * @throws Error If the client or its redirection URI is unknown: the browser must not go there
     */
    public static function authorizationRedirect(array $request)
    {
        try {
            self::checkRequest($request);
        } catch (InvalidArgumentException $e) {
            // The agent is known: the error goes back to it
            return self::redirectUri($request, ['error' => $e->getMessage()]);
        }
        return self::baseUrl() . '/portal/?oauth_request=' . rawurlencode(http_build_query(self::requestFields($request)));
    }

    /**
     * Agent asking for the authorization, for the consent page of the portal.
     *
     * @param string $request The authorization request (query string given to the portal)
     * @return array name of the agent and host where the browser goes back
     * @throws Error If the request is invalid
     */
    #[ApiRoute('/oauth/client', method: 'GET')]
    public static function client($request)
    {
        $fields = self::parseRequest($request);
        $client = self::checkRequestOrFail($fields);
        return ['name' => $client['name'], 'redirect_host' => parse_url($fields['redirect_uri'], PHP_URL_HOST)];
    }

    /**
     * Answer of the user on the consent page: an authorization code for the agent
     * (valid 5 minutes, once), or a refusal.
     *
     * @param string $request  The authorization request (query string given to the portal)
     * @param bool   $approved True if the user allows the agent
     * @return array redirect: where the browser goes back to the agent
     * @throws Error If the request is invalid, or the MCP server disabled (403)
     */
    #[ApiRoute('/oauth/approve', method: 'POST')]
    public static function approve($request, $approved = true)
    {
        $fields = self::parseRequest($request);
        $client = self::checkRequestOrFail($fields);

        if (!filter_var($approved, FILTER_VALIDATE_BOOLEAN)) {
            return ['redirect' => self::redirectUri($fields, ['error' => 'access_denied'])];
        }

        $code = bin2hex(random_bytes(32));
        Db::execute("DELETE FROM oauth_codes WHERE expires_at < NOW()", "");
        Db::execute("INSERT INTO oauth_codes (code_hash, client_id, user_id, redirect_uri, code_challenge, expires_at)
                     VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL ? SECOND)", "sssssi",
            hash('sha256', $code), $client['client_id'], Jwt::getUserIdFromToken(), $fields['redirect_uri'], $fields['code_challenge'], self::CODE_LIFETIME);

        return ['redirect' => self::redirectUri($fields, ['code' => $code, 'iss' => self::baseUrl()])];
    }

    /**
     * Token endpoint: an authorization code (with its PKCE verifier) or a refresh token
     * for an access token and a new refresh token.
     *
     * @return string JSON: the tokens, or an OAuth error
     */
    #[ApiRoute('/oauth/token', method: 'POST', public: true, raw: true)]
    public static function token($grant_type = null, $code = null, $redirect_uri = null, $client_id = null,
        $code_verifier = null, $refresh_token = null, $client_secret = null, $resource = null, $scope = null, ...$other)
    {
        if (!Mcp::enabled()) {
            return self::error(403, 'access_denied', 'The MCP server is disabled');
        }

        // Client: client_id (+ secret in the form, or HTTP Basic)
        $basic = self::basicCredentials();
        $clientId = $basic[0] ?? $client_id;
        $secret = $basic[1] ?? $client_secret;
        $client = is_string($clientId) ? Db::queryOne("SELECT client_id, client_secret_hash, name FROM oauth_clients WHERE client_id = ?", "s", $clientId) : null;
        if (!$client || ($client['client_secret_hash'] !== null && !hash_equals($client['client_secret_hash'], hash('sha256', (string)$secret)))) {
            if (!headers_sent()) {
                header('WWW-Authenticate: Basic realm="Carbure"');
            }
            return self::error(401, 'invalid_client', 'Unknown client or wrong secret');
        }

        if ($grant_type === 'authorization_code') {
            return self::exchangeCode($client, $code, $redirect_uri, $code_verifier);
        }
        if ($grant_type === 'refresh_token') {
            return self::refresh($client, $refresh_token);
        }
        return self::error(400, 'unsupported_grant_type', 'grant_type must be authorization_code or refresh_token');
    }

    /**
     * Value of the WWW-Authenticate header of the MCP server: where an agent finds how
     * to get a token.
     *
     * @return string The header value
     */
    public static function challenge()
    {
        return 'Bearer realm="Carbure", resource_metadata="' . self::baseUrl() . '/.well-known/oauth-protected-resource"';
    }

    /**
     * Public URL of the server: setting public_url, or the scheme (X-Forwarded-Proto of
     * a reverse proxy) and the host of the request.
     *
     * @return string The URL, without trailing slash
     */
    public static function baseUrl()
    {
        if (Config::has('public_url')) {
            return rtrim(Config::get('public_url'), '/');
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string)Webservice::getHeader('X-Forwarded-Proto')) === 'https';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        if (!preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$|^\[[0-9A-Fa-f:]+\](:\d+)?$/', $host)) {
            $host = 'localhost';
        }
        return ($https ? 'https' : 'http') . "://$host";
    }

    /**
     * Exchange an authorization code (once) for the tokens.
     *
     * @param array       $client   The client
     * @param string|null $code     The authorization code
     * @param string|null $redirect The redirection URI of the authorization request
     * @param string|null $verifier The PKCE verifier
     * @return string JSON
     */
    private static function exchangeCode($client, $code, $redirect, $verifier)
    {
        $hash = hash('sha256', (string)$code);
        $row = Db::queryOne("SELECT user_id, redirect_uri, code_challenge FROM oauth_codes
                             WHERE code_hash = ? AND client_id = ? AND expires_at >= NOW()", "ss", $hash, $client['client_id']);
        // Used once, even when the rest of the request is wrong
        Db::execute("DELETE FROM oauth_codes WHERE code_hash = ?", "s", $hash);
        if (!$row || $redirect !== $row['redirect_uri']) {
            return self::error(400, 'invalid_grant', 'Invalid or expired authorization code');
        }
        if (!is_string($verifier) || !preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier)
            || !hash_equals($row['code_challenge'], self::base64url(hash('sha256', $verifier, true)))) {
            return self::error(400, 'invalid_grant', 'Invalid code_verifier');
        }
        return self::issueTokens((int)$row['user_id'], $client);
    }

    /**
     * New tokens for a refresh token (rotation: the old ones stop working).
     *
     * @param array       $client  The client
     * @param string|null $refresh The refresh token
     * @return string JSON
     */
    private static function refresh($client, $refresh)
    {
        $row = Db::queryOne("SELECT id, user_id FROM api_tokens WHERE refresh_hash = ? AND client_id = ?",
            "ss", hash('sha256', (string)$refresh), $client['client_id']);
        if (!$row) {
            return self::error(400, 'invalid_grant', 'Invalid refresh token');
        }
        Db::execute("DELETE FROM api_tokens WHERE id = ?", "i", $row['id']);
        return self::issueTokens((int)$row['user_id'], $client);
    }

    /**
     * Create the access token (an API token of the user, named after the agent) and its
     * refresh token; the previous tokens of the same agent for this user are replaced.
     *
     * @param int   $userId The user
     * @param array $client The client
     * @return string JSON
     */
    private static function issueTokens($userId, $client)
    {
        Db::execute("DELETE FROM api_tokens WHERE user_id = ? AND client_id = ?", "is", $userId, $client['client_id']);

        $token = ApiToken::PREFIX . bin2hex(random_bytes(24));
        $refresh = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . self::TOKEN_DAYS . ' days'));
        Db::execute("INSERT INTO api_tokens (user_id, name, token_hash, token_hint, expires_at, client_id, refresh_hash) VALUES (?, ?, ?, ?, ?, ?, ?)",
            "issssss", $userId, mb_substr($client['name'], 0, 50), hash('sha256', $token), substr($token, 0, 10), $expiresAt,
            $client['client_id'], hash('sha256', $refresh));

        return self::json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => self::TOKEN_DAYS * 86400,
            'refresh_token' => $refresh,
        ]);
    }

    /**
     * Check an authorization request.
     *
     * @param array $request Fields of the request
     * @return array The client
     * @throws Error If the client or its redirection URI is unknown (no redirection possible)
     * @throws InvalidArgumentException Other errors, with the OAuth error code, sent back to the agent
     */
    private static function checkRequest(array $request)
    {
        $clientId = $request['client_id'] ?? null;
        $client = is_string($clientId) ? Db::queryOne("SELECT client_id, name, redirect_uris FROM oauth_clients WHERE client_id = ?", "s", $clientId) : null;
        if (!$client) {
            throw new Error("Unknown client", 400);
        }
        if (!in_array($request['redirect_uri'] ?? null, json_decode($client['redirect_uris'], true) ?: [], true)) {
            throw new Error("Unknown redirect_uri for this client", 400);
        }
        if (($request['response_type'] ?? null) !== 'code') {
            throw new InvalidArgumentException('unsupported_response_type');
        }
        if (($request['code_challenge_method'] ?? null) !== 'S256' || !preg_match('/^[A-Za-z0-9\-_]{43,128}$/', (string)($request['code_challenge'] ?? ''))) {
            throw new InvalidArgumentException('invalid_request');
        }
        if (!Mcp::enabled()) {
            throw new InvalidArgumentException('access_denied');
        }
        return $client;
    }

    /**
     * Check an authorization request coming back from the portal.
     *
     * @param array $request Fields of the request
     * @return array The client
     * @throws Error If the request is invalid (400), or the MCP server disabled (403)
     */
    private static function checkRequestOrFail(array $request)
    {
        try {
            return self::checkRequest($request);
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === 'access_denied') {
                throw new Error("The MCP server is disabled: an administrator enables it in the AI agents tab", 403);
            }
            throw new Error("Invalid authorization request: " . $e->getMessage(), 400);
        }
    }

    /**
     * Fields of an authorization request given as a query string.
     *
     * @param string $request The query string
     * @return array The fields
     */
    private static function parseRequest($request)
    {
        parse_str((string)$request, $fields);
        return self::requestFields($fields);
    }

    /**
     * Keep the known fields of an authorization request, as strings.
     *
     * @param array $request The request
     * @return array The fields
     */
    private static function requestFields(array $request)
    {
        $fields = [];
        foreach (self::REQUEST_FIELDS as $key) {
            if (isset($request[$key]) && is_string($request[$key]) && $request[$key] !== '') {
                $fields[$key] = $request[$key];
            }
        }
        return $fields;
    }

    /**
     * Redirection URI of the request with parameters (and the state of the agent).
     *
     * @param array $request The request (checked redirect_uri)
     * @param array $params  Parameters to add
     * @return string The URL
     */
    private static function redirectUri(array $request, array $params)
    {
        if (isset($request['state'])) {
            $params['state'] = $request['state'];
        }
        $uri = $request['redirect_uri'];
        return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($params);
    }

    /**
     * Check a redirection URI: https, or http on the loopback (agents on the computer), without fragment.
     *
     * @param mixed $uri The URI
     * @return bool True if allowed
     */
    private static function validRedirectUri($uri)
    {
        if (!is_string($uri) || strlen($uri) > 500 || str_contains($uri, '#')) {
            return false;
        }
        $parts = parse_url($uri);
        if (!$parts || empty($parts['host'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        return $scheme === 'https' || ($scheme === 'http' && in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '[::1]'], true));
    }

    /**
     * Client id and secret of an HTTP Basic authentication.
     *
     * @return array|null [client_id, client_secret]
     */
    private static function basicCredentials()
    {
        $header = (string)Webservice::getHeader('Authorization');
        if (!preg_match('/^Basic\s+(.+)$/i', $header, $m) || ($decoded = base64_decode($m[1], true)) === false || !str_contains($decoded, ':')) {
            return null;
        }
        return array_map('urldecode', explode(':', $decoded, 2));
    }

    /**
     * Base64url without padding (PKCE).
     *
     * @param string $bytes The bytes
     * @return string The encoded value
     */
    private static function base64url($bytes)
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * JSON response.
     *
     * @param array $data   The data
     * @param int   $status The HTTP status
     * @return string The JSON
     */
    private static function json($data, $status = 200)
    {
        http_response_code($status);
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * OAuth error response (RFC 6749, section 5.2).
     *
     * @param int    $status      The HTTP status
     * @param string $error       The OAuth error code
     * @param string $description The description
     * @return string The JSON
     */
    private static function error($status, $error, $description)
    {
        return self::json(['error' => $error, 'error_description' => $description], $status);
    }
}
