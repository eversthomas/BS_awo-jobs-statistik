<?php
/**
 * Plugin-Aktivierung: Anlegen aller Tabellen mit dbDelta.
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\Core;

require_once ABSPATH . 'wp-admin/includes/upgrade.php';

final class Installer
{
    /**
     * Wird bei Plugin-Aktivierung aufgerufen. Legt alle 5 Tabellen an.
     */
    public static function activate(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix;

        $tables = self::getTableSchemas($prefix, $charset_collate);
        foreach ($tables as $sql) {
            dbDelta($sql);
        }

        self::seedKonfiguration($wpdb);
    }

    /**
     * SQL für dbDelta – Tabellendefinitionen wie in ARCHITECTURE.md.
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

        return [$ausschreibungen, $logische_stellen, $zuordnungen, $snapshots, $konfiguration];
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
