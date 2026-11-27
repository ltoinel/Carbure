<?php

/**
 * Secret.php
 *
 * Encryption at rest of the bank credentials kept by woob (#532), with a key that never
 * lives in the data volume: the environment, or a secret file mounted in the container.
 * A copy of data/woob (backup, snapshot, stolen disk) then holds no usable credential.
 *
 * No dependency on the other classes: also loaded alone by tools/carbure-secret, the
 * command woob runs to read a value.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

use Exception;

final class Secret {

    /** Prefix of an encrypted value: version of the format */
    public const PREFIX = 'v1:';

    /** Environment variable of the key (or NAME_FILE: path of a file that holds it) */
    public const KEY = 'CARBURE_SECRET_KEY';

    /** Environment variable of the previous key, while it is being replaced */
    public const OLD_KEY = 'CARBURE_SECRET_KEY_OLD';

    /** Docker secrets read without any variable: /run/secrets/<name> */
    private const SECRET_FILES = [
        self::KEY => '/run/secrets/carbure_secret_key',
        self::OLD_KEY => '/run/secrets/carbure_secret_key_old',
    ];

    /** Shortest key accepted (characters) */
    public const MIN_LENGTH = 32;

    /**
     * Whether the credentials can be encrypted: a key is configured and libsodium is there.
     *
     * @return bool True if encrypt() works
     * @throws Exception If the key is too short
     */
    public static function available(): bool
    {
        return function_exists('sodium_crypto_secretbox') && self::key(self::KEY) !== null;
    }

    /**
     * Encrypt a value.
     *
     * @param string $value The clear value
     * @return string "v1:" followed by the nonce and the ciphertext, in base64
     * @throws Exception If no key is configured, or it is too short
     */
    public static function encrypt(string $value): string
    {
        $key = self::key(self::KEY);
        if ($key === null) {
            throw new Exception(self::KEY . " is not set: the bank credentials cannot be encrypted");
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($value, $nonce, $key));
    }

    /**
     * Decrypt a value with the key, else with the previous key.
     *
     * @param string $encrypted The value given by encrypt()
     * @return string|null The clear value, null if no configured key decrypts it
     * @throws Exception If a key is too short
     */
    public static function decrypt(string $encrypted): ?string
    {
        foreach ([self::KEY, self::OLD_KEY] as $name) {
            $value = self::decryptWith($encrypted, self::key($name));
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Whether a value is encrypted with the current key (not with the previous one, nor
     * with a lost one).
     *
     * @param string $encrypted The value given by encrypt()
     * @return bool True if the current key decrypts it
     * @throws Exception If the key is too short
     */
    public static function isCurrent(string $encrypted): bool
    {
        return self::decryptWith($encrypted, self::key(self::KEY)) !== null;
    }

    /**
     * Decrypt a value with one key.
     *
     * @param string      $encrypted The value given by encrypt()
     * @param string|null $key       The key (32 bytes), null if not configured
     * @return string|null The clear value, null if the key is not the right one
     */
    private static function decryptWith(string $encrypted, ?string $key): ?string
    {
        if ($key === null || !str_starts_with($encrypted, self::PREFIX)) {
            return null;
        }
        $raw = base64_decode(substr($encrypted, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }
        $value = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
        return $value === false ? null : $value;
    }

    /**
     * Key of 32 bytes derived from a configured secret: the variable, else the file named
     * by <variable>_FILE, else the Docker secret of the same name.
     *
     * @param string $name KEY or OLD_KEY
     * @return string|null The key, null if not configured
     * @throws Exception If the secret is shorter than MIN_LENGTH characters
     */
    private static function key(string $name): ?string
    {
        $secret = getenv($name);
        if (!is_string($secret) || $secret === '') {
            $file = getenv($name . '_FILE') ?: self::SECRET_FILES[$name];
            $secret = is_readable($file) ? trim((string)file_get_contents($file)) : '';
        }
        if ($secret === '') {
            return null;
        }
        if (strlen($secret) < self::MIN_LENGTH) {
            throw new Exception("$name is too short: at least " . self::MIN_LENGTH . " characters (e.g. openssl rand -base64 32)");
        }
        return sodium_crypto_generichash($secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
