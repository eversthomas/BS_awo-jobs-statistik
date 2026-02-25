<?php
/**
 * Admin-Menü und Unterseiten: Dashboard, Import, Logische Stellen, Einstellungen.
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\WordPress\Admin;

use BS_Awo_Jobs_Statistik\Analysis\FluktuationAnalyzer;
use BS_Awo_Jobs_Statistik\Analysis\VakanzAnalyzer;
use BS_Awo_Jobs_Statistik\Analysis\VzaCalculator;
use BS_Awo_Jobs_Statistik\Core\Database;
use BS_Awo_Jobs_Statistik\Dedup\LogischeStellen;
use BS_Awo_Jobs_Statistik\Import\ApiImporter;
use BS_Awo_Jobs_Statistik\Snapshot\SnapshotService;

final class AdminPage
{
    public const MENU_SLUG = 'bs-awo-jobs-statistik';
    public const PAGE_DASHBOARD = 'bs-awo-jobs-statistik';
    public const PAGE_IMPORT = 'bs-awo-jobs-import';
    public const PAGE_LOGISCHE = 'bs-awo-jobs-logische';
    public const PAGE_EINSTELLUNGEN = 'bs-awo-jobs-einstellungen';

    /** @var \wpdb */
    private $wpdb;

    public function __construct($wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function registerMenu(): void
    {
        add_menu_page(
            __('BS AWO Jobs Statistik', 'bs-awo-jobs-statistik'),
            __('AWO Jobs Statistik', 'bs-awo-jobs-statistik'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'renderDashboard'],
            'dashicons-chart-bar',
            30
        );
        add_submenu_page(self::MENU_SLUG, __('Dashboard', 'bs-awo-jobs-statistik'), __('Dashboard', 'bs-awo-jobs-statistik'), 'manage_options', self::PAGE_DASHBOARD, [$this, 'renderDashboard']);
        add_submenu_page(self::MENU_SLUG, __('Import', 'bs-awo-jobs-statistik'), __('Import', 'bs-awo-jobs-statistik'), 'manage_options', self::PAGE_IMPORT, [$this, 'renderImport']);
        add_submenu_page(self::MENU_SLUG, __('Logische Stellen', 'bs-awo-jobs-statistik'), __('Logische Stellen', 'bs-awo-jobs-statistik'), 'manage_options', self::PAGE_LOGISCHE, [$this, 'renderLogischeStellen']);
        add_submenu_page(self::MENU_SLUG, __('Einstellungen', 'bs-awo-jobs-statistik'), __('Einstellungen', 'bs-awo-jobs-statistik'), 'manage_options', self::PAGE_EINSTELLUNGEN, [$this, 'renderEinstellungen']);
    }

    public function renderDashboard(): void
    {
        $tblConfig = $this->wpdb->prefix . Database::TABLE_KONFIGURATION;
        $vollzeit = (int) ($this->wpdb->get_var($this->wpdb->prepare("SELECT wert FROM {$tblConfig} WHERE schluessel = %s", 'vollzeit_stunden')) ?: 39);

        $vza = new VzaCalculator($this->wpdb, $vollzeit);
        $aktuell = $vza->berechneAktuell();
        $gesamt = $vza->berechneGesamt();

        $fluk = new FluktuationAnalyzer($this->wpdb);
        $top10 = $fluk->haeufigsteStellen(10);

        $vakanz = new VakanzAnalyzer($this->wpdb);
        $offen = $vakanz->offenSeit();
        $offenTop = array_slice($offen, 0, 5);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Dashboard', 'bs-awo-jobs-statistik'); ?></h1>

            <div class="bs-awo-stats-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin:1.5rem 0;">
                <div class="card" style="padding:1rem;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.1);border-left:4px solid #2271b1;">
                    <strong><?php echo esc_html__('Offene Stellen', 'bs-awo-jobs-statistik'); ?></strong>
                    <div style="font-size:1.75rem;margin-top:.5rem;"><?php echo esc_html((string) count($offen)); ?></div>
                </div>
                <div class="card" style="padding:1rem;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.1);border-left:4px solid #00a32a;">
                    <strong><?php echo esc_html__('Gesamt-VZÄ', 'bs-awo-jobs-statistik'); ?></strong>
                    <div style="font-size:1.75rem;margin-top:.5rem;"><?php echo esc_html(number_format($gesamt, 2, ',', '.')); ?></div>
                </div>
                <div class="card" style="padding:1rem;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.1);border-left:4px solid #d63638;">
                    <strong><?php echo esc_html__('Unbekannt (Teilzeit)', 'bs-awo-jobs-statistik'); ?></strong>
                    <div style="font-size:1.75rem;margin-top:.5rem;"><?php echo esc_html((string) ($aktuell['unbekannt_anzahl'] ?? 0)); ?></div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
                <div class="card" style="padding:1rem;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.1);">
                    <h2><?php echo esc_html__('Top 10 Fluktuationsstellen', 'bs-awo-jobs-statistik'); ?></h2>
                    <table class="widefat striped">
                        <thead><tr><th>#</th><th><?php echo esc_html__('Titel', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('Einrichtung', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('Anzahl', 'bs-awo-jobs-statistik'); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($top10 as $i => $row): ?>
                            <tr><td><?php echo $i + 1; ?></td><td><?php echo esc_html($row['titel']); ?></td><td><?php echo esc_html($row['einrichtung']); ?></td><td><?php echo esc_html((string) $row['anzahl_ausschreibungen']); ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (empty($top10)): ?>
                            <tr><td colspan="4"><?php echo esc_html__('Keine Daten.', 'bs-awo-jobs-statistik'); ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card" style="padding:1rem;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.1);">
                    <h2><?php echo esc_html__('Längste offene Vakanzen', 'bs-awo-jobs-statistik'); ?></h2>
                    <table class="widefat striped">
                        <thead><tr><th><?php echo esc_html__('Stellennr.', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('Tage', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('Titel', 'bs-awo-jobs-statistik'); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($offenTop as $row): ?>
                            <tr><td><?php echo esc_html($row['stellennummer']); ?></td><td><?php echo esc_html((string) $row['tage_offen']); ?></td><td><?php echo esc_html($row['titel']); ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (empty($offenTop)): ?>
                            <tr><td colspan="3"><?php echo esc_html__('Keine offenen Stellen.', 'bs-awo-jobs-statistik'); ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    public function renderImport(): void
    {
        $this->maybeHandleImport();
        $this->maybeHandleApiSync();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Import', 'bs-awo-jobs-statistik'); ?></h1>

            <div class="card" style="max-width:600px;padding:1.5rem;margin:1rem 0;">
                <h2><?php echo esc_html__('Excel/CSV-Upload', 'bs-awo-jobs-statistik'); ?></h2>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('bs_awo_jobs_excel_import', 'bs_awo_excel_nonce'); ?>
                    <input type="hidden" name="bs_awo_action" value="excel_import">
                    <p><input type="file" name="excel_file" accept=".xlsx,.xls,.csv"></p>
                    <?php submit_button(__('Datei importieren', 'bs-awo-jobs-statistik')); ?>
                </form>
            </div>

            <div class="card" style="max-width:600px;padding:1.5rem;margin:1rem 0;">
                <h2><?php echo esc_html__('API synchronisieren', 'bs-awo-jobs-statistik'); ?></h2>
                <p><?php echo esc_html__('API abrufen, Stundenzahlen ergänzen und Snapshot schreiben.', 'bs-awo-jobs-statistik'); ?></p>
                <form method="post">
                    <?php wp_nonce_field('bs_awo_jobs_api_sync', 'bs_awo_api_nonce'); ?>
                    <input type="hidden" name="bs_awo_action" value="api_sync">
                    <?php submit_button(__('API jetzt synchronisieren', 'bs-awo-jobs-statistik')); ?>
                </form>
            </div>
        </div>
        <?php
    }

    private function maybeHandleImport(): void
    {
        if (!isset($_POST['bs_awo_action']) || $_POST['bs_awo_action'] !== 'excel_import') {
            return;
        }
        if (!wp_verify_nonce($_POST['bs_awo_excel_nonce'] ?? '', 'bs_awo_jobs_excel_import') || !current_user_can('manage_options')) {
            return;
        }
        if (empty($_FILES['excel_file']['tmp_name']) || !is_uploaded_file($_FILES['excel_file']['tmp_name'])) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Bitte eine Datei auswählen.', 'bs-awo-jobs-statistik') . '</p></div>';
            return;
        }

        $importer = new \BS_Awo_Jobs_Statistik\Import\ExcelImporter($this->wpdb);
        $result = $importer->import($_FILES['excel_file']['tmp_name']);
        $dedup = new LogischeStellen($this->wpdb);
        $dedup->run();

        echo '<div class="notice notice-success"><p>' . esc_html(sprintf(__('Excel-Import: %d erfolgreich, %d Fehler.', 'bs-awo-jobs-statistik'), $result['success'], count($result['errors']))) . '</p></div>';
        if (!empty($result['errors'])) {
            echo '<div class="notice notice-warning"><ul>';
            foreach (array_slice($result['errors'], 0, 10) as $e) {
                echo '<li>' . esc_html($e) . '</li>';
            }
            if (count($result['errors']) > 10) {
                echo '<li>… ' . (count($result['errors']) - 10) . ' weitere</li>';
            }
            echo '</ul></div>';
        }
    }

    private function maybeHandleApiSync(): void
    {
        if (!isset($_POST['bs_awo_action']) || $_POST['bs_awo_action'] !== 'api_sync') {
            return;
        }
        if (!wp_verify_nonce($_POST['bs_awo_api_nonce'] ?? '', 'bs_awo_jobs_api_sync') || !current_user_can('manage_options')) {
            return;
        }

        $tblConfig = $this->wpdb->prefix . Database::TABLE_KONFIGURATION;
        $apiUrl = $this->wpdb->get_var($this->wpdb->prepare("SELECT wert FROM {$tblConfig} WHERE schluessel = %s", 'api_url'));
        if (empty($apiUrl)) {
            echo '<div class="notice notice-error"><p>' . esc_html__('API-URL in Einstellungen konfigurieren.', 'bs-awo-jobs-statistik') . '</p></div>';
            return;
        }

        $api = new ApiImporter($this->wpdb, (string) $apiUrl);
        $apiResult = $api->import('');
        $snapshot = new SnapshotService($this->wpdb, (string) $apiUrl);
        $snapResult = $snapshot->run();
        $dedup = new LogischeStellen($this->wpdb);
        $dedup->run();

        echo '<div class="notice notice-success"><p>' . esc_html(sprintf(__('API: %d importiert. Snapshot: %d neu, %d aktualisiert. Deduplizierung ausgeführt.', 'bs-awo-jobs-statistik'), $apiResult['success'], $snapResult['neu'], $snapResult['aktualisiert'])) . '</p></div>';
    }

    public function renderLogischeStellen(): void
    {
        $this->maybeHandleLogischeAction();

        $tblL = $this->wpdb->prefix . Database::TABLE_LOGISCHE_STELLEN;
        $tblZ = $this->wpdb->prefix . Database::TABLE_ZUORDNUNGEN;
        $tblA = $this->wpdb->prefix . Database::TABLE_AUSSCHREIBUNGEN;

        $rows = $this->wpdb->get_results(
            "SELECT l.id, l.titel, l.einrichtung, l.manuell_verifiziert,
                    (SELECT GROUP_CONCAT(z.stellennummer ORDER BY z.stellennummer) FROM {$tblZ} z WHERE z.logische_stelle_id = l.id) AS stellennummern
             FROM {$tblL} l
             ORDER BY l.id
             LIMIT 200",
            ARRAY_A
        );

        $alleLogischen = $this->wpdb->get_results("SELECT id, titel, einrichtung FROM {$tblL} ORDER BY titel, einrichtung", ARRAY_A);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Logische Stellen', 'bs-awo-jobs-statistik'); ?></h1>

            <p><?php echo esc_html__('Automatisch gruppiert nach Titel + Einrichtung. Manuell zuordnen oder trennen.', 'bs-awo-jobs-statistik'); ?></p>

            <table class="widefat striped">
                <thead>
                <tr>
                    <th><?php echo esc_html__('ID', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Titel', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Einrichtung', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Status', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Stellennummern', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Aktionen', 'bs-awo-jobs-statistik'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows ?: [] as $r):
                    $sns = $r['stellennummern'] ? explode(',', $r['stellennummern']) : [];
                    $verifiziert = !empty($r['manuell_verifiziert']);
                    ?>
                    <tr>
                        <td><?php echo esc_html($r['id']); ?></td>
                        <td><?php echo esc_html($r['titel']); ?></td>
                        <td><?php echo esc_html($r['einrichtung']); ?></td>
                        <td><span class="badge" style="background:<?php echo $verifiziert ? '#00a32a' : '#dba617'; ?>;color:#fff;padding:2px 8px;border-radius:3px;"><?php echo $verifiziert ? esc_html__('verifiziert', 'bs-awo-jobs-statistik') : esc_html__('automatisch', 'bs-awo-jobs-statistik'); ?></span></td>
                        <td><code><?php echo esc_html(implode(', ', array_slice($sns, 0, 5)) . (count($sns) > 5 ? ' …' : '')); ?></code></td>
                        <td>
                            <?php foreach (array_slice($sns, 0, 3) as $sn): ?>
                                <form method="post" style="display:inline-block;margin-right:4px;">
                                    <?php wp_nonce_field('bs_awo_trennen_' . $sn, 'bs_awo_trennen_nonce'); ?>
                                    <input type="hidden" name="bs_awo_action" value="trennen">
                                    <input type="hidden" name="stellennummer" value="<?php echo esc_attr($sn); ?>">
                                    <button type="submit" class="button button-small"><?php echo esc_html__('Trennen', 'bs-awo-jobs-statistik'); ?> <?php echo esc_html($sn); ?></button>
                                </form>
                            <?php endforeach; ?>
                            <?php if (count($sns) > 3): ?>
                                <span style="color:#666;">+<?php echo count($sns) - 3; ?> weitere</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="6"><?php echo esc_html__('Keine logischen Stellen.', 'bs-awo-jobs-statistik'); ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>

            <div class="card" style="max-width:500px;margin-top:2rem;padding:1rem;">
                <h3><?php echo esc_html__('Manuell zuordnen', 'bs-awo-jobs-statistik'); ?></h3>
                <form method="post">
                    <?php wp_nonce_field('bs_awo_zuordnen', 'bs_awo_zuordnen_nonce'); ?>
                    <input type="hidden" name="bs_awo_action" value="zuordnen">
                    <p>
                        <label><?php echo esc_html__('Stellennummer', 'bs-awo-jobs-statistik'); ?> <input type="text" name="stellennummer_zu" required></label>
                    </p>
                    <p>
                        <label><?php echo esc_html__('Ziel Logische Stelle', 'bs-awo-jobs-statistik'); ?>
                            <select name="logische_stelle_id" required>
                                <option value=""><?php echo esc_html__('— wählen —', 'bs-awo-jobs-statistik'); ?></option>
                                <?php foreach ($alleLogischen ?: [] as $l): ?>
                                    <option value="<?php echo esc_attr($l['id']); ?>"><?php echo esc_html($l['id'] . ' – ' . $l['titel'] . ' @ ' . $l['einrichtung']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </p>
                    <?php submit_button(__('Zusammenführen', 'bs-awo-jobs-statistik')); ?>
                </form>
            </div>
        </div>
        <?php
    }

    private function maybeHandleLogischeAction(): void
    {
        if (!isset($_POST['bs_awo_action']) || !current_user_can('manage_options')) {
            return;
        }
        $action = $_POST['bs_awo_action'] ?? '';

        if ($action === 'trennen') {
            $sn = sanitize_text_field($_POST['stellennummer'] ?? '');
            if ($sn && wp_verify_nonce($_POST['bs_awo_trennen_nonce'] ?? '', 'bs_awo_trennen_' . $sn)) {
                $dedup = new LogischeStellen($this->wpdb);
                $dedup->zuordnungTrennen($sn);
                echo '<div class="notice notice-success"><p>' . esc_html__('Zuordnung getrennt.', 'bs-awo-jobs-statistik') . '</p></div>';
            }
        }

        if ($action === 'zuordnen') {
            if (!wp_verify_nonce($_POST['bs_awo_zuordnen_nonce'] ?? '', 'bs_awo_zuordnen')) {
                return;
            }
            $sn = sanitize_text_field($_POST['stellennummer_zu'] ?? '');
            $logId = (int) ($_POST['logische_stelle_id'] ?? 0);
            if ($sn && $logId) {
                $tblZ = $this->wpdb->prefix . Database::TABLE_ZUORDNUNGEN;
                $exists = $this->wpdb->get_var($this->wpdb->prepare("SELECT 1 FROM {$tblZ} WHERE stellennummer = %s", $sn));
                $dedup = new LogischeStellen($this->wpdb);
                if (!$exists) {
                    $tblA = $this->wpdb->prefix . Database::TABLE_AUSSCHREIBUNGEN;
                    $row = $this->wpdb->get_row($this->wpdb->prepare("SELECT titel, einrichtung FROM {$tblA} WHERE stellennummer = %s", $sn), ARRAY_A);
                    if ($row) {
                        $now = date('Y-m-d H:i:s');
                        $this->wpdb->insert($tblZ, ['logische_stelle_id' => $logId, 'stellennummer' => $sn, 'zuordnungstyp' => 'manuell', 'erstellt_am' => $now], ['%d', '%s', '%s', '%s']);
                        $this->wpdb->update($this->wpdb->prefix . Database::TABLE_LOGISCHE_STELLEN, ['manuell_verifiziert' => 1, 'aktualisiert_am' => $now], ['id' => $logId], ['%d', '%s'], ['%d']);
                        echo '<div class="notice notice-success"><p>' . esc_html__('Zuordnung erstellt.', 'bs-awo-jobs-statistik') . '</p></div>';
                    }
                } else {
                    $dedup->manuellZuordnen($sn, $logId);
                    echo '<div class="notice notice-success"><p>' . esc_html__('Zuordnung aktualisiert.', 'bs-awo-jobs-statistik') . '</p></div>';
                }
            }
        }
    }

    public function renderEinstellungen(): void
    {
        $settings = new SettingsPage($this->wpdb);
        $settings->render();
    }
}
