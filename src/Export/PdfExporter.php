<?php
/**
 * PDF-Export: Kennzahlen, Tabellen und Diagramme als Bericht.
 * Nutzt mPDF und QuickChart.io für Chart-Bilder.
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\Export;

use BS_Awo_Jobs_Statistik\Analysis\FluktuationAnalyzer;
use BS_Awo_Jobs_Statistik\Analysis\VakanzAnalyzer;
use BS_Awo_Jobs_Statistik\Analysis\VzaCalculator;
use BS_Awo_Jobs_Statistik\Core\Database;
use Mpdf\Mpdf;

final class PdfExporter
{
    private const QUICKCHART_BASE = 'https://quickchart.io/chart';

    private object $db;

    private int $vollzeitStunden;

    public function __construct(object $db, int $vollzeitStunden = 39)
    {
        $this->db = $db;
        $this->vollzeitStunden = $vollzeitStunden;
    }

    /**
     * PDF erstellen und als Download senden.
     */
    public function exportAndSend(): void
    {
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 20,
            'margin_bottom' => 20,
        ]);

        $vza = new VzaCalculator($this->db, $this->vollzeitStunden);
        $fluk = new FluktuationAnalyzer($this->db);
        $vakanz = new VakanzAnalyzer($this->db);

        $aktuell = $vza->berechneAktuell();
        $gesamt = $vza->berechneGesamt();
        $offen = $vakanz->offenSeit();
        $uebersichtCounts = $vakanz->getUebersichtCounts();
        $top10 = $fluk->haeufigsteStellen(10);
        $idsTop10 = array_column($top10, 'logische_stelle_id');
        $plzByLog = $fluk->getPlzFuerLogischeStellen($idsTop10);

        $vzaVerlauf = $vza->berechneVzaVerlauf(90);
        $chartVzaLabels = array_keys($vzaVerlauf);
        $chartVzaData = array_values($vzaVerlauf);
        $chartFlukLabels = array_map(static fn ($r) => mb_substr($r['titel'], 0, 35) . (mb_strlen($r['titel']) > 35 ? '…' : ''), $top10);
        $chartFlukData = array_column($top10, 'anzahl_ausschreibungen');
        $chartVakanzLabels = array_map(static function ($r) {
            $t = $r['titel'];
            $short = mb_strlen($t) > 20 ? mb_substr($t, 0, 20) . '…' : $t;
            return $r['stellennummer'] . ' (' . $short . ')';
        }, array_slice($offen, 0, 10));
        $chartVakanzData = array_map(static fn ($r) => $r['tage_offen'], array_slice($offen, 0, 10));
        $nachIntern = $aktuell['nach_intern'] ?? [];
        arsort($nachIntern);
        $chartFachbereichLabels = array_keys($nachIntern);
        $chartFachbereichData = array_values($nachIntern);

        $html = $this->buildHtml(
            $offen,
            $gesamt,
            $aktuell,
            $uebersichtCounts,
            $top10,
            $plzByLog,
            $chartVzaLabels,
            $chartVzaData,
            $chartFlukLabels,
            $chartFlukData,
            $chartVakanzLabels,
            $chartVakanzData,
            $chartFachbereichLabels,
            $chartFachbereichData
        );

        $mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);

        $filename = 'bs-awo-jobs-statistik-bericht-' . date('Y-m-d') . '.pdf';
        $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
        exit;
    }

    /**
     * QuickChart-URL für ein Diagramm abrufen und als Data-URI zurückgeben.
     */
    private function fetchChartAsDataUri(array $config, int $width = 700, int $height = 280): string
    {
        $url = self::QUICKCHART_BASE . '?c=' . rawurlencode(json_encode($config)) . '&width=' . $width . '&height=' . $height . '&devicePixelRatio=1';
        $body = null;
        if (function_exists('wp_remote_get')) {
            $response = wp_remote_get($url, ['timeout' => 15]);
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $body = wp_remote_retrieve_body($response);
            }
        }
        if ($body === null || $body === '') {
            $body = @file_get_contents($url);
        }
        if ($body !== false && $body !== '') {
            return 'data:image/png;base64,' . base64_encode($body);
        }
        return '';
    }

    private function buildHtml(
        array $offen,
        float $gesamt,
        array $aktuell,
        array $uebersichtCounts,
        array $top10,
        array $plzByLog,
        array $chartVzaLabels,
        array $chartVzaData,
        array $chartFlukLabels,
        array $chartFlukData,
        array $chartVakanzLabels,
        array $chartVakanzData,
        array $chartFachbereichLabels,
        array $chartFachbereichData
    ): string {
        $unbekannt = (int) ($aktuell['unbekannt_anzahl'] ?? 0);

        $html = '<h1>BS AWO Jobs Statistik – Bericht</h1>';
        $html .= '<p style="color:#666;">Stand: ' . date('d.m.Y') . '</p>';

        $html .= '<h2>Übersicht</h2>';
        $html .= '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';
        $html .= '<tr style="background:#f5f5f5;"><td style="padding:8px;border:1px solid #ddd;"><strong>Offene Stellen</strong></td><td style="padding:8px;border:1px solid #ddd;">' . count($offen) . '</td></tr>';
        $html .= '<tr><td style="padding:8px;border:1px solid #ddd;"><strong>Gesamt-VZÄ</strong></td><td style="padding:8px;border:1px solid #ddd;">' . number_format($gesamt, 2, ',', '.') . '</td></tr>';
        $html .= '<tr style="background:#f5f5f5;"><td style="padding:8px;border:1px solid #ddd;"><strong>Unbekannt (Teilzeit)</strong></td><td style="padding:8px;border:1px solid #ddd;">' . $unbekannt . '</td></tr>';
        $html .= '</table>';

        if (!empty($chartVzaLabels) && !empty($chartVzaData)) {
            $vzaConfig = [
                'type' => 'line',
                'data' => [
                    'labels' => $chartVzaLabels,
                    'datasets' => [
                        ['label' => 'VZÄ', 'data' => array_map('floatval', $chartVzaData), 'borderColor' => '#2271b1', 'backgroundColor' => 'rgba(34,113,177,0.1)', 'fill' => true],
                    ],
                ],
                'options' => ['scales' => ['y' => ['beginAtZero' => true]]],
            ];
            $img = $this->fetchChartAsDataUri($vzaConfig, 700, 220);
            if ($img !== '') {
                $html .= '<h2>VZÄ-Verlauf (letzte 90 Tage)</h2>';
                $html .= '<p><img src="' . $img . '" style="max-width:100%;height:auto;" /></p>';
            }
        }

        $flukConfig = [
            'type' => 'bar',
            'data' => [
                'labels' => $chartFlukLabels,
                'datasets' => [['label' => 'Ausschreibungen', 'data' => array_map('intval', $chartFlukData), 'backgroundColor' => '#00a32a']],
            ],
            'options' => ['indexAxis' => 'y', 'scales' => ['x' => ['beginAtZero' => true, 'ticks' => ['stepSize' => 1]]]],
        ];
        $imgFluk = $this->fetchChartAsDataUri($flukConfig, 700, 300);
        if ($imgFluk !== '') {
            $html .= '<h2>Top 10 Fluktuationsstellen</h2>';
            $html .= '<p><img src="' . $imgFluk . '" style="max-width:100%;height:auto;" /></p>';
        }

        if (!empty($chartVakanzLabels) && !empty($chartVakanzData)) {
            $vakanzConfig = [
                'type' => 'bar',
                'data' => [
                    'labels' => $chartVakanzLabels,
                    'datasets' => [['label' => 'Tage offen', 'data' => array_map('intval', $chartVakanzData), 'backgroundColor' => '#d63638']],
                ],
                'options' => ['indexAxis' => 'y', 'scales' => ['x' => ['beginAtZero' => true]]],
            ];
            $imgVakanz = $this->fetchChartAsDataUri($vakanzConfig, 700, 300);
            if ($imgVakanz !== '') {
                $html .= '<h2>Längste offene Vakanzen</h2>';
                $html .= '<p><img src="' . $imgVakanz . '" style="max-width:100%;height:auto;" /></p>';
            }
        }

        if (!empty($chartFachbereichLabels) && !empty($chartFachbereichData)) {
            $pieColors = ['#2271b1', '#00a32a', '#d63638', '#dba617', '#72aee6', '#2c3338', '#50575e', '#787c82'];
            $n = count($chartFachbereichLabels);
            $colors = array_slice(array_merge($pieColors, array_fill(0, max(0, $n - count($pieColors)), '#888888')), 0, $n);
            $fbConfig = [
                'type' => 'pie',
                'data' => [
                    'labels' => $chartFachbereichLabels,
                    'datasets' => [['data' => array_map('floatval', $chartFachbereichData), 'backgroundColor' => $colors]],
                ],
            ];
            $imgFb = $this->fetchChartAsDataUri($fbConfig, 500, 320);
            if ($imgFb !== '') {
                $html .= '<h2>Offene Stellen nach Fachbereich</h2>';
                $html .= '<p><img src="' . $imgFb . '" style="max-width:100%;height:auto;" /></p>';
            }
        }

        $html .= '<h2>Top 10 Fluktuationsstellen (Tabelle)</h2>';
        $html .= '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:10px;">';
        $html .= '<tr style="background:#5B7FBD;color:#fff;"><th style="padding:6px;border:1px solid #333;">#</th><th style="padding:6px;border:1px solid #333;">Titel</th><th style="padding:6px;border:1px solid #333;">Einrichtung</th><th style="padding:6px;border:1px solid #333;">PLZ</th><th style="padding:6px;border:1px solid #333;">Anz.</th></tr>';
        foreach ($top10 as $i => $row) {
            $plz = $plzByLog[$row['logische_stelle_id']] ?? '';
            $html .= '<tr style="background:' . ($i % 2 === 1 ? '#f5f7fa' : '#fff') . ';">';
            $html .= '<td style="padding:6px;border:1px solid #ddd;">' . ($i + 1) . '</td>';
            $html .= '<td style="padding:6px;border:1px solid #ddd;">' . htmlspecialchars($row['titel']) . '</td>';
            $html .= '<td style="padding:6px;border:1px solid #ddd;">' . htmlspecialchars($row['einrichtung']) . '</td>';
            $html .= '<td style="padding:6px;border:1px solid #ddd;">' . htmlspecialchars($plz) . '</td>';
            $html .= '<td style="padding:6px;border:1px solid #ddd;">' . (int) $row['anzahl_ausschreibungen'] . '</td></tr>';
        }
        $html .= '</table>';

        $html .= '<h2>Top 10 längste Vakanzen</h2>';
        $html .= '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;font-size:10px;">';
        $html .= '<tr style="background:#5B7FBD;color:#fff;"><th style="padding:6px;border:1px solid #333;">Stellennr.</th><th style="padding:6px;border:1px solid #333;">Tage</th><th style="padding:6px;border:1px solid #333;">Titel</th><th style="padding:6px;border:1px solid #333;">Einrichtung</th></tr>';
        foreach (array_slice($offen, 0, 10) as $r) {
            $html .= '<tr><td style="padding:6px;border:1px solid #ddd;">' . htmlspecialchars($r['stellennummer']) . '</td>';
            $html .= '<td style="padding:6px;border:1px solid #ddd;">' . (int) $r['tage_offen'] . '</td>';
            $html .= '<td style="padding:6px;border:1px solid #ddd;">' . htmlspecialchars($r['titel']) . '</td>';
            $html .= '<td style="padding:6px;border:1px solid #ddd;">' . htmlspecialchars($r['einrichtung']) . '</td></tr>';
        }
        $html .= '</table>';

        $html .= '<h2>Nach Stellentitel (Top 10)</h2>';
        $html .= '<table style="width:100%;border-collapse:collapse;font-size:10px;">';
        $html .= '<tr style="background:#5B7FBD;color:#fff;"><th style="padding:6px;border:1px solid #333;">Titel</th><th style="padding:6px;border:1px solid #333;">Anzahl</th></tr>';
        $cnt = 0;
        foreach ($uebersichtCounts['nach_titel'] as $titel => $c) {
            if ($cnt++ >= 10) {
                break;
            }
            $html .= '<tr><td style="padding:6px;border:1px solid #ddd;">' . htmlspecialchars($titel) . '</td><td style="padding:6px;border:1px solid #ddd;">' . (int) $c . '</td></tr>';
        }
        $html .= '</table>';

        $html .= '<p style="margin-top:30px;font-size:9px;color:#888;">BS AWO Jobs Statistik – ' . date('d.m.Y H:i') . '</p>';

        return $html;
    }
}
