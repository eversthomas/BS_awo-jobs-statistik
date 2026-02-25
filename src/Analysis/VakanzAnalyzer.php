<?php
/**
 * Vakanzzeit-Analyse pro logischer Stelle.
 * ARCHITECTURE.md: Ø Vakanzzeit in Tagen (startdatum bis stopdatum), offenSeit für aktuell offene Stellen.
 * Keine WordPress-Abhängigkeiten.
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\Analysis;

use BS_Awo_Jobs_Statistik\Core\Database;

final class VakanzAnalyzer
{
    /** @var object mit prefix, get_results() */
    private $db;

    public function __construct(object $db)
    {
        $this->db = $db;
    }

    /**
     * Pro logischer Stelle: durchschnittliche Vakanzzeit in Tagen (startdatum bis stopdatum).
     * Nur Ausschreibungen mit beiden Datumsangaben.
     *
     * @return array<int, array{
     *   logische_stelle_id: int,
     *   titel: string,
     *   einrichtung: string,
     *   anzahl_abgeschlossen: int,
     *   durchschnitt_vakanz_tage: float
     * }>
     */
    public function berechne(): array
    {
        $tblA = $this->db->prefix . Database::TABLE_AUSSCHREIBUNGEN;
        $tblL = $this->db->prefix . Database::TABLE_LOGISCHE_STELLEN;
        $tblZ = $this->db->prefix . Database::TABLE_ZUORDNUNGEN;

        $sql = "SELECT z.logische_stelle_id, l.titel, l.einrichtung,
                       COUNT(a.stellennummer) AS anzahl,
                       AVG(DATEDIFF(a.stopdatum, a.startdatum)) AS avg_tage
                FROM {$tblZ} z
                JOIN {$tblL} l ON l.id = z.logische_stelle_id
                JOIN {$tblA} a ON a.stellennummer = z.stellennummer
                WHERE a.startdatum IS NOT NULL AND a.stopdatum IS NOT NULL
                GROUP BY z.logische_stelle_id, l.titel, l.einrichtung
                HAVING anzahl > 0";

        $rows = $this->db->get_results($sql, ARRAY_A);
        $result = [];

        foreach ($rows ?: [] as $row) {
            $result[(int) $row['logische_stelle_id']] = [
                'logische_stelle_id' => (int) $row['logische_stelle_id'],
                'titel' => (string) ($row['titel'] ?? ''),
                'einrichtung' => (string) ($row['einrichtung'] ?? ''),
                'anzahl_abgeschlossen' => (int) $row['anzahl'],
                'durchschnitt_vakanz_tage' => round((float) ($row['avg_tage'] ?? 0), 1),
            ];
        }

        return $result;
    }

    /**
     * Aktuell offene Stellen (zuletzt_gesehen_api IS NOT NULL) mit Tagen seit startdatum.
     *
     * @return array<int, array{
     *   stellennummer: string,
     *   titel: string,
     *   einrichtung: string,
     *   startdatum: string,
     *   tage_offen: int
     * }>
     */
    public function offenSeit(): array
    {
        $tblA = $this->db->prefix . Database::TABLE_AUSSCHREIBUNGEN;

        $sql = "SELECT stellennummer, titel, einrichtung, startdatum,
                       DATEDIFF(CURDATE(), startdatum) AS tage_offen
                FROM {$tblA}
                WHERE zuletzt_gesehen_api IS NOT NULL AND startdatum IS NOT NULL
                ORDER BY tage_offen DESC";

        $rows = $this->db->get_results($sql, ARRAY_A);
        $result = [];

        foreach ($rows ?: [] as $row) {
            $result[] = [
                'stellennummer' => (string) ($row['stellennummer'] ?? ''),
                'titel' => (string) ($row['titel'] ?? ''),
                'einrichtung' => (string) ($row['einrichtung'] ?? ''),
                'startdatum' => (string) ($row['startdatum'] ?? ''),
                'tage_offen' => max(0, (int) ($row['tage_offen'] ?? 0)),
            ];
        }

        return $result;
    }
}
