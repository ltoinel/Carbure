<?php

/**
 * ApiToken.php
 *
 * API tokens of the users: long-lived, revocable credentials of the MCP server
 * (/api/mcp) used by Claude. Only a SHA-256 of a token is stored: the token is
 * shown once, at its creation.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

final class ApiToken {

    /**
     * Prefix of the tokens, to tell them apart from a JWT
     */
    public const PREFIX = 'cbt_';

    /**
     * Maximum number of tokens per user
     */
    private const MAX_TOKENS = 20;

    /**
     * Get the API tokens of the authenticated user (without the tokens themselves).
     *
     * @return array The tokens: id, name, token_hint, created_at, last_used_at
     */
    #[ApiRoute('/token', method: 'GET')]
    public static function getMine()
    {
        $sql = "SELECT id, name, token_hint, created_at, last_used_at FROM api_tokens WHERE user_id = ? ORDER BY created_at DESC, id DESC";
        return Db::execute($sql, "i", Jwt::getUserIdFromToken())->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Create an API token for the authenticated user.
     *
     * @param string $name A name to recognize it (e.g. "Claude Code on my laptop")
     * @return array The token: id, name, token (shown only this time), token_hint
     * @throws Error If the name is invalid or the user has too many tokens
     */
    #[ApiRoute('/token', method: 'POST')]
    public static function create($name)
    {
        $name = trim((string)$name);
        if ($name === '' || mb_strlen($name) > 50) {
            throw new Error("The name must contain 1 to 50 characters", 400);
        }

        $userId = Jwt::getUserIdFromToken();
        $count = Db::queryOne("SELECT COUNT(*) AS n FROM api_tokens WHERE user_id = ?", "i", $userId)['n'];
        if ($count >= self::MAX_TOKENS) {
            throw new Error("Too many API tokens: delete the ones you no longer use", 409);
        }

        $token = self::PREFIX . bin2hex(random_bytes(24));
        $hint = substr($token, 0, 10);
        $stmt = Db::execute("INSERT INTO api_tokens (user_id, name, token_hash, token_hint) VALUES (?, ?, ?, ?)",
            "isss", $userId, $name, hash('sha256', $token), $hint);

        return ['id' => $stmt->insert_id, 'name' => $name, 'token' => $token, 'token_hint' => $hint];
    }

    /**
     * Revoke an API token of the authenticated user.
     *
     * @param int $id The token id
     * @return bool True if deleted
     * @throws Error If the token does not exist or belongs to another user
     */
    #[ApiRoute('/token', method: 'DELETE')]
    public static function delete($id)
    {
        $stmt = Db::execute("DELETE FROM api_tokens WHERE id = ? AND user_id = ?", "ii", $id, Jwt::getUserIdFromToken());
        if ($stmt->affected_rows === 0) {
            throw new Error("API token not found", 404);
        }
        return true;
    }

    /**
     * Find the user of an API token and record its use.
     *
     * @param string $token The token
     * @return int|null The user id, or null if the token is unknown
     */
    public static function authenticate($token)
    {
        if (!is_string($token) || !str_starts_with($token, self::PREFIX)) {
            return null;
        }
        $hash = hash('sha256', $token);
        $row = Db::queryOne("SELECT id, user_id FROM api_tokens WHERE token_hash = ?", "s", $hash);
        if (!$row) {
            return null;
        }
        Db::execute("UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?", "i", $row['id']);
        return (int)$row['user_id'];
    }
}
