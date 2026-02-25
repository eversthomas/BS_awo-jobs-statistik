<?php
/**
 * Excel-Export der statistischen Dashboard-Daten.
 * Nutzt dieselben Analyse-Module wie die Admin-UI (single source of truth).
 * Keine WordPress-Abhängigkeiten außer optionalem $wpdb.
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\Export;

use BS_Awo_Jobs_Statistik\Analysis\FluktuationAnalyzer;
use BS_Awo_Jobs_Statistik\Analysis\VakanzAnalyzer;
use BS_Awo_Jobs_Statistik\Analysis\VzaCalculator;
use BS_Awo_Jobs_Statistik\Core\Database;
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
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
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
        ]);
        for ($row = $dataStartRow; $row <= $lastRow; $row++) {
            if (($row - $dataStartRow) % 2 === 1) {
                $sheet->getStyle('A' . $row . ':' . $lastCol . $row)->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_ZEBRA);
            }
        }
    }

    /**
     * Spaltenbreite automatisch anpassen.
     */
    private function autoSizeColumns(Worksheet $sheet, string $lastCol): void
    {
        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /**
     * Erstellt Excel für den angegebenen Tab und sendet Download.
     *
     * @param string $tab uebersicht|fluktuation|vakanzen|fachbereiche|plz|alle
     */
    public function exportAndSend(string $tab): void
    {
        $spreadsheet = $this->buildSpreadsheet($tab);
        if ($spreadsheet === null) {
            return;
        }

        $filename = 'bs-awo-jobs-statistik-' . ($tab === 'alle' ? 'gesamt' : $tab) . '-' . date('Y-m-d') . '.xlsx';
        $writer = new Xlsx($spreadsheet);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer->save('php://output');
        exit;
    }

    private function buildSpreadsheet(string $tab): ?Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $validTabs = ['uebersicht', 'fluktuation', 'vakanzen', 'fachbereiche', 'plz', 'alle'];
        if (!in_array($tab, $validTabs, true)) {
            return null;
        }

        $vza = new VzaCalculator($this->db, $this->vollzeitStunden);
        $fluk = new FluktuationAnalyzer($this->db);
        $vakanz = new VakanzAnalyzer($this->db);

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

    private function fillUebersicht(Spreadsheet $spreadsheet, VzaCalculator $vza, VakanzAnalyzer $vakanz): void
    {
        $aktuell = $vza->berechneAktuell();
        $gesamt = $vza->berechneGesamt();
        $offen = $vakanz->offenSeit();
        $uebersichtCounts = $vakanz->getUebersichtCounts();

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Übersicht');

        $row = 1;
        $sheet->setCellValue('A' . $row, 'BS AWO Jobs Statistik – Übersicht');
        $sheet->mergeCells('A' . $row . ':B' . $row);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $row += 2;

        $sheet->setCellValue('A' . $row, 'Offene Stellen');
        $sheet->setCellValue('B' . $row, count($offen));
        $row++;
        $sheet->setCellValue('A' . $row, 'Gesamt-VZÄ');
        $sheet->setCellValue('B' . $row, round($gesamt, 2));
        $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
        $row++;
        $sheet->setCellValue('A' . $row, 'Unbekannt (Teilzeit)');
        $sheet->setCellValue('B' . $row, $aktuell['unbekannt_anzahl'] ?? 0);
        $row += 2;

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
        $this->autoSizeColumns($sheet, 'B');
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
        $col = 'A';
        foreach ($headers as $h) {
            $sheet->setCellValue($col . '1', $h);
            $col++;
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
        $this->autoSizeColumns($sheet, 'F');
    }

    private function fillVakanzen(Spreadsheet $spreadsheet, VakanzAnalyzer $vakanz): void
    {
        $offen = $vakanz->offenSeit();

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Offene Vakanzen');

        $headers = ['Stellennr.', 'Tage', 'Titel', 'Einrichtung', 'Ort'];
        $col = 'A';
        foreach ($headers as $h) {
            $sheet->setCellValue($col . '1', $h);
            $col++;
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
        $this->autoSizeColumns($sheet, 'E');
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
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
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
        $this->autoSizeColumns($sheet, 'D');
    }

    private function fillPlz(Spreadsheet $spreadsheet, VakanzAnalyzer $vakanz): void
    {
        $plzStats = $vakanz->nachPlz();

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('PLZ');

        $headers = ['PLZ', 'Ort', 'Anzahl', 'VZÄ', 'Stellennummern', 'Stellentitel'];
        $col = 'A';
        foreach ($headers as $h) {
            $sheet->setCellValue($col . '1', $h);
            $col++;
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
        $this->autoSizeColumns($sheet, 'F');
    }
}
