<?php

/**
 * InsightAlert.php
 *
 * Alerts of the users when the value of the month of an enabled insight goes above or
 * below their threshold.
 *
 * - Set by each user on the cards of the Insights tab: a direction (above, below) and an
 *   amount, compared with the absolute value of the month, as the card shows it.
 * - Checked with the budget alerts: after each synchronization and each import of a
 *   statement file, for the current month only.
 * - Sent with the kind "insight" of the notification preferences, off on every channel
 *   by default (opt-in).
 * - Once per user, insight and month (insight_alert.notified_month); alerted again when
 *   the threshold or the direction is changed.
 * - Only the enabled insights alert: disabling an insight drops its alerts, deleting it
 *   too (foreign key).
 * - Before the migration 2026-11-03_insight_alert, no alert: GET answers none and PUT 409.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Resources;

use Carbure\Lib\ApiError;
use Carbure\Lib\ApiRoute;
use Carbure\Lib\Db;
use Carbure\Lib\Jwt;
use Carbure\Lib\Logger;
use Carbure\Lib\Messages;
use Error;
use Throwable;

final class InsightAlert {

    /** Kind of notification of the alerts */
    const KIND = 'insight';

    /** Directions of an alert: the value goes above or below the threshold */
    const DIRECTIONS = ['above', 'below'];

    /** Highest threshold accepted (decimal(12,2)) */
    const MAX_THRESHOLD = 9999999999.99;

    /**
     * Whether the table insight_alert exists (migration 2026-11-03_insight_alert), read once
     */
    private static ?bool $ready = null;

    /**
     * Whether the alerts can be read and saved: the migration 2026-11-03_insight_alert is
     * applied. Read once per request.
     *
     * @return bool
     */
    public static function ready(): bool
    {
        return self::$ready ??= Db::queryOne("SELECT 1 FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'insight_alert'", "") !== null;
    }

    /**
     * The alerts of the authenticated user, on the enabled insights.
     *
     * @return list<array{insight: int, direction: string, threshold: float}> The alerts, by insight
     */
    #[ApiRoute('/insight/alert', method: 'GET')]
    public static function get(): array
    {
        if (!self::ready()) {
            return [];
        }
        return array_map(fn(array $row): array => self::alertOf($row), self::alertsOf(Jwt::getUserIdFromToken()));
    }

    /**
     * Set the alert of the authenticated user on an enabled insight, replacing the
     * previous one (alerted again this month if the new threshold is crossed).
     *
     * @param int|string       $insight   The insight
     * @param string           $direction above or below
     * @param int|float|string $threshold A positive amount, compared with the absolute value of the month
     * @return array{insight: int, direction: string, threshold: float} The alert
     * @throws Error If the insight is unknown (404) or disabled, the direction or the threshold
     *               is not valid (400), or the migration is not applied yet (409)
     */
    #[ApiRoute('/insight/alert', method: 'PUT', changes: [])]
    public static function set(int|string $insight, string $direction, int|float|string $threshold): array
    {
        $id = self::enabledInsight($insight);
        if (!in_array($direction, self::DIRECTIONS, true)) {
            throw new ApiError("The direction must be above or below", 400, 'insight_alert_direction_invalid');
        }
        $amount = self::thresholdValue($threshold);
        if (!self::ready()) {
            throw new ApiError("The database must be updated first", 409, 'insight_migration_pending');
        }
        Db::execute("INSERT INTO insight_alert (user_id, insight_id, direction, threshold, notified_month) VALUES (?, ?, ?, ?, NULL)
            ON DUPLICATE KEY UPDATE direction = VALUES(direction), threshold = VALUES(threshold), notified_month = NULL",
            "iisd", Jwt::getUserIdFromToken(), $id, $direction, $amount);
        return ['insight' => $id, 'direction' => $direction, 'threshold' => $amount];
    }

    /**
     * Remove the alert of the authenticated user on an insight.
     *
     * @param int|string $insight The insight
     * @return bool True if removed
     * @throws Error If the user has no alert on this insight (404)
     */
    #[ApiRoute('/insight/alert', method: 'DELETE', changes: [])]
    public static function delete(int|string $insight): bool
    {
        $removed = self::ready() && Db::execute("DELETE FROM insight_alert WHERE user_id = ? AND insight_id = ?", "ii",
            Jwt::getUserIdFromToken(), (int)$insight)->affected_rows > 0;
        if (!$removed) {
            throw new ApiError("No alert on this insight", 404, 'insight_alert_not_found');
        }
        return true;
    }

    /**
     * Drop the alerts of an insight, when it is disabled.
     *
     * @param int $insight The insight
     * @return void
     */
    public static function dropAlerts(int $insight): void
    {
        if (self::ready()) {
            Db::execute("DELETE FROM insight_alert WHERE insight_id = ?", "i", $insight);
        }
    }

    /**
     * Drop the alerts whose insight no longer exists or is disabled: after the import of
     * the insights of a backup (written with the foreign keys off).
     *
     * @return void
     */
    public static function dropOrphans(): void
    {
        if (self::ready()) {
            $disabled = Insight::ready() ? " OR i.enabled = 0" : "";
            Db::query("DELETE a FROM insight_alert a LEFT JOIN budget_insight i ON i.id = a.insight_id WHERE i.id IS NULL$disabled");
        }
    }

    /**
     * Check the enabled insights of the current month and alert the users whose threshold
     * is crossed. A failure is logged: it never fails a synchronization or an import.
     *
     * @return int The number of alerts sent
     */
    public static function check(): int
    {
        try {
            return self::checkMonth((int)date('n'), (int)date('Y'));
        } catch (Throwable $e) {
            Logger::error("Check of the insight alerts failed: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Check the alerts of a month: each insight watched is run once.
     *
     * @param int $month The month (1-12)
     * @param int $year  The year
     * @return int The number of alerts sent
     */
    public static function checkMonth(int $month, int $year): int
    {
        if (!self::ready()) {
            return 0;
        }
        $first = sprintf('%04d-%02d-01', $year, $month);
        $values = [];
        $sent = 0;
        foreach (self::pending($first) as $alert) {
            $id = (int)$alert['insight_id'];
            $values[$id] ??= Insight::amount((string)$alert['sql'], $month, $year);
            $sent += self::alert($alert, $values[$id], $first) ? 1 : 0;
        }
        if ($sent > 0) {
            Logger::info("$sent insight alert(s) sent for $first");
        }
        return $sent;
    }

    /**
     * Whether a value crosses the threshold of an alert: strictly above or below it.
     *
     * @param float  $value     The value of the month (absolute value)
     * @param string $direction above or below
     * @param float  $threshold The threshold
     * @return bool
     */
    public static function crossed(float $value, string $direction, float $threshold): bool
    {
        return $direction === 'above' ? $value > $threshold : $value < $threshold;
    }

    /**
     * Title and text of an alert, in the language of the user (Messages): "Insight
     * au-dessus du seuil : Dépenses" / "2 140,00 € ce mois-ci (seuil : 2 000,00 €)".
     *
     * @param string $language  The language of the user
     * @param string $name      The name of the insight
     * @param string $direction above or below
     * @param float  $value     The value of the month (absolute value)
     * @param float  $threshold The threshold
     * @return array{0: string, 1: string} Title and text
     */
    public static function message(string $language, string $name, string $direction, float $value, float $threshold): array
    {
        $title = Messages::t($language, 'insight_' . $direction . '_title', ['insight' => $name]);
        $body = Messages::t($language, 'insight_body', [
            'amount' => Messages::amount($language, $value),
            'threshold' => Messages::amount($language, $threshold),
        ]);
        return [$title, $body];
    }

    /**
     * Rows of the alerts of a user on the enabled insights.
     *
     * @param int $userId The user
     * @return list<array<string, mixed>> The rows
     */
    private static function alertsOf(int $userId): array
    {
        $enabled = Insight::ready() ? " AND i.enabled = 1" : "";
        return Db::select("SELECT a.insight_id, a.direction, a.threshold FROM insight_alert a
            JOIN budget_insight i ON i.id = a.insight_id WHERE a.user_id = ?$enabled ORDER BY a.insight_id", "i", $userId)->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * An alert as answered by the API.
     *
     * @param array<string, mixed> $row A row of insight_alert
     * @return array{insight: int, direction: string, threshold: float}
     */
    private static function alertOf(array $row): array
    {
        return ['insight' => (int)$row['insight_id'], 'direction' => (string)$row['direction'], 'threshold' => (float)$row['threshold']];
    }

    /**
     * The alerts not sent yet this month, on the enabled insights, of the users who receive
     * the insight alerts (at least one channel of the kind "insight" on).
     *
     * @param string $month First day of the month (Y-m-d)
     * @return list<array<string, mixed>> The alerts with the user, his language, the insight and its query
     */
    private static function pending(string $month): array
    {
        $enabled = Insight::ready() ? " AND i.enabled = 1" : "";
        $rows = Db::select("SELECT a.user_id, a.insight_id, a.direction, a.threshold, u.language, i.name, i.`sql`
            FROM insight_alert a JOIN budget_insight i ON i.id = a.insight_id JOIN users u ON u.id = a.user_id
            WHERE (a.notified_month IS NULL OR a.notified_month <> ?)$enabled ORDER BY a.insight_id, a.user_id",
            "s", $month)->fetch_all(MYSQLI_ASSOC);
        return array_values(array_filter($rows, fn(array $row): bool => self::subscribed((int)$row['user_id'])));
    }

    /**
     * Whether a user receives the insight alerts on at least one channel.
     *
     * @param int $userId The user
     * @return bool
     */
    private static function subscribed(int $userId): bool
    {
        $channels = Notification::preferences($userId)[self::KIND];
        return $channels['push'] || $channels['email'];
    }

    /**
     * Alert a user when the value of the month crosses his threshold.
     *
     * @param array<string, mixed> $alert The alert (row of pending())
     * @param float|null           $value The value of the month (null: the query failed)
     * @param string               $month First day of the month (Y-m-d)
     * @return bool Whether the user was alerted
     */
    private static function alert(array $alert, ?float $value, string $month): bool
    {
        $threshold = (float)$alert['threshold'];
        $direction = (string)$alert['direction'];
        if ($value === null || !self::crossed(abs($value), $direction, $threshold)) {
            return false;
        }
        $userId = (int)$alert['user_id'];
        Db::execute("UPDATE insight_alert SET notified_month = ? WHERE user_id = ? AND insight_id = ?", "sii",
            $month, $userId, (int)$alert['insight_id']);

        [$title, $body] = self::message((string)$alert['language'], (string)$alert['name'], $direction, abs($value), $threshold);
        Notification::notify($userId, self::KIND, $title, $body, Transaction::countUnpointed());
        return true;
    }

    /**
     * An enabled insight sent by a user.
     *
     * @param int|string $insight The insight
     * @return int Its id
     * @throws Error If it does not exist (404) or is disabled (400)
     */
    private static function enabledInsight(int|string $insight): int
    {
        $id = filter_var($insight, FILTER_VALIDATE_INT);
        $enabled = Insight::ready() ? "enabled" : "1 AS enabled";
        $row = $id === false ? null : Db::queryOne("SELECT $enabled FROM budget_insight WHERE id = ?", "i", $id);
        if ($id === false || $row === null) {
            throw new ApiError("Insight not found", 404, 'insight_not_found');
        }
        if (!(int)$row['enabled']) {
            throw new ApiError("Only an enabled insight can alert", 400, 'insight_alert_disabled');
        }
        return $id;
    }

    /**
     * Stored value of a threshold sent by a user: a positive amount, rounded to the cent.
     *
     * @param int|float|string $threshold The threshold sent (a number or a numeric string)
     * @return float
     * @throws Error If it is not a positive amount (400)
     */
    public static function thresholdValue(int|float|string $threshold): float
    {
        $value = filter_var($threshold, FILTER_VALIDATE_FLOAT);
        if ($value === false || !is_finite($value) || $value < 0 || $value > self::MAX_THRESHOLD) {
            throw new ApiError("The threshold must be a positive amount", 400, 'insight_alert_threshold_invalid');
        }
        return round($value, 2);
    }
}
