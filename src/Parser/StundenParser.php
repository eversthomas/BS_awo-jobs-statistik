<?php
/**
 * Extraktion der Stundenzahl aus HTML-Fließtext (API-Feld Infos).
 * ARCHITECTURE.md: Regex für einfache Zahl und Spanne, Komma normalisieren.
 * Keine WordPress-Abhängigkeiten.
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\Parser;

final class StundenParser
{
    /**
     * Stundenzahl aus HTML-Text extrahieren.
     * Einfache Zahl (z. B. "21,00 Stunden", "39 Std.") oder Spanne ("25 bis 30 Stunden") → höherer Wert.
     *
     * @return float|null NULL wenn kein Match
     */
    public static function parse(string $html): ?float
    {
        $text = strip_tags($html);
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        // Spanne: "25 bis 30 Stunden" oder "25 bis 30 Std."
        if (preg_match('/(\d+)[,.]?\d*\s*bis\s*(\d+)[,.]?\d*\s*(?:Stunden|Std\.?)/ui', $text, $m)) {
            $low = (float) str_replace(',', '.', trim($m[1]));
            $high = (float) str_replace(',', '.', trim($m[2]));
            return max($low, $high);
        }

        // Einfache Zahl: "21,00 Stunden" oder "39 Std." oder "21.5 Stunden"
        if (preg_match('/(\d+[,.]?\d*)\s*(?:Stunden|Std\.?)/ui', $text, $m)) {
            $num = str_replace(',', '.', trim($m[1]));
            $val = (float) $num;
            return $val > 0 ? $val : null;
        }

        return null;
    }
}
