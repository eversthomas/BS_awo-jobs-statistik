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
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $now = date('Y-m-d H:i:s');

        $tblSnap = $this->db->prefix . Database::TABLE_SNAPSHOTS;
        $tblAuss = $this->db->prefix . Database::TABLE_AUSSCHREIBUNGEN;

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

            $this->db->update(
                $tblAuss,
                ['zuletzt_gesehen_api' => $now],
                ['stellennummer' => $sn['stellennummer']],
                ['%s'],
                ['%s']
            );
            $aktualisiert++;
        }

        $yesterdayOnline = $this->db->get_col($this->db->prepare(
            "SELECT stellennummer FROM {$tblSnap} WHERE snapshot_datum = %s AND status = %s",
            $yesterday,
            'online'
        ));
        $currentStellennummern = array_column($current, 'stellennummer');

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
     * @return list<array{stellennummer: string, stunden: float|null, zeitmodell: string}>
     */
    private function fetchCurrentPositions(): array
    {
        $json = $this->fetchApi();
        if ($json === null) {
            return [];
        }
        $decoded = json_decode($json, true);
        $data = $this->extractItemList($decoded);
        if ($data === null) {
            return [];
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
            $infos = $item['Infos'] ?? '';
            $stunden = is_string($infos) ? StundenParser::parse($infos) : null;
            $zeitmodell = trim((string) ($item['Zeitmodell'] ?? '')) ?: null;

            $result[] = [
                'stellennummer' => $sn,
                'stunden' => $stunden,
                'zeitmodell' => $zeitmodell,
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
}
