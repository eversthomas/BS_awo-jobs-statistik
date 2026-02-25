<?php
/**
 * Test: Snapshot-Service manuell ausführen.
 * Prüfung: bs_awojobs_snapshots enthält Einträge mit heutigem Datum und status='online'.
 */
require_once __DIR__ . '/../../../wp-load.php';

global $wpdb;

$tblConfig = $wpdb->prefix . 'bs_awojobs_konfiguration';
$apiUrl = $wpdb->get_var($wpdb->prepare(
    "SELECT wert FROM {$tblConfig} WHERE schluessel = %s",
    'api_url'
));

if (empty($apiUrl)) {
    $apiUrl = 'https://www.awo-jobs.de/stellenboerse-wesel.json';
}

echo "=== Snapshot-Service (API: " . substr($apiUrl, 0, 50) . "...) ===\n\n";

$service = new \BS_Awo_Jobs_Statistik\Snapshot\SnapshotService($wpdb, $apiUrl);
$result = $service->run();

echo "Ergebnis: neu=" . $result['neu'] . ", aktualisiert=" . $result['aktualisiert'] . "\n\n";

$tblSnap = $wpdb->prefix . 'bs_awojobs_snapshots';
$today = date('Y-m-d');
$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT stellennummer, stunden, zeitmodell, status FROM {$tblSnap} WHERE snapshot_datum = %s ORDER BY stellennummer LIMIT 15",
    $today
), ARRAY_A);

echo "Snapshots für heute ({$today}):\n";
if (empty($rows)) {
    echo "  (keine Einträge)\n";
} else {
    foreach ($rows as $r) {
        echo "  {$r['stellennummer']} | stunden=" . ($r['stunden'] ?? 'NULL') . " | status={$r['status']}\n";
    }
}

$countOnline = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$tblSnap} WHERE snapshot_datum = %s AND status = %s",
    $today,
    'online'
));
$countOffline = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$tblSnap} WHERE snapshot_datum = %s AND status = %s",
    $today,
    'offline'
));
echo "\nGesamt heute: {$countOnline} online, {$countOffline} offline\n";
