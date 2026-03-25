<?php
/**
 * Excel-Export der statistischen Dashboard-Daten.
 * Nutzt dieselben Analyse-Module wie die Admin-UI (single source of truth).
 * Keine WordPress-Abhängigkeiten außer optionalem $wpdb.
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\Export;

use BS_Awo_Jobs_Statistik\AktiveStellen\AktiveStellenExportOptions;
use BS_Awo_Jobs_Statistik\AktiveStellen\AktiveStellenFilterInput;
use BS_Awo_Jobs_Statistik\AktiveStellen\AktiveStellenQuery;
use BS_Awo_Jobs_Statistik\Analysis\FluktuationAnalyzer;
use BS_Awo_Jobs_Statistik\Analysis\VakanzAnalyzer;
use BS_Awo_Jobs_Statistik\Analysis\VzaCalculator;
use BS_Awo_Jobs_Statistik\Core\Database;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ExcelExporter
{
    /** Farben: zurückhaltend, professionell */
    private const COLOR_HEADER_BG = '5B7FBD';
    private const COLOR_HEADER_TEXT = 'FFFFFF';
    private const COLOR_ZEBRA = 'F5F7FA';
    private const COLOR_BORDER = 'D1D5DB';

    /** Spaltenbreite in Zeichen (Excel „Standardbreite“); mit Umbruch statt AutoSize */
    private const COL_WIDTH_MIN = 8.0;
    private const COL_WIDTH_DEFAULT = 20.0;
    private const COL_WIDTH_MAX = 42.0;

    private object $db;

    private int $vollzeitStunden;

    public function __construct(object $db, int $vollzeitStunden = 39)
    {
        $this->db = $db;
        $this->vollzeitStunden = $vollzeitStunden;
    }

    /**
     * Tabellenkopf: fett, Hintergrund, weiße Schrift.
     */
    private function styleTableHeader(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => self::COLOR_HEADER_TEXT]],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::COLOR_HEADER_BG],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_TOP,
                'wrapText' => true,
            ],
        ]);
    }

    /**
     * Rahmen und Zebra-Streifen für Datentabelle.
     *
     * @param int $lastRow letzte Zeile der Tabelle (inkl. Header)
     */
    private function styleDataTable(Worksheet $sheet, string $range, int $dataStartRow, string $lastCol, int $lastRow): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::COLOR_BORDER]],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_TOP,
                'wrapText' => true,
            ],
        ]);
        for ($row = $dataStartRow; $row <= $lastRow; $row++) {
            if (($row - $dataStartRow) % 2 === 1) {
                $sheet->getStyle('A' . $row . ':' . $lastCol . $row)->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_ZEBRA);
            }
        }
    }

    /**
     * Spaltenbreiten mit Min/Max begrenzen (kein AutoSize — lange Texte umbrechen).
     *
     * @param array<string, float> $widthByLetter Spaltenbuchstabe => Breite; fehlend DEFAULT
     */
    private function setBoundedColumnWidths(Worksheet $sheet, string $lastCol, array $widthByLetter = []): void
    {
        $n = Coordinate::columnIndexFromString($lastCol);
        for ($i = 1; $i <= $n; $i++) {
            $letter = Coordinate::stringFromColumnIndex($i);
            $w = $widthByLetter[$letter] ?? self::COL_WIDTH_DEFAULT;
            $w = max(self::COL_WIDTH_MIN, min(self::COL_WIDTH_MAX, $w));
            $dim = $sheet->getColumnDimension($letter);
            $dim->setAutoSize(false);
            $dim->setWidth($w);
        }
    }

    /**
     * Zeilenumbruch für Titel-/Infoblöcke (z. B. zusammengeführte Zellen).
     */
    private function applyWrapTopLeft(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getAlignment()
            ->setWrapText(true)
            ->setVertical(Alignment::VERTICAL_TOP)
            ->setHorizontal(Alignment::HORIZONTAL_LEFT);
    }

    /**
     * Erstellt Excel für den angegebenen Tab und sendet Download.
     *
     * @param string $tab uebersicht|fluktuation|vakanzen|fachbereiche|plz|alle|aktive_stellen
     */
    public function exportAndSend(string $tab, ?AktiveStellenFilterInput $aktiveFilter = null, ?AktiveStellenExportOptions $aktiveExportOptions = null): void
    {
        $spreadsheet = $this->buildSpreadsheet($tab, $aktiveFilter, $aktiveExportOptions);
        if ($spreadsheet === null) {
            return;
        }

        if ($tab === 'aktive_stellen') {
            $filename = 'bs-awo-aktive-stellen-' . date('Y-m-d') . '.xlsx';
        } else {
            $filename = 'bs-awo-jobs-statistik-' . ($tab === 'alle' ? 'gesamt' : $tab) . '-' . date('Y-m-d') . '.xlsx';
        }
        $writer = new Xlsx($spreadsheet);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer->save('php://output');
        exit;
    }

    private function buildSpreadsheet(
        string $tab,
        ?AktiveStellenFilterInput $aktiveFilter = null,
        ?AktiveStellenExportOptions $aktiveExportOptions = null
    ): ?Spreadsheet {
        $spreadsheet = new Spreadsheet();
        $validTabs = ['uebersicht', 'fluktuation', 'vakanzen', 'fachbereiche', 'plz', 'alle', 'aktive_stellen'];
        if (!in_array($tab, $validTabs, true)) {
            return null;
        }

        $vza = new VzaCalculator($this->db, $this->vollzeitStunden);
        $fluk = new FluktuationAnalyzer($this->db);
        $vakanz = new VakanzAnalyzer($this->db);

        if ($tab === 'aktive_stellen') {
            $filter = $aktiveFilter ?? new AktiveStellenFilterInput();
            $opts = $aktiveExportOptions ?? new AktiveStellenExportOptions();
            $this->fillAktiveStellen($spreadsheet, $vza, $filter, $opts);

            return $spreadsheet;
        }

        if ($tab === 'alle') {
            $this->fillUebersicht($spreadsheet, $vza, $vakanz);
            $spreadsheet->createSheet();
            $spreadsheet->setActiveSheetIndex(1);
            $this->fillFluktuation($spreadsheet, $fluk);
            $spreadsheet->createSheet();
            $spreadsheet->setActiveSheetIndex(2);
            $this->fillVakanzen($spreadsheet, $vakanz);
            $spreadsheet->createSheet();
            $spreadsheet->setActiveSheetIndex(3);
            $this->fillFachbereiche($spreadsheet, $vza);
            $spreadsheet->createSheet();
            $spreadsheet->setActiveSheetIndex(4);
            $this->fillPlz($spreadsheet, $vakanz);
            $spreadsheet->setActiveSheetIndex(0);
            return $spreadsheet;
        }

        switch ($tab) {
            case 'uebersicht':
                $this->fillUebersicht($spreadsheet, $vza, $vakanz);
                break;
            case 'fluktuation':
                $this->fillFluktuation($spreadsheet, $fluk);
                break;
            case 'vakanzen':
                $this->fillVakanzen($spreadsheet, $vakanz);
                break;
            case 'fachbereiche':
                $this->fillFachbereiche($spreadsheet, $vza);
                break;
            case 'plz':
                $this->fillPlz($spreadsheet, $vakanz);
                break;
        }

        return $spreadsheet;
    }

    private function fillAktiveStellen(
        Spreadsheet $spreadsheet,
        VzaCalculator $vza,
        AktiveStellenFilterInput $filter,
        AktiveStellenExportOptions $options
    ): void {
        $allRows = AktiveStellenQuery::fetchAktiveZeilen($this->db);
        $rows = AktiveStellenQuery::resolveForExport($allRows, $filter, $options);

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Aktive Stellen');

        $row = 1;
        $sheet->setCellValue('A' . $row, 'BS AWO Jobs Statistik – Aktive Stellen');
        $sheet->mergeCells('A' . $row . ':M' . $row);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $this->applyWrapTopLeft($sheet, 'A1:M1');
        $row++;
        $scopeLabel = $options->useUiFilters()
            ? 'Aktuelle Filter (wie Tab „Aktive Stellen“)'
            : 'Alle aktiven Stellen (ohne Such-/Dropdown-Filter)';
        $statLabel = $options->nurBeruecksichtigt()
            ? 'Nur berücksichtigte Stellen (in_statistik_beruecksichtigen = 1)'
            : 'Alle Zeilen inkl. ausgeschlossener Stellen';
        $sheet->setCellValue('A' . $row, 'Export: ' . $scopeLabel . ' · ' . $statLabel);
        $sheet->mergeCells('A' . $row . ':M' . $row);
        $this->applyWrapTopLeft($sheet, 'A' . $row . ':M' . $row);
        $row += 2;

        $headers = [
            'Berücksichtigen',
            'Stellennr.',
            'Titel',
            'Einrichtung',
            'Fachbereich',
            'Mandantenfeld',
            'PLZ',
            'Ort',
            'Zeitmodell',
            'Stunden',
            'VZÄ',
            'Startdatum',
            'Quelle Stunden',
        ];
        $colIndex = 1;
        foreach ($headers as $h) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIndex) . $row, $h);
            ++$colIndex;
        }
        $this->styleTableHeader($sheet, 'A' . $row . ':M' . $row);
        $sheet->freezePane('A' . ($row + 1));
        $headerRow = $row;
        $row++;
        $dataStartRow = $row;

        foreach ($rows as $r) {
            $stunden = isset($r['stunden']) && $r['stunden'] !== null ? (float) $r['stunden'] : null;
            $vzaWert = $vza->vzaFuerListenzeile($stunden);
            $ber = (int) ($r['in_statistik_beruecksichtigen'] ?? 1) === 1 ? 'Ja' : 'Nein';

            $sheet->setCellValue('A' . $row, $ber);
            $sheet->setCellValue('B' . $row, (string) ($r['stellennummer'] ?? ''));
            $sheet->setCellValue('C' . $row, (string) ($r['titel'] ?? ''));
            $sheet->setCellValue('D' . $row, (string) ($r['einrichtung'] ?? ''));
            $sheet->setCellValue('E' . $row, (string) ($r['fachbereich_boerse'] ?? ''));
            $sheet->setCellValue('F' . $row, (string) ($r['fachbereich_intern'] ?? ''));
            $sheet->setCellValue('G' . $row, (string) ($r['plz_einsatzort'] ?? ''));
            $sheet->setCellValue('H' . $row, (string) ($r['einsatzort'] ?? ''));
            $sheet->setCellValue('I' . $row, (string) ($r['zeitmodell'] ?? ''));
            if ($stunden !== null) {
                $sheet->setCellValue('J' . $row, $stunden);
                $sheet->getStyle('J' . $row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
            } else {
                $sheet->setCellValue('J' . $row, '–');
            }
            $sheet->setCellValue('K' . $row, $vzaWert);
            $sheet->getStyle('K' . $row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
            $sheet->setCellValue('L' . $row, (string) ($r['startdatum'] ?? ''));
            $sheet->setCellValue('M' . $row, (string) ($r['stunden_quelle'] ?? ''));
            $row++;
        }

        $lastDataRow = $row - 1;
        if ($lastDataRow >= $dataStartRow) {
            $this->styleDataTable($sheet, 'A' . $headerRow . ':M' . $lastDataRow, $dataStartRow, 'M', $lastDataRow);
        }
        $this->setBoundedColumnWidths($sheet, 'M', [
            'A' => 12,
            'B' => 14,
            'C' => 30,
            'D' => 24,
            'E' => 22,
            'F' => 20,
            'G' => 10,
            'H' => 18,
            'I' => 14,
            'J' => 11,
            'K' => 10,
            'L' => 12,
            'M' => 18,
        ]);
    }

    private function fillUebersicht(Spreadsheet $spreadsheet, VzaCalculator $vza, VakanzAnalyzer $vakanz): void
    {
        $gesamt = $vza->berechneGesamt();
        $offen = $vakanz->offenSeit();
        $uebersichtCounts = $vakanz->getUebersichtCounts();

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Übersicht');

        $row = 1;
        $sheet->setCellValue('A' . $row, 'BS AWO Jobs Statistik – Übersicht');
        $sheet->mergeCells('A' . $row . ':B' . $row);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $this->applyWrapTopLeft($sheet, 'A1:B1');
        $row += 2;

        $sheet->setCellValue('A' . $row, 'Offene Stellen');
        $sheet->setCellValue('B' . $row, count($offen));
        $row++;
        $sheet->setCellValue('A' . $row, 'Gesamt-VZÄ');
        $sheet->setCellValue('B' . $row, round($gesamt, 2));
        $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
        $row++;

        $sheet->setCellValue('A' . $row, 'Nach Stellentitel');
        $sheet->setCellValue('B' . $row, 'Anzahl');
        $this->styleTableHeader($sheet, 'A' . $row . ':B' . $row);
        $row++;
        $titelStart = $row;
        foreach ($uebersichtCounts['nach_titel'] as $titel => $cnt) {
            $sheet->setCellValue('A' . $row, $titel);
            $sheet->setCellValue('B' . $row, $cnt);
            $row++;
        }
        $this->styleDataTable($sheet, 'A' . ($titelStart - 1) . ':B' . ($row - 1), $titelStart, 'B', $row - 1);
        $row += 2;

        $sheet->setCellValue('A' . $row, 'Nach Fachbereich');
        $sheet->setCellValue('B' . $row, 'Anzahl');
        $this->styleTableHeader($sheet, 'A' . $row . ':B' . $row);
        $row++;
        $fbStart = $row;
        foreach ($uebersichtCounts['nach_fachbereich'] as $fb => $cnt) {
            $sheet->setCellValue('A' . $row, $fb);
            $sheet->setCellValue('B' . $row, $cnt);
            $row++;
        }
        $this->styleDataTable($sheet, 'A' . ($fbStart - 1) . ':B' . ($row - 1), $fbStart, 'B', $row - 1);
        $row += 2;

        $sheet->setCellValue('A' . $row, 'Nach Postleitzahl');
        $sheet->setCellValue('B' . $row, 'Anzahl');
        $this->styleTableHeader($sheet, 'A' . $row . ':B' . $row);
        $row++;
        $plzStart = $row;
        foreach ($uebersichtCounts['nach_plz'] as $plz => $cnt) {
            $sheet->setCellValue('A' . $row, $plz);
            $sheet->setCellValue('B' . $row, $cnt);
            $row++;
        }
        $this->styleDataTable($sheet, 'A' . ($plzStart - 1) . ':B' . ($row - 1), $plzStart, 'B', $row - 1);
        $this->setBoundedColumnWidths($sheet, 'B', [
            'A' => 34,
            'B' => 12,
        ]);
    }

    private function fillFluktuation(Spreadsheet $spreadsheet, FluktuationAnalyzer $fluk): void
    {
        $top10 = $fluk->haeufigsteStellen(100);
        $ids = array_column($top10, 'logische_stelle_id');
        $stellennummernByLog = $fluk->getStellennummernOnlineZuerst($ids);
        $plzByLog = $fluk->getPlzFuerLogischeStellen($ids);

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Fluktuation');

        $headers = ['#', 'Titel', 'Einrichtung', 'PLZ', 'Ausschreibungen', 'Stellennummern'];
        $colIndex = 1;
        foreach ($headers as $h) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIndex) . '1', $h);
            ++$colIndex;
        }
        $this->styleTableHeader($sheet, 'A1:F1');
        $sheet->freezePane('A2');
        $row = 2;
        foreach ($top10 as $i => $r) {
            $sheet->setCellValue('A' . $row, $i + 1);
            $sheet->setCellValue('B' . $row, $r['titel']);
            $sheet->setCellValue('C' . $row, $r['einrichtung']);
            $sheet->setCellValue('D' . $row, $plzByLog[$r['logische_stelle_id']] ?? '');
            $sheet->setCellValue('E' . $row, $r['anzahl_ausschreibungen']);
            $sns = $stellennummernByLog[$r['logische_stelle_id']] ?? [];
            $sheet->setCellValue('F' . $row, implode(', ', $sns));
            $row++;
        }
        $lastRow = max(2, $row - 1);
        if ($lastRow >= 2) {
            $this->styleDataTable($sheet, 'A1:F' . $lastRow, 2, 'F', $lastRow);
        }
        $this->setBoundedColumnWidths($sheet, 'F', [
            'A' => 8,
            'B' => 28,
            'C' => 22,
            'D' => 10,
            'E' => 14,
            'F' => self::COL_WIDTH_MAX,
        ]);
    }

    private function fillVakanzen(Spreadsheet $spreadsheet, VakanzAnalyzer $vakanz): void
    {
        $offen = $vakanz->offenSeit();

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Offene Vakanzen');

        $headers = ['Stellennr.', 'Tage', 'Titel', 'Einrichtung', 'Ort'];
        $colIndex = 1;
        foreach ($headers as $h) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIndex) . '1', $h);
            ++$colIndex;
        }
        $this->styleTableHeader($sheet, 'A1:E1');
        $sheet->freezePane('A2');
        $row = 2;
        foreach ($offen as $r) {
            $sheet->setCellValue('A' . $row, $r['stellennummer']);
            $sheet->setCellValue('B' . $row, $r['tage_offen']);
            $sheet->setCellValue('C' . $row, $r['titel']);
            $sheet->setCellValue('D' . $row, $r['einrichtung']);
            $sheet->setCellValue('E' . $row, trim(($r['plz_einsatzort'] ?? '') . ' ' . ($r['einsatzort'] ?? '')));
            $row++;
        }
        $lastRow = max(2, $row - 1);
        if ($lastRow >= 2) {
            $this->styleDataTable($sheet, 'A1:E' . $lastRow, 2, 'E', $lastRow);
        }
        $this->setBoundedColumnWidths($sheet, 'E', [
            'A' => 14,
            'B' => 10,
            'C' => 26,
            'D' => 22,
            'E' => 24,
        ]);
    }

    private function fillFachbereiche(Spreadsheet $spreadsheet, VzaCalculator $vza): void
    {
        $aktuell = $vza->berechneAktuell();
        $vzaProEinrichtung = $vza->berechneVzaProEinrichtung();

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Fachbereiche');

        $row = 1;
        $sheet->setCellValue('A' . $row, 'VZÄ nach Fachbereich (Stellenbörse)');
        $sheet->setCellValue('B' . $row, 'VZÄ');
        $this->styleTableHeader($sheet, 'A' . $row . ':B' . $row);
        $row++;
        $boerseStart = $row;
        $nachBoerse = $aktuell['nach_boerse'] ?? [];
        arsort($nachBoerse);
        foreach ($nachBoerse as $fb => $val) {
            $sheet->setCellValue('A' . $row, $fb);
            $sheet->setCellValue('B' . $row, round($val, 2));
            $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
            $row++;
        }
        if ($row > $boerseStart) {
            $this->styleDataTable($sheet, 'A' . ($boerseStart - 1) . ':B' . ($row - 1), $boerseStart, 'B', $row - 1);
        }
        $row += 2;

        $sheet->setCellValue('A' . $row, 'VZÄ nach Mandantenfeld (internes Kürzel)');
        $sheet->setCellValue('B' . $row, 'VZÄ');
        $this->styleTableHeader($sheet, 'A' . $row . ':B' . $row);
        $row++;
        $internStart = $row;
        $nachIntern = $aktuell['nach_intern'] ?? [];
        arsort($nachIntern);
        foreach ($nachIntern as $fb => $val) {
            $sheet->setCellValue('A' . $row, $fb);
            $sheet->setCellValue('B' . $row, round($val, 2));
            $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
            $row++;
        }
        if ($row > $internStart) {
            $this->styleDataTable($sheet, 'A' . ($internStart - 1) . ':B' . ($row - 1), $internStart, 'B', $row - 1);
        }
        $row += 2;

        $sheet->setCellValue('A' . $row, 'VZÄ pro Einrichtung (Fachbereich / Einrichtung / VZÄ)');
        $sheet->mergeCells('A' . $row . ':D' . $row);
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $this->applyWrapTopLeft($sheet, 'A' . $row . ':D' . $row);
        $row++;
        $sheet->setCellValue('A' . $row, 'Quelle');
        $sheet->setCellValue('B' . $row, 'Fachbereich');
        $sheet->setCellValue('C' . $row, 'Einrichtung');
        $sheet->setCellValue('D' . $row, 'VZÄ');
        $this->styleTableHeader($sheet, 'A' . $row . ':D' . $row);
        $row++;
        $einrStart = $row;
        foreach (['boerse' => 'Stellenbörse', 'intern' => 'Mandantenfeld'] as $src => $label) {
            foreach ($vzaProEinrichtung[$src] ?? [] as $fb => $eins) {
                foreach ($eins as $einr => $vzaVal) {
                    $sheet->setCellValue('A' . $row, $label);
                    $sheet->setCellValue('B' . $row, $fb);
                    $sheet->setCellValue('C' . $row, $einr);
                    $sheet->setCellValue('D' . $row, round($vzaVal, 2));
                    $sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
                    $row++;
                }
            }
        }
        if ($row > $einrStart) {
            $this->styleDataTable($sheet, 'A' . ($einrStart - 1) . ':D' . ($row - 1), $einrStart, 'D', $row - 1);
        }
        $this->setBoundedColumnWidths($sheet, 'D', [
            'A' => 18,
            'B' => 22,
            'C' => 24,
            'D' => 12,
        ]);
    }

    private function fillPlz(Spreadsheet $spreadsheet, VakanzAnalyzer $vakanz): void
    {
        $plzStats = $vakanz->nachPlz();

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('PLZ');

        $headers = ['PLZ', 'Ort', 'Anzahl', 'VZÄ', 'Stellennummern', 'Stellentitel'];
        $colIndex = 1;
        foreach ($headers as $h) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($colIndex) . '1', $h);
            ++$colIndex;
        }
        $this->styleTableHeader($sheet, 'A1:F1');
        $sheet->freezePane('A2');
        $row = 2;
        foreach ($plzStats as $r) {
            $sheet->setCellValue('A' . $row, $r['plz']);
            $sheet->setCellValue('B' . $row, $r['einsatzort'] ?? '');
            $sheet->setCellValue('C' . $row, $r['anzahl']);
            $sheet->setCellValue('D' . $row, round($r['vza_summe'], 2));
            $sheet->setCellValue('E' . $row, $r['stellennummern'] ?? '');
            $sheet->setCellValue('F' . $row, $r['titel_liste'] ?? '');
            $row++;
        }
        $lastRow = max(2, $row - 1);
        if ($lastRow >= 2) {
            $sheet->getStyle('D2:D' . $lastRow)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
            $this->styleDataTable($sheet, 'A1:F' . $lastRow, 2, 'F', $lastRow);
        }
        $this->setBoundedColumnWidths($sheet, 'F', [
            'A' => 9,
            'B' => 20,
            'C' => 10,
            'D' => 11,
            'E' => self::COL_WIDTH_MAX,
            'F' => self::COL_WIDTH_MAX,
        ]);
    }
}