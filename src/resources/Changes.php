<?php

/**
 * Changes.php
 *
 * Change markers of the household data: one version per kind of data, bumped by every
 * write, so that a client (mobile app, portal) reloads only the kinds that moved since
 * it loaded them (GET /changes, one indexed read of a few rows).
 *
 * The kinds written by a route are declared on its attribute (#[ApiRoute(...,
 * changes: ['budgets'])]) and bumped by Webservice after a successful call; the bank
 * synchronization bumps them itself, only when it changed something. The bumps of a
 * request are kept in memory and written at its end in one query (flush()), which also
 * sends the new versions in the X-Carbure-Changes header: the client that made the write
 * knows that it has nothing to reload.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Resources;

use Carbure\Lib\ApiRoute;
use Carbure\Lib\Db;
use Carbure\Lib\Logger;
use LogicException;
use Throwable;

final class Changes {

    /** Kinds of data that have a change marker */
    public const KINDS = ['transactions', 'accounts', 'categories', 'budgets', 'insights', 'settings'];

    /** Response header of a write: the new versions of the kinds it bumped */
    public const HEADER = 'X-Carbure-Changes';

    /**
     * Kinds bumped by the current request, not written yet
     *
     * @var array<string, true>
     */
    private static array $pending = [];

    /**
     * Version and date of the last change of each kind of data (any authenticated user:
     * the data of the household). A kind never changed has the version 0.
     *
     * @return array<string, array{version: int, updated_at: string|null}> By kind
     */
    #[ApiRoute('/changes', method: 'GET')]
    public static function get(): array
    {
        $changes = array_fill_keys(self::KINDS, ['version' => 0, 'updated_at' => null]);
        foreach (Db::select("SELECT kind, version, updated_at FROM data_change")->fetch_all(MYSQLI_ASSOC) as $row) {
            if (isset($changes[$row['kind']])) {
                $changes[$row['kind']] = ['version' => (int)$row['version'], 'updated_at' => $row['updated_at']];
            }
        }
        return $changes;
    }

    /**
     * Mark kinds of data as changed by the current request (written by flush()).
     *
     * @param string ...$kinds Kinds of Changes::KINDS
     * @return void
     * @throws LogicException If a kind is unknown
     */
    public static function touch(string ...$kinds): void
    {
        foreach ($kinds as $kind) {
            if (!in_array($kind, self::KINDS, true)) {
                throw new LogicException("Unknown kind of data change: $kind");
            }
            self::$pending[$kind] = true;
        }
    }

    /**
     * Bump the versions of the kinds marked as changed, in one query, and send them in
     * the X-Carbure-Changes header ("budgets=1835, transactions=1836") while the headers
     * can still be sent. Nothing is read or written when nothing changed. A failure is
     * only logged: the data is written, a client reloads it at the latest on its next
     * start.
     *
     * @return void
     */
    public static function flush(): void
    {
        if (!self::$pending) {
            return;
        }
        $kinds = array_keys(self::$pending);
        self::$pending = [];

        try {
            $placeholders = implode(', ', array_fill(0, count($kinds), '(?, 1, NOW(3))'));
            Db::execute("INSERT INTO data_change (kind, version, updated_at) VALUES $placeholders
                ON DUPLICATE KEY UPDATE version = version + 1, updated_at = VALUES(updated_at)",
                str_repeat('s', count($kinds)), ...$kinds);

            if (!headers_sent()) {
                $in = implode(', ', array_fill(0, count($kinds), '?'));
                $rows = Db::select("SELECT kind, version FROM data_change WHERE kind IN ($in) ORDER BY kind",
                    str_repeat('s', count($kinds)), ...$kinds)->fetch_all(MYSQLI_ASSOC);
                header(self::HEADER . ': ' . implode(', ', array_map(fn(array $row) => "$row[kind]=$row[version]", $rows)));
            }
        } catch (Throwable $e) {
            Logger::warn("The change markers (" . implode(', ', $kinds) . ") cannot be bumped: " . $e->getMessage());
        }
    }
}
