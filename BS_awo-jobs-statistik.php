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
    \add_action('admin_init', static function () {
        \BS_Awo_Jobs_Statistik\Core\Installer::ensureSchemaUpToDate();
    }, 5);
    \add_action('admin_menu', static function () {
        $admin = new \BS_Awo_Jobs_Statistik\WordPress\Admin\AdminPage($GLOBALS['wpdb']);
        $admin->registerMenu();
    });
    \add_action('load-toplevel_page_bs-awo-jobs-statistik', static function () {
        $exportTab = isset($_GET['bs_export']) ? \sanitize_key($_GET['bs_export']) : '';
        if ($exportTab === '' || !\current_user_can('manage_options')) {
            return;
        }
        $tblConfig = $GLOBALS['wpdb']->prefix . \BS_Awo_Jobs_Statistik\Core\Database::TABLE_KONFIGURATION;
        $vollzeit = (int) ($GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare("SELECT wert FROM {$tblConfig} WHERE schluessel = %s", 'vollzeit_stunden')) ?: 39);

        if ($exportTab === 'pdf' && \wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bs_awo_export_pdf')) {
            $exporter = new \BS_Awo_Jobs_Statistik\Export\PdfExporter($GLOBALS['wpdb'], $vollzeit);
            $exporter->exportAndSend();
        }

        if ($exportTab === 'aktive_stellen' && \wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bs_awo_export_aktive_stellen')) {
            $filter = new \BS_Awo_Jobs_Statistik\AktiveStellen\AktiveStellenFilterInput(
                isset($_GET['bs_as_q']) ? \sanitize_text_field(\wp_unslash($_GET['bs_as_q'])) : '',
                isset($_GET['bs_as_fb']) ? \sanitize_text_field(\wp_unslash($_GET['bs_as_fb'])) : '',
                isset($_GET['bs_as_fbi']) ? \sanitize_text_field(\wp_unslash($_GET['bs_as_fbi'])) : '',
                isset($_GET['bs_as_einr']) ? \sanitize_text_field(\wp_unslash($_GET['bs_as_einr'])) : '',
                isset($_GET['bs_as_plz']) ? \sanitize_text_field(\wp_unslash($_GET['bs_as_plz'])) : '',
                isset($_GET['bs_as_sq']) ? \sanitize_text_field(\wp_unslash($_GET['bs_as_sq'])) : ''
            );
            $scope = isset($_GET['bs_as_export_scope']) ? \sanitize_key(\wp_unslash($_GET['bs_as_export_scope'])) : \BS_Awo_Jobs_Statistik\AktiveStellen\AktiveStellenExportOptions::SCOPE_FILTERED;
            $stat = isset($_GET['bs_as_export_stat']) ? \sanitize_key(\wp_unslash($_GET['bs_as_export_stat'])) : \BS_Awo_Jobs_Statistik\AktiveStellen\AktiveStellenExportOptions::STAT_ALLE;
            if (!\in_array($scope, [\BS_Awo_Jobs_Statistik\AktiveStellen\AktiveStellenExportOptions::SCOPE_ALL, \BS_Awo_Jobs_Statistik\AktiveStellen\AktiveStellenExportOptions::SCOPE_FILTERED], true)) {
                $scope = \BS_Awo_Jobs_Statistik\AktiveStellen\AktiveStellenExportOptions::SCOPE_FILTERED;
            }
            if (!\in_array($stat, [\BS_Awo_Jobs_Statistik\AktiveStellen\AktiveStellenExportOptions::STAT_ALLE, \BS_Awo_Jobs_Statistik\AktiveStellen\AktiveStellenExportOptions::STAT_NUR_BERUECKSICHTIGT], true)) {
                $stat = \BS_Awo_Jobs_Statistik\AktiveStellen\AktiveStellenExportOptions::STAT_ALLE;
            }
            $aktiveOpts = new \BS_Awo_Jobs_Statistik\AktiveStellen\AktiveStellenExportOptions($scope, $stat);
            $exporter = new \BS_Awo_Jobs_Statistik\Export\ExcelExporter($GLOBALS['wpdb'], $vollzeit);
            $exporter->exportAndSend('aktive_stellen', $filter, $aktiveOpts);
        }

        $validExportTabs = ['uebersicht', 'fluktuation', 'vakanzen', 'fachbereiche', 'plz', 'alle'];
        if (\in_array($exportTab, $validExportTabs, true) && \wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bs_awo_export_' . $exportTab)) {
            $exporter = new \BS_Awo_Jobs_Statistik\Export\ExcelExporter($GLOBALS['wpdb'], $vollzeit);
            $exporter->exportAndSend($exportTab);
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
        if (str_contains($hook, 'bs-awo-jobs-import')) {
            \wp_enqueue_script('jquery');
            \wp_add_inline_script('jquery', 'jQuery(function($){$("#bs-awo-excel-import-form, #bs-awo-api-sync-form").on("submit",function(){$(this).find("input[type=submit], button[type=submit]").prop("disabled",true);$("#bs-awo-import-overlay").addClass("is-visible");});});');
        }
        if (str_contains($hook, 'bs-awo-jobs-logische')) {
            \wp_enqueue_script('jquery');
            \wp_add_inline_script('jquery', 'jQuery(function($){var rows=$(".bs-awo-logische-row"),total=rows.length;function update(){var q=$("#bs-awo-logische-suche").val().toLowerCase(),f=$("#bs-awo-logische-filter").val(),n=0;rows.each(function(){var r=$(this),s=r.attr("data-search")||"",o=r.attr("data-online")==="1",v=r.attr("data-verifiziert")==="1",m=(!q||s.indexOf(q)>=0)&&(!f||(f==="online"&&o)||(f==="offline"&&!o)||(f==="verifiziert"&&v)||(f==="automatisch"&&!v));r.toggle(m);if(m)n++;});$("#bs-awo-logische-treffer").text(n+"/"+total);}$("#bs-awo-logische-suche").on("input",update);$("#bs-awo-logische-filter").on("change",update);update();});');
        }
    });
}
