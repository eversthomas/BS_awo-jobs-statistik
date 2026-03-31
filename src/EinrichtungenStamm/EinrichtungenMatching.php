<?php
/**
 * Matching von Einrichtungsnamen (API/Seed vs. Stammdaten) ohne externe Bibliotheken.
 * Exakt über normalisierten Schlüssel; Verdacht über einfachen Textvergleich.
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\EinrichtungenStamm;

use BS_Awo_Jobs_Statistik\AktiveStellen\AktiveStellenQuery;

final class EinrichtungenMatching
{
    public const PRUEFSTATUS_OK = 'ok';
    public const PRUEFSTATUS_PRUEFEN = 'pruefen';

    public const QUELLE_MANUELL = 'manuell';
    public const QUELLE_SEED = 'seed';
    public const QUELLE_API = 'api';

    public const API_ABWEICHUNG_BLOCK_START = '--- API-Abweichung (automatisch, Stamm führend) ---';

    /** Ab diesem Prozentsatz (similar_text auf Match-Keys) → Prüffall statt blindem Neu-Anlegen */
    private const AEHLICHKEIT_VERDACHT_AB_PROZENT = 88.0;

    /**
     * Match-Key für Einrichtungen: baut auf normalizeFilterValue auf, mit vorsichtigen Zusatzregeln
     * (optional AWO-Präfix, Bindestrich≈Leerzeichen zwischen Wörtern), ohne Baum-/Einrichtungs-Typen zu streichen.
     */
    public static function matchKeyFromRaw(string $raw): string
    {
        $s = self::vorverarbeitungFuerEinrichtungsMatch($raw);

        return AktiveStellenQuery::normalizeFilterValue($s);
    }

    /**
     * Rohname für Key-Bildung: nicht für Anzeige; konservativ.
     */
    public static function vorverarbeitungFuerEinrichtungsMatch(string $raw): string
    {
        $s = trim($raw);
        if ($s === '') {
            return '';
        }
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/\x{00A0}/u', ' ', $s);
        $s = preg_replace('/[‐-‒–—−]/u', '-', $s);
        // Bindestriche zwischen Buchstaben/Ziffern → Leerzeichen (Johannes-Rau-Haus vs. Johannes Rau Haus)
        $s = preg_replace('/(?<=[\p{L}\p{N}])-(?=[\p{L}\p{N}])/u', ' ', $s);
        $s = preg_replace('/\s+/u', ' ', trim($s));
        // Optionales Präfix „AWO “ am Anfang (nach Unicode-/Worttrennung)
        $s = preg_replace('/^awo\s+/iu', '', $s);
        $s = preg_replace('/\s+/u', ' ', trim($s));

        return $s;
    }

    /**
     * @param array<string, mixed> $row Stammdaten-Zeile
     */
    public static function matchKeyFromRow(array $row): string
    {
        $stored = isset($row['einrichtung_normalized']) ? trim((string) $row['einrichtung_normalized']) : '';
        if ($stored !== '') {
            return $stored;
        }

        return self::matchKeyFromRaw((string) ($row['einrichtung'] ?? ''));
    }

    /**
     * Erste passende ID bei gleichem Match-Key (Mehrfachtreffer: erste Zeile).
     *
     * @param list<array<string, mixed>> $stammRows
     */
    public static function findExactIdByKey(array $stammRows, string $key): ?int
    {
        if ($key === '') {
            return null;
        }
        foreach ($stammRows as $r) {
            if (self::matchKeyFromRow($r) === $key) {
                return (int) ($r['id'] ?? 0) ?: null;
            }
        }

        return null;
    }

    /**
     * Ziel-ID für Seed-Updates: Wurzel-Master des Clusters, wenn alle Stammzeilen mit gleichem Match-Key
     * zum selben kanonischen Master gehören. Sonst Fallback auf {@see findExactIdByKey} (erste Zeile).
     *
     * @param list<array<string, mixed>> $stammRows
     * @param array<int, array<string, mixed>> $idToRow
     */
    public static function resolveSeedUpdateStammId(array $stammRows, string $key, array $idToRow): ?int
    {
        if ($key === '') {
            return null;
        }
        $roots = [];
        foreach ($stammRows as $r) {
            if (self::matchKeyFromRow($r) !== $key) {
                continue;
            }
            $rid = (int) ($r['id'] ?? 0);
            if ($rid <= 0) {
                continue;
            }
            $roots[EinrichtungenStammCluster::resolveRootMasterId($r, $idToRow)] = true;
        }
        $rootList = array_keys($roots);
        if (count($rootList) === 1) {
            return $rootList[0] > 0 ? $rootList[0] : null;
        }
        if ($rootList === []) {
            return null;
        }

        return self::findExactIdByKey($stammRows, $key);
    }

    /**
     * Verdächtig ähnlich (nicht exakt gleicher Key): höchster similar_text-Score.
     *
     * @param list<array<string, mixed>> $stammRows
     * @return array{id: int, name: string, prozent: float}|null
     */
    public static function findAehnlichstenNichtExakten(array $stammRows, string $key): ?array
    {
        if ($key === '') {
            return null;
        }

        $best = null;
        $bestPct = 0.0;

        foreach ($stammRows as $r) {
            $rk = self::matchKeyFromRow($r);
            if ($rk === '' || $rk === $key) {
                continue;
            }

            similar_text($key, $rk, $pct);
            if ($pct >= self::AEHLICHKEIT_VERDACHT_AB_PROZENT && ($best === null || $pct > $bestPct)) {
                $bestPct = $pct;
                $best = [
                    'id' => (int) ($r['id'] ?? 0),
                    'name' => (string) ($r['einrichtung'] ?? ''),
                    'prozent' => round((float) $pct, 1),
                ];
            }
        }

        return $best !== null && $best['id'] > 0 ? $best : null;
    }

    /**
     * Abweichungen zwischen aktueller API-Zuordnung (Aggregate) und Stammdaten — nur lesen, kein Überschreiben.
     *
     * @param array<string, mixed> $stammRow
     * @return list<string> Meldungen für Menschen (leer = fachlich Deckungsgleich für geprüfte Felder)
     */
    public static function detectApiStammAbweichungen(
        array $stammRow,
        string $apiEinrichtungRaw,
        string $apiFbBoerse,
        ?string $apiFbInternRaw
    ): array {
        $reasons = [];
        $apiEinr = trim($apiEinrichtungRaw);
        $stammEinr = trim((string) ($stammRow['einrichtung'] ?? ''));
        if ($apiEinr !== $stammEinr) {
            $reasons[] = sprintf(
                \__('Einrichtungsname weicht ab (API: %1$s | Stamm: %2$s)', 'bs-awo-jobs-statistik'),
                $apiEinr !== '' ? $apiEinr : '–',
                $stammEinr !== '' ? $stammEinr : '–'
            );
        }

        $sBoerse = AktiveStellenQuery::normalizeFilterValue(trim((string) ($stammRow['fachbereich_boerse'] ?? '')));
        $aBoerse = AktiveStellenQuery::normalizeFilterValue(trim($apiFbBoerse));
        if ($aBoerse !== $sBoerse) {
            $reasons[] = sprintf(
                \__('Fachbereich Stellenbörse weicht ab (API: %1$s | Stamm: %2$s)', 'bs-awo-jobs-statistik'),
                trim($apiFbBoerse) !== '' ? trim($apiFbBoerse) : '–',
                trim((string) ($stammRow['fachbereich_boerse'] ?? '')) !== '' ? trim((string) ($stammRow['fachbereich_boerse'] ?? '')) : '–'
            );
        }

        $apiInternTrim = $apiFbInternRaw !== null ? trim((string) $apiFbInternRaw) : '';
        $sIntern = AktiveStellenQuery::normalizeFilterValue(trim((string) ($stammRow['fachbereich_intern'] ?? '')));
        $aIntern = AktiveStellenQuery::normalizeFilterValue($apiInternTrim);
        if ($aIntern !== $sIntern) {
            $reasons[] = sprintf(
                \__('Mandantenfeld weicht ab (API: %1$s | Stamm: %2$s)', 'bs-awo-jobs-statistik'),
                $apiInternTrim !== '' ? $apiInternTrim : '–',
                trim((string) ($stammRow['fachbereich_intern'] ?? '')) !== '' ? trim((string) ($stammRow['fachbereich_intern'] ?? '')) : '–'
            );
        }

        return $reasons;
    }

    /**
     * Entfernt den zuletzt gesetzten automatischen API-Abweichungsblock aus einem Prüfhinweis.
     */
    public static function stripApiAbweichungBlockFromHinweis(string $hint): string
    {
        $start = preg_quote(self::API_ABWEICHUNG_BLOCK_START, '/');
        $pattern = '/\R?' . $start . '\R.*?\R---\R?/su';
        $h = preg_replace($pattern, '', $hint);

        return trim(preg_replace('/\R{3,}/u', "\n\n", (string) $h));
    }

    /**
     * @param list<string> $reasons
     */
    public static function formatApiAbweichungBlock(array $reasons): string
    {
        if ($reasons === []) {
            return '';
        }
        $lines = [];
        foreach ($reasons as $r) {
            $lines[] = '• ' . $r;
        }

        return self::API_ABWEICHUNG_BLOCK_START . "\n" . implode("\n", $lines) . "\n---";
    }

    /**
     * @param list<string> $reasons leer = nur API-Block entfernen, sonst neuen Block anhängen
     */
    public static function mergeApiAbweichungInPruefHinweis(?string $existingHint, array $reasons): string
    {
        $base = self::stripApiAbweichungBlockFromHinweis(trim((string) $existingHint));
        if ($reasons === []) {
            return $base;
        }
        $block = self::formatApiAbweichungBlock($reasons);
        if ($base === '') {
            return $block;
        }

        return $base . "\n\n" . $block;
    }
}
