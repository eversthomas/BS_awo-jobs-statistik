<?php
/**
 * Test: Analyse-Module (VZÄ, Fluktuation, Vakanz).
 * Prüfung: Alle Methoden aufrufen, Ergebnis-Arrays ausgeben, auf Plausibilität prüfen.
 */
require_once __DIR__ . '/../../../wp-load.php';

global $wpdb;

$tblConfig = $wpdb->prefix . 'bs_awojobs_konfiguration';
$vollzeitStunden = (int) ($wpdb->get_var($wpdb->prepare(
    "SELECT wert FROM {$tblConfig} WHERE schluessel = %s",
    'vollzeit_stunden'
)) ?: 39);

$vza = new \BS_Awo_Jobs_Statistik\Analysis\VzaCalculator($wpdb, $vollzeitStunden);
$aktuell = $vza->berechneAktuell();
$gesamt = $vza->berechneGesamt();

$fluk = new \BS_Awo_Jobs_Statistik\Analysis\FluktuationAnalyzer($wpdb);
$top10 = $fluk->haeufigsteStellen(10);

$vakanz = new \BS_Awo_Jobs_Statistik\Analysis\VakanzAnalyzer($wpdb);
$offen = $vakanz->offenSeit();
$vakanzData = $vakanz->berechne();
$longest = array_filter($vakanzData, static fn($r) => $r['anzahl_abgeschlossen'] > 0);
usort($longest, static fn($a, $b) => $b['durchschnitt_vakanz_tage'] <=> $a['durchschnitt_vakanz_tage']);
$longest = array_slice($longest, 0, 5);

$boerse = $aktuell['nach_boerse'] ?? [];
arsort($boerse);

$isCli = php_sapi_name() === 'cli';

if ($isCli) {
    // Plain-Text für CLI
    echo "=== VzaCalculator (Vollzeitstunden: {$vollzeitStunden}) ===\n";
    echo "berechneAktuell() – Gruppierung nach Fachbereich Boerse:\n";
    foreach ($boerse as $fb => $sum) {
        echo "  {$fb}: " . number_format($sum, 2, ',', '.') . " VZÄ\n";
    }
    echo "\nUnbekannt (Teilzeit ohne Stunden): " . ($aktuell['unbekannt_anzahl'] ?? 0) . "\n";
    echo "berechneGesamt(): " . number_format($gesamt, 2, ',', '.') . " VZÄ\n\n";

    echo "=== FluktuationAnalyzer ===\n";
    foreach ($top10 as $i => $row) {
        $d = $row['durchschnitt_tage_zwischen'] ?? null;
        echo ($i + 1) . ". [{$row['anzahl_ausschreibungen']}x] {$row['titel']} @ {$row['einrichtung']}\n";
        echo "   Ø Abstand zwischen Ausschreibungen: " . ($d !== null ? $d . ' Tage' : '-') . "\n";
    }

    echo "\n=== VakanzAnalyzer ===\n";
    echo "offenSeit() – Längste offene Stellen (Top 10):\n";
    foreach (array_slice($offen, 0, 10) as $row) {
        echo "  {$row['stellennummer']} | {$row['tage_offen']} Tage | {$row['titel']} @ {$row['einrichtung']}\n";
    }
    echo "\nGesamt aktuell offen: " . count($offen) . " Stellen\n";
    echo "\nberechne() – Längste durchschnittliche Vakanzzeit (Top 5):\n";
    foreach ($longest as $row) {
        echo "  " . round($row['durchschnitt_vakanz_tage']) . " Tage Ø | {$row['titel']} @ {$row['einrichtung']}\n";
    }
} else {
    // HTML-Ausgabe für Browser
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BS AWO Jobs Statistik – Analyse-Test</title>
    <style>
        :root { --bg: #1a1d23; --card: #252931; --text: #e4e6eb; --muted: #8b9199; --accent: #4a9eff; --success: #4ade80; }
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 1.5rem; line-height: 1.5; }
        h1 { font-size: 1.5rem; margin: 0 0 1.5rem; color: var(--accent); }
        .card { background: var(--card); border-radius: 8px; padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; }
        .card h2 { font-size: 1rem; margin: 0 0 1rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; }
        .card h2 span { color: var(--text); }
        .metric { font-size: 1.5rem; font-weight: 600; color: var(--success); margin-bottom: 1rem; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 0.5rem 0.75rem; font-weight: 600; color: var(--muted); font-size: 0.85rem; }
        td { padding: 0.5rem 0.75rem; border-top: 1px solid rgba(255,255,255,0.06); }
        tr:hover td { background: rgba(255,255,255,0.03); }
        .num { font-variant-numeric: tabular-nums; text-align: right; white-space: nowrap; }
        .badge { display: inline-block; background: rgba(74,158,255,0.2); color: var(--accent); padding: 0.2em 0.5em; border-radius: 4px; font-size: 0.85em; }
        .note { font-size: 0.9rem; color: var(--muted); margin-top: 0.75rem; }
    </style>
</head>
<body>
<h1>BS AWO Jobs Statistik – Analyse-Ergebnis</h1>

<div class="card">
    <h2>VZÄ-Rechner <span>(Vollzeitstunden: <?= $vollzeitStunden ?>)</span></h2>
    <div class="metric"><?= number_format($gesamt, 2, ',', '.') ?> VZÄ</div>
    <p class="note">Summe aller Vollzeitäquivalente aktuell offener Stellen</p>
    <table>
        <thead><tr><th>Fachbereich (Stellenbörse)</th><th class="num">VZÄ</th></tr></thead>
        <tbody>
        <?php foreach ($boerse as $fb => $sum): ?>
            <tr><td><?= htmlspecialchars($fb) ?></td><td class="num"><?= number_format($sum, 2, ',', '.') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (($aktuell['unbekannt_anzahl'] ?? 0) > 0): ?>
        <p class="note">Teilzeit-Stellen ohne Stundenzahl (nicht in VZÄ enthalten): <strong><?= $aktuell['unbekannt_anzahl'] ?></strong></p>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Fluktuation – häufigste Stellen</h2>
    <p class="note">Logische Stellen mit den meisten Ausschreibungen (Jahresend-Kopien + Wiederbesetzungen)</p>
    <table>
        <thead><tr><th>#</th><th>Titel</th><th>Einrichtung</th><th class="num">Anzahl</th><th class="num">Ø Abstand</th></tr></thead>
        <tbody>
        <?php foreach ($top10 as $i => $row):
            $d = $row['durchschnitt_tage_zwischen'] ?? null;
            $dStr = $d !== null ? round($d) . ' Tage' : '–';
        ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($row['titel']) ?></td>
                <td><?= htmlspecialchars($row['einrichtung']) ?></td>
                <td class="num"><span class="badge"><?= $row['anzahl_ausschreibungen'] ?>x</span></td>
                <td class="num"><?= $dStr ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2>Vakanz – aktuell offene Stellen</h2>
    <div class="metric"><?= count($offen) ?> Stellen</div>
    <p class="note">Offen = zuletzt in der API gesehen (aktuell geschaltet)</p>
    <table>
        <thead><tr><th>Stellennr.</th><th>Tage offen</th><th>Titel</th><th>Einrichtung</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($offen, 0, 10) as $row): ?>
            <tr>
                <td><code><?= htmlspecialchars($row['stellennummer']) ?></code></td>
                <td class="num"><?= $row['tage_offen'] ?> Tage</td>
                <td><?= htmlspecialchars($row['titel']) ?></td>
                <td><?= htmlspecialchars($row['einrichtung']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2>Vakanz – längste durchschnittliche Vakanzzeit</h2>
    <p class="note">Pro logischer Stelle: Ø Tage von Start bis Stop (nur abgeschlossene Ausschreibungen)</p>
    <table>
        <thead><tr><th>Titel</th><th>Einrichtung</th><th class="num">Ø Tage</th><th class="num">Abgeschl.</th></tr></thead>
        <tbody>
        <?php foreach ($longest as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['titel']) ?></td>
                <td><?= htmlspecialchars($row['einrichtung']) ?></td>
                <td class="num"><?= round($row['durchschnitt_vakanz_tage']) ?> Tage</td>
                <td class="num"><?= $row['anzahl_abgeschlossen'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>
<?php
}
