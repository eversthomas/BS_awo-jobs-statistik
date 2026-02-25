<?php
/**
 * Vakanzzeit-Analyse pro logischer Stelle.
 * ARCHITECTURE.md: Ø Vakanzzeit in Tagen (startdatum bis stopdatum), offenSeit für aktuell offene Stellen.
 * Keine WordPress-Abhängigkeiten.
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\Analysis;

use BS_Awo_Jobs_Statistik\Core\Database;
use BS_Awo_Jobs_Statistik\Core\StringNormalizer;

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
     *   plz_einsatzort: string|null,
     *   einsatzort: string|null,
     *   startdatum: string,
     *   tage_offen: int
     * }>
     */
    public function offenSeit(): array
    {
        $tblA = $this->db->prefix . Database::TABLE_AUSSCHREIBUNGEN;

        $sql = "SELECT stellennummer, titel, einrichtung, plz_einsatzort, einsatzort, startdatum,
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
                'plz_einsatzort' => isset($row['plz_einsatzort']) && $row['plz_einsatzort'] !== '' ? (string) $row['plz_einsatzort'] : null,
                'einsatzort' => isset($row['einsatzort']) && $row['einsatzort'] !== '' ? (string) $row['einsatzort'] : null,
                'startdatum' => (string) ($row['startdatum'] ?? ''),
                'tage_offen' => max(0, (int) ($row['tage_offen'] ?? 0)),
            ];
        }

        return $result;
    }

    /**
     * Statistiken nach Postleitzahl (nur aktuell offene Stellen).
     *
     * @return array<int, array{plz: string, einsatzort: string|null, anzahl: int, vza_summe: float}>
     */
    public function nachPlz(): array
    {
        $tblA = $this->db->prefix . Database::TABLE_AUSSCHREIBUNGEN;
        $tblConfig = $this->db->prefix . Database::TABLE_KONFIGURATION;
        $vollzeit = (float) ($this->db->get_var($this->db->prepare(
            "SELECT wert FROM {$tblConfig} WHERE schluessel = %s",
            'vollzeit_stunden'
        )) ?: 39);

        $sql = "SELECT plz_einsatzort, einsatzort,
                       COUNT(*) AS anzahl,
                       SUM(CASE
                           WHEN stunden IS NOT NULL AND stunden > 0 THEN stunden / {$vollzeit}
                           WHEN zeitmodell LIKE '%Vollzeit%' THEN 1.0
                           ELSE 0
                       END) AS vza_summe,
                       GROUP_CONCAT(stellennummer ORDER BY stellennummer SEPARATOR ', ') AS stellennummern,
                       GROUP_CONCAT(DISTINCT titel ORDER BY titel SEPARATOR ' | ') AS titel_liste
                FROM {$tblA}
                WHERE zuletzt_gesehen_api IS NOT NULL
                  AND plz_einsatzort IS NOT NULL AND plz_einsatzort != ''
                GROUP BY plz_einsatzort, einsatzort
                ORDER BY anzahl DESC, plz_einsatzort";

        $rows = $this->db->get_results($sql, ARRAY_A);
        $result = [];
        foreach ($rows ?: [] as $row) {
            $result[] = [
                'plz' => (string) ($row['plz_einsatzort'] ?? ''),
                'einsatzort' => isset($row['einsatzort']) && $row['einsatzort'] !== '' ? (string) $row['einsatzort'] : null,
                'anzahl' => (int) ($row['anzahl'] ?? 0),
                'vza_summe' => round((float) ($row['vza_summe'] ?? 0), 2),
                'stellennummern' => (string) ($row['stellennummern'] ?? ''),
                'titel_liste' => (string) ($row['titel_liste'] ?? ''),
            ];
        }
        return $result;
    }

    /**
     * Zählungen für Übersicht: offene Stellen nach Titel, Fachbereich, PLZ.
     *
     * @return array{nach_titel: array<string, int>, nach_fachbereich: array<string, int>, nach_plz: array<string, int>}
     */
    public function getUebersichtCounts(): array
    {
        $tblA = $this->db->prefix . Database::TABLE_AUSSCHREIBUNGEN;

        $nachTitel = [];
        $rows = $this->db->get_results(
            "SELECT titel, COUNT(*) AS cnt FROM {$tblA} WHERE zuletzt_gesehen_api IS NOT NULL AND titel != '' GROUP BY titel ORDER BY cnt DESC LIMIT 15",
            ARRAY_A
        );
        foreach ($rows ?: [] as $r) {
            $nachTitel[(string) $r['titel']] = (int) $r['cnt'];
        }

        $nachFachbereich = [];
        $rows = $this->db->get_results(
            "SELECT fachbereich_boerse, COUNT(*) AS cnt FROM {$tblA} WHERE zuletzt_gesehen_api IS NOT NULL GROUP BY fachbereich_boerse ORDER BY cnt DESC",
            ARRAY_A
        );
        foreach ($rows ?: [] as $r) {
            $key = StringNormalizer::fachbereich($r['fachbereich_boerse'] ?? null);
            $nachFachbereich[$key] = ($nachFachbereich[$key] ?? 0) + (int) $r['cnt'];
        }

        $nachPlz = [];
        $rows = $this->db->get_results(
            "SELECT plz_einsatzort, COUNT(*) AS cnt FROM {$tblA} WHERE zuletzt_gesehen_api IS NOT NULL AND plz_einsatzort IS NOT NULL AND plz_einsatzort != '' GROUP BY plz_einsatzort ORDER BY cnt DESC LIMIT 15",
            ARRAY_A
        );
        foreach ($rows ?: [] as $r) {
            $nachPlz[(string) $r['plz_einsatzort']] = (int) $r['cnt'];
        }

        return ['nach_titel' => $nachTitel, 'nach_fachbereich' => $nachFachbereich, 'nach_plz' => $nachPlz];
    }
}
