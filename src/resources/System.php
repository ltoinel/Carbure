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
     * Version of the database schema and migrations to apply (administrators).
     *
     * @return array version, pending
     */
    #[ApiRoute('/system/schema', method: 'GET')]
    public static function schema()
    {
        User::requireAdmin();
        $migrator = new Migrator(Db::getConnection());
        return ['version' => $migrator->version(), 'pending' => $migrator->pending()];
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
