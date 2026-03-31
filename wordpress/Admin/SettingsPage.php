<?php
/**
 * Einstellungsseite: Konfiguration + Gefahrenzone.
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\WordPress\Admin;

use BS_Awo_Jobs_Statistik\Core\Database;
use BS_Awo_Jobs_Statistik\Core\Installer;

final class SettingsPage
{
    public const SLUG = 'bs-awo-jobs-einstellungen';
    public const ACTION_SAVE = 'bs_awo_jobs_save_settings';
    public const ACTION_DELETE = 'bs_awo_jobs_delete_all';

    /** @var \wpdb */
    private $wpdb;

    public function __construct($wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function render(): void
    {
        $this->maybeHandleSave();
        $this->maybeHandleDelete();

        $tbl = $this->wpdb->prefix . Database::TABLE_KONFIGURATION;
        $config = [];
        foreach (['api_url', 'vollzeit_stunden', 'fachbereich_intern_aktiv', 'cronjob_intervall'] as $key) {
            $config[$key] = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT wert FROM {$tbl} WHERE schluessel = %s",
                $key
            ));
        }

        $apiUrl = esc_attr((string) ($config['api_url'] ?? ''));
        $vollzeit = esc_attr((string) ($config['vollzeit_stunden'] ?? '39'));
        $fbIntern = !empty($config['fachbereich_intern_aktiv']);
        $cronIntervall = esc_attr((string) ($config['cronjob_intervall'] ?? 'daily'));
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Einstellungen', 'bs-awo-jobs-statistik'); ?></h1>

            <form method="post" action="">
                <?php wp_nonce_field(self::ACTION_SAVE, 'bs_awo_jobs_nonce'); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_SAVE); ?>">

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="api_url"><?php echo esc_html__('API-URL', 'bs-awo-jobs-statistik'); ?></label></th>
                        <td><input type="url" name="api_url" id="api_url" value="<?php echo $apiUrl; ?>" class="regular-text" placeholder="https://..."></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vollzeit_stunden"><?php echo esc_html__('Vollzeitstunden', 'bs-awo-jobs-statistik'); ?></label></th>
                        <td><input type="number" name="vollzeit_stunden" id="vollzeit_stunden" value="<?php echo $vollzeit; ?>" min="1" max="60" step="0.5"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Fachbereich', 'bs-awo-jobs-statistik'); ?></th>
                        <td>
                            <label><input type="checkbox" name="fachbereich_intern_aktiv" value="1" <?php checked($fbIntern); ?>>
                                <?php echo esc_html__('Internes Kürzel als Fachbereich nutzen (wenn vorhanden)', 'bs-awo-jobs-statistik'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cronjob_intervall"><?php echo esc_html__('Snapshot-Häufigkeit', 'bs-awo-jobs-statistik'); ?></label></th>
                        <td>
                            <select name="cronjob_intervall" id="cronjob_intervall">
                                <option value="hourly" <?php selected($cronIntervall, 'hourly'); ?>><?php echo esc_html__('Stündlich', 'bs-awo-jobs-statistik'); ?></option>
                                <option value="twicedaily" <?php selected($cronIntervall, 'twicedaily'); ?>><?php echo esc_html__('Zweimal täglich', 'bs-awo-jobs-statistik'); ?></option>
                                <option value="daily" <?php selected($cronIntervall, 'daily'); ?>><?php echo esc_html__('Täglich', 'bs-awo-jobs-statistik'); ?></option>
                                <option value="weekly" <?php selected($cronIntervall, 'weekly'); ?>><?php echo esc_html__('Wöchentlich', 'bs-awo-jobs-statistik'); ?></option>
                            </select>
                            <p class="description"><?php echo esc_html__('Wie oft der API-Snapshot ausgeführt wird.', 'bs-awo-jobs-statistik'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Einstellungen speichern', 'bs-awo-jobs-statistik')); ?>
            </form>

            <hr style="margin: 2rem 0;">

            <h2 style="color: #b32d2e;"><?php echo esc_html__('Gefahrenzone', 'bs-awo-jobs-statistik'); ?></h2>
            <p><?php echo esc_html__('Alle importierten Daten unwiderruflich löschen. Die Tabellen werden geleert und die Konfiguration zurückgesetzt.', 'bs-awo-jobs-statistik'); ?></p>
            <form method="post" action="" id="bs-awo-jobs-delete-form" onsubmit="return confirm('<?php echo esc_js(__('Wirklich alle Daten unwiderruflich löschen?', 'bs-awo-jobs-statistik')); ?>');">
                <?php wp_nonce_field(self::ACTION_DELETE, 'bs_awo_jobs_delete_nonce'); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_DELETE); ?>">
                <?php submit_button(__('Alle Daten unwiderruflich löschen', 'bs-awo-jobs-statistik'), 'delete', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    private function maybeHandleSave(): void
    {
        if (!isset($_POST['action']) || $_POST['action'] !== self::ACTION_SAVE) {
            return;
        }
        if (!wp_verify_nonce($_POST['bs_awo_jobs_nonce'] ?? '', self::ACTION_SAVE) || !current_user_can('manage_options')) {
            return;
        }

        $tbl = $this->wpdb->prefix . Database::TABLE_KONFIGURATION;
        $updates = [
            'api_url' => sanitize_text_field($_POST['api_url'] ?? ''),
            'vollzeit_stunden' => max(1, min(60, (float) ($_POST['vollzeit_stunden'] ?? 39))),
            'fachbereich_intern_aktiv' => !empty($_POST['fachbereich_intern_aktiv']) ? '1' : '0',
            'cronjob_intervall' => in_array($_POST['cronjob_intervall'] ?? '', ['hourly', 'twicedaily', 'daily', 'weekly'], true)
                ? $_POST['cronjob_intervall'] : 'daily',
        ];

        foreach ($updates as $key => $value) {
            $this->wpdb->update($tbl, ['wert' => (string) $value], ['schluessel' => $key], ['%s'], ['%s']);
        }

        echo '<div class="notice notice-success"><p>' . esc_html__('Einstellungen gespeichert.', 'bs-awo-jobs-statistik') . '</p></div>';
    }

    private function maybeHandleDelete(): void
    {
        if (!isset($_POST['action']) || $_POST['action'] !== self::ACTION_DELETE) {
            return;
        }
        if (!wp_verify_nonce($_POST['bs_awo_jobs_delete_nonce'] ?? '', self::ACTION_DELETE) || !current_user_can('manage_options')) {
            return;
        }

        $prefix = $this->wpdb->prefix;
        $tables = [
            Database::TABLE_AUSSCHREIBUNGEN,
            Database::TABLE_LOGISCHE_STELLEN,
            Database::TABLE_ZUORDNUNGEN,
            Database::TABLE_SNAPSHOTS,
            Database::TABLE_KONFIGURATION,
            Database::TABLE_EINRICHTUNGEN_STAMM,
        ];

        foreach ($tables as $t) {
            $this->wpdb->query("TRUNCATE TABLE `{$prefix}{$t}`");
        }

        Installer::seedKonfigurationStatic($this->wpdb);
        $this->wpdb->update(
            $prefix . Database::TABLE_KONFIGURATION,
            ['wert' => '1'],
            ['schluessel' => 'daten_beim_deinstallieren_loeschen'],
            ['%s'],
            ['%s']
        );

        echo '<div class="notice notice-warning"><p>' . esc_html__('Alle Daten wurden gelöscht.', 'bs-awo-jobs-statistik') . '</p></div>';
    }
}
