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
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ExcelExporter
{
    private object $db;

    private int $vollzeitStunden;

    public function __construct(object $db, int $vollzeitStunden = 39)
    {
        $this->db = $db;
        $this->vollzeitStunden = $vollzeitStunden;
    }

    /**
     * Erstellt Excel für den angegebenen Tab und sendet Download.
     *
     * @param string $tab uebersicht|fluktuation|vakanzen|fachbereiche|plz
     */
    public function exportAndSend(string $tab): void
    {
        $spreadsheet = $this->buildSpreadsheet($tab);
        if ($spreadsheet === null) {
            return;
        }

        $filename = 'bs-awo-jobs-statistik-' . $tab . '-' . date('Y-m-d') . '.xlsx';
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
        $validTabs = ['uebersicht', 'fluktuation', 'vakanzen', 'fachbereiche', 'plz'];
        if (!in_array($tab, $validTabs, true)) {
            return null;
        }

        $vza = new VzaCalculator($this->db, $this->vollzeitStunden);
        $fluk = new FluktuationAnalyzer($this->db);
        $vakanz = new VakanzAnalyzer($this->db);

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
        $row += 2;

        $sheet->setCellValue('A' . $row, 'Offene Stellen');
        $sheet->setCellValue('B' . $row, count($offen));
        $row++;
        $sheet->setCellValue('A' . $row, 'Gesamt-VZÄ');
        $sheet->setCellValue('B' . $row, round($gesamt, 2));
        $row++;
        $sheet->setCellValue('A' . $row, 'Unbekannt (Teilzeit)');
        $sheet->setCellValue('B' . $row, $aktuell['unbekannt_anzahl'] ?? 0);
        $row += 2;

        $sheet->setCellValue('A' . $row, 'Nach Stellentitel');
        $sheet->setCellValue('B' . $row, 'Anzahl');
        $row++;
        foreach ($uebersichtCounts['nach_titel'] as $titel => $cnt) {
            $sheet->setCellValue('A' . $row, $titel);
            $sheet->setCellValue('B' . $row, $cnt);
            $row++;
        }
        $row += 2;

        $sheet->setCellValue('A' . $row, 'Nach Fachbereich');
        $sheet->setCellValue('B' . $row, 'Anzahl');
        $row++;
        foreach ($uebersichtCounts['nach_fachbereich'] as $fb => $cnt) {
            $sheet->setCellValue('A' . $row, $fb);
            $sheet->setCellValue('B' . $row, $cnt);
            $row++;
        }
        $row += 2;

        $sheet->setCellValue('A' . $row, 'Nach Postleitzahl');
        $sheet->setCellValue('B' . $row, 'Anzahl');
        $row++;
        foreach ($uebersichtCounts['nach_plz'] as $plz => $cnt) {
            $sheet->setCellValue('A' . $row, $plz);
            $sheet->setCellValue('B' . $row, $cnt);
            $row++;
        }
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
        $row = 2;
        foreach ($offen as $r) {
            $sheet->setCellValue('A' . $row, $r['stellennummer']);
            $sheet->setCellValue('B' . $row, $r['tage_offen']);
            $sheet->setCellValue('C' . $row, $r['titel']);
            $sheet->setCellValue('D' . $row, $r['einrichtung']);
            $sheet->setCellValue('E' . $row, trim(($r['plz_einsatzort'] ?? '') . ' ' . ($r['einsatzort'] ?? '')));
            $row++;
        }
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
        $row++;
        $nachBoerse = $aktuell['nach_boerse'] ?? [];
        arsort($nachBoerse);
        foreach ($nachBoerse as $fb => $val) {
            $sheet->setCellValue('A' . $row, $fb);
            $sheet->setCellValue('B' . $row, round($val, 2));
            $row++;
        }
        $row += 2;

        $sheet->setCellValue('A' . $row, 'VZÄ nach Mandantenfeld (internes Kürzel)');
        $sheet->setCellValue('B' . $row, 'VZÄ');
        $row++;
        $nachIntern = $aktuell['nach_intern'] ?? [];
        arsort($nachIntern);
        foreach ($nachIntern as $fb => $val) {
            $sheet->setCellValue('A' . $row, $fb);
            $sheet->setCellValue('B' . $row, round($val, 2));
            $row++;
        }
        $row += 2;

        $sheet->setCellValue('A' . $row, 'VZÄ pro Einrichtung (Fachbereich / Einrichtung / VZÄ)');
        $row++;
        $sheet->setCellValue('A' . $row, 'Quelle');
        $sheet->setCellValue('B' . $row, 'Fachbereich');
        $sheet->setCellValue('C' . $row, 'Einrichtung');
        $sheet->setCellValue('D' . $row, 'VZÄ');
        $row++;
        foreach (['boerse' => 'Stellenbörse', 'intern' => 'Mandantenfeld'] as $src => $label) {
            foreach ($vzaProEinrichtung[$src] ?? [] as $fb => $eins) {
                foreach ($eins as $einr => $vzaVal) {
                    $sheet->setCellValue('A' . $row, $label);
                    $sheet->setCellValue('B' . $row, $fb);
                    $sheet->setCellValue('C' . $row, $einr);
                    $sheet->setCellValue('D' . $row, round($vzaVal, 2));
                    $row++;
                }
            }
        }
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
    }
}
