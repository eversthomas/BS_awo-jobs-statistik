<?php
/**
 * Fluktuationsanalyse pro logischer Stelle.
 * ARCHITECTURE.md: Anzahl Ausschreibungen, Zeitraum, durchschnittliche Tage zwischen Ausschreibungen.
 * Keine WordPress-Abhängigkeiten.
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\Analysis;

use BS_Awo_Jobs_Statistik\Core\Database;

final class FluktuationAnalyzer
{
    /** @var object mit prefix, get_results() */
    private $db;

    public function __construct(object $db)
    {
        $this->db = $db;
    }

    /**
     * Pro logischer Stelle: Anzahl Ausschreibungen, Zeitraum erste–letzte, Ø Tage zwischen Ausschreibungen.
     *
     * @return array<int, array{
     *   logische_stelle_id: int,
     *   titel: string,
     *   einrichtung: string,
     *   anzahl_ausschreibungen: int,
     *   erste_ausschreibung: string|null,
     *   letzte_ausschreibung: string|null,
     *   tage_zeitraum: int|null,
     *   durchschnitt_tage_zwischen: float|null
     * }>
     */
    public function berechne(): array
    {
        $tblA = $this->db->prefix . Database::TABLE_AUSSCHREIBUNGEN;
        $tblL = $this->db->prefix . Database::TABLE_LOGISCHE_STELLEN;
        $tblZ = $this->db->prefix . Database::TABLE_ZUORDNUNGEN;

        $sql = "SELECT z.logische_stelle_id, l.titel, l.einrichtung,
                       COUNT(a.stellennummer) AS anzahl,
                       MIN(a.startdatum) AS erste,
                       MAX(a.startdatum) AS letzte
                FROM {$tblZ} z
                JOIN {$tblL} l ON l.id = z.logische_stelle_id
                JOIN {$tblA} a ON a.stellennummer = z.stellennummer
                WHERE a.startdatum IS NOT NULL
                GROUP BY z.logische_stelle_id, l.titel, l.einrichtung";

        $rows = $this->db->get_results($sql, ARRAY_A);
        $result = [];

        foreach ($rows ?: [] as $row) {
            $anzahl = (int) $row['anzahl'];
            $erste = $row['erste'] ?? null;
            $letzte = $row['letzte'] ?? null;

            $tageZeitraum = null;
            $durchschnittTage = null;
            if ($erste && $letzte) {
                $days = (strtotime($letzte) - strtotime($erste)) / 86400;
                $tageZeitraum = (int) round($days);
                if ($anzahl > 1) {
                    $durchschnittTage = round($tageZeitraum / ($anzahl - 1), 1);
                }
            }

            $result[(int) $row['logische_stelle_id']] = [
                'logische_stelle_id' => (int) $row['logische_stelle_id'],
                'titel' => (string) ($row['titel'] ?? ''),
                'einrichtung' => (string) ($row['einrichtung'] ?? ''),
                'anzahl_ausschreibungen' => $anzahl,
                'erste_ausschreibung' => $erste,
                'letzte_ausschreibung' => $letzte,
                'tage_zeitraum' => $tageZeitraum,
                'durchschnitt_tage_zwischen' => $durchschnittTage,
            ];
        }

        return $result;
    }

    /**
     * Top-N logische Stellen nach Ausschreibungshäufigkeit.
     *
     * @return array<int, array{logische_stelle_id: int, titel: string, einrichtung: string, anzahl: int}>
     */
    public function haeufigsteStellen(int $limit = 10): array
    {
        $data = $this->berechne();
        uasort($data, static function ($a, $b) {
            return $b['anzahl_ausschreibungen'] <=> $a['anzahl_ausschreibungen'];
        });
        return array_slice(array_values($data), 0, $limit);
    }
}
