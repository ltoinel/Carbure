<?php

/**
 * System.php
 *
 * State of the instance for the administrators: version of the database schema
 * and migrations to apply, applied from the portal (no command to run).
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

final class System {

    /**
     * Configuration file changed by rotateJwtSecret (tests use a copy)
     */
    public static ?string $configFile = null;

    /**
     * Replace the secret of the sessions by a new random one (administrators):
     * every user, portal and iOS app, must log in again.
     *
     * @return bool True if replaced
     * @throws Exception If the configuration file cannot be written
     */
    #[ApiRoute('/system/jwt-secret', method: 'POST')]
    public static function rotateJwtSecret()
    {
        User::requireAdmin();
        $file = self::$configFile ?? dirname(__DIR__, 2) . '/conf/' . preg_replace('/[^a-z0-9_-]/i', '', getenv('APP_ENV') ?: 'prod') . '.ini';
        if (!is_file($file) || !is_writable($file)) {
            throw new Exception("The configuration file cannot be written by the web server");
        }
        $ini = Installer::setIni(file_get_contents($file), 'jwtsecret', bin2hex(random_bytes(32)));
        if (file_put_contents($file, $ini) === false) {
            throw new Exception("The configuration file cannot be written by the web server");
        }
        Logger::warn("jwtsecret renewed: every session must log in again");
        return true;
    }

    /**
     * Check if the sessions use the example secret of the configuration
     * (administrators are asked to renew it).
     *
     * @return bool True if the secret is weak
     */
    public static function weakJwtSecret()
    {
        $secret = (string)(Config::has('jwtsecret') ? Config::get('jwtsecret') : '');
        return strlen($secret) < 32 || in_array($secret, ['secret', 'changeme', 'change-me'], true);
    }

    /**
     * Health of the instance, for the Docker HEALTHCHECK and supervision tools
     * (no authentication, no data): the database answers.
     *
     * @return array status (ok), version (of Carbure), database (ok), schema (version)
     * @throws Exception If the database does not answer (HTTP 500)
     */
    #[ApiRoute('/health', method: 'GET', public: true)]
    public static function health()
    {
        Db::query("SELECT 1");
        return ['status' => 'ok', 'version' => self::version(), 'database' => 'ok', 'schema' => (new Migrator(Db::getConnection()))->version()];
    }

    /**
     * Version of Carbure: the VERSION file written by the release build ("dev" otherwise).
     *
     * @return string The version
     */
    public static function version()
    {
        $file = dirname(__DIR__, 2) . '/VERSION';
        $version = is_file($file) ? trim((string)file_get_contents($file)) : '';
        return preg_match('/^[0-9A-Za-z.+-]{1,40}$/', $version) ? $version : 'dev';
    }

    /**
     * Version of the database schema and migrations to apply (administrators).
     *
     * @return array version, pending
     */
    #[ApiRoute('/system/schema', method: 'GET')]
    public static function schema()
    {
        User::requireAdmin();
        $migrator = new Migrator(Db::getConnection());
        return ['version' => $migrator->version(), 'pending' => $migrator->pending(), 'weakJwtSecret' => self::weakJwtSecret()];
    }

    /**
     * Apply the pending migrations (administrators).
     *
     * @return array version, applied
     * @throws Exception If a migration fails
     */
    #[ApiRoute('/system/migrate', method: 'POST')]
    public static function migrate()
    {
        User::requireAdmin();
        $migrator = new Migrator(Db::getConnection());
        try {
            $applied = $migrator->migrate(fn($message) => Logger::info("Migration $message"));
        } catch (RuntimeException $e) {
            throw new Exception($e->getMessage());
        }
        return ['version' => $migrator->version(), 'applied' => $applied];
    }
}
