<?php
/**
 * Test: Excel- und API-Import.
 * Prüfung: Stundenzahl aus API extrahiert, quelle wechselt bei bereits importierten Excel-Stellen auf "beide".
 */
require_once __DIR__ . '/../../../wp-load.php';

global $wpdb;

$table = $wpdb->prefix . \BS_Awo_Jobs_Statistik\Core\Database::TABLE_AUSSCHREIBUNGEN;

// Optional: Excel zuerst importieren (dann gleiche Stellennummern per API → quelle = "beide")
$excelFile = __DIR__ . '/test-daten.xlsx';
if (is_readable($excelFile)) {
    echo "=== Excel-Import ===\n";
    $excel = new \BS_Awo_Jobs_Statistik\Import\ExcelImporter($wpdb);
    $r = $excel->import($excelFile);
    echo "Erfolgreich: " . $r['success'] . ", Fehler: " . count($r['errors']) . "\n";
    if (!empty($r['errors'])) {
        foreach ($r['errors'] as $e) {
            echo "  - " . $e . "\n";
        }
    }
}

// API-URL aus Konfiguration oder Test-URL
$apiUrl = $wpdb->get_var($wpdb->prepare(
    "SELECT wert FROM {$wpdb->prefix}bs_awojobs_konfiguration WHERE schluessel = %s",
    'api_url'
));

// zum testen api url
$apiUrl = 'https://www.awo-jobs.de/stellenboerse-wesel.json';

if (empty($apiUrl)) {
    $apiUrl = getenv('BS_AWO_JOBS_API_URL') ?: '';
}
if ($apiUrl === '') {
    echo "\n=== API-Import übersprungen (api_url leer). Setze api_url in Konfiguration oder BS_AWO_JOBS_API_URL.\n";
} else {
    echo "\n=== API-Import ===\n";
    $api = new \BS_Awo_Jobs_Statistik\Import\ApiImporter($wpdb, $apiUrl);
    $r = $api->import('');
    echo "Erfolgreich: " . $r['success'] . ", Fehler: " . count($r['errors']) . "\n";
    if (!empty($r['errors'])) {
        foreach ($r['errors'] as $e) {
            echo "  - " . $e . "\n";
        }
    }
}

// Prüfung: ein paar Zeilen mit stellennummer, stunden, quelle anzeigen
echo "\n=== Ausschreibungen (Stellennummer, Stunden, Quelle) ===\n";
$rows = $wpdb->get_results(
    "SELECT stellennummer, stunden, stunden_quelle, quelle FROM {$table} ORDER BY importiert_am DESC LIMIT 10",
    ARRAY_A
);
if (empty($rows)) {
    echo "Keine Einträge.\n";
} else {
    foreach ($rows as $row) {
        $stunden = $row['stunden'] !== null ? $row['stunden'] : '(NULL)';
        echo $row['stellennummer'] . " | stunden=" . $stunden . " | stunden_quelle=" . ($row['stunden_quelle'] ?? '') . " | quelle=" . $row['quelle'] . "\n";
    }
}

// Deduplizierung: Logische Stellen
echo "\n=== Logische Stellen (Deduplizierung) ===\n";
$dedup = new \BS_Awo_Jobs_Statistik\Dedup\LogischeStellen($wpdb);
$r = $dedup->run();
echo "Erstellt: " . $r['erstellt'] . ", Zugeordnet: " . $r['zugeordnet'] . "\n";

$tblL = $wpdb->prefix . \BS_Awo_Jobs_Statistik\Core\Database::TABLE_LOGISCHE_STELLEN;
$tblZ = $wpdb->prefix . \BS_Awo_Jobs_Statistik\Core\Database::TABLE_ZUORDNUNGEN;
$logische = $wpdb->get_results("SELECT id, titel, einrichtung, manuell_verifiziert FROM {$tblL} ORDER BY id LIMIT 10", ARRAY_A);
echo "\nLogische Stellen (Auszug):\n";
foreach ($logische as $row) {
    echo "  " . $row['id'] . " | " . $row['titel'] . " | " . $row['einrichtung'] . " | manuell=" . $row['manuell_verifiziert'] . "\n";
}
$zuord = $wpdb->get_results("SELECT logische_stelle_id, stellennummer, zuordnungstyp FROM {$tblZ} ORDER BY id LIMIT 15", ARRAY_A);
echo "\nZuordnungen (Auszug):\n";
foreach ($zuord as $row) {
    echo "  " . $row['stellennummer'] . " → logische_stelle_id=" . $row['logische_stelle_id'] . " (" . $row['zuordnungstyp'] . ")\n";
}
