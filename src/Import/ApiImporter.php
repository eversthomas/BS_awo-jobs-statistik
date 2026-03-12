<?php
/**
 * API-Import (JSON) in bs_awojobs_ausschreibungen.
 * Felder und Unix-Timestamps laut ARCHITECTURE.md (Quelle 1: JSON API).
 * Stundenzahl aus Infos via StundenParser. Einzige WP-Abhängigkeit: wp_remote_get(), sonst file_get_contents.
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\Import;

use BS_Awo_Jobs_Statistik\Core\Database;
use BS_Awo_Jobs_Statistik\Parser\StundenParser;

final class ApiImporter implements ImportInterface
{
    /** API-Feldname → DB-Feld (ARCHITECTURE: Quelle 1, Mandantnr/Einrichtungsnr) */
    private const FIELD_MAP = [
        'Stellennummer' => 'stellennummer',
        'Stellenbezeichnung' => 'titel',
        'Einrichtung' => 'einrichtung',
        'Fachbereich' => 'fachbereich_boerse',
        'Mandantnr/Einrichtungsnr' => 'fachbereich_intern',
        'Anstellungsart' => 'anstellungsart',
        'Vertragsart' => 'vertragsart',
        'Zeitmodell' => 'zeitmodell',
        'Startdatum' => 'startdatum',
        'Stopdatum' => 'stopdatum',
        'PLZ_Einsatzort' => 'plz_einsatzort',
        'Einsatzort' => 'einsatzort',
    ];

    /** @var object mit prefix, prepare(), query(), get_row() */
    private $db;

    private string $apiUrl;

    public function __construct(object $db, string $apiUrl)
    {
        $this->db = $db;
        $this->apiUrl = $apiUrl;
    }

    /**
     * Importiert von der im Konstruktor gesetzten API-URL (filePath wird ignoriert).
     *
     * @return array{success: int, errors: list<string>}
     */
    public function import(string $filePath): array
    {
        $errors = [];
        $success = 0;

        $json = $this->fetchApi();
        if ($json === null) {
            $errors[] = 'API-Abruf fehlgeschlagen oder leere Antwort.';
            return ['success' => 0, 'errors' => $errors];
        }

        $decoded = json_decode($json, true);
        $data = self::extractItemList($decoded);
        if ($data === null) {
            $errors[] = 'Ungültiges JSON oder keine Liste von Datensätzen.';
            return ['success' => 0, 'errors' => $errors];
        }

        $table = $this->db->prefix . Database::TABLE_AUSSCHREIBUNGEN;
        $now = date('Y-m-d H:i:s');

        foreach ($data as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = $this->mapItem($item);
            if ($row === null) {
                $errors[] = "Datensatz #" . ($index + 1) . ": fehlende oder ungültige Stellennummer.";
                continue;
            }
            $row['quelle'] = 'api';
            $row['importiert_am'] = $now;
            $row['zuletzt_gesehen_api'] = $now;

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

            $row['stunden'] = $stunden;
            $row['stunden_quelle'] = $stundenQuelle;

            if ($this->upsertRow($table, $row)) {
                $success++;
            }
        }

        return ['success' => $success, 'errors' => $errors];
    }

    /**
     * Wenn API ein Objekt mit einer Array-Property liefert (z. B. { "stellen": [...] }), diese extrahieren.
     *
     * @return array<int, array>|null
     */
    private static function extractItemList($decoded): ?array
    {
        if (is_array($decoded) && isset($decoded[0]) && is_array($decoded[0])) {
            return $decoded;
        }
        if (is_array($decoded) && !isset($decoded[0])) {
            foreach ($decoded as $key => $val) {
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
     * @return array<string, mixed>|null null wenn Stellennummer fehlt/ungültig
     */
    private function mapItem(array $item): ?array
    {
        $stellennummer = $this->stringValue($item['Stellennummer'] ?? null);
        if ($stellennummer === null || $stellennummer === '' || strlen($stellennummer) > 20) {
            return null;
        }

        $row = [
            'stellennummer' => $stellennummer,
            'titel' => $this->stringValue($item['Stellenbezeichnung'] ?? null) ?? '',
            'einrichtung' => $this->stringValue($item['Einrichtung'] ?? null) ?? '',
            'fachbereich_boerse' => $this->stringValue($item['Fachbereich'] ?? null) ?? '',
            'fachbereich_intern' => $this->stringValue($item['Mandantnr/Einrichtungsnr'] ?? null),
            'anstellungsart' => $this->stringValue($item['Anstellungsart'] ?? null) ?? '',
            'vertragsart' => $this->stringValue($item['Vertragsart'] ?? null) ?? '',
            'zeitmodell' => $this->truncate($this->stringValue($item['Zeitmodell'] ?? null) ?? '', 50),
            'startdatum' => $this->timestampToDate($item['Startdatum'] ?? null),
            'stopdatum' => $this->timestampToDate($item['Stopdatum'] ?? null),
            'plz_einsatzort' => $this->stringValue($item['PLZ_Einsatzort'] ?? null),
            'einsatzort' => $this->stringValue($item['Einsatzort'] ?? null),
            'erstellt_von' => null,
        ];
        return $row;
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

    /**
     * INSERT ... ON DUPLICATE KEY UPDATE.
     * Bei bestehender Zeile mit quelle='excel': nur leere Felder ergänzen, quelle='beide', zuletzt_gesehen_api=NOW().
     */
    private function upsertRow(string $table, array $row): bool
    {
        $columns = [
            'stellennummer', 'titel', 'einrichtung', 'fachbereich_boerse', 'fachbereich_intern',
            'anstellungsart', 'vertragsart', 'zeitmodell', 'stunden', 'stunden_quelle',
            'startdatum', 'stopdatum', 'plz_einsatzort', 'einsatzort', 'erstellt_von',
            'quelle', 'importiert_am', 'zuletzt_gesehen_api',
        ];
        $placeholders = [];
        $values = [];
        foreach ($columns as $col) {
            $v = $row[$col] ?? null;
            if ($v === null) {
                $placeholders[] = 'NULL';
            } else {
                $placeholders[] = '%s';
                $values[] = $v;
            }
        }
        $colsStr = implode(', ', array_map(static function (string $c) {
            return '`' . $c . '`';
        }, $columns));
        $placeStr = implode(', ', $placeholders);

        $updates = [
            'titel' => "`titel` = COALESCE(NULLIF(`titel`, ''), VALUES(`titel`))",
            'einrichtung' => "`einrichtung` = COALESCE(NULLIF(`einrichtung`, ''), VALUES(`einrichtung`))",
            'fachbereich_boerse' => "`fachbereich_boerse` = COALESCE(NULLIF(`fachbereich_boerse`, ''), VALUES(`fachbereich_boerse`))",
            'fachbereich_intern' => "`fachbereich_intern` = COALESCE(NULLIF(`fachbereich_intern`, ''), VALUES(`fachbereich_intern`))",
            'anstellungsart' => "`anstellungsart` = COALESCE(NULLIF(`anstellungsart`, ''), VALUES(`anstellungsart`))",
            'vertragsart' => "`vertragsart` = COALESCE(NULLIF(`vertragsart`, ''), VALUES(`vertragsart`))",
            'zeitmodell' => "`zeitmodell` = COALESCE(NULLIF(`zeitmodell`, ''), VALUES(`zeitmodell`))",
            'stunden' => "`stunden` = VALUES(`stunden`)",
            'stunden_quelle' => "`stunden_quelle` = VALUES(`stunden_quelle`)",
            'startdatum' => "`startdatum` = COALESCE(`startdatum`, VALUES(`startdatum`))",
            'stopdatum' => "`stopdatum` = COALESCE(`stopdatum`, VALUES(`stopdatum`))",
            'plz_einsatzort' => "`plz_einsatzort` = COALESCE(NULLIF(`plz_einsatzort`, ''), VALUES(`plz_einsatzort`))",
            'einsatzort' => "`einsatzort` = COALESCE(NULLIF(`einsatzort`, ''), VALUES(`einsatzort`))",
            'erstellt_von' => "`erstellt_von` = COALESCE(NULLIF(`erstellt_von`, ''), VALUES(`erstellt_von`))",
            'quelle' => "`quelle` = IF(`quelle` = 'excel', 'beide', VALUES(`quelle`))",
            'importiert_am' => "`importiert_am` = VALUES(`importiert_am`)",
            'zuletzt_gesehen_api' => "`zuletzt_gesehen_api` = NOW()",
        ];
        $updateStr = implode(', ', $updates);

        $sql = "INSERT INTO `{$table}` ({$colsStr}) VALUES ({$placeStr}) ON DUPLICATE KEY UPDATE {$updateStr}";
        if ($values !== []) {
            $sql = $this->db->prepare($sql, $values);
        }
        return $sql !== false && $this->db->query($sql) !== false;
    }
}