<?php
/**
 * WordPress-Cron für Snapshot-Service.
 * Registriert bei Aktivierung, entfernt bei Deaktivierung.
 * Intervall aus bs_awojobs_konfiguration (cronjob_intervall).
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\WordPress\Cron;

use BS_Awo_Jobs_Statistik\Core\Database;
use BS_Awo_Jobs_Statistik\Snapshot\SnapshotService;

final class CronHandler
{
    public const HOOK = 'bs_awo_jobs_statistik_snapshot';

    /**
     * Bei Plugin-Aktivierung: Cronjob planen.
     */
    public static function schedule(): void
    {
        $intervall = self::getIntervall();
        if (wp_next_scheduled(self::HOOK)) {
            return;
        }
        wp_schedule_event(time(), $intervall, self::HOOK);
    }

    private static function getIntervall(): string
    {
        $wpdb = $GLOBALS['wpdb'];
        $tbl = $wpdb->prefix . Database::TABLE_KONFIGURATION;
        $val = $wpdb->get_var($wpdb->prepare(
            "SELECT wert FROM {$tbl} WHERE schluessel = %s",
            'cronjob_intervall'
        ));
        $valid = ['hourly', 'twicedaily', 'daily', 'weekly'];
        return $val && in_array($val, $valid, true) ? $val : 'daily';
    }

    /**
     * Bei Plugin-Deaktivierung: Cronjob entfernen.
     */
    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * Cron-Hook-Callback: SnapshotService ausführen.
     */
    public static function run(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $tblConfig = $wpdb->prefix . Database::TABLE_KONFIGURATION;

        $apiUrl = $wpdb->get_var($wpdb->prepare(
            "SELECT wert FROM {$tblConfig} WHERE schluessel = %s",
            'api_url'
        ));

        if (empty($apiUrl)) {
            return;
        }

        $service = new SnapshotService($wpdb, (string) $apiUrl);
        $service->run();
    }
}
