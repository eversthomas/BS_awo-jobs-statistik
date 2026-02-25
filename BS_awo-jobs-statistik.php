<?php
/**
 * Plugin Name:       BS AWO Jobs Statistik
 * Plugin URI:        https://github.com/bezugssysteme/BS_awo-jobs-statistik
 * Description:       Statistische Auswertung offener und historischer Stellenausschreibungen (AWO). VZÄ, Fluktuation, Vakanzzeit.
 * Version:           0.1.0
 * Author:            Bezugssysteme (BS)
 * Author URI:        https://bezugssysteme.de
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bs-awo-jobs-statistik
 * Domain Path:       /languages
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik;

if (!defined('ABSPATH')) {
    exit;
}

define('BS_AWO_JOBS_STATISTIK_FILE', __FILE__);
define('BS_AWO_JOBS_STATISTIK_DIR', __DIR__);

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_readable($autoload)) {
    require_once $autoload;
}
require_once __DIR__ . '/src/Core/Database.php';
require_once __DIR__ . '/src/Core/Installer.php';

\register_activation_hook(__FILE__, [\BS_Awo_Jobs_Statistik\Core\Installer::class, 'activate']);
\register_deactivation_hook(__FILE__, [\BS_Awo_Jobs_Statistik\WordPress\Cron\CronHandler::class, 'unschedule']);

\add_action(\BS_Awo_Jobs_Statistik\WordPress\Cron\CronHandler::HOOK, [\BS_Awo_Jobs_Statistik\WordPress\Cron\CronHandler::class, 'run']);

if (is_admin()) {
    \add_action('admin_menu', static function () {
        $admin = new \BS_Awo_Jobs_Statistik\WordPress\Admin\AdminPage($GLOBALS['wpdb']);
        $admin->registerMenu();
    });
    \add_action('load-toplevel_page_bs-awo-jobs-statistik', static function () {
        $exportTab = isset($_GET['bs_export']) ? \sanitize_key($_GET['bs_export']) : '';
        $validExportTabs = ['uebersicht', 'fluktuation', 'vakanzen', 'fachbereiche', 'plz'];
        if ($exportTab !== '' && \in_array($exportTab, $validExportTabs, true) && \current_user_can('manage_options')) {
            if (\wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bs_awo_export_' . $exportTab)) {
                $tblConfig = $GLOBALS['wpdb']->prefix . \BS_Awo_Jobs_Statistik\Core\Database::TABLE_KONFIGURATION;
                $vollzeit = (int) ($GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare("SELECT wert FROM {$tblConfig} WHERE schluessel = %s", 'vollzeit_stunden')) ?: 39);
                $exporter = new \BS_Awo_Jobs_Statistik\Export\ExcelExporter($GLOBALS['wpdb'], $vollzeit);
                $exporter->exportAndSend($exportTab);
            }
        }
    });
    \add_action('admin_enqueue_scripts', static function (string $hook) {
        if (str_contains($hook, 'bs-awo-jobs-statistik')) {
            \wp_enqueue_script(
                'chartjs',
                'https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js',
                [],
                '4.4.6',
                true
            );
        }
    });
}
