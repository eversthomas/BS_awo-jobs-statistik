<?php
/**
 * VZÄ-Berechnung für aktuell offene Stellen.
 * ARCHITECTURE.md: stunden/vollzeitStunden, Vollzeit-Fallback 1.0, Teilzeit ohne Stunden = NULL.
 * Keine WordPress-Abhängigkeiten.
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\Analysis;

use BS_Awo_Jobs_Statistik\Core\Database;

final class VzaCalculator
{
    /** @var object mit prefix, get_results(), get_var() */
    private $db;

    private int $vollzeitStunden;

    public function __construct(object $db, int $vollzeitStunden = 39)
    {
        $this->db = $db;
        $this->vollzeitStunden = $vollzeitStunden;
    }

    /**
     * VZÄ aller aktuell offenen Stellen (zuletzt_gesehen_api IS NOT NULL),
     * gruppiert nach fachbereich_boerse und fachbereich_intern.
     *
     * @return array{
     *   nach_boerse: array<string, float|int>,
     *   nach_intern: array<string, float|int>,
     *   unbekannt_anzahl: int
     * }
     */
    public function berechneAktuell(): array
    {
        $tbl = $this->db->prefix . Database::TABLE_AUSSCHREIBUNGEN;
        $rows = $this->db->get_results(
            "SELECT stunden, zeitmodell, fachbereich_boerse, fachbereich_intern
             FROM {$tbl}
             WHERE zuletzt_gesehen_api IS NOT NULL",
            ARRAY_A
        );

        $nachBoerse = [];
        $nachIntern = [];
        $unbekanntAnzahl = 0;

        foreach ($rows ?: [] as $row) {
            $vza = $this->einzelVza(
                $row['stunden'] !== null ? (float) $row['stunden'] : null,
                (string) ($row['zeitmodell'] ?? '')
            );

            if ($vza === null) {
                $unbekanntAnzahl++;
                continue;
            }

            $fbBoerse = trim((string) ($row['fachbereich_boerse'] ?? '')) ?: '(leer)';
            $fbIntern = trim((string) ($row['fachbereich_intern'] ?? '')) ?: '(leer)';

            $nachBoerse[$fbBoerse] = ($nachBoerse[$fbBoerse] ?? 0) + $vza;
            $nachIntern[$fbIntern] = ($nachIntern[$fbIntern] ?? 0) + $vza;
        }

        return [
            'nach_boerse' => $nachBoerse,
            'nach_intern' => $nachIntern,
            'unbekannt_anzahl' => $unbekanntAnzahl,
        ];
    }

    /**
     * Summe aller VZÄ aktuell offener Stellen (ohne "unbekannt").
     */
    public function berechneGesamt(): float
    {
        $tbl = $this->db->prefix . Database::TABLE_AUSSCHREIBUNGEN;
        $rows = $this->db->get_results(
            "SELECT stunden, zeitmodell FROM {$tbl} WHERE zuletzt_gesehen_api IS NOT NULL",
            ARRAY_A
        );

        $summe = 0.0;
        foreach ($rows ?: [] as $row) {
            $vza = $this->einzelVza(
                $row['stunden'] !== null ? (float) $row['stunden'] : null,
                (string) ($row['zeitmodell'] ?? '')
            );
            if ($vza !== null) {
                $summe += $vza;
            }
        }
        return round($summe, 2);
    }

    /**
     * VZÄ-Verlauf aus Snapshots (für Charts).
     * Pro Tag die Summe der VZÄ aller online-Stellen.
     *
     * @param int $tage Anzahl Tage rückwärts
     * @return array<string, float> Datum (Y-m-d) => VZÄ-Summe
     */
    public function berechneVzaVerlauf(int $tage = 90): array
    {
        $tblSnap = $this->db->prefix . Database::TABLE_SNAPSHOTS;
        $vollzeit = (float) $this->vollzeitStunden;

        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT snapshot_datum,
                        SUM(CASE
                            WHEN stunden IS NOT NULL AND stunden > 0 THEN stunden / %f
                            WHEN zeitmodell LIKE '%%Vollzeit%%' THEN 1.0
                            ELSE 0
                        END) AS vza_summe
                 FROM {$tblSnap}
                 WHERE status = 'online'
                   AND snapshot_datum >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
                 GROUP BY snapshot_datum
                 ORDER BY snapshot_datum",
                $vollzeit,
                $tage
            ),
            ARRAY_A
        );

        $result = [];
        foreach ($rows ?: [] as $row) {
            $result[(string) $row['snapshot_datum']] = round((float) ($row['vza_summe'] ?? 0), 2);
        }
        return $result;
    }

    /**
     * VZÄ für eine einzelne Stelle.
     * stunden/vollzeitStunden; NULL + Vollzeit → 1.0; Teilzeit ohne Stunden → NULL.
     *
     * @return float|null
     */
    private function einzelVza(?float $stunden, string $zeitmodell): ?float
    {
        if ($stunden !== null && $stunden > 0) {
            return round($stunden / $this->vollzeitStunden, 4);
        }
        if (stripos($zeitmodell, 'Vollzeit') !== false) {
            return 1.0;
        }
        return null;
    }
}
