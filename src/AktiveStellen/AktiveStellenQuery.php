<?php
/**
 * Eine Quelle für Daten und Filterlogik des Tabs „Aktive Stellen“
 * (SQL, Normalisierung, UI-Filter, Export mit Berücksichtigen).
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\AktiveStellen;

use BS_Awo_Jobs_Statistik\Core\Database;

final class AktiveStellenQuery
{
    /**
     * Alle aktuell als „online“ markierten Ausschreibungen (Masteransicht).
     *
     * @return list<array<string, mixed>>
     */
    public static function fetchAktiveZeilen(object $wpdb): array
    {
        $tblA = $wpdb->prefix . Database::TABLE_AUSSCHREIBUNGEN;
        $rows = $wpdb->get_results(
            "SELECT stellennummer, titel, einrichtung, fachbereich_boerse, fachbereich_intern,
                    plz_einsatzort, einsatzort, zeitmodell, stunden, stunden_quelle, startdatum,
                    in_statistik_beruecksichtigen
             FROM {$tblA}
             WHERE zuletzt_gesehen_api IS NOT NULL
             ORDER BY fachbereich_boerse, einrichtung, titel, stellennummer",
            \ARRAY_A
        );

        return $rows ? array_map(static fn ($r) => array_merge([], $r), $rows) : [];
    }

    public static function normalizeFilterValue(?string $value): string
    {
        $value = trim((string) $value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\x{00A0}/u', ' ', $value);
        $value = preg_replace('/[‐-‒–—−]/u', '-', $value);
        $value = preg_replace('/\s+/u', ' ', $value);

        return mb_strtolower((string) $value);
    }

    /**
     * Dieselben Regeln wie die Tabellenansicht im Admin (keine Berücksichtigen-Spalte).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function applyUiFilters(array $rows, AktiveStellenFilterInput $filter): array
    {
        $out = array_values(array_filter($rows, static function (array $row) use ($filter): bool {
            if (
                $filter->fachbereich !== ''
                && self::normalizeFilterValue((string) ($row['fachbereich_boerse'] ?? ''))
                    !== self::normalizeFilterValue($filter->fachbereich)
            ) {
                return false;
            }
            if (
                $filter->mandantenfeld !== ''
                && self::normalizeFilterValue((string) ($row['fachbereich_intern'] ?? ''))
                    !== self::normalizeFilterValue($filter->mandantenfeld)
            ) {
                return false;
            }
            if (
                $filter->einrichtung !== ''
                && self::normalizeFilterValue((string) ($row['einrichtung'] ?? ''))
                    !== self::normalizeFilterValue($filter->einrichtung)
            ) {
                return false;
            }
            if ($filter->plz !== '' && (string) ($row['plz_einsatzort'] ?? '') !== $filter->plz) {
                return false;
            }
            if ($filter->quelleStunden !== '' && (string) ($row['stunden_quelle'] ?? '') !== $filter->quelleStunden) {
                return false;
            }

            if ($filter->q !== '') {
                $haystack = mb_strtolower(implode(' ', [
                    (string) ($row['stellennummer'] ?? ''),
                    (string) ($row['titel'] ?? ''),
                    (string) ($row['einrichtung'] ?? ''),
                    (string) ($row['fachbereich_boerse'] ?? ''),
                    (string) ($row['fachbereich_intern'] ?? ''),
                    (string) ($row['plz_einsatzort'] ?? ''),
                    (string) ($row['einsatzort'] ?? ''),
                    (string) ($row['zeitmodell'] ?? ''),
                    (string) ($row['stunden_quelle'] ?? ''),
                ]));
                $needle = mb_strtolower($filter->q);
                if (mb_strpos($haystack, $needle) === false) {
                    return false;
                }
            }

            return true;
        }));

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function filterNurBeruecksichtigt(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => (int) ($row['in_statistik_beruecksichtigen'] ?? 1) === 1
        ));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function resolveForExport(array $rows, AktiveStellenFilterInput $filter, AktiveStellenExportOptions $options): array
    {
        $work = $rows;
        if ($options->useUiFilters()) {
            $work = self::applyUiFilters($work, $filter);
        }
        if ($options->nurBeruecksichtigt()) {
            $work = self::filterNurBeruecksichtigt($work);
        }

        return $work;
    }
}
