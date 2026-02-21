<?php
/**
 * Tabellennamen-Konstanten für BS AWO Jobs Statistik.
 * Keine WordPress-Abhängigkeiten; volle Tabellennamen werden mit $wpdb->prefix gebildet.
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\Core;

final class Database
{
    /** Tabellen-Suffix (mit $wpdb->prefix = voller Tabellenname) */
    public const TABLE_AUSSCHREIBUNGEN = 'bs_awojobs_ausschreibungen';
    public const TABLE_LOGISCHE_STELLEN = 'bs_awojobs_logische_stellen';
    public const TABLE_ZUORDNUNGEN = 'bs_awojobs_zuordnungen';
    public const TABLE_SNAPSHOTS = 'bs_awojobs_snapshots';
    public const TABLE_KONFIGURATION = 'bs_awojobs_konfiguration';

    /**
     * Liefert alle Tabellen-Suffixe (für Install/Uninstall).
     *
     * @return list<string>
     */
    public static function getTableNames(): array
    {
        return [
            self::TABLE_AUSSCHREIBUNGEN,
            self::TABLE_LOGISCHE_STELLEN,
            self::TABLE_ZUORDNUNGEN,
            self::TABLE_SNAPSHOTS,
            self::TABLE_KONFIGURATION,
        ];
    }
}
