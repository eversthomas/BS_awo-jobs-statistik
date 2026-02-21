<?php
/**
 * Wird von WordPress beim Löschen des Plugins ausgeführt.
 * Löscht die Plugin-Tabellen nur, wenn die Option daten_beim_deinstallieren_loeschen explizit gesetzt ist.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/src/Core/Database.php';

$wpdb = $GLOBALS['wpdb'];
$prefix = $wpdb->prefix;

$table_config = $prefix . \BS_Awo_Jobs_Statistik\Core\Database::TABLE_KONFIGURATION;

$wert = null;
if ($wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($table_config) . "'") === $table_config) {
    $wert = $wpdb->get_var($wpdb->prepare(
        "SELECT wert FROM {$table_config} WHERE schluessel = %s",
        'daten_beim_deinstallieren_loeschen'
    ));
}

if ($wert !== '1') {
    return;
}

foreach (\BS_Awo_Jobs_Statistik\Core\Database::getTableNames() as $table_suffix) {
    $full_name = $prefix . $table_suffix;
    $wpdb->query("DROP TABLE IF EXISTS `{$full_name}`");
}
