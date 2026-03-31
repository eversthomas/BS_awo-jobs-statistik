<?php
/**
 * Plugin-Aktivierung: Anlegen aller Tabellen mit dbDelta.
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\Core;

final class Installer
{
    /** Option: erhöhen, wenn neue Tabellen/Spalten per dbDelta nachziehbar sein müssen */
    public const SCHEMA_VERSION = 7;

    /**
     * Nach Updates ohne Re-Aktivierung: fehlende Tabellen nachziehen (admin).
     */
    public static function ensureSchemaUpToDate(): void
    {
        if (!is_admin()) {
            return;
        }
        $prev = (int) \get_option('bs_awo_jobs_schema_version', 0);
        if ($prev >= self::SCHEMA_VERSION) {
            return;
        }
        self::installTables();
        if ($prev < 5) {
            $wpdb = $GLOBALS['wpdb'];
            (new \BS_Awo_Jobs_Statistik\EinrichtungenStamm\EinrichtungenStammRepository($wpdb))
                ->backfillEinrichtungenStammNachUpgrade();
        }
        if ($prev < 7) {
            $wpdb = $GLOBALS['wpdb'];
            self::migrateEinrichtungenStammDropUniqueEinrichtung($wpdb);
            self::installTables();
        }
        \update_option('bs_awo_jobs_schema_version', self::SCHEMA_VERSION);
    }

    /**
     * Wird bei Plugin-Aktivierung aufgerufen. Legt alle Tabellen an.
     */
    public static function activate(): void
    {
        self::installTables();
        $wpdb = $GLOBALS['wpdb'];
        self::seedKonfiguration($wpdb);

        \BS_Awo_Jobs_Statistik\WordPress\Cron\CronHandler::schedule();
        \update_option('bs_awo_jobs_schema_version', self::SCHEMA_VERSION);
    }

    /**
     * dbDelta für alle Plugin-Tabellen (idempotent).
     */
    public static function installTables(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tables = self::getTableSchemas($prefix, $charset_collate);
        foreach ($tables as $sql) {
            dbDelta($sql);
        }
    }

    /**
     * Schema 7: UNIQUE auf einrichtung verhindert mehrere Stammdatensätze mit gleichem Namen im Master-/Alias-Cluster.
     * Index wird zu einem normalen Schlüssel (dbDelta legt neuen Index an).
     */
    private static function migrateEinrichtungenStammDropUniqueEinrichtung($wpdb): void
    {
        $t = $wpdb->prefix . Database::TABLE_EINRICHTUNGEN_STAMM;
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t));
        if ($exists !== $t) {
            return;
        }
        $indexes = $wpdb->get_results("SHOW INDEX FROM `{$t}` WHERE Key_name = 'einrichtung'", ARRAY_A);
        if (!$indexes) {
            return;
        }
        $nonUnique = isset($indexes[0]['Non_unique']) ? (int) $indexes[0]['Non_unique'] : 1;
        if ($nonUnique === 0) {
            $wpdb->query("ALTER TABLE `{$t}` DROP INDEX `einrichtung`");
        }
    }

    /**
     * SQL für dbDelta – Tabellendefinitionen wie in ARCHITECTURE.md (inkl. Einrichtungen-Stamm).
     *
     * @return list<string>
     */
    private static function getTableSchemas(string $prefix, string $charset_collate): array
    {
        $ausschreibungen = "CREATE TABLE {$prefix}" . Database::TABLE_AUSSCHREIBUNGEN . " (
            id int(11) NOT NULL AUTO_INCREMENT,
            stellennummer varchar(20) NOT NULL,
            titel varchar(255) NOT NULL,
            einrichtung varchar(255) NOT NULL,
            fachbereich_boerse varchar(100) NOT NULL,
            fachbereich_intern varchar(100) DEFAULT NULL,
            anstellungsart varchar(50) NOT NULL,
            vertragsart varchar(50) NOT NULL,
            zeitmodell varchar(50) NOT NULL,
            stunden decimal(4,2) DEFAULT NULL,
            stunden_quelle varchar(10) DEFAULT NULL,
            startdatum date DEFAULT NULL,
            stopdatum date DEFAULT NULL,
            plz_einsatzort varchar(10) DEFAULT NULL,
            einsatzort varchar(100) DEFAULT NULL,
            erstellt_von varchar(255) DEFAULT NULL,
            in_statistik_beruecksichtigen tinyint(1) NOT NULL DEFAULT 1,
            quelle varchar(10) NOT NULL,
            importiert_am datetime NOT NULL,
            zuletzt_gesehen_api datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY stellennummer (stellennummer)
        ) $charset_collate;";

        $logische_stellen = "CREATE TABLE {$prefix}" . Database::TABLE_LOGISCHE_STELLEN . " (
            id int(11) NOT NULL AUTO_INCREMENT,
            titel varchar(255) NOT NULL,
            einrichtung varchar(255) NOT NULL,
            fachbereich_boerse varchar(100) DEFAULT NULL,
            fachbereich_intern varchar(100) DEFAULT NULL,
            anstellungsart varchar(50) DEFAULT NULL,
            manuell_verifiziert tinyint(1) NOT NULL,
            erstellt_am datetime NOT NULL,
            aktualisiert_am datetime NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        $zuordnungen = "CREATE TABLE {$prefix}" . Database::TABLE_ZUORDNUNGEN . " (
            id int(11) NOT NULL AUTO_INCREMENT,
            logische_stelle_id int(11) NOT NULL,
            stellennummer varchar(20) NOT NULL,
            zuordnungstyp varchar(10) NOT NULL,
            erstellt_am datetime NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        $snapshots = "CREATE TABLE {$prefix}" . Database::TABLE_SNAPSHOTS . " (
            id int(11) NOT NULL AUTO_INCREMENT,
            stellennummer varchar(20) NOT NULL,
            snapshot_datum date NOT NULL,
            stunden decimal(4,2) DEFAULT NULL,
            zeitmodell varchar(50) DEFAULT NULL,
            status varchar(10) NOT NULL,
            PRIMARY KEY  (id),
            KEY stellennummer_snapshot_datum (stellennummer, snapshot_datum)
        ) $charset_collate;";

        $konfiguration = "CREATE TABLE {$prefix}" . Database::TABLE_KONFIGURATION . " (
            schluessel varchar(100) NOT NULL,
            wert text NOT NULL,
            beschreibung varchar(255) DEFAULT NULL,
            PRIMARY KEY  (schluessel)
        ) $charset_collate;";

        $einrichtungen_stamm = "CREATE TABLE {$prefix}" . Database::TABLE_EINRICHTUNGEN_STAMM . " (
            id int(11) NOT NULL AUTO_INCREMENT,
            einrichtung varchar(255) NOT NULL,
            einrichtung_normalized varchar(191) DEFAULT NULL,
            letzter_api_name varchar(255) DEFAULT NULL,
            master_einrichtung_id int(11) DEFAULT NULL,
            fachbereich_boerse varchar(100) NOT NULL DEFAULT '',
            fachbereich_intern varchar(100) DEFAULT NULL,
            aktiv tinyint(1) NOT NULL DEFAULT 1,
            bemerkung text,
            soll_vza_fachkraefte decimal(10,2) DEFAULT NULL,
            soll_vza_hilfskraefte decimal(10,2) DEFAULT NULL,
            soll_vza_3 decimal(10,2) DEFAULT NULL,
            soll_vza_4 decimal(10,2) DEFAULT NULL,
            soll_vza_5 decimal(10,2) DEFAULT NULL,
            gesamt_vza_override decimal(10,2) DEFAULT NULL,
            pruefstatus varchar(20) NOT NULL DEFAULT 'ok',
            pruef_hinweis varchar(500) DEFAULT NULL,
            quelle varchar(20) NOT NULL DEFAULT 'manuell',
            erstellt_am datetime NOT NULL,
            aktualisiert_am datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY einrichtung (einrichtung(191)),
            KEY fachbereich_intern (fachbereich_intern(100)),
            KEY einrichtung_normalized (einrichtung_normalized(191)),
            KEY master_einrichtung_id (master_einrichtung_id),
            KEY pruefstatus (pruefstatus)
        ) $charset_collate;";

        return [$ausschreibungen, $logische_stellen, $zuordnungen, $snapshots, $konfiguration, $einrichtungen_stamm];
    }

    /**
     * Standardwerte in bs_awojobs_konfiguration einfügen (ARCHITECTURE.md).
     * Öffentlich für SettingsPage (nach Daten-Löschung).
     */
    public static function seedKonfigurationStatic($wpdb): void
    {
        self::seedKonfiguration($wpdb);
    }

    /**
     * Standardwerte in bs_awojobs_konfiguration einfügen (ARCHITECTURE.md).
     */
    private static function seedKonfiguration($wpdb): void
    {
        $table = $wpdb->prefix . Database::TABLE_KONFIGURATION;

        $rows = [
            ['api_url', '', null],
            ['vollzeit_stunden', '39', null],
            ['fachbereich_intern_aktiv', '0', null],
            ['cronjob_intervall', 'daily', null],
            ['daten_beim_deinstallieren_loeschen', '0', null],
        ];

        foreach ($rows as $row) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$table} WHERE schluessel = %s",
                $row[0]
            ));
            if ($exists) {
                continue;
            }
            $wpdb->insert($table, [
                'schluessel' => $row[0],
                'wert' => $row[1],
                'beschreibung' => $row[2],
            ], ['%s', '%s', '%s']);
        }
    }
}