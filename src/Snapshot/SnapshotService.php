<?php
/**
 * Täglicher API-Snapshot: Stundenzahlen historischer Stellen sichern.
 * ARCHITECTURE.md: Online/Offline-Status, zuletzt_gesehen_api aktualisieren.
 * Nur wp_remote_get/file_get_contents als externe Abhängigkeit.
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\Snapshot;

use BS_Awo_Jobs_Statistik\Core\Database;
use BS_Awo_Jobs_Statistik\Parser\StundenParser;

final class SnapshotService
{
    /** @var object mit prefix, prepare(), query(), get_results(), insert() */
    private $db;

    private string $apiUrl;

    public function __construct(object $db, string $apiUrl)
    {
        $this->db = $db;
        $this->apiUrl = $apiUrl;
    }

    /**
     * Snapshot durchführen: API abrufen, Snapshots schreiben, zuletzt_gesehen_api setzen.
     *
     * @return array{neu: int, aktualisiert: int}
     */
    public function run(): array
    {
        $neu = 0;
        $aktualisiert = 0;

        $current = $this->fetchCurrentPositions();
        if ($current === null) {
            return ['neu' => 0, 'aktualisiert' => 0];
        }

        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $now = date('Y-m-d H:i:s');

        $tblSnap = $this->db->prefix . Database::TABLE_SNAPSHOTS;
        $tblAuss = $this->db->prefix . Database::TABLE_AUSSCHREIBUNGEN;

        $currentStellennummern = array_values(array_unique(array_column($current, 'stellennummer')));

        /**
         * JSON-API ist die Single Source of Truth für "aktuell online".
         * Deshalb vor dem Setzen der aktuellen API-Stellen alle bisherigen Online-Markierungen zurücksetzen.
         */
        $this->db->query(
            "UPDATE {$tblAuss}
             SET zuletzt_gesehen_api = NULL
             WHERE zuletzt_gesehen_api IS NOT NULL"
        );

        foreach ($current as $sn) {
            $exists = $this->db->get_var($this->db->prepare(
                "SELECT 1 FROM {$tblSnap} WHERE stellennummer = %s AND snapshot_datum = %s",
                $sn['stellennummer'],
                $today
            ));
            if (!$exists) {
                $ok = $this->db->insert($tblSnap, [
                    'stellennummer' => $sn['stellennummer'],
                    'snapshot_datum' => $today,
                    'stunden' => $sn['stunden'],
                    'zeitmodell' => $sn['zeitmodell'],
                    'status' => 'online',
                ], ['%s', '%s', '%s', '%s', '%s']);
                if ($ok) {
                    $neu++;
                }
            }

            $existsInAusschreibungen = $this->db->get_var($this->db->prepare(
                "SELECT 1 FROM {$tblAuss} WHERE stellennummer = %s",
                $sn['stellennummer']
            ));

            if ($existsInAusschreibungen) {
                $ok = $this->db->update(
                    $tblAuss,
                    [
                        'titel' => $sn['titel'] ?? '',
                        'einrichtung' => $sn['einrichtung'] ?? '',
                        'fachbereich_boerse' => $sn['fachbereich_boerse'] ?? '',
                        'fachbereich_intern' => $sn['fachbereich_intern'] ?? null,
                        'anstellungsart' => $sn['anstellungsart'] ?? '',
                        'vertragsart' => $sn['vertragsart'] ?? '',
                        'zeitmodell' => $sn['zeitmodell'] ?? '',
                        'stunden' => $sn['stunden'],
                        'stunden_quelle' => $sn['stunden_quelle'],
                        'startdatum' => $sn['startdatum'] ?? null,
                        'stopdatum' => $sn['stopdatum'] ?? null,
                        'plz_einsatzort' => $sn['plz_einsatzort'] ?? null,
                        'einsatzort' => $sn['einsatzort'] ?? null,
                        'zuletzt_gesehen_api' => $now,
                        'quelle' => 'api',
                        'importiert_am' => $now,
                    ],
                    ['stellennummer' => $sn['stellennummer']]
                );

                if ($ok === false) {
                    // optional: später zentrales Fehlerhandling ergänzen
                }
            } else {
                $ok = $this->db->insert(
                    $tblAuss,
                    [
                        'stellennummer' => $sn['stellennummer'],
                        'titel' => $sn['titel'] ?? '',
                        'einrichtung' => $sn['einrichtung'] ?? '',
                        'fachbereich_boerse' => $sn['fachbereich_boerse'] ?? '',
                        'fachbereich_intern' => $sn['fachbereich_intern'] ?? null,
                        'anstellungsart' => $sn['anstellungsart'] ?? '',
                        'vertragsart' => $sn['vertragsart'] ?? '',
                        'zeitmodell' => $sn['zeitmodell'] ?? '',
                        'stunden' => $sn['stunden'],
                        'stunden_quelle' => $sn['stunden_quelle'],
                        'startdatum' => $sn['startdatum'] ?? null,
                        'stopdatum' => $sn['stopdatum'] ?? null,
                        'plz_einsatzort' => $sn['plz_einsatzort'] ?? null,
                        'einsatzort' => $sn['einsatzort'] ?? null,
                        'erstellt_von' => null,
                        'quelle' => 'api',
                        'importiert_am' => $now,
                        'zuletzt_gesehen_api' => $now,
                    ]
                );

                if ($ok === false) {
                    // optional: später zentrales Fehlerhandling ergänzen
                }
            }

            $aktualisiert++;

        }

        $yesterdayOnline = $this->db->get_col($this->db->prepare(
            "SELECT stellennummer FROM {$tblSnap} WHERE snapshot_datum = %s AND status = %s",
            $yesterday,
            'online'
        ));

        foreach ($yesterdayOnline ?: [] as $stellennummer) {
            if (in_array($stellennummer, $currentStellennummern, true)) {
                continue;
            }
            $exists = $this->db->get_var($this->db->prepare(
                "SELECT 1 FROM {$tblSnap} WHERE stellennummer = %s AND snapshot_datum = %s",
                $stellennummer,
                $today
            ));
            if (!$exists) {
                $row = $this->db->get_row($this->db->prepare(
                    "SELECT stunden, zeitmodell FROM {$tblAuss} WHERE stellennummer = %s",
                    $stellennummer
                ), ARRAY_A);
                $this->db->insert($tblSnap, [
                    'stellennummer' => $stellennummer,
                    'snapshot_datum' => $today,
                    'stunden' => $row['stunden'] ?? null,
                    'zeitmodell' => $row['zeitmodell'] ?? null,
                    'status' => 'offline',
                ], ['%s', '%s', '%s', '%s', '%s']);
                $neu++;
            }
        }

        return ['neu' => $neu, 'aktualisiert' => $aktualisiert];
    }

    /**
     * API abrufen, alle Stellennummern + Stunden + Zeitmodell erfassen.
     *
     * Rückgabe:
     * - null = API-Fehler / JSON ungültig
     * - []   = API erfolgreich gelesen, aber keine Stellen enthalten
     *
     * @return list<array{stellennummer: string, stunden: float|null, zeitmodell: string}>|null
     */
    private function fetchCurrentPositions(): ?array
    {
        $json = $this->fetchApi();
        if ($json === null) {
            return null;
        }
        $decoded = json_decode($json, true);
        $data = $this->extractItemList($decoded);
        if ($data === null) {
            return null;
        }

        $result = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sn = $this->stringValue($item['Stellennummer'] ?? null);
                if ($sn === null || $sn === '' || strlen($sn) > 20) {
        continue;
    }
            $einleitungstext = is_string($item['Einleitungstext'] ?? null) ? $item['Einleitungstext'] : '';
            $infos = is_string($item['Infos'] ?? null) ? $item['Infos'] : '';

            $stunden = StundenParser::parse($einleitungstext);
            $stundenQuelle = null;

            if ($stunden !== null) {
                $stundenQuelle = 'api_einleitung';
            } else {
                $stunden = StundenParser::parse($infos);
                if ($stunden !== null) {
                    $stundenQuelle = 'api_infos';
                }
            }

            $zeitmodell = trim((string) ($item['Zeitmodell'] ?? '')) ?: null;

            $result[] = [
                'stellennummer' => $sn,
                'titel' => $this->stringValue($item['Stellenbezeichnung'] ?? null) ?? '',
                'einrichtung' => $this->stringValue($item['Einrichtung'] ?? null) ?? '',
                'fachbereich_boerse' => $this->stringValue($item['Fachbereich'] ?? null) ?? '',
                'fachbereich_intern' => $this->stringValue($item['Mandantnr/Einrichtungsnr'] ?? null),
                'anstellungsart' => $this->stringValue($item['Anstellungsart'] ?? null) ?? '',
                'vertragsart' => $this->stringValue($item['Vertragsart'] ?? null) ?? '',
                'zeitmodell' => $this->truncate($zeitmodell ?? '', 50),
                'startdatum' => $this->timestampToDate($item['Startdatum'] ?? null),
                'stopdatum' => $this->timestampToDate($item['Stopdatum'] ?? null),
                'plz_einsatzort' => $this->stringValue($item['PLZ_Einsatzort'] ?? null),
                'einsatzort' => $this->stringValue($item['Einsatzort'] ?? null),
                'stunden' => $stunden,
                'stunden_quelle' => $stundenQuelle,
            ];
        }
        return $result;
    }

    private function fetchApi(): ?string
    {
        if (function_exists('wp_remote_get')) {
            $response = wp_remote_get($this->apiUrl, ['timeout' => 30]);
            if (is_wp_error($response)) {
                return null;
            }
            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            return ($code >= 200 && $code < 300 && $body !== '') ? $body : null;
        }
        $context = stream_context_create(['http' => ['timeout' => 30]]);
        $body = @file_get_contents($this->apiUrl, false, $context);
        return $body !== false ? $body : null;
    }

    /**
     * @param mixed $decoded
     * @return array<int, array>|null
     */
    private function extractItemList($decoded): ?array
    {
        if (is_array($decoded) && isset($decoded[0]) && is_array($decoded[0])) {
            return $decoded;
        }
        if (is_array($decoded) && !isset($decoded[0])) {
            foreach ($decoded as $val) {
                if (is_array($val) && isset($val[0]) && is_array($val[0])) {
                    return $val;
                }
                if (is_array($val)) {
                    return [$val];
                }
            }
        }
        return null;
    }

    private function stringValue($v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private function timestampToDate($v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $ts = is_numeric($v) ? (int) $v : null;
        if ($ts === null || $ts < 0) {
            return null;
        }
        return date('Y-m-d', $ts);
    }
    
    private function truncate(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }

        return substr($value, 0, $maxLength);
    }
}