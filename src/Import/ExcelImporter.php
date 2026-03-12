<?php
/**
 * Excel/CSV-Import in bs_awojobs_ausschreibungen.
 * Spalten-Mapping und Datumsnormalisierung laut ARCHITECTURE.md (Quelle 2: Excel-Export).
 * Keine WordPress-Abhängigkeiten; Datenbankzugriff über injiziertes Objekt (z. B. $wpdb).
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\Import;

use BS_Awo_Jobs_Statistik\Core\Database;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class ExcelImporter implements ImportInterface
{
    /** Excel-Spaltenname → DB-Feld (aus ARCHITECTURE.md) */
    private const COLUMN_MAP = [
        'S-Nr' => 'stellennummer',
        'Titel' => 'titel',
        'Einrichtung' => 'einrichtung',
        'Fachbereich' => 'fachbereich_boerse',
        'Internes Kürzel' => 'fachbereich_intern',
        'Anstellungsart' => 'anstellungsart',
        'Vertragsart' => 'vertragsart',
        'BA Zeiteinteilung' => 'zeitmodell',
        'Start' => 'startdatum',
        'Stop' => 'stopdatum',
        'PLZ Einsatzort' => 'plz_einsatzort',
        'Einsatzort' => 'einsatzort',
        'Erstellt von' => 'erstellt_von',
    ];

    private const NULL_VALUES = ['', 'n/a', 'N/A', 'na', 'NA', '-'];

    /** @var object mit property prefix und Methode prepare() (z. B. $wpdb) */
    private $db;

    public function __construct(object $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{success: int, errors: list<string>}
     */
    public function import(string $filePath): array
    {
        $errors = [];
        $success = 0;

        if (!is_readable($filePath)) {
            $errors[] = "Datei nicht lesbar: {$filePath}";
            return ['success' => 0, 'errors' => $errors];
        }

        try {
            $spreadsheet = IOFactory::load($filePath);
        } catch (\Throwable $e) {
            $errors[] = "Datei konnte nicht geladen werden: " . $e->getMessage();
            return ['success' => 0, 'errors' => $errors];
        }

        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        $colCount = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        if ($highestRow < 2) {
            $errors[] = 'Keine Datenzeilen (nur Header oder leer).';
            return ['success' => 0, 'errors' => $errors];
        }

        $headerRow = 1;
        $colIndexToField = [];
        for ($c = 1; $c <= $colCount; $c++) {
            $cellVal = $sheet->getCellByColumnAndRow($c, $headerRow)->getValue();
            $header = is_scalar($cellVal) ? trim((string) $cellVal) : '';
            if ($header !== '' && isset(self::COLUMN_MAP[$header])) {
                $colIndexToField[$c] = self::COLUMN_MAP[$header];
            }
        }

        $table = $this->db->prefix . Database::TABLE_AUSSCHREIBUNGEN;
        $importiertAm = date('Y-m-d H:i:s');

        for ($row = 2; $row <= $highestRow; $row++) {
            $rowData = [];
            foreach ($colIndexToField as $col => $field) {
                $cell = $sheet->getCellByColumnAndRow($col, $row);
                $val = $cell->getValue();
                if ($val !== null && $val !== '') {
                    $val = trim((string) $val);
                }
                $rowData[$field] = $val === '' ? null : $val;
            }

            $stellennummer = $rowData['stellennummer'] ?? null;
            if ($stellennummer !== null) {
                $stellennummer = trim((string) $stellennummer);
            }
            if ($stellennummer === null || $stellennummer === '' || strlen($stellennummer) > 20) {
                $errors[] = "Zeile {$row}: fehlende oder ungültige Stellennummer (max. 20 Zeichen).";
                continue;
            }

            $rowData['stellennummer'] = $stellennummer;
            $rowData['quelle'] = 'excel';
            $rowData['importiert_am'] = $importiertAm;

            $rowData['startdatum'] = self::normalizeDate($rowData['startdatum'] ?? null);
            $rowData['stopdatum'] = self::normalizeDate($rowData['stopdatum'] ?? null);

            foreach (['titel', 'einrichtung', 'fachbereich_boerse', 'anstellungsart', 'vertragsart', 'zeitmodell'] as $notNullField) {
                $v = $rowData[$notNullField] ?? null;
                if ($v === null || in_array(trim((string) $v), self::NULL_VALUES, true)) {
                    $rowData[$notNullField] = '';
                } else {
                    $rowData[$notNullField] = trim((string) $v);
                }
            }
            foreach (['fachbereich_intern', 'plz_einsatzort', 'einsatzort', 'erstellt_von'] as $nullableField) {
                $v = $rowData[$nullableField] ?? null;
                if ($v === null || (is_string($v) && in_array(trim($v), self::NULL_VALUES, true))) {
                    $rowData[$nullableField] = null;
                } else {
                    $rowData[$nullableField] = trim((string) $v);
                }
            }

            $inserted = $this->upsertRow($table, $rowData);
            if ($inserted) {
                $success++;
            }
        }

        return ['success' => $success, 'errors' => $errors];
    }

    /**
     * DD.MM.YYYY HH:MM → YYYY-MM-DD, Uhrzeit verwerfen.
     */
    private static function normalizeDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $s = trim((string) $value);
        if (in_array($s, self::NULL_VALUES, true)) {
            return null;
        }
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})/', $s, $m)) {
            $d = (int) $m[1];
            $mo = (int) $m[2];
            $y = (int) $m[3];
            if ($d >= 1 && $d <= 31 && $mo >= 1 && $mo <= 12 && $y >= 1900 && $y <= 2100) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }
        return null;
    }

    /**
     * INSERT ... ON DUPLICATE KEY UPDATE (Schlüssel: stellennummer).
     * NULL-Werte werden als SQL-NULL gesetzt (nicht per prepare).
     */
    private function upsertRow(string $table, array $row): bool
    {
        $columns = [
            'stellennummer', 'titel', 'einrichtung', 'fachbereich_boerse', 'fachbereich_intern',
            'anstellungsart', 'vertragsart', 'zeitmodell', 'startdatum', 'stopdatum',
            'plz_einsatzort', 'einsatzort', 'erstellt_von', 'quelle', 'importiert_am',
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
            "`titel` = COALESCE(NULLIF(`titel`, ''), VALUES(`titel`))",
            "`einrichtung` = COALESCE(NULLIF(`einrichtung`, ''), VALUES(`einrichtung`))",
            "`fachbereich_boerse` = COALESCE(NULLIF(`fachbereich_boerse`, ''), VALUES(`fachbereich_boerse`))",
            "`fachbereich_intern` = COALESCE(`fachbereich_intern`, VALUES(`fachbereich_intern`))",
            "`anstellungsart` = COALESCE(NULLIF(`anstellungsart`, ''), VALUES(`anstellungsart`))",
            "`vertragsart` = COALESCE(NULLIF(`vertragsart`, ''), VALUES(`vertragsart`))",
            "`zeitmodell` = COALESCE(NULLIF(`zeitmodell`, ''), VALUES(`zeitmodell`))",
            "`startdatum` = COALESCE(`startdatum`, VALUES(`startdatum`))",
            "`stopdatum` = COALESCE(`stopdatum`, VALUES(`stopdatum`))",
            "`plz_einsatzort` = COALESCE(NULLIF(`plz_einsatzort`, ''), VALUES(`plz_einsatzort`))",
            "`einsatzort` = COALESCE(NULLIF(`einsatzort`, ''), VALUES(`einsatzort`))",
            "`erstellt_von` = COALESCE(NULLIF(`erstellt_von`, ''), VALUES(`erstellt_von`))",
            "`quelle` = IF(`quelle` = 'api' OR `quelle` = 'beide', 'beide', VALUES(`quelle`))",
            "`importiert_am` = VALUES(`importiert_am`)",
        ];
        $updateStr = implode(', ', $updates);
        $sql = "INSERT INTO `{$table}` ({$colsStr}) VALUES ({$placeStr}) ON DUPLICATE KEY UPDATE {$updateStr}";
        if ($values !== []) {
            $sql = $this->db->prepare($sql, $values);
        }
        return $sql !== false && $this->db->query($sql) !== false;
    }
}