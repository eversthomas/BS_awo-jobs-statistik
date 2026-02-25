<?php
/**
 * Normalisierung von Freitext-Werten für konsistente Auswertungen.
 * Vereinheitlicht z.B. "stationäre Pflege" und "Stationäre Pflege" zu einem Key.
 * Keine WordPress-Abhängigkeiten.
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\Core;

final class StringNormalizer
{
    /**
     * Fachbereich / Mandantenfeld normalisieren für Gruppierungen.
     * Trim + Title-Case, damit "stationäre Pflege" und "Stationäre Pflege" zusammengefasst werden.
     *
     * @param string|null $value Rohwert aus DB
     * @return string Normalisierter Wert, oder '(leer)' bei leerem Input
     */
    public static function fachbereich(?string $value): string
    {
        $s = trim((string) ($value ?? ''));
        if ($s === '') {
            return '(leer)';
        }
        return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Einrichtung normalisieren für Gruppierungen.
     * "Kindertagesstätte" und "Kita" werden gleich behandelt, zusätzlich Title-Case.
     *
     * @param string|null $value Rohwert aus DB
     * @return string Normalisierter Wert, oder '(leer)' bei leerem Input
     */
    public static function einrichtung(?string $value): string
    {
        $s = trim((string) ($value ?? ''));
        if ($s === '') {
            return '(leer)';
        }
        $s = str_ireplace('Kindertagesstätte', 'Kita', $s);
        return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
    }
}
