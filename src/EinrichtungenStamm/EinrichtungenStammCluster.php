<?php
/**
 * Kanonische Einrichtung (Master) und zugeordnete Alias-Datensätze — reine Hilfslogik, ohne DB.
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\EinrichtungenStamm;

use BS_Awo_Jobs_Statistik\AktiveStellen\AktiveStellenQuery;
use BS_Awo_Jobs_Statistik\Analysis\VzaCalculator;

final class EinrichtungenStammCluster
{
    /**
     * @param list<array<string, mixed>> $allRows
     * @return array<int, array<string, mixed>>
     */
    public static function idMap(array $allRows): array
    {
        $m = [];
        foreach ($allRows as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id > 0) {
                $m[$id] = $r;
            }
        }

        return $m;
    }

    /**
     * Wurzel-Master-ID (Datensatz ohne übergeordneten Master).
     */
    public static function resolveRootMasterId(array $row, array $idToRow): int
    {
        $current = $row;
        for ($i = 0; $i < 50; $i++) {
            $pid = (int) ($current['master_einrichtung_id'] ?? 0);
            if ($pid <= 0) {
                return (int) ($current['id'] ?? 0);
            }
            $next = $idToRow[$pid] ?? null;
            if ($next === null) {
                return (int) ($current['id'] ?? 0);
            }
            $current = $next;
        }

        return (int) ($current['id'] ?? 0);
    }

    /**
     * Alle Stammzeilen, die zu demselben kanonischen Master gehören (inkl. Master selbst).
     *
     * @param list<array<string, mixed>> $allRows
     * @return list<array<string, mixed>>
     */
    public static function membersOfMaster(int $masterId, array $allRows): array
    {
        if ($masterId <= 0) {
            return [];
        }
        $idToRow = self::idMap($allRows);
        $out = [];
        foreach ($allRows as $r) {
            if (self::resolveRootMasterId($r, $idToRow) === $masterId) {
                $out[] = $r;
            }
        }

        return $out;
    }

    /**
     * Normalisierte und Match-Keys aller in Ausschreibungen vorkommenden Schreibweisen der Cluster,
     * damit Offen-VZÄ zugeordnet werden können (inkl. letzter API-Name als zusätzliche Schreibweise).
     *
     * @param list<array<string, mixed>> $clusterRows
     * @return array{norm: array<string, true>, match: array<string, true>}
     */
    public static function facilityKeysForAusschreibungsMatch(array $clusterRows): array
    {
        $norm = [];
        $match = [];
        foreach ($clusterRows as $r) {
            foreach (['einrichtung', 'letzter_api_name'] as $field) {
                $rawBlock = trim((string) ($r[$field] ?? ''));
                if ($rawBlock === '') {
                    continue;
                }
                $parts = $field === 'letzter_api_name'
                    ? preg_split('/\R+/u', $rawBlock) ?: [$rawBlock]
                    : [$rawBlock];
                foreach ($parts as $raw) {
                    $raw = trim((string) $raw);
                    if ($raw === '') {
                        continue;
                    }
                    $n = AktiveStellenQuery::normalizeFilterValue($raw);
                    if ($n !== '') {
                        $norm[$n] = true;
                    }
                    $mk = EinrichtungenMatching::matchKeyFromRaw($raw);
                    if ($mk !== '') {
                        $match[$mk] = true;
                    }
                }
            }
        }

        return ['norm' => $norm, 'match' => $match];
    }

    /**
     * Summe der VZÄ aus aktiven Zeilen, deren Einrichtung zum Cluster passt (jede Stellenzeile höchstens einmal).
     *
     * @param list<array<string, mixed>> $aktivRows bereits gefiltert (z. B. nur berücksichtigt)
     * @param list<array<string, mixed>> $clusterRows
     */
    public static function summeOffeneVzaFuerCluster($wpdb, array $aktivRows, array $clusterRows, int $vollzeitStunden): float
    {
        $keys = self::facilityKeysForAusschreibungsMatch($clusterRows);
        $normSet = $keys['norm'];
        $matchSet = $keys['match'];
        $vza = new VzaCalculator($wpdb, $vollzeitStunden);
        $sum = 0.0;
        foreach ($aktivRows as $ar) {
            $e = trim((string) ($ar['einrichtung'] ?? ''));
            if ($e === '') {
                continue;
            }
            $kn = AktiveStellenQuery::normalizeFilterValue($e);
            $km = EinrichtungenMatching::matchKeyFromRaw($e);
            $in = isset($normSet[$kn]) || ($km !== '' && isset($matchSet[$km]));
            if (!$in) {
                continue;
            }
            $std = isset($ar['stunden']) && $ar['stunden'] !== null ? (float) $ar['stunden'] : null;
            $sum += $vza->vzaFuerListenzeile($std);
        }

        return $sum;
    }
}
