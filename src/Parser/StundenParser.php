<?php
/**
 * Extraktion der Stundenzahl aus HTML-/Fließtext aus API-Feldern wie
 * Einleitungstext oder Infos.
 * Erkennt einfache Zahlen und Stunden-Spannen.
 * Bei Spannen wird der höhere Wert zurückgegeben.
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\Parser;

final class StundenParser
{
    /**
     * Stundenzahl aus HTML-Text extrahieren.
     *
     * Beispiele:
     * - "21,00 Stunden"
     * - "39 Std."
     * - "10 Std./Woche"
     * - "25 bis 30 Stunden"
     * - "19,50 - 35 Std./Woche"
     * - "20–30 Std."
     * - "20-25 Std. bei einer 5,5 Tage-Woche"
     *
     * @return float|null NULL wenn kein Match
     */
    public static function parse(string $html): ?float
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\x{00A0}/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', (string) $text);
        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        // Spannen:
        // "25 bis 30 Stunden"
        // "19,50 - 35 Std./Woche"
        // "20–30 Std."
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:bis|-|–|—)\s*(\d+(?:[.,]\d+)?)\s*(?:Stunden|Std\.?)(?:\s*\/\s*Woche)?/ui', $text, $m)) {
            $low = (float) str_replace(',', '.', $m[1]);
            $high = (float) str_replace(',', '.', $m[2]);
            return max($low, $high);
        }

        // Einfache Zahl:
        // "21,00 Stunden"
        // "39 Std."
        // "10 Std./Woche"
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:Stunden|Std\.?)(?:\s*\/\s*Woche)?/ui', $text, $m)) {
            $val = (float) str_replace(',', '.', $m[1]);
            return $val > 0 ? $val : null;
        }

        return null;
    }
}