<?php

/**
 * Jwt.php
 *
 * JWT Check & Sign
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

use DateTime;
use Error;

final class Jwt {

    /**
     * User authenticated by another mean than a JWT (API token of the MCP server)
     */
    private static ?int $actingUser = null;

    /**
     * Tokens already checked against the database in this request: token => user id, or
     * null when refused (revoked session, deleted user)
     *
     * @var array<string, int|null>
     */
    private static array $checked = [];

    /**
     * Whether the column users.tokens_valid_after exists (migration
     * 2026-11-27_user_tokens_valid_after); null until checked
     */
    private static ?bool $revocationReady = null;

    /**
     * Run the next calls as a user authenticated by another mean (an API token):
     * getUserIdFromToken() then returns this user. null goes back to the JWT.
     *
     * @param int|null $userId The user, or null
     * @return void
     */
    public static function actAs(?int $userId): void
    {
        self::$actingUser = $userId;
        Logger::setContext('user', self::$actingUser);
    }

    /**
     * Create a JWT token for a user.
     *
     * @param int      $userId The user id
     * @param int|null $after  Time of the last revocation of the sessions of the user
     *                         (users.tokens_valid_after): the token is issued after it, even
     *                         within the same second
     * @return array{exp: int, sub: int, iat: int, token: string} The token data with 'token', 'exp', 'sub', 'iat'
     */
    public static function createJwt(int $userId, ?int $after = null): array
    {
        // Create token header as a JSON string
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_THROW_ON_ERROR);

        // Create token payload as a JSON string
        $expire = new DateTime('NOW');
        $expire->modify('+30 days');

        $data = [
            'exp' => $expire->getTimestamp(),
            'sub' => $userId,
            'iat' => max(time(), $after === null ? 0 : $after + 1),
        ];

        $payload = json_encode($data, JSON_THROW_ON_ERROR);

        // Encode Header to Base64Url String
        $base64UrlHeader = self::encode($header);

        // Encode Payload to Base64Url String
        $base64UrlPayload = self::encode($payload);

        // Create Signature Hash
        $signature = self::sign($base64UrlHeader, $base64UrlPayload);

        // Encode Signature to Base64Url String
        $base64UrlSignature = self::encode($signature);

        // Create JWT
        $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;

        // Add the JWT to the payload
        $data['token'] = $jwt;

        return $data;
    }

    /**
     * Sign the JWT token.
     *
     * @param string $base64UrlHeader  The base64Url encoded header
     * @param string $base64UrlPayload The base64Url encoded payload
     * @return string The HMAC signature
     */
    private static function sign(string $base64UrlHeader, string $base64UrlPayload): string
    {
        $jwtsecret = Config::get('jwtsecret');
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $jwtsecret, true);

        return  $signature;
    }

    /**
     * Encode a string to base64Url.
     *
     * @param string $data The data to encode
     * @return string The base64Url encoded string
     */
    private static function encode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    /**
     * Parse a JWT token.
     *
     * @param string $jwt The JWT token
     * @return object|false The decoded payload or false if invalid
     */
    public static function parseJwt(string $jwt): object|false
    {

        // We check the signature
        if (self::isTokenSigned($jwt)) {
            $parts = explode('.', $jwt);
            $data = json_decode(base64_decode(strtr($parts[1], '-_', '+/')));

            // Check expiration date
            if (is_object($data) && isset($data->exp, $data->sub) && $data->exp > time()) {
                return $data;
            }
        }

        return false;
    }

    /**
     * Check the JWT signature.
     *
     * @param string $jwt The JWT token
     * @return bool True if the signature is valid
     */
    private static function isTokenSigned(string $jwt): bool
    {
        $jwt = explode('.', $jwt);
        if (count($jwt) !== 3) {
            return false;
        }

        $signature = self::sign($jwt[0], $jwt[1]);
        $signature = self::encode($signature);

        return hash_equals($signature, $jwt[2]);
    }

    /**
     * Extract JWT token from Authorization header
     * @return string|null The token or null if not found
     */
    public static function getTokenFromHeader(): ?string
    {
        $authHeader = Webservice::getHeader('Authorization');

        if ($authHeader === null) {
            return null;
        }
        
        // Extract token from "Bearer <token>"
        if (preg_match('/Bearer\s+(.+)/', $authHeader, $matches)) {
            return $matches[1];
        }
        
        // Try without Bearer prefix
        return $authHeader;
    }

    /**
     * User of a JWT whose session is still open: the token is signed and not expired, its
     * user still exists and has not revoked the sessions since it was issued (password
     * change, "sign out everywhere"). One primary-key lookup per token and per request.
     *
     * @param string $token The JWT
     * @return int|null The user id, null if the token is refused
     */
    public static function authenticate(string $token): ?int
    {
        if (!array_key_exists($token, self::$checked)) {
            $payload = self::parseJwt($token);
            self::$checked[$token] = $payload === false ? null : self::openSession($payload);
        }
        return self::$checked[$token];
    }

    /**
     * User of a valid token, unless deleted or signed out since the token was issued.
     * The column of the revocation is read from the whole row: no error before its
     * migration (nothing is revoked until then).
     *
     * @param object $payload The payload of a signed, unexpired token
     * @return int|null The user id, null if the session is closed
     */
    private static function openSession(object $payload): ?int
    {
        $userId = (int)($payload->sub ?? 0);
        $user = Db::queryOne("SELECT * FROM users WHERE id = ?", "i", $userId);
        if ($user === null) {
            Logger::warn("JWT token of a user who no longer exists");
            return null;
        }
        $validAfter = $user['tokens_valid_after'] ?? null;
        if ($validAfter !== null && (int)($payload->iat ?? 0) <= (int)$validAfter) {
            Logger::warn("JWT token of a session that was signed out");
            return null;
        }
        return $userId;
    }

    /**
     * Close every session of a user: the tokens issued so far are refused from now on
     * (password change, "sign out everywhere"). Nothing is revoked before the migration.
     *
     * @param int $userId The user
     * @return int|null The time of the revocation, to give to createJwt() for a session that
     *                  stays open; null if the migration is not applied yet
     */
    public static function revokeSessions(int $userId): ?int
    {
        if (!self::revocationReady()) {
            return null;
        }
        $now = time();
        Db::execute("UPDATE users SET tokens_valid_after = GREATEST(COALESCE(tokens_valid_after, 0), ?) WHERE id = ?", "ii", $now, $userId);
        self::forget();
        $row = Db::queryOne("SELECT tokens_valid_after FROM users WHERE id = ?", "i", $userId);
        return $row === null ? $now : (int)$row['tokens_valid_after'];
    }

    /**
     * Whether the sessions can be revoked (migration applied), checked once per request.
     *
     * @return bool True once the migration 2026-11-27_user_tokens_valid_after is applied
     */
    public static function revocationReady(): bool
    {
        if (self::$revocationReady === null) {
            $row = Db::queryOne("SELECT COUNT(*) AS found FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'tokens_valid_after'", "");
            self::$revocationReady = (int)($row['found'] ?? 0) > 0;
        }
        return self::$revocationReady;
    }

    /**
     * Forget the tokens already checked (a session was closed, a user deleted, or the
     * schema changed): the next call reads the database again.
     *
     * @return void
     */
    public static function forget(): void
    {
        self::$checked = [];
        self::$revocationReady = null;
    }

    /**
     * Get the user ID from the Authorization header.
     * @return int the user ID
     */
    public static function getUserIdFromToken(): int
    {
        if (self::$actingUser !== null) {
            return self::$actingUser;
        }

        $token = self::getTokenFromHeader();
        
        if (!$token) {
            throw new Error("Missing Authorization header");
        }

        $userId = self::authenticate($token);

        if ($userId === null) {
            throw new Error("Unauthorized - Invalid or missing JWT token", 401);
        }

        return $userId;
    }

    /**
     * Check the JWT token from Authorization header
     * @return bool true if valid JWT token, false otherwise
     */
    public static function checkAuthorization(): bool
    {
        $token = self::getTokenFromHeader();
        
        if (!$token) {
            Logger::warn("Missing Authorization header");
            return false;
        }

        // Signed, not expired, and its session still open
        $userId = self::authenticate($token);

        if ($userId === null) {
            Logger::warn("Invalid JWT token");
            return false;
        }

        Logger::setContext('user', $userId);
        return true;
    }
}