<?php
/**
 * Effektive Gesamt-Soll-VZÄ aus Teilfeldern und optionalem Override (kein redundanter „gesamt“-Speicher).
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\EinrichtungenStamm;

final class EinrichtungenStammSollVza
{
    /** @var list<string> */
    private const TEILFELDER = [
        'soll_vza_fachkraefte',
        'soll_vza_hilfskraefte',
        'soll_vza_3',
        'soll_vza_4',
        'soll_vza_5',
    ];

    /**
     * @param array<string, mixed> $row DB-Zeile Einrichtungen-Stamm
     */
    public static function summeTeilwerte(array $row): float
    {
        $sum = 0.0;
        foreach (self::TEILFELDER as $k) {
            if (!isset($row[$k]) || $row[$k] === null || $row[$k] === '') {
                continue;
            }
            $sum += (float) $row[$k];
        }

        return round($sum, 2);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nutztManuellenGesamtwert(array $row): bool
    {
        if (!array_key_exists('gesamt_vza_override', $row)) {
            return false;
        }
        $v = $row['gesamt_vza_override'];

        return $v !== null && $v !== '';
    }

    /**
     * Override gesetzt → dieses; sonst Summe der fünf Kategorien.
     *
     * @param array<string, mixed> $row
     */
    public static function effectiveGesamt(array $row): float
    {
        if (self::nutztManuellenGesamtwert($row)) {
            return round((float) $row['gesamt_vza_override'], 2);
        }

        return self::summeTeilwerte($row);
    }
}
