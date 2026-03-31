<?php
/**
 * Admin-Menü und Unterseiten: Dashboard, Import, Logische Stellen, Einstellungen.
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\WordPress\Admin;

use BS_Awo_Jobs_Statistik\AktiveStellen\AktiveStellenExportOptions;
use BS_Awo_Jobs_Statistik\AktiveStellen\AktiveStellenFilterInput;
use BS_Awo_Jobs_Statistik\AktiveStellen\AktiveStellenQuery;
use BS_Awo_Jobs_Statistik\Analysis\FluktuationAnalyzer;
use BS_Awo_Jobs_Statistik\EinrichtungenStamm\EinrichtungenAuswertungService;
use BS_Awo_Jobs_Statistik\EinrichtungenStamm\EinrichtungenMatching;
use BS_Awo_Jobs_Statistik\EinrichtungenStamm\EinrichtungenStammCluster;
use BS_Awo_Jobs_Statistik\EinrichtungenStamm\EinrichtungenStammFilterInput;
use BS_Awo_Jobs_Statistik\EinrichtungenStamm\EinrichtungenStammRepository;
use BS_Awo_Jobs_Statistik\EinrichtungenStamm\EinrichtungenStammSollVza;
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
    public const PAGE_EINRICHTUNGEN = 'bs-awo-jobs-einrichtungen';
    public const PAGE_EINSTELLUNGEN = 'bs-awo-jobs-einstellungen';

    /** @var \wpdb */
    private $wpdb;

    public function __construct($wpdb)
    {
        $this->wpdb = $wpdb;

        add_action('admin_init', [$this, 'handleAdminActions']);
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
        add_submenu_page(self::MENU_SLUG, __('Stammdaten Einrichtungen', 'bs-awo-jobs-statistik'), __('Stammdaten Einrichtungen', 'bs-awo-jobs-statistik'), 'manage_options', self::PAGE_EINRICHTUNGEN, [$this, 'renderEinrichtungenStamm']);
        add_submenu_page(self::MENU_SLUG, __('Einstellungen', 'bs-awo-jobs-statistik'), __('Einstellungen', 'bs-awo-jobs-statistik'), 'manage_options', self::PAGE_EINSTELLUNGEN, [$this, 'renderEinstellungen']);
    }
    
    public function handleAdminActions(): void
    {
        $this->maybeHandleAktiveStellenToggle();
        $this->maybeHandleEinrichtungStammPost();
    }

    public function renderDashboard(): void
    {
        $tblConfig = $this->wpdb->prefix . Database::TABLE_KONFIGURATION;
        $vollzeit = (int) ($this->wpdb->get_var($this->wpdb->prepare("SELECT wert FROM {$tblConfig} WHERE schluessel = %s", 'vollzeit_stunden')) ?: 39);

        $activeTab = sanitize_key($_GET['bs_tab'] ?? 'aktive_stellen');
        $tabs = ['uebersicht', 'aktive_stellen', 'einrichtung_auswertung', 'charts', 'fluktuation', 'vakanzen', 'fachbereiche', 'plz'];
        if (!in_array($activeTab, $tabs, true)) {
            $activeTab = 'aktive_stellen';
        }

        $vza = null;
        $aktuell = [];
        $gesamt = 0.0;
        $fluk = null;
        $top10 = [];
        $stellennummernByLog = [];
        $plzByLog = [];
        $vakanz = null;
        $offen = [];
        $offenTop = [];
        $plzStats = [];
        $uebersichtCounts = ['nach_titel' => [], 'nach_fachbereich' => [], 'nach_plz' => []];
        $aktiveStellenRows = [];
        $aktiveSuche = '';
        $aktiveFachbereich = '';
        $aktiveMandantenfeld = '';
        $aktiveEinrichtung = '';
        $aktivePlz = '';
        $aktiveQuelle = '';
        $aktiveFachbereicheLocal = [];
        $aktiveMandantenfelderLocal = [];
        $aktiveEinrichtungenLocal = [];
        $aktivePlzListeLocal = [];
        $aktiveStellenFiltered = [];
        $aktiveGesamtAnzahl = 0;
        $aktiveGesamtVza = 0.0;
        $evaRows = [];
        $evaFilter = new EinrichtungenStammFilterInput();
        $evaEinrichtungOptions = [];
        $evaFbOptions = [];
        $evaMandantOptions = [];

        switch ($activeTab) {
            case 'aktive_stellen':
                $vza = new VzaCalculator($this->wpdb, $vollzeit);
                $aktiveStellenRows = AktiveStellenQuery::fetchAktiveZeilen($this->wpdb);

                $aktiveSuche = isset($_GET['bs_as_q']) ? sanitize_text_field(wp_unslash($_GET['bs_as_q'])) : '';
                $aktiveFachbereich = isset($_GET['bs_as_fb']) ? sanitize_text_field(wp_unslash($_GET['bs_as_fb'])) : '';
                $aktiveMandantenfeld = isset($_GET['bs_as_fbi']) ? sanitize_text_field(wp_unslash($_GET['bs_as_fbi'])) : '';
                $aktiveEinrichtung = isset($_GET['bs_as_einr']) ? sanitize_text_field(wp_unslash($_GET['bs_as_einr'])) : '';
                $aktivePlz = isset($_GET['bs_as_plz']) ? sanitize_text_field(wp_unslash($_GET['bs_as_plz'])) : '';
                $aktiveQuelle = isset($_GET['bs_as_sq']) ? sanitize_text_field(wp_unslash($_GET['bs_as_sq'])) : '';

                foreach ($aktiveStellenRows as $row) {
                    $fb = trim((string) ($row['fachbereich_boerse'] ?? ''));
                    $fbi = trim((string) ($row['fachbereich_intern'] ?? ''));
                    $einr = trim((string) ($row['einrichtung'] ?? ''));
                    $plz = trim((string) ($row['plz_einsatzort'] ?? ''));

                    if ($fb !== '') {
                        $fbKey = AktiveStellenQuery::normalizeFilterValue($fb);
                        if (!isset($aktiveFachbereicheLocal[$fbKey])) {
                            $aktiveFachbereicheLocal[$fbKey] = $fb;
                        }
                    }

                    if ($fbi !== '') {
                        $fbiKey = AktiveStellenQuery::normalizeFilterValue($fbi);
                        if (!isset($aktiveMandantenfelderLocal[$fbiKey])) {
                            $aktiveMandantenfelderLocal[$fbiKey] = $fbi;
                        }
                    }

                    if ($einr !== '') {
                        $einrKey = AktiveStellenQuery::normalizeFilterValue($einr);
                        if (!isset($aktiveEinrichtungenLocal[$einrKey])) {
                            $aktiveEinrichtungenLocal[$einrKey] = $einr;
                        }
                    }

                    if ($plz !== '') {
                        $aktivePlzListeLocal[$plz] = $plz;
                    }
                }

                ksort($aktiveFachbereicheLocal);
                ksort($aktiveMandantenfelderLocal);
                ksort($aktiveEinrichtungenLocal);
                ksort($aktivePlzListeLocal);

                $aktiveFilterInput = new AktiveStellenFilterInput(
                    $aktiveSuche,
                    $aktiveFachbereich,
                    $aktiveMandantenfeld,
                    $aktiveEinrichtung,
                    $aktivePlz,
                    $aktiveQuelle
                );
                $aktiveStellenFiltered = AktiveStellenQuery::applyUiFilters($aktiveStellenRows, $aktiveFilterInput);
                foreach ($aktiveStellenFiltered as $row) {
                    $aktiveGesamtAnzahl++;
                    $stundenRow = isset($row['stunden']) && $row['stunden'] !== null ? (float) $row['stunden'] : null;
                    $aktiveGesamtVza += $vza->vzaFuerListenzeile($stundenRow);
                }
                break;

            case 'einrichtung_auswertung':
                $repoEva = new EinrichtungenStammRepository($this->wpdb);
                $evaEinrichtungOptions = $repoEva->mergedEinrichtungOptions();
                $evaFbOptions = $repoEva->mergedFachbereichBoerseOptions();
                $evaMandantOptions = $repoEva->mergedMandantenfeldOptions();
                $evaQ = isset($_GET['bs_eva_q']) ? sanitize_text_field(wp_unslash($_GET['bs_eva_q'])) : '';
                $evaFb = isset($_GET['bs_eva_fb']) ? sanitize_text_field(wp_unslash($_GET['bs_eva_fb'])) : '';
                $evaFbi = isset($_GET['bs_eva_fbi']) ? sanitize_text_field(wp_unslash($_GET['bs_eva_fbi'])) : '';
                $evaEinr = isset($_GET['bs_eva_einr']) ? sanitize_text_field(wp_unslash($_GET['bs_eva_einr'])) : '';
                if (isset($_GET['bs_eva_aktiv'])) {
                    $evaAktiv = sanitize_key(wp_unslash($_GET['bs_eva_aktiv']));
                    if ($evaAktiv !== '' && $evaAktiv !== '1' && $evaAktiv !== '0') {
                        $evaAktiv = '';
                    }
                } else {
                    $evaAktiv = '1';
                }
                $evaFilter = new EinrichtungenStammFilterInput($evaQ, $evaFb, $evaFbi, $evaAktiv, $evaEinr, '');
                $svc = new EinrichtungenAuswertungService($this->wpdb, $vollzeit);
                $evaRows = $svc->buildAuswertungRows($evaFilter);
                break;

            case 'charts':
                $vza = new VzaCalculator($this->wpdb, $vollzeit);
                $aktuell = $vza->berechneAktuell();
                $fluk = new FluktuationAnalyzer($this->wpdb);
                $top10 = $fluk->haeufigsteStellen(10);
                $idsTop10 = array_column($top10, 'logische_stelle_id');
                $stellennummernByLog = $fluk->getStellennummernOnlineZuerst($idsTop10);
                $plzByLog = $fluk->getPlzFuerLogischeStellen($idsTop10);
                $vakanz = new VakanzAnalyzer($this->wpdb);
                $offen = $vakanz->offenSeit();
                break;

            case 'fluktuation':
                $fluk = new FluktuationAnalyzer($this->wpdb);
                $top10 = $fluk->haeufigsteStellen(10);
                $idsTop10 = array_column($top10, 'logische_stelle_id');
                $stellennummernByLog = $fluk->getStellennummernOnlineZuerst($idsTop10);
                $plzByLog = $fluk->getPlzFuerLogischeStellen($idsTop10);
                break;

            case 'fachbereiche':
                $vza = new VzaCalculator($this->wpdb, $vollzeit);
                $aktuell = $vza->berechneAktuell();
                break;

            case 'uebersicht':
                $vza = new VzaCalculator($this->wpdb, $vollzeit);
                $gesamt = $vza->berechneGesamt();
                $vakanz = new VakanzAnalyzer($this->wpdb);
                $offen = $vakanz->offenSeit();
                $uebersichtCounts = $vakanz->getUebersichtCounts();
                break;

            case 'vakanzen':
                $vakanz = new VakanzAnalyzer($this->wpdb);
                $offen = $vakanz->offenSeit();
                $offenTop = array_slice($offen, 0, 10);
                break;

            case 'plz':
                $vakanz = new VakanzAnalyzer($this->wpdb);
                $plzStats = $vakanz->nachPlz();
                break;
        }
        ?>
        <div class="wrap bs-awo-statistik-dashboard">
            <h1><?php echo esc_html__('Dashboard', 'bs-awo-jobs-statistik'); ?></h1>
            <style>
                .bs-awo-statistik-dashboard .bs-awo-dashboard-toolbar{display:flex;flex-wrap:wrap;gap:.5rem .75rem;align-items:center;margin-bottom:1rem;}
                .bs-awo-statistik-dashboard .bs-awo-dashboard-toolbar .description{margin:0;flex:1 1 12rem;min-width:0;}
                .bs-awo-statistik-dashboard .nav-tab-wrapper{display:flex;flex-wrap:wrap;gap:.125rem .25rem;}
                .bs-awo-statistik-dashboard .nav-tab-wrapper .nav-tab{float:none;margin-bottom:.125rem;}
                .bs-awo-statistik-dashboard .bs-awo-tab-content{max-width:100%;min-width:0;overflow-x:auto;box-sizing:border-box;-webkit-overflow-scrolling:touch;}
            </style>

            <div class="bs-awo-dashboard-toolbar">
                <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['bs_export' => 'alle'], admin_url('admin.php?page=' . self::PAGE_DASHBOARD)), 'bs_awo_export_alle')); ?>" class="button button-primary"><?php echo esc_html__('Alle Daten als Excel exportieren', 'bs-awo-jobs-statistik'); ?></a>
                <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['bs_export' => 'pdf'], admin_url('admin.php?page=' . self::PAGE_DASHBOARD)), 'bs_awo_export_pdf')); ?>" class="button"><?php echo esc_html__('Als PDF exportieren', 'bs-awo-jobs-statistik'); ?></a>
                <span class="description"><?php echo esc_html__('Excel: Übersicht, Fluktuation, Vakanzen, Fachbereiche, PLZ. PDF: Kennzahlen, Diagramme und Tabellen.', 'bs-awo-jobs-statistik'); ?></span>
            </div>

            <nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom:0;">
                <a href="?page=<?php echo esc_attr(self::PAGE_DASHBOARD); ?>&bs_tab=aktive_stellen" class="nav-tab <?php echo $activeTab === 'aktive_stellen' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__('Aktive Stellen', 'bs-awo-jobs-statistik'); ?></a>
                <a href="?page=<?php echo esc_attr(self::PAGE_DASHBOARD); ?>&bs_tab=einrichtung_auswertung" class="nav-tab <?php echo $activeTab === 'einrichtung_auswertung' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__('Soll / Offen / Besetzt', 'bs-awo-jobs-statistik'); ?></a>
                <a href="?page=<?php echo esc_attr(self::PAGE_DASHBOARD); ?>&bs_tab=charts" class="nav-tab <?php echo $activeTab === 'charts' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__('Charts', 'bs-awo-jobs-statistik'); ?></a>
                <a href="?page=<?php echo esc_attr(self::PAGE_DASHBOARD); ?>&bs_tab=fluktuation" class="nav-tab <?php echo $activeTab === 'fluktuation' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__('Fluktuation', 'bs-awo-jobs-statistik'); ?></a>
                <a href="?page=<?php echo esc_attr(self::PAGE_DASHBOARD); ?>&bs_tab=fachbereiche" class="nav-tab <?php echo $activeTab === 'fachbereiche' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__('Fachbereiche', 'bs-awo-jobs-statistik'); ?></a>
            </nav>

            <div class="bs-awo-tab-content" style="background:#fff;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.1);margin-top:-1px;border:1px solid #c3c4c7;border-top:none;">

            <?php if ($activeTab === 'uebersicht'): ?>
                <p style="margin-bottom:1rem;"><a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['bs_export' => 'uebersicht', 'bs_tab' => 'uebersicht'], admin_url('admin.php?page=' . self::PAGE_DASHBOARD)), 'bs_awo_export_uebersicht')); ?>" class="button"><?php echo esc_html__('Als Excel exportieren', 'bs-awo-jobs-statistik'); ?></a></p>
                <div class="bs-awo-stats-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin-bottom:2rem;">
                    <div class="card" style="padding:1rem;border-left:4px solid #2271b1;">
                        <strong><?php echo esc_html__('Offene Stellen', 'bs-awo-jobs-statistik'); ?></strong>
                        <div style="font-size:1.75rem;margin-top:.5rem;"><?php echo esc_html((string) count($offen)); ?></div>
                    </div>
                    <div class="card" style="padding:1rem;border-left:4px solid #00a32a;">
                        <strong><?php echo esc_html__('Gesamt-VZÄ', 'bs-awo-jobs-statistik'); ?></strong>
                        <div style="font-size:1.75rem;margin-top:.5rem;"><?php echo esc_html(number_format($gesamt, 2, ',', '.')); ?></div>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1.5rem;">
                    <div><h3><?php echo esc_html__('Nach Stellentitel', 'bs-awo-jobs-statistik'); ?></h3>
                        <table class="widefat striped"><thead><tr><th><?php echo esc_html__('Titel', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('Anzahl', 'bs-awo-jobs-statistik'); ?></th></tr></thead><tbody>
                        <?php foreach ($uebersichtCounts['nach_titel'] as $titel => $cnt): ?>
                            <tr><td><?php echo esc_html($titel); ?></td><td><?php echo esc_html((string) $cnt); ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (empty($uebersichtCounts['nach_titel'])): ?><tr><td colspan="2"><?php echo esc_html__('Keine Daten.', 'bs-awo-jobs-statistik'); ?></td></tr><?php endif; ?>
                        </tbody></table></div>
                    <div><h3><?php echo esc_html__('Nach Fachbereich', 'bs-awo-jobs-statistik'); ?></h3>
                        <table class="widefat striped"><thead><tr><th><?php echo esc_html__('Fachbereich', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('Anzahl', 'bs-awo-jobs-statistik'); ?></th></tr></thead><tbody>
                        <?php foreach ($uebersichtCounts['nach_fachbereich'] as $fb => $cnt): ?>
                            <tr><td><?php echo esc_html($fb); ?></td><td><?php echo esc_html((string) $cnt); ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (empty($uebersichtCounts['nach_fachbereich'])): ?><tr><td colspan="2"><?php echo esc_html__('Keine Daten.', 'bs-awo-jobs-statistik'); ?></td></tr><?php endif; ?>
                        </tbody></table></div>
                    <div><h3><?php echo esc_html__('Nach Postleitzahl', 'bs-awo-jobs-statistik'); ?></h3>
                        <table class="widefat striped"><thead><tr><th><?php echo esc_html__('PLZ', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('Anzahl', 'bs-awo-jobs-statistik'); ?></th></tr></thead><tbody>
                        <?php foreach ($uebersichtCounts['nach_plz'] as $plz => $cnt): ?>
                            <tr><td><code><?php echo esc_html($plz); ?></code></td><td><?php echo esc_html((string) $cnt); ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (empty($uebersichtCounts['nach_plz'])): ?><tr><td colspan="2"><?php echo esc_html__('Keine Daten.', 'bs-awo-jobs-statistik'); ?></td></tr><?php endif; ?>
                        </tbody></table></div>
                </div>
            <?php endif; ?>
            
            <?php if ($activeTab === 'aktive_stellen'): ?>
            <h2><?php echo esc_html__('Aktive Stellen', 'bs-awo-jobs-statistik'); ?></h2>
            <p class="description"><?php echo esc_html__('Zentrale Ansicht aller aktuell offenen Stellen aus der API. Diese Version 1 dient als neue verlässliche Masteransicht.', 'bs-awo-jobs-statistik'); ?></p>
            <form method="get" style="margin:1rem 0 1.5rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.75rem;align-items:end;">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_DASHBOARD); ?>">
            <input type="hidden" name="bs_tab" value="aktive_stellen">

            <label>
                <?php echo esc_html__('Suche', 'bs-awo-jobs-statistik'); ?><br>
                <input type="text" name="bs_as_q" value="<?php echo esc_attr($aktiveSuche); ?>" placeholder="<?php echo esc_attr__('Stellennummer, Titel, Einrichtung, Ort …', 'bs-awo-jobs-statistik'); ?>" style="width:100%;">
            </label>

            <label>
                <?php echo esc_html__('Fachbereich', 'bs-awo-jobs-statistik'); ?><br>
                <select name="bs_as_fb" style="width:100%;">
                    <option value=""><?php echo esc_html__('Alle', 'bs-awo-jobs-statistik'); ?></option>
                    <?php foreach ($aktiveFachbereicheLocal as $fb): ?>
                        <option value="<?php echo esc_attr($fb); ?>" <?php selected($aktiveFachbereich, $fb); ?>><?php echo esc_html($fb); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            
            <label>
                <?php echo esc_html__('Mandantenfeld', 'bs-awo-jobs-statistik'); ?><br>
                <select name="bs_as_fbi" style="width:100%;">
                    <option value=""><?php echo esc_html__('Alle', 'bs-awo-jobs-statistik'); ?></option>
                    <?php foreach (array_values($aktiveMandantenfelderLocal) as $fbiValue): ?>
                        <option value="<?php echo esc_attr((string) $fbiValue); ?>" <?php selected($aktiveMandantenfeld, (string) $fbiValue); ?>><?php echo esc_html((string) $fbiValue); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <?php echo esc_html__('Einrichtung', 'bs-awo-jobs-statistik'); ?><br>
                <select name="bs_as_einr" style="width:100%;">
                    <option value=""><?php echo esc_html__('Alle', 'bs-awo-jobs-statistik'); ?></option>
                    <?php foreach ($aktiveEinrichtungenLocal as $einr): ?>
                        <option value="<?php echo esc_attr($einr); ?>" <?php selected($aktiveEinrichtung, $einr); ?>><?php echo esc_html($einr); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <?php echo esc_html__('PLZ', 'bs-awo-jobs-statistik'); ?><br>
                <select name="bs_as_plz" style="width:100%;">
                    <option value=""><?php echo esc_html__('Alle', 'bs-awo-jobs-statistik'); ?></option>
                    <?php foreach ($aktivePlzListeLocal as $plz): ?>
                        <option value="<?php echo esc_attr($plz); ?>" <?php selected($aktivePlz, $plz); ?>><?php echo esc_html($plz); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <?php echo esc_html__('Quelle Stunden', 'bs-awo-jobs-statistik'); ?><br>
                <select name="bs_as_sq" style="width:100%;">
                    <option value=""><?php echo esc_html__('Alle', 'bs-awo-jobs-statistik'); ?></option>
                    <option value="api_infos" <?php selected($aktiveQuelle, 'api_infos'); ?>>api_infos</option>
                    <option value="api_einleitung" <?php selected($aktiveQuelle, 'api_einleitung'); ?>>api_einleitung</option>
                </select>
            </label>

            <div style="display:flex;gap:0.5rem;align-items:center;">
                <button type="submit" class="button button-primary"><?php echo esc_html__('Filtern', 'bs-awo-jobs-statistik'); ?></button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_DASHBOARD . '&bs_tab=aktive_stellen')); ?>" class="button"><?php echo esc_html__('Zurücksetzen', 'bs-awo-jobs-statistik'); ?></a>
            </div>
        </form>

            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem;margin:1rem 0 1.5rem;">
                <div class="card" style="padding:1rem;border-left:4px solid #2271b1;">
                    <strong><?php echo esc_html__('Aktive Stellen', 'bs-awo-jobs-statistik'); ?></strong>
                    <div style="font-size:1.75rem;margin-top:.5rem;"><?php echo esc_html((string) $aktiveGesamtAnzahl); ?></div>
                </div>
                <div class="card" style="padding:1rem;border-left:4px solid #00a32a;">
                    <strong><?php echo esc_html__('Aktive VZÄ', 'bs-awo-jobs-statistik'); ?></strong>
                    <div style="font-size:1.75rem;margin-top:.5rem;"><?php echo esc_html(number_format($aktiveGesamtVza, 2, ',', '.')); ?></div>
                </div>
            </div>

            <div class="bs-awo-aktive-export card" style="padding:1rem;margin-bottom:1rem;max-width:720px;">
                <h3 style="margin-top:0;"><?php echo esc_html__('Excel-Export', 'bs-awo-jobs-statistik'); ?></h3>
                <p class="description"><?php echo esc_html__('Nutzt dieselben Filter wie die Tabelle, sofern „Aktuelle Filter“ gewählt ist. Die Spalte „Berücksichtigen“ entspricht der Datenbank.', 'bs-awo-jobs-statistik'); ?></p>
                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:flex-end;">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_DASHBOARD); ?>">
                    <input type="hidden" name="bs_export" value="aktive_stellen">
                    <input type="hidden" name="bs_tab" value="aktive_stellen">
                    <input type="hidden" name="bs_as_q" value="<?php echo esc_attr($aktiveSuche); ?>">
                    <input type="hidden" name="bs_as_fb" value="<?php echo esc_attr($aktiveFachbereich); ?>">
                    <input type="hidden" name="bs_as_fbi" value="<?php echo esc_attr($aktiveMandantenfeld); ?>">
                    <input type="hidden" name="bs_as_einr" value="<?php echo esc_attr($aktiveEinrichtung); ?>">
                    <input type="hidden" name="bs_as_plz" value="<?php echo esc_attr($aktivePlz); ?>">
                    <input type="hidden" name="bs_as_sq" value="<?php echo esc_attr($aktiveQuelle); ?>">
                    <?php wp_nonce_field('bs_awo_export_aktive_stellen'); ?>
                    <label>
                        <?php echo esc_html__('Umfang', 'bs-awo-jobs-statistik'); ?><br>
                        <select name="bs_as_export_scope">
                            <option value="<?php echo esc_attr(AktiveStellenExportOptions::SCOPE_FILTERED); ?>"><?php echo esc_html__('Aktuelle Filter (wie Tabelle)', 'bs-awo-jobs-statistik'); ?></option>
                            <option value="<?php echo esc_attr(AktiveStellenExportOptions::SCOPE_ALL); ?>"><?php echo esc_html__('Alle aktiven Stellen', 'bs-awo-jobs-statistik'); ?></option>
                        </select>
                    </label>
                    <label>
                        <?php echo esc_html__('Spalte „Berücksichtigen“', 'bs-awo-jobs-statistik'); ?><br>
                        <select name="bs_as_export_stat">
                            <option value="<?php echo esc_attr(AktiveStellenExportOptions::STAT_ALLE); ?>"><?php echo esc_html__('Alle Zeilen (berücksichtigt und ausgeschlossen)', 'bs-awo-jobs-statistik'); ?></option>
                            <option value="<?php echo esc_attr(AktiveStellenExportOptions::STAT_NUR_BERUECKSICHTIGT); ?>"><?php echo esc_html__('Nur berücksichtigte Stellen', 'bs-awo-jobs-statistik'); ?></option>
                        </select>
                    </label>
                    <button type="submit" class="button button-primary"><?php echo esc_html__('Als Excel exportieren', 'bs-awo-jobs-statistik'); ?></button>
                </form>
            </div>

            <table class="widefat striped">
                <thead>
                <tr>
                    <th><?php echo esc_html__('Berücksichtigen', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Stellennr.', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Titel', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Einrichtung', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Fachbereich', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Mandantenfeld', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('PLZ', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Ort', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Zeitmodell', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Stunden', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('VZÄ', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Startdatum', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Quelle Stunden', 'bs-awo-jobs-statistik'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($aktiveStellenFiltered ?: [] as $row): ?>
                    <?php
                    $stunden = isset($row['stunden']) && $row['stunden'] !== null ? (float) $row['stunden'] : null;
                    $vzaWert = $vza->vzaFuerListenzeile($stunden);
                    ?>
                    <tr>
                        <td style="text-align:center;">
                            <form method="post" style="margin:0;">
                                <?php wp_nonce_field('bs_awo_toggle_aktive_stelle', 'bs_awo_toggle_nonce'); ?>
                                <input type="hidden" name="bs_awo_action" value="toggle_aktive_stelle">
                                <input type="hidden" name="bs_tab" value="aktive_stellen">
                                <input type="hidden" name="stellennummer" value="<?php echo esc_attr((string) ($row['stellennummer'] ?? '')); ?>">
                                <input type="hidden" name="bs_as_q" value="<?php echo esc_attr($aktiveSuche); ?>">
                                <input type="hidden" name="bs_as_fb" value="<?php echo esc_attr($aktiveFachbereich); ?>">
                                <input type="hidden" name="bs_as_fbi" value="<?php echo esc_attr($aktiveMandantenfeld); ?>">
                                <input type="hidden" name="bs_as_einr" value="<?php echo esc_attr($aktiveEinrichtung); ?>">
                                <input type="hidden" name="bs_as_plz" value="<?php echo esc_attr($aktivePlz); ?>">
                                <input type="hidden" name="bs_as_sq" value="<?php echo esc_attr($aktiveQuelle); ?>">
                                <input type="hidden" name="in_statistik_beruecksichtigen" value="<?php echo ((int) ($row['in_statistik_beruecksichtigen'] ?? 1) === 1) ? '0' : '1'; ?>">
                                <input type="checkbox" <?php checked((int) ($row['in_statistik_beruecksichtigen'] ?? 1), 1); ?> onchange="this.form.submit()">
                            </form>
                        </td>
                        <td><code><?php echo esc_html((string) ($row['stellennummer'] ?? '')); ?></code></td>
                        <td><?php echo esc_html((string) ($row['titel'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($row['einrichtung'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($row['fachbereich_boerse'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($row['fachbereich_intern'] ?? '')); ?></td>
                        <td><code><?php echo esc_html((string) ($row['plz_einsatzort'] ?? '')); ?></code></td>
                        <td><?php echo esc_html((string) ($row['einsatzort'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($row['zeitmodell'] ?? '')); ?></td>
                        <td><?php echo $stunden !== null ? esc_html(number_format($stunden, 2, ',', '.')) : '–'; ?></td>
                        <td><?php echo esc_html(number_format($vzaWert, 2, ',', '.')); ?></td>
                        <td><?php echo esc_html((string) ($row['startdatum'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($row['stunden_quelle'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($aktiveStellenFiltered)): ?>
                    <tr><td colspan="13"><?php echo esc_html__('Keine aktiven Stellen gefunden.', 'bs-awo-jobs-statistik'); ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>

            <?php if ($activeTab === 'charts'): ?>
                <?php
                $vzaVerlauf = $vza->berechneVzaVerlauf(90);
                $chartVzaLabels = array_keys($vzaVerlauf);
                $chartVzaData = array_values($vzaVerlauf);
                $chartFlukLabels = array_map(static fn ($r) => wp_strip_all_tags(mb_substr($r['titel'], 0, 35) . (mb_strlen($r['titel']) > 35 ? '…' : '')), $top10);
                $chartFlukData = array_column($top10, 'anzahl_ausschreibungen');
                $chartVakanzLabels = array_map(static fn ($r) => $r['stellennummer'] . ' (' . mb_substr($r['titel'], 0, 25) . (mb_strlen($r['titel']) > 25 ? '…' : '') . ')', array_slice($offen, 0, 10));
                $chartVakanzData = array_map(static fn ($r) => $r['tage_offen'], array_slice($offen, 0, 10));
                $nachIntern = $aktuell['nach_intern'] ?? [];
                arsort($nachIntern);
                $chartFachbereichLabels = array_keys($nachIntern);
                $chartFachbereichData = array_values($nachIntern);
                ?>
                <div class="bs-awo-charts" style="display:grid;grid-template-columns:1fr;gap:2rem;">
                    <div>
                        <h3><?php echo esc_html__('VZÄ-Verlauf (letzte 90 Tage)', 'bs-awo-jobs-statistik'); ?></h3>
                        <p class="description"><?php echo esc_html__('Gesamt-VZÄ offener Stellen je Snapshot-Datum. Erfordert tägliche API-Snapshots.', 'bs-awo-jobs-statistik'); ?></p>
                        <?php if (empty($vzaVerlauf)): ?>
                            <p class="notice notice-info inline"><?php echo esc_html__('Keine Snapshot-Daten. API regelmäßig synchronisieren, damit der VZÄ-Verlauf angezeigt wird.', 'bs-awo-jobs-statistik'); ?></p>
                        <?php endif; ?>
                        <div style="max-width:800px;height:300px;">
                            <canvas id="bs-awo-chart-vza"></canvas>
                        </div>
                    </div>
                    <div>
                        <h3><?php echo esc_html__('Top 10 Fluktuationsstellen', 'bs-awo-jobs-statistik'); ?></h3>
                        <p class="description"><?php echo esc_html__('Logische Stellen mit den meisten Ausschreibungen.', 'bs-awo-jobs-statistik'); ?></p>
                        <div style="max-width:800px;height:300px;">
                            <canvas id="bs-awo-chart-fluktuation"></canvas>
                        </div>
                    </div>
                    <div>
                        <h3><?php echo esc_html__('Längste offene Vakanzen', 'bs-awo-jobs-statistik'); ?></h3>
                        <p class="description"><?php echo esc_html__('Top 10 Stellen nach Dauer (Tage offen).', 'bs-awo-jobs-statistik'); ?></p>
                        <div style="max-width:800px;height:300px;">
                            <canvas id="bs-awo-chart-vakanzen"></canvas>
                        </div>
                    </div>
                    <div>
                        <h3><?php echo esc_html__('Offene Stellen nach Fachbereich (Mandantenfeld)', 'bs-awo-jobs-statistik'); ?></h3>
                        <p class="description"><?php echo esc_html__('VZÄ-Verteilung nach internem Kürzel / Mandantenfeld.', 'bs-awo-jobs-statistik'); ?></p>
                        <div style="max-width:500px;height:350px;">
                            <canvas id="bs-awo-chart-fachbereich"></canvas>
                        </div>
                    </div>
                </div>
                <?php
                wp_localize_script('chartjs', 'bsAwoChartsData', [
                    'vzaLabels' => $chartVzaLabels,
                    'vzaData' => array_map('floatval', $chartVzaData),
                    'flukLabels' => $chartFlukLabels,
                    'flukData' => array_map('intval', $chartFlukData),
                    'vakanzLabels' => $chartVakanzLabels,
                    'vakanzData' => array_map('intval', $chartVakanzData),
                    'fachbereichLabels' => $chartFachbereichLabels,
                    'fachbereichData' => array_map('floatval', $chartFachbereichData),
                ]);
                wp_add_inline_script('chartjs', "(function(){if(typeof Chart==='undefined')return;var d=window.bsAwoChartsData||{};var c1=document.getElementById('bs-awo-chart-vza');var c2=document.getElementById('bs-awo-chart-fluktuation');var c3=document.getElementById('bs-awo-chart-vakanzen');var c4=document.getElementById('bs-awo-chart-fachbereich');var pieColors=['#2271b1','#00a32a','#d63638','#dba617','#72aee6','#2c3338','#50575e','#787c82'];if(c1)new Chart(c1,{type:'line',data:{labels:d.vzaLabels||[],datasets:[{label:'VZÄ',data:d.vzaData||[],borderColor:'#2271b1',backgroundColor:'rgba(34,113,177,0.1)',fill:true,tension:0.3}]},options:{responsive:true,maintainAspectRatio:false,scales:{y:{beginAtZero:true}}}});if(c2)new Chart(c2,{type:'bar',data:{labels:d.flukLabels||[],datasets:[{label:'Ausschreibungen',data:d.flukData||[],backgroundColor:'#00a32a'}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,scales:{x:{beginAtZero:true,ticks:{stepSize:1}}}}});if(c3)new Chart(c3,{type:'bar',data:{labels:d.vakanzLabels||[],datasets:[{label:'Tage offen',data:d.vakanzData||[],backgroundColor:'#d63638'}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,scales:{x:{beginAtZero:true,ticks:{stepSize:1}}}}});if(c4&&d.fachbereichLabels&&d.fachbereichLabels.length)new Chart(c4,{type:'pie',data:{labels:d.fachbereichLabels,datasets:[{data:d.fachbereichData||[],backgroundColor:pieColors,borderWidth:1}]},options:{responsive:true,maintainAspectRatio:false,plugins:{tooltip:{callbacks:{label:function(ctx){return ctx.label+': '+ctx.raw.toFixed(2)+' VZÄ';}}}}}});})();");
                ?>
            <?php endif; ?>

            <?php if ($activeTab === 'fluktuation'): ?>
                <p style="margin-bottom:1rem;"><a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['bs_export' => 'fluktuation', 'bs_tab' => 'fluktuation'], admin_url('admin.php?page=' . self::PAGE_DASHBOARD)), 'bs_awo_export_fluktuation')); ?>" class="button"><?php echo esc_html__('Als Excel exportieren', 'bs-awo-jobs-statistik'); ?></a></p>
                <h2><?php echo esc_html__('Top 10 Fluktuationsstellen', 'bs-awo-jobs-statistik'); ?></h2>
                <p class="description"><?php echo esc_html__('Logische Stellen mit den meisten Ausschreibungen. Stellennummern: online zuerst, dann offline (jeweils neueste zuerst).', 'bs-awo-jobs-statistik'); ?></p>
                <table class="widefat striped">
                    <thead><tr><th>#</th><th><?php echo esc_html__('Titel', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('Einrichtung', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('PLZ', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('Ausschreibungen', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('Stellennummern', 'bs-awo-jobs-statistik'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($top10 as $i => $row):
                        $sns = $stellennummernByLog[$row['logische_stelle_id']] ?? [];
                        $snPreview = implode(', ', array_slice($sns, 0, 3)) . (count($sns) > 3 ? ' …' : '');
                        $plzStr = $plzByLog[$row['logische_stelle_id']] ?? '';
                    ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><?php echo esc_html($row['titel']); ?></td>
                            <td><?php echo esc_html($row['einrichtung']); ?></td>
                            <td><code><?php echo esc_html($plzStr ?: '–'); ?></code></td>
                            <td><?php echo esc_html((string) $row['anzahl_ausschreibungen']); ?></td>
                            <td><code style="font-size:11px;"><?php echo esc_html($snPreview ?: '–'); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($top10)): ?>
                        <tr><td colspan="6"><?php echo esc_html__('Keine Daten.', 'bs-awo-jobs-statistik'); ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                <?php if (!empty($top10)): ?>
                <details style="margin-top:1.5rem;">
                    <summary style="cursor:pointer;font-weight:600;"><?php echo esc_html__('Alle Stellennummern anzeigen', 'bs-awo-jobs-statistik'); ?></summary>
                    <table class="widefat striped" style="margin-top:0.5rem;">
                        <thead><tr><th>#</th><th><?php echo esc_html__('Titel', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('Einrichtung', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('PLZ', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('Ausschreibungen', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('Stellennummern', 'bs-awo-jobs-statistik'); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($top10 as $i => $row):
                            $sns = $stellennummernByLog[$row['logische_stelle_id']] ?? [];
                            $plzStr = $plzByLog[$row['logische_stelle_id']] ?? '';
                        ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td><?php echo esc_html($row['titel']); ?></td>
                                <td><?php echo esc_html($row['einrichtung']); ?></td>
                                <td><code><?php echo esc_html($plzStr ?: '–'); ?></code></td>
                                <td><?php echo esc_html((string) $row['anzahl_ausschreibungen']); ?></td>
                                <td><code><?php echo esc_html(implode(', ', $sns)); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($activeTab === 'vakanzen'): ?>
                <p style="margin-bottom:1rem;"><a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['bs_export' => 'vakanzen', 'bs_tab' => 'vakanzen'], admin_url('admin.php?page=' . self::PAGE_DASHBOARD)), 'bs_awo_export_vakanzen')); ?>" class="button"><?php echo esc_html__('Als Excel exportieren', 'bs-awo-jobs-statistik'); ?></a></p>
                <h2><?php echo esc_html__('Offene Vakanzen', 'bs-awo-jobs-statistik'); ?></h2>
                <table class="widefat striped">
                    <thead><tr><th><?php echo esc_html__('Stellennr.', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('Tage', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('Titel', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('Einrichtung', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('Ort', 'bs-awo-jobs-statistik'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($offenTop as $row): ?>
                        <tr>
                            <td><code><?php echo esc_html($row['stellennummer']); ?></code></td>
                            <td><?php echo esc_html((string) $row['tage_offen']); ?></td>
                            <td><?php echo esc_html($row['titel']); ?></td>
                            <td><?php echo esc_html($row['einrichtung']); ?></td>
                            <td><?php echo esc_html(trim(($row['plz_einsatzort'] ?? '') . ' ' . ($row['einsatzort'] ?? '')) ?: '–'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (count($offen) > 10): foreach (array_slice($offen, 10) as $row): ?>
                        <tr>
                            <td><code><?php echo esc_html($row['stellennummer']); ?></code></td>
                            <td><?php echo esc_html((string) $row['tage_offen']); ?></td>
                            <td><?php echo esc_html($row['titel']); ?></td>
                            <td><?php echo esc_html($row['einrichtung']); ?></td>
                            <td><?php echo esc_html(trim(($row['plz_einsatzort'] ?? '') . ' ' . ($row['einsatzort'] ?? '')) ?: '–'); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    <?php if (empty($offen)): ?>
                        <tr><td colspan="5"><?php echo esc_html__('Keine offenen Stellen.', 'bs-awo-jobs-statistik'); ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ($activeTab === 'fachbereiche'): ?>
                <p style="margin-bottom:1rem;"><a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['bs_export' => 'fachbereiche', 'bs_tab' => 'fachbereiche'], admin_url('admin.php?page=' . self::PAGE_DASHBOARD)), 'bs_awo_export_fachbereiche')); ?>" class="button"><?php echo esc_html__('Als Excel exportieren', 'bs-awo-jobs-statistik'); ?></a></p>
                <h2><?php echo esc_html__('VZÄ nach Fachbereich (Stellenbörse)', 'bs-awo-jobs-statistik'); ?></h2>
                <p class="description"><?php echo esc_html__('Aktuell offene Stellen gruppiert nach Fachbereich der Stellenbörse.', 'bs-awo-jobs-statistik'); ?></p>
                <table class="widefat striped">
                    <thead><tr><th><?php echo esc_html__('Fachbereich', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('VZÄ', 'bs-awo-jobs-statistik'); ?></th></tr></thead>
                    <tbody>
                    <?php
                    $nachBoerse = $aktuell['nach_boerse'] ?? [];
                    arsort($nachBoerse);
                    foreach ($nachBoerse as $fb => $vzaVal): ?>
                        <tr><td><?php echo esc_html($fb); ?></td><td><?php echo esc_html(number_format($vzaVal, 2, ',', '.')); ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($nachBoerse)): ?>
                        <tr><td colspan="2"><?php echo esc_html__('Keine Daten.', 'bs-awo-jobs-statistik'); ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                <h2 style="margin-top:2rem;"><?php echo esc_html__('VZÄ nach Mandantenfeld (internes Kürzel)', 'bs-awo-jobs-statistik'); ?></h2>
                <p class="description"><?php echo esc_html__('Aktuell offene Stellen gruppiert nach internem Kürzel / Mandantenfeld.', 'bs-awo-jobs-statistik'); ?></p>
                <table class="widefat striped">
                    <thead><tr><th><?php echo esc_html__('Mandantenfeld', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('VZÄ', 'bs-awo-jobs-statistik'); ?></th></tr></thead>
                    <tbody>
                    <?php
                    $nachIntern = $aktuell['nach_intern'] ?? [];
                    arsort($nachIntern);
                    foreach ($nachIntern as $fb => $vzaVal): ?>
                        <tr><td><?php echo esc_html($fb); ?></td><td><?php echo esc_html(number_format($vzaVal, 2, ',', '.')); ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($nachIntern)): ?>
                        <tr><td colspan="2"><?php echo esc_html__('Keine Daten.', 'bs-awo-jobs-statistik'); ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>

                <?php
                $vzaProEinrichtung = $vza->berechneVzaProEinrichtung();
                $fbSource = sanitize_key($_GET['bs_fb_source'] ?? 'boerse');
                if ($fbSource !== 'boerse' && $fbSource !== 'intern') {
                    $fbSource = 'boerse';
                }
                $fachbereicheList = $fbSource === 'boerse' ? array_keys($vzaProEinrichtung['boerse']) : array_keys($vzaProEinrichtung['intern']);
                $selectedFb = isset($_GET['bs_fb']) ? sanitize_text_field(wp_unslash($_GET['bs_fb'])) : '';
                if ($selectedFb !== '' && !in_array($selectedFb, $fachbereicheList, true)) {
                    $selectedFb = $fachbereicheList[0] ?? '';
                }
                if ($selectedFb === '' && $fachbereicheList !== []) {
                    $selectedFb = $fachbereicheList[0];
                }
                $einrichtungenRows = [];
                if ($selectedFb !== '') {
                    $einrichtungenRows = $vzaProEinrichtung[$fbSource][$selectedFb] ?? [];
                }
                ?>
                <h2 style="margin-top:2rem;"><?php echo esc_html__('VZÄ pro Einrichtung (gefiltert nach Fachbereich)', 'bs-awo-jobs-statistik'); ?></h2>
                <p class="description"><?php echo esc_html__('Wählen Sie einen Fachbereich, um die zugehörigen Einrichtungen mit ihren VZÄ anzuzeigen.', 'bs-awo-jobs-statistik'); ?></p>
                <form method="get" style="margin-bottom:1.5rem;">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_DASHBOARD); ?>">
                    <input type="hidden" name="bs_tab" value="fachbereiche">
                    <label style="margin-right:1rem;"><?php echo esc_html__('Quelle', 'bs-awo-jobs-statistik'); ?>:
                        <select name="bs_fb_source" onchange="this.form.submit()">
                            <option value="boerse" <?php selected($fbSource, 'boerse'); ?>><?php echo esc_html__('Stellenbörse', 'bs-awo-jobs-statistik'); ?></option>
                            <option value="intern" <?php selected($fbSource, 'intern'); ?>><?php echo esc_html__('Mandantenfeld (internes Kürzel)', 'bs-awo-jobs-statistik'); ?></option>
                        </select>
                    </label>
                    <label><?php echo esc_html__('Fachbereich', 'bs-awo-jobs-statistik'); ?>:
                        <select name="bs_fb" onchange="this.form.submit()">
                            <?php foreach ($fachbereicheList as $fb): ?>
                                <option value="<?php echo esc_attr($fb); ?>" <?php selected($selectedFb, $fb); ?>><?php echo esc_html($fb); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>
                <table class="widefat striped">
                    <thead><tr><th><?php echo esc_html__('Einrichtung', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('VZÄ', 'bs-awo-jobs-statistik'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($einrichtungenRows as $einr => $vzaVal): ?>
                        <tr><td><?php echo esc_html($einr); ?></td><td><?php echo esc_html(number_format($vzaVal, 2, ',', '.')); ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($einrichtungenRows)): ?>
                        <tr><td colspan="2"><?php echo esc_html__('Keinen Fachbereich gewählt oder keine Einrichtungen.', 'bs-awo-jobs-statistik'); ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ($activeTab === 'plz'): ?>
                <p style="margin-bottom:1rem;"><a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['bs_export' => 'plz', 'bs_tab' => 'plz'], admin_url('admin.php?page=' . self::PAGE_DASHBOARD)), 'bs_awo_export_plz')); ?>" class="button"><?php echo esc_html__('Als Excel exportieren', 'bs-awo-jobs-statistik'); ?></a></p>
                <h2><?php echo esc_html__('Statistik nach Postleitzahl', 'bs-awo-jobs-statistik'); ?></h2>
                <p class="description"><?php echo esc_html__('Aktuell offene Stellen nach PLZ. Stellennummern und Stellentitel zur gezielten Suche.', 'bs-awo-jobs-statistik'); ?></p>
                <?php if (!empty($plzStats)): ?>
                <table class="widefat striped">
                    <thead><tr><th><?php echo esc_html__('PLZ', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('Ort', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('Anzahl', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('VZÄ', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('Stellennummern', 'bs-awo-jobs-statistik'); ?></th><th><?php echo esc_html__('Stellentitel', 'bs-awo-jobs-statistik'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($plzStats as $r): ?>
                        <tr>
                            <td><code><?php echo esc_html($r['plz']); ?></code></td>
                            <td><?php echo esc_html($r['einsatzort'] ?? '–'); ?></td>
                            <td><?php echo esc_html((string) $r['anzahl']); ?></td>
                            <td><?php echo esc_html(number_format($r['vza_summe'], 2, ',', '.')); ?></td>
                            <td><code style="font-size:11px;"><?php echo esc_html($r['stellennummern'] ?? ''); ?></code></td>
                            <td style="font-size:11px;"><?php echo esc_html($r['titel_liste'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p><?php echo esc_html__('Keine Stellen mit erfasster Postleitzahl.', 'bs-awo-jobs-statistik'); ?></p>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($activeTab === 'einrichtung_auswertung'): ?>
                <h2><?php echo esc_html__('Einrichtungen: Soll-VZÄ, offene VZÄ, besetzte VZÄ', 'bs-awo-jobs-statistik'); ?></h2>
                <p class="description"><?php echo esc_html__('Anzeige pro kanonischer Master-Einrichtung: Soll-VZÄ nur aus dem Master-Stammdatensatz. Offene VZÄ summieren alle berücksichtigten aktiven Stellen, deren Einrichtungsname zu diesem Master oder einem zugeordneten Alias-Datensatz passt (gleicher Name bzw. gleicher Match-Key). Besetzt = Soll − Offen. VZÄ-Berechnung wie in „Aktive Stellen“.', 'bs-awo-jobs-statistik'); ?></p>

                <form method="get" style="margin:1rem 0 1.5rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.75rem;align-items:end;">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_DASHBOARD); ?>">
                    <input type="hidden" name="bs_tab" value="einrichtung_auswertung">

                    <label>
                        <?php echo esc_html__('Suche', 'bs-awo-jobs-statistik'); ?><br>
                        <input type="text" name="bs_eva_q" value="<?php echo esc_attr($evaFilter->q); ?>" placeholder="<?php echo esc_attr__('Name, Mandantenfeld, Bemerkung …', 'bs-awo-jobs-statistik'); ?>" style="width:100%;">
                    </label>

                    <label>
                        <?php echo esc_html__('Fachbereich (Stellenbörse)', 'bs-awo-jobs-statistik'); ?><br>
                        <select name="bs_eva_fb" style="width:100%;">
                            <option value=""><?php echo esc_html__('Alle', 'bs-awo-jobs-statistik'); ?></option>
                            <?php foreach ($evaFbOptions as $fb): ?>
                                <option value="<?php echo esc_attr($fb); ?>" <?php selected($evaFilter->fachbereichBoerse, $fb); ?>><?php echo esc_html($fb); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        <?php echo esc_html__('Mandantenfeld', 'bs-awo-jobs-statistik'); ?><br>
                        <select name="bs_eva_fbi" style="width:100%;">
                            <option value=""><?php echo esc_html__('Alle', 'bs-awo-jobs-statistik'); ?></option>
                            <?php foreach ($evaMandantOptions as $fbi): ?>
                                <option value="<?php echo esc_attr($fbi); ?>" <?php selected($evaFilter->mandantenfeld, $fbi); ?>><?php echo esc_html($fbi); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        <?php echo esc_html__('Einrichtung', 'bs-awo-jobs-statistik'); ?><br>
                        <select name="bs_eva_einr" style="width:100%;">
                            <option value=""><?php echo esc_html__('Alle', 'bs-awo-jobs-statistik'); ?></option>
                            <?php foreach ($evaEinrichtungOptions as $en): ?>
                                <option value="<?php echo esc_attr($en); ?>" <?php selected($evaFilter->einrichtung, $en); ?>><?php echo esc_html($en); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        <?php echo esc_html__('Stammdaten: Aktiv', 'bs-awo-jobs-statistik'); ?><br>
                        <select name="bs_eva_aktiv" style="width:100%;">
                            <option value=""><?php echo esc_html__('Alle', 'bs-awo-jobs-statistik'); ?></option>
                            <option value="1" <?php selected($evaFilter->aktiv, '1'); ?>><?php echo esc_html__('Aktiv', 'bs-awo-jobs-statistik'); ?></option>
                            <option value="0" <?php selected($evaFilter->aktiv, '0'); ?>><?php echo esc_html__('Inaktiv', 'bs-awo-jobs-statistik'); ?></option>
                        </select>
                    </label>

                    <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
                        <button type="submit" class="button button-primary"><?php echo esc_html__('Filtern', 'bs-awo-jobs-statistik'); ?></button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_DASHBOARD . '&bs_tab=einrichtung_auswertung')); ?>" class="button"><?php echo esc_html__('Zurücksetzen', 'bs-awo-jobs-statistik'); ?></a>
                    </div>
                </form>

                <table class="widefat striped">
                    <thead>
                    <tr>
                        <th><?php echo esc_html__('Einrichtung (Master)', 'bs-awo-jobs-statistik'); ?></th>
                        <th><?php echo esc_html__('Fachbereich Börse', 'bs-awo-jobs-statistik'); ?></th>
                        <th><?php echo esc_html__('Mandantenfeld', 'bs-awo-jobs-statistik'); ?></th>
                        <th><?php echo esc_html__('Stamm-Aliase', 'bs-awo-jobs-statistik'); ?></th>
                        <th><?php echo esc_html__('Soll-VZÄ', 'bs-awo-jobs-statistik'); ?></th>
                        <th><?php echo esc_html__('Offene VZÄ', 'bs-awo-jobs-statistik'); ?></th>
                        <th><?php echo esc_html__('Besetzte VZÄ', 'bs-awo-jobs-statistik'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($evaRows as $er): ?>
                        <tr>
                            <td><strong><?php echo esc_html((string) ($er['einrichtung'] ?? '')); ?></strong></td>
                            <td><?php echo esc_html((string) ($er['fachbereich_boerse'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($er['fachbereich_intern'] ?? '–')); ?></td>
                            <td><?php echo esc_html((string) (int) ($er['kanonisch_alias_anzahl'] ?? 0)); ?></td>
                            <td><?php echo esc_html(number_format((float) ($er['soll_vza_effektiv'] ?? 0), 2, ',', '.')); ?></td>
                            <td><?php echo esc_html(number_format((float) ($er['offen_vza'] ?? 0), 2, ',', '.')); ?></td>
                            <td><?php echo esc_html(number_format((float) ($er['besetzt_vza'] ?? 0), 2, ',', '.')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($evaRows === []): ?>
                        <tr><td colspan="7"><?php echo esc_html__('Keine Einrichtungen für den aktuellen Filter.', 'bs-awo-jobs-statistik'); ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            </div>
        </div>
        <?php
    }
    
    private function maybeHandleAktiveStellenToggle(): void
    {
        if (!isset($_POST['bs_awo_action']) || $_POST['bs_awo_action'] !== 'toggle_aktive_stelle') {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        if (!wp_verify_nonce($_POST['bs_awo_toggle_nonce'] ?? '', 'bs_awo_toggle_aktive_stelle')) {
            return;
        }

        $stellennummer = sanitize_text_field($_POST['stellennummer'] ?? '');
        if ($stellennummer === '') {
            return;
        }

        $wert = isset($_POST['in_statistik_beruecksichtigen']) && (string) $_POST['in_statistik_beruecksichtigen'] === '0' ? 0 : 1;

        $tblA = $this->wpdb->prefix . Database::TABLE_AUSSCHREIBUNGEN;
        $this->wpdb->update(
            $tblA,
            ['in_statistik_beruecksichtigen' => $wert],
            ['stellennummer' => $stellennummer],
            ['%d'],
            ['%s']
        );

        $redirectArgs = [
            'page' => self::PAGE_DASHBOARD,
            'bs_tab' => 'aktive_stellen',
        ];

        foreach (['bs_as_q', 'bs_as_fb', 'bs_as_fbi', 'bs_as_einr', 'bs_as_plz', 'bs_as_sq'] as $param) {
            if (isset($_POST[$param]) && $_POST[$param] !== '') {
                $redirectArgs[$param] = sanitize_text_field(wp_unslash($_POST[$param]));
            }
        }

        nocache_headers();
        wp_safe_redirect(add_query_arg($redirectArgs, admin_url('admin.php')), 303);
        exit;
    }

    public function renderImport(): void
    {
        $this->maybeHandleImport();
        $this->maybeHandleApiSync();
        ?>
        <div class="wrap" style="position:relative;">
            <div id="bs-awo-import-overlay" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(255,255,255,0.85);z-index:100000;align-items:center;justify-content:center;flex-direction:column;display:none;">
                <span class="spinner is-active" style="float:none;margin:0 0 1rem;display:block;width:40px;height:40px;"></span>
                <p style="font-size:1.1rem;margin:0;"><?php echo esc_html__('Import wird ausgeführt, bitte warten…', 'bs-awo-jobs-statistik'); ?></p>
            </div>
            <h1><?php echo esc_html__('Import', 'bs-awo-jobs-statistik'); ?></h1>

            <div class="card" style="max-width:600px;padding:1.5rem;margin:1rem 0;">
                <h2><?php echo esc_html__('Excel/CSV-Upload', 'bs-awo-jobs-statistik'); ?></h2>
                <form id="bs-awo-excel-import-form" method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('bs_awo_jobs_excel_import', 'bs_awo_excel_nonce'); ?>
                    <input type="hidden" name="bs_awo_action" value="excel_import">
                    <p><input type="file" name="excel_file" accept=".xlsx,.xls,.csv"></p>
                    <?php submit_button(__('Datei importieren', 'bs-awo-jobs-statistik')); ?>
                </form>
            </div>

            <div class="card" style="max-width:600px;padding:1.5rem;margin:1rem 0;">
                <h2><?php echo esc_html__('API synchronisieren', 'bs-awo-jobs-statistik'); ?></h2>
                <p><?php echo esc_html__('API abrufen, Stundenzahlen ergänzen und Snapshot schreiben.', 'bs-awo-jobs-statistik'); ?></p>
                <form id="bs-awo-api-sync-form" method="post">
                    <?php wp_nonce_field('bs_awo_jobs_api_sync', 'bs_awo_api_nonce'); ?>
                    <input type="hidden" name="bs_awo_action" value="api_sync">
                    <?php submit_button(__('API jetzt synchronisieren', 'bs-awo-jobs-statistik')); ?>
                </form>
            </div>
        </div>
        <style>#bs-awo-import-overlay.is-visible{display:flex!important;}</style>
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
                    (SELECT GROUP_CONCAT(z.stellennummer ORDER BY z.stellennummer) FROM {$tblZ} z WHERE z.logische_stelle_id = l.id) AS stellennummern,
                    (SELECT COUNT(*) FROM {$tblZ} z2 JOIN {$tblA} a ON a.stellennummer = z2.stellennummer WHERE z2.logische_stelle_id = l.id AND a.zuletzt_gesehen_api IS NOT NULL) > 0 AS hat_online
             FROM {$tblL} l
             ORDER BY hat_online DESC, l.id
             LIMIT 500",
            ARRAY_A
        );

        $alleLogischen = $this->wpdb->get_results("SELECT id, titel, einrichtung FROM {$tblL} ORDER BY titel, einrichtung", ARRAY_A);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Logische Stellen', 'bs-awo-jobs-statistik'); ?></h1>

            <p><?php echo esc_html__('Automatisch gruppiert nach Titel + Einrichtung. Manuell zuordnen oder trennen.', 'bs-awo-jobs-statistik'); ?></p>

            <div style="margin-bottom:1rem;display:flex;flex-wrap:wrap;gap:1rem;align-items:center;">
                <label>
                    <?php echo esc_html__('Suchen', 'bs-awo-jobs-statistik'); ?>:
                    <input type="search" id="bs-awo-logische-suche" placeholder="<?php echo esc_attr__('Titel, Einrichtung, Stellennummer…', 'bs-awo-jobs-statistik'); ?>" style="width:220px;margin-left:0.25rem;">
                </label>
                <label>
                    <?php echo esc_html__('Filter', 'bs-awo-jobs-statistik'); ?>:
                    <select id="bs-awo-logische-filter" style="margin-left:0.25rem;">
                        <option value=""><?php echo esc_html__('Alle', 'bs-awo-jobs-statistik'); ?></option>
                        <option value="online"><?php echo esc_html__('Nur Online', 'bs-awo-jobs-statistik'); ?></option>
                        <option value="offline"><?php echo esc_html__('Nur Offline', 'bs-awo-jobs-statistik'); ?></option>
                        <option value="verifiziert"><?php echo esc_html__('Nur verifiziert', 'bs-awo-jobs-statistik'); ?></option>
                        <option value="automatisch"><?php echo esc_html__('Nur automatisch', 'bs-awo-jobs-statistik'); ?></option>
                    </select>
                </label>
                <span id="bs-awo-logische-treffer" class="description" style="margin-left:0.5rem;"></span>
            </div>

            <table class="widefat striped" id="bs-awo-logische-tabelle">
                <thead>
                <tr>
                    <th><?php echo esc_html__('ID', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Titel', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Einrichtung', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Aktuell', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Zuordnung', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Stellennummern', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Aktionen', 'bs-awo-jobs-statistik'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows ?: [] as $r):
                    $sns = $r['stellennummern'] ? explode(',', $r['stellennummern']) : [];
                    $verifiziert = !empty($r['manuell_verifiziert']);
                    $online = !empty($r['hat_online']);
                    $rowStyle = $online ? '' : 'background:#f0f0f1;';
                    $searchText = strtolower(implode(' ', [($r['titel'] ?? ''), ($r['einrichtung'] ?? ''), ($r['stellennummern'] ?? '')]));
                    ?>
                    <tr class="bs-awo-logische-row" style="<?php echo esc_attr($rowStyle); ?>" data-online="<?php echo $online ? '1' : '0'; ?>" data-verifiziert="<?php echo $verifiziert ? '1' : '0'; ?>" data-search="<?php echo esc_attr($searchText); ?>">
                        <td><?php echo esc_html($r['id']); ?></td>
                        <td><?php echo esc_html($r['titel']); ?></td>
                        <td><?php echo esc_html($r['einrichtung']); ?></td>
                        <td>
                            <span class="badge" style="background:<?php echo $online ? '#00a32a' : '#8c8f94'; ?>;color:#fff;padding:2px 8px;border-radius:3px;" title="<?php echo $online ? esc_attr__('Mindestens eine Ausschreibung ist aktuell online', 'bs-awo-jobs-statistik') : esc_attr__('Alle Ausschreibungen dieser logischen Stelle sind offline', 'bs-awo-jobs-statistik'); ?>">
                                <?php echo $online ? esc_html__('Online', 'bs-awo-jobs-statistik') : esc_html__('Offline', 'bs-awo-jobs-statistik'); ?>
                            </span>
                        </td>
                        <td><span class="badge" style="background:<?php echo $verifiziert ? '#2271b1' : '#dba617'; ?>;color:#fff;padding:2px 8px;border-radius:3px;"><?php echo $verifiziert ? esc_html__('verifiziert', 'bs-awo-jobs-statistik') : esc_html__('automatisch', 'bs-awo-jobs-statistik'); ?></span></td>
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
                    <tr><td colspan="7"><?php echo esc_html__('Keine logischen Stellen.', 'bs-awo-jobs-statistik'); ?></td></tr>
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

    public function renderEinrichtungenStamm(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $repo = new EinrichtungenStammRepository($this->wpdb);

        $notice = isset($_GET['bs_awo_notice']) ? sanitize_key((string) $_GET['bs_awo_notice']) : '';
        if ($notice === 'saved') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Stammdaten gespeichert.', 'bs-awo-jobs-statistik') . '</p></div>';
        } elseif ($notice === 'seeded') {
            $neu = isset($_GET['bs_awo_notice_neu']) ? (int) $_GET['bs_awo_notice_neu'] : 0;
            $bereits = isset($_GET['bs_awo_notice_bereits']) ? (int) $_GET['bs_awo_notice_bereits'] : 0;
            $pruef = isset($_GET['bs_awo_notice_pruef']) ? (int) $_GET['bs_awo_notice_pruef'] : 0;
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
                /* translators: 1: neue Zeilen gesamt, 2: ohne Insert zugeordnet, 3: Prüffälle (Teilmenge von 1) */
                __('Übernahme abgeschlossen: %1$d neue Zeilen angelegt (davon %3$d Prüffälle). %2$d Einrichtungen waren bereits eindeutig zugeordnet (nur „Letzter API-Name“/Zeitstempel aktualisiert).', 'bs-awo-jobs-statistik'),
                $neu,
                $bereits,
                $pruef
            )) . '</p></div>';
        } elseif ($notice === 'duplicate_exact' || $notice === 'duplicate_normalized') {
            $cid = isset($_GET['bs_awo_conflict_id']) ? (int) $_GET['bs_awo_conflict_id'] : 0;
            $other = $cid > 0 ? $repo->getById($cid) : null;
            $fremdName = $other ? (string) ($other['einrichtung'] ?? '') : '';
            if ($notice === 'duplicate_exact') {
                $msg = sprintf(
                    /* translators: 1: ID, 2: existing facility name */
                    __('Speichern abgebrochen: Es gibt bereits eine Einrichtung mit genau diesem Namen (Datensatz %1$d: %2$s).', 'bs-awo-jobs-statistik'),
                    $cid,
                    $fremdName !== '' ? $fremdName : '–'
                );
            } else {
                $msg = sprintf(
                    /* translators: 1: ID, 2: existing facility name */
                    __('Speichern abgebrochen: Es gibt bereits eine Einrichtung mit demselben normalisierten Schlüssel (wie bei Matching/Übernahme). Bestehender Datensatz %1$d: %2$s. Bitte diesen Datensatz bearbeiten oder den Namen fachlich eindeutig machen. Es wird nichts zusammengeführt und keine Dublette angelegt.', 'bs-awo-jobs-statistik'),
                    $cid,
                    $fremdName !== '' ? $fremdName : '–'
                );
            }
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        } elseif ($notice === 'master_invalid') {
            $detail = isset($_GET['bs_awo_master_msg']) ? sanitize_text_field(wp_unslash(rawurldecode((string) $_GET['bs_awo_master_msg']))) : '';
            $msg = $detail !== '' ? $detail : __('Die Master-Zuordnung konnte nicht gespeichert werden.', 'bs-awo-jobs-statistik');
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }

        $editId = isset($_GET['bs_es_edit']) ? (int) $_GET['bs_es_edit'] : 0;

        $esView = isset($_GET['bs_es_view']) ? sanitize_key(wp_unslash((string) $_GET['bs_es_view'])) : '';
        if ($esView !== '' && $esView !== 'alle') {
            $esView = '';
        }
        $esListenModus = ($esView === 'alle') ? '' : 'wurzel';

        $filter = new EinrichtungenStammFilterInput(
            isset($_GET['bs_es_q']) ? sanitize_text_field(wp_unslash($_GET['bs_es_q'])) : '',
            isset($_GET['bs_es_fb']) ? sanitize_text_field(wp_unslash($_GET['bs_es_fb'])) : '',
            isset($_GET['bs_es_fbi']) ? sanitize_text_field(wp_unslash($_GET['bs_es_fbi'])) : '',
            isset($_GET['bs_es_aktiv']) ? sanitize_key(wp_unslash($_GET['bs_es_aktiv'])) : '',
            isset($_GET['bs_es_einr']) ? sanitize_text_field(wp_unslash($_GET['bs_es_einr'])) : '',
            isset($_GET['bs_es_pruef']) ? sanitize_key(wp_unslash($_GET['bs_es_pruef'])) : '',
            $esListenModus
        );
        if ($filter->aktiv !== '' && $filter->aktiv !== '1' && $filter->aktiv !== '0') {
            $filter = new EinrichtungenStammFilterInput(
                $filter->q,
                $filter->fachbereichBoerse,
                $filter->mandantenfeld,
                '',
                $filter->einrichtung,
                $filter->pruefstatus,
                $filter->listenModus
            );
        }
        if ($filter->pruefstatus !== '' && !in_array($filter->pruefstatus, [EinrichtungenMatching::PRUEFSTATUS_OK, EinrichtungenMatching::PRUEFSTATUS_PRUEFEN], true)) {
            $filter = new EinrichtungenStammFilterInput(
                $filter->q,
                $filter->fachbereichBoerse,
                $filter->mandantenfeld,
                $filter->aktiv,
                $filter->einrichtung,
                '',
                $filter->listenModus
            );
        }

        $mandantenOptions = $repo->mergedMandantenfeldOptions();
        $fbBoerseOptions = $repo->mergedFachbereichBoerseOptions();
        $einrichtungOptionsStamm = $filter->listenModus === 'wurzel'
            ? $repo->mergedEinrichtungOptionsFuerMasterliste()
            : $repo->mergedEinrichtungOptions();

        $filterQueryBase = [
            'page' => self::PAGE_EINRICHTUNGEN,
        ];
        if ($esView === 'alle') {
            $filterQueryBase['bs_es_view'] = 'alle';
        }
        foreach (['bs_es_q', 'bs_es_fb', 'bs_es_fbi', 'bs_es_aktiv', 'bs_es_einr', 'bs_es_pruef'] as $p) {
            if ($p === 'bs_es_q') {
                $val = $filter->q;
            } elseif ($p === 'bs_es_fb') {
                $val = $filter->fachbereichBoerse;
            } elseif ($p === 'bs_es_fbi') {
                $val = $filter->mandantenfeld;
            } elseif ($p === 'bs_es_einr') {
                $val = $filter->einrichtung;
            } elseif ($p === 'bs_es_pruef') {
                $val = $filter->pruefstatus;
            } else {
                $val = $filter->aktiv;
            }
            if ($val !== '') {
                $filterQueryBase[$p] = $val;
            }
        }

        if ($editId > 0) {
            $row = $repo->getById($editId);
            if (!$row) {
                echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__('Eintrag nicht gefunden.', 'bs-awo-jobs-statistik') . '</p></div></div>';

                return;
            }
            ?>
            <div class="wrap bs-awo-statistik-dashboard">
                <h1><?php echo esc_html__('Einrichtung bearbeiten', 'bs-awo-jobs-statistik'); ?></h1>
                <p>
                    <a href="<?php echo esc_url(add_query_arg($filterQueryBase, admin_url('admin.php'))); ?>" class="button"><?php echo esc_html__('Zurück zur Liste', 'bs-awo-jobs-statistik'); ?></a>
                </p>
                <?php $this->renderEinrichtungStammForm($row, $filterQueryBase, $repo); ?>
            </div>
            <?php

            return;
        }

        if (isset($_GET['bs_es_new']) && $_GET['bs_es_new'] === '1') {
            ?>
            <div class="wrap bs-awo-statistik-dashboard">
                <h1><?php echo esc_html__('Neue Einrichtung', 'bs-awo-jobs-statistik'); ?></h1>
                <p>
                    <a href="<?php echo esc_url(add_query_arg($filterQueryBase, admin_url('admin.php'))); ?>" class="button"><?php echo esc_html__('Zurück zur Liste', 'bs-awo-jobs-statistik'); ?></a>
                </p>
                <?php $this->renderEinrichtungStammForm(null, $filterQueryBase, $repo); ?>
            </div>
            <?php

            return;
        }

        $rows = $repo->fetchFiltered($filter);
        $allStammForZuordnung = $repo->fetchAllOrdered();
        $idMapZuordnung = EinrichtungenStammCluster::idMap($allStammForZuordnung);
        ?>
        <div class="wrap bs-awo-statistik-dashboard">
            <h1><?php echo esc_html__('Stammdaten Einrichtungen', 'bs-awo-jobs-statistik'); ?></h1>
            <p class="description"><?php echo esc_html__('Masterliste: eine Zeile pro realem Standort (nur eigenständige Einrichtungen). Falsch geschriebene Namen aus der API landen nach Seed ggf. als eigener Datensatz — bitte unter „Bearbeiten“ beim Alias „Gehört zu (Master)“ auf die bestehende Einrichtung setzen; technisch bleibt ein Alias-Datensatz bestehen, in der Masterliste sehen Sie ihn nicht. Ansicht „Alle Datensätze“ zeigt Aliase zur Kontrolle.', 'bs-awo-jobs-statistik'); ?></p>

            <div class="card" style="padding:1rem;margin:1rem 0;max-width:920px;">
                <h2 style="margin-top:0;"><?php echo esc_html__('Fehlende Einrichtungen aus Ausschreibungen anlegen', 'bs-awo-jobs-statistik'); ?></h2>
                <p class="description"><?php echo esc_html__('Matching: gleicher normalisierter Name → keine Dublette; „Letzter API-Name“, Zeitstempel und der Match-Key aus dem Stammnamen werden gepflegt. Weicht der aggregierte API-Name oder ein Fachbereich/Mandantenfeld vom Stammsatz ab, bleibt der Stamm unverändert, es entsteht ein Prüffall mit Hinweisblock. Vermutlich ähnliche Namen erzeugen sichtbare Dubletten-Prüffälle (kein automatisches Zusammenführen). Klar neue Namen werden normal angelegt. Soll-/Bemerkungsfelder der Stammzeile bleiben unangetastet.', 'bs-awo-jobs-statistik'); ?></p>
                <form method="post">
                    <?php wp_nonce_field('bs_awo_einrichtung_seed', 'bs_awo_einrichtung_seed_nonce'); ?>
                    <input type="hidden" name="bs_awo_action" value="einrichtung_seed">
                    <?php foreach ($filterQueryBase as $k => $v): ?>
                        <?php if ($k !== 'page'): ?>
                            <input type="hidden" name="filter_ret_<?php echo esc_attr($k); ?>" value="<?php echo esc_attr((string) $v); ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php submit_button(__('Jetzt übernehmen', 'bs-awo-jobs-statistik'), 'secondary'); ?>
                </form>
            </div>

            <form method="get" style="margin:1rem 0 1.5rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.75rem;align-items:end;">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_EINRICHTUNGEN); ?>">

                <label>
                    <?php echo esc_html__('Suche', 'bs-awo-jobs-statistik'); ?><br>
                    <input type="text" name="bs_es_q" value="<?php echo esc_attr($filter->q); ?>" placeholder="<?php echo esc_attr__('Name, Mandantenfeld, Bemerkung …', 'bs-awo-jobs-statistik'); ?>" style="width:100%;">
                </label>

                <label>
                    <?php echo esc_html__('Fachbereich (Stellenbörse)', 'bs-awo-jobs-statistik'); ?><br>
                    <select name="bs_es_fb" style="width:100%;">
                        <option value=""><?php echo esc_html__('Alle', 'bs-awo-jobs-statistik'); ?></option>
                        <?php foreach ($fbBoerseOptions as $fb): ?>
                            <option value="<?php echo esc_attr($fb); ?>" <?php selected($filter->fachbereichBoerse, $fb); ?>><?php echo esc_html($fb); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <?php echo esc_html__('Mandantenfeld', 'bs-awo-jobs-statistik'); ?><br>
                    <select name="bs_es_fbi" style="width:100%;">
                        <option value=""><?php echo esc_html__('Alle', 'bs-awo-jobs-statistik'); ?></option>
                        <?php foreach ($mandantenOptions as $fbi): ?>
                            <option value="<?php echo esc_attr($fbi); ?>" <?php selected($filter->mandantenfeld, $fbi); ?>><?php echo esc_html($fbi); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <?php echo esc_html__('Status', 'bs-awo-jobs-statistik'); ?><br>
                    <select name="bs_es_aktiv" style="width:100%;">
                        <option value=""><?php echo esc_html__('Alle', 'bs-awo-jobs-statistik'); ?></option>
                        <option value="1" <?php selected($filter->aktiv, '1'); ?>><?php echo esc_html__('Aktiv', 'bs-awo-jobs-statistik'); ?></option>
                        <option value="0" <?php selected($filter->aktiv, '0'); ?>><?php echo esc_html__('Inaktiv', 'bs-awo-jobs-statistik'); ?></option>
                    </select>
                </label>

                <label>
                    <?php echo esc_html__('Einrichtung', 'bs-awo-jobs-statistik'); ?><br>
                    <select name="bs_es_einr" style="width:100%;">
                        <option value=""><?php echo esc_html__('Alle', 'bs-awo-jobs-statistik'); ?></option>
                        <?php foreach ($einrichtungOptionsStamm as $en): ?>
                            <option value="<?php echo esc_attr($en); ?>" <?php selected($filter->einrichtung, $en); ?>><?php echo esc_html($en); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <?php echo esc_html__('Ansicht', 'bs-awo-jobs-statistik'); ?><br>
                    <select name="bs_es_view" style="width:100%;">
                        <option value="" <?php selected($esView, ''); ?>><?php echo esc_html__('Masterliste (eine Zeile pro Einrichtung)', 'bs-awo-jobs-statistik'); ?></option>
                        <option value="alle" <?php selected($esView, 'alle'); ?>><?php echo esc_html__('Alle Datensätze (inkl. Aliase)', 'bs-awo-jobs-statistik'); ?></option>
                    </select>
                </label>

                <label>
                    <?php echo esc_html__('Prüfstatus', 'bs-awo-jobs-statistik'); ?><br>
                    <select name="bs_es_pruef" style="width:100%;">
                        <option value=""><?php echo esc_html__('Alle', 'bs-awo-jobs-statistik'); ?></option>
                        <option value="<?php echo esc_attr(EinrichtungenMatching::PRUEFSTATUS_OK); ?>" <?php selected($filter->pruefstatus, EinrichtungenMatching::PRUEFSTATUS_OK); ?>><?php echo esc_html__('OK', 'bs-awo-jobs-statistik'); ?></option>
                        <option value="<?php echo esc_attr(EinrichtungenMatching::PRUEFSTATUS_PRUEFEN); ?>" <?php selected($filter->pruefstatus, EinrichtungenMatching::PRUEFSTATUS_PRUEFEN); ?>><?php echo esc_html__('Zu prüfen', 'bs-awo-jobs-statistik'); ?></option>
                    </select>
                </label>

                <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
                    <button type="submit" class="button button-primary"><?php echo esc_html__('Filtern', 'bs-awo-jobs-statistik'); ?></button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_EINRICHTUNGEN)); ?>" class="button"><?php echo esc_html__('Zurücksetzen', 'bs-awo-jobs-statistik'); ?></a>
                    <a href="<?php echo esc_url(add_query_arg(array_merge($filterQueryBase, ['bs_es_new' => '1']), admin_url('admin.php'))); ?>" class="button button-primary"><?php echo esc_html__('Neue Einrichtung', 'bs-awo-jobs-statistik'); ?></a>
                </div>
            </form>

            <table class="widefat striped">
                <thead>
                <tr>
                    <th><?php echo esc_html__('Einrichtung', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Master / Aliase', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Letzter API-Name', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Fachbereich Börse', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Mandantenfeld', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Aktiv', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Gesamt-VZÄ (Soll)', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Prüfstatus', 'bs-awo-jobs-statistik'); ?></th>
                    <th><?php echo esc_html__('Aktion', 'bs-awo-jobs-statistik'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><strong><?php echo esc_html((string) ($r['einrichtung'] ?? '')); ?></strong></td>
                        <td>
                            <?php
                            $rid = (int) ($r['id'] ?? 0);
                            $mId = (int) ($r['master_einrichtung_id'] ?? 0);
                            if ($mId > 0) {
                                $mRow = $idMapZuordnung[$mId] ?? null;
                                $mLabel = $mRow ? sprintf(
                                    /* translators: 1: master facility name, 2: master id */
                                    __('Alias von → %1$s (ID %2$d)', 'bs-awo-jobs-statistik'),
                                    (string) ($mRow['einrichtung'] ?? ''),
                                    $mId
                                ) : sprintf(
                                    /* translators: %d: master id */
                                    __('Alias von Master-ID %d', 'bs-awo-jobs-statistik'),
                                    $mId
                                );
                                echo '<span class="description">' . esc_html($mLabel) . '</span>';
                            } else {
                                $nA = $repo->countDirectAliases($rid);
                                if ($nA > 0) {
                                    echo '<mark>' . esc_html(
                                        sprintf(
                                            /* translators: %d: number of alias records */
                                            __('Master (%d Aliase)', 'bs-awo-jobs-statistik'),
                                            $nA
                                        )
                                    ) . '</mark>';
                                } else {
                                    echo '<span class="description">' . esc_html__('Eigenständig', 'bs-awo-jobs-statistik') . '</span>';
                                }
                            }
                            ?>
                        </td>
                        <td>
                            <?php
                            $apiName = trim((string) ($r['letzter_api_name'] ?? ''));
                            if ($apiName !== '') {
                                if (mb_strlen($apiName, 'UTF-8') > 48) {
                                    echo '<span title="' . esc_attr($apiName) . '">';
                                    echo esc_html(mb_substr($apiName, 0, 45, 'UTF-8') . '…');
                                    echo '</span>';
                                } else {
                                    echo esc_html($apiName);
                                }
                            } else {
                                echo '<span class="description">–</span>';
                            }
                            ?>
                        </td>
                        <td><?php echo esc_html((string) ($r['fachbereich_boerse'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($r['fachbereich_intern'] ?? '–')); ?></td>
                        <td><?php echo !empty($r['aktiv']) ? esc_html__('Ja', 'bs-awo-jobs-statistik') : esc_html__('Nein', 'bs-awo-jobs-statistik'); ?></td>
                        <td>
                            <?php
                            $eg = EinrichtungenStammSollVza::effectiveGesamt($r);
                            $auto = !EinrichtungenStammSollVza::nutztManuellenGesamtwert($r);
                            echo esc_html(number_format($eg, 2, ',', '.'));
                            echo ' ';
                            if ($auto) {
                                echo '<span class="description">(' . esc_html__('auto', 'bs-awo-jobs-statistik') . ')</span>';
                            } else {
                                echo '<span class="description">(' . esc_html__('manuell', 'bs-awo-jobs-statistik') . ')</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <?php
                            $ps = (string) ($r['pruefstatus'] ?? EinrichtungenMatching::PRUEFSTATUS_OK);
                            if ($ps === EinrichtungenMatching::PRUEFSTATUS_PRUEFEN) {
                                echo '<mark>' . esc_html__('Zu prüfen', 'bs-awo-jobs-statistik') . '</mark>';
                                $phi = trim((string) ($r['pruef_hinweis'] ?? ''));
                                if ($phi !== '') {
                                    $short = preg_replace('/\s+/u', ' ', $phi);
                                    if ($short !== null && mb_strlen($short, 'UTF-8') > 140) {
                                        $short = mb_substr((string) $short, 0, 137, 'UTF-8') . '…';
                                    }
                                    echo '<div class="description" style="margin-top:0.35em;max-width:22em;">' . esc_html((string) $short) . '</div>';
                                }
                            } else {
                                echo esc_html__('OK', 'bs-awo-jobs-statistik');
                            }
                            ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url(add_query_arg(array_merge($filterQueryBase, ['bs_es_edit' => (int) ($r['id'] ?? 0)]), admin_url('admin.php'))); ?>" class="button button-small"><?php echo esc_html__('Bearbeiten', 'bs-awo-jobs-statistik'); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                    <tr><td colspan="9"><?php echo esc_html__('Keine Einträge (oder keine Treffer mit aktuellem Filter).', 'bs-awo-jobs-statistik'); ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed>|null $row null = neuer Datensatz
     * @param array<string, string|int> $filterQueryBase
     */
    private function renderEinrichtungStammForm(?array $row, array $filterQueryBase, EinrichtungenStammRepository $repo): void
    {
        $id = $row ? (int) ($row['id'] ?? 0) : 0;
        $einrichtung = $row ? (string) ($row['einrichtung'] ?? '') : '';
        $fbb = $row ? (string) ($row['fachbereich_boerse'] ?? '') : '';
        $fbi = $row ? (string) ($row['fachbereich_intern'] ?? '') : '';
        $aktiv = $row ? (int) ($row['aktiv'] ?? 1) : 1;
        $bem = $row ? (string) ($row['bemerkung'] ?? '') : '';
        $s = static function ($k) use ($row): string {
            if (!$row || !isset($row[$k]) || $row[$k] === null || $row[$k] === '') {
                return '';
            }

            return (string) $row[$k];
        };
        ?>
        <form method="post" class="card" style="max-width:720px;padding:1.5rem;">
            <?php wp_nonce_field('bs_awo_einrichtung_save', 'bs_awo_einrichtung_save_nonce'); ?>
            <input type="hidden" name="bs_awo_action" value="einrichtung_save">
            <input type="hidden" name="einrichtung_id" value="<?php echo esc_attr((string) $id); ?>">
            <?php foreach ($filterQueryBase as $k => $v): ?>
                <?php if ($k !== 'page' && $k !== 'bs_es_edit' && $k !== 'bs_es_new'): ?>
                    <input type="hidden" name="filter_ret_<?php echo esc_attr($k); ?>" value="<?php echo esc_attr((string) $v); ?>">
                <?php endif; ?>
            <?php endforeach; ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="bs_es_f_einr"><?php echo esc_html__('Einrichtungsname', 'bs-awo-jobs-statistik'); ?></label></th>
                    <td><input name="einrichtung" id="bs_es_f_einr" type="text" class="regular-text" value="<?php echo esc_attr($einrichtung); ?>" required></td>
                </tr>
                <?php if ($row) : ?>
                    <?php
                    $aliasUnterMir = $repo->countDirectAliases($id);
                    $masterKandidaten = $repo->fetchRootMasterKandidatenFuerSelect($id);
                    $masterAktuell = (int) ($row['master_einrichtung_id'] ?? 0);
                    ?>
                <tr>
                    <th scope="row"><label for="bs_es_f_master"><?php echo esc_html__('Gehört zu (Master)', 'bs-awo-jobs-statistik'); ?></label></th>
                    <td>
                        <?php if ($aliasUnterMir > 0) : ?>
                            <input type="hidden" name="master_einrichtung_id" value="<?php echo $masterAktuell > 0 ? esc_attr((string) $masterAktuell) : ''; ?>">
                            <p class="description"><?php echo esc_html(sprintf(
                                /* translators: %d: number of facilities that point to this record as master */
                                __('Dieser Datensatz ist %d weiterer Einrichtung(en) in Stammdaten als Master zugeordnet. Eine Alias-Zuordnung an einen anderen Master ist dafür gesperrt (zuerst andere Datensätze umhängen).', 'bs-awo-jobs-statistik'),
                                $aliasUnterMir
                            )); ?></p>
                        <?php else : ?>
                            <select name="master_einrichtung_id" id="bs_es_f_master">
                                <option value=""><?php echo esc_html__('Eigenständig (kein übergeordneter Datensatz)', 'bs-awo-jobs-statistik'); ?></option>
                                <?php foreach ($masterKandidaten as $mk) : ?>
                                    <option value="<?php echo esc_attr((string) (int) ($mk['id'] ?? 0)); ?>" <?php selected($masterAktuell, (int) ($mk['id'] ?? 0)); ?>>
                                        <?php echo esc_html(sprintf('ID %1$d — %2$s', (int) ($mk['id'] ?? 0), (string) ($mk['einrichtung'] ?? ''))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php echo esc_html__('So ordnen Sie eine falsch geschriebene bzw. doppelte Einrichtung Ihrer Masterliste zu: dieselbe fachliche Einrichtung wie im Dropdown wählen und speichern. Die Stammdaten-Liste zeigt standardmäßig nur Master; der Alias bleibt in „Alle Datensätze“ sichtbar. Auswertung „Soll / Offen / Besetzt“: Offen-VZÄ über alle Namen des Clusters, Soll nur vom Master.', 'bs-awo-jobs-statistik'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php else : ?>
                    <?php $masterKandidatenNeu = $repo->fetchRootMasterKandidatenFuerSelect(0); ?>
                <tr>
                    <th scope="row"><label for="bs_es_f_masternew"><?php echo esc_html__('Gehört zu (Master)', 'bs-awo-jobs-statistik'); ?></label></th>
                    <td>
                        <select name="master_einrichtung_id" id="bs_es_f_masternew">
                            <option value=""><?php echo esc_html__('Eigenständig (kein übergeordneter Datensatz)', 'bs-awo-jobs-statistik'); ?></option>
                            <?php foreach ($masterKandidatenNeu as $mk) : ?>
                                <option value="<?php echo esc_attr((string) (int) ($mk['id'] ?? 0)); ?>">
                                    <?php echo esc_html(sprintf('ID %1$d — %2$s', (int) ($mk['id'] ?? 0), (string) ($mk['einrichtung'] ?? ''))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php echo esc_html__('Optional: sofort als Alias eines bestehenden Masters anlegen (z. B. zweite Schreibweise derselben Einrichtung).', 'bs-awo-jobs-statistik'); ?></p>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th scope="row"><label for="bs_es_f_fb"><?php echo esc_html__('Fachbereich (Stellenbörse)', 'bs-awo-jobs-statistik'); ?></label></th>
                    <td><input name="fachbereich_boerse" id="bs_es_f_fb" type="text" class="regular-text" value="<?php echo esc_attr($fbb); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="bs_es_f_fbi"><?php echo esc_html__('Mandantenfeld (intern)', 'bs-awo-jobs-statistik'); ?></label></th>
                    <td><input name="fachbereich_intern" id="bs_es_f_fbi" type="text" class="regular-text" value="<?php echo esc_attr($fbi); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Aktiv', 'bs-awo-jobs-statistik'); ?></th>
                    <td>
                        <label><input type="checkbox" name="aktiv" value="1" <?php checked($aktiv, 1); ?>> <?php echo esc_html__('Einrichtung ist aktiv', 'bs-awo-jobs-statistik'); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="bs_es_f_bem"><?php echo esc_html__('Bemerkung', 'bs-awo-jobs-statistik'); ?></label></th>
                    <td><textarea name="bemerkung" id="bs_es_f_bem" class="large-text" rows="4"><?php echo esc_textarea($bem); ?></textarea></td>
                </tr>
                <tr>
                    <th colspan="2"><strong><?php echo esc_html__('Soll-VZÄ (Vorbereitung, ohne Auswertung)', 'bs-awo-jobs-statistik'); ?></strong></th>
                </tr>
                <tr>
                    <th scope="row"><label for="bs_es_f_s1"><?php echo esc_html__('Soll VZÄ Fachkräfte', 'bs-awo-jobs-statistik'); ?></label></th>
                    <td><input name="soll_vza_fachkraefte" id="bs_es_f_s1" type="text" class="small-text" value="<?php echo esc_attr($s('soll_vza_fachkraefte')); ?>" placeholder="z. B. 12,5"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="bs_es_f_s2"><?php echo esc_html__('Soll VZÄ Hilfskräfte', 'bs-awo-jobs-statistik'); ?></label></th>
                    <td><input name="soll_vza_hilfskraefte" id="bs_es_f_s2" type="text" class="small-text" value="<?php echo esc_attr($s('soll_vza_hilfskraefte')); ?>" placeholder="z. B. 3"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="bs_es_f_s3"><?php echo esc_html__('Soll VZÄ 3', 'bs-awo-jobs-statistik'); ?></label></th>
                    <td><input name="soll_vza_3" id="bs_es_f_s3" type="text" class="small-text" value="<?php echo esc_attr($s('soll_vza_3')); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="bs_es_f_s4"><?php echo esc_html__('Soll VZÄ 4', 'bs-awo-jobs-statistik'); ?></label></th>
                    <td><input name="soll_vza_4" id="bs_es_f_s4" type="text" class="small-text" value="<?php echo esc_attr($s('soll_vza_4')); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="bs_es_f_s5"><?php echo esc_html__('Soll VZÄ 5', 'bs-awo-jobs-statistik'); ?></label></th>
                    <td><input name="soll_vza_5" id="bs_es_f_s5" type="text" class="small-text" value="<?php echo esc_attr($s('soll_vza_5')); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Summe der Kategorien', 'bs-awo-jobs-statistik'); ?></th>
                    <td class="description">
                        <?php
                        $sumRow = $row ?? [];
                        echo esc_html(number_format(EinrichtungenStammSollVza::summeTeilwerte($sumRow), 2, ',', '.'));
                        echo ' — ';
                        echo esc_html__('wird verwendet, wenn kein manueller Gesamtwert gesetzt ist.', 'bs-awo-jobs-statistik');
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="bs_es_f_gesamt_override"><?php echo esc_html__('Manuelle Gesamt-VZÄ (Override)', 'bs-awo-jobs-statistik'); ?></label></th>
                    <td>
                        <input name="gesamt_vza_override" id="bs_es_f_gesamt_override" type="text" class="small-text" value="<?php echo esc_attr($s('gesamt_vza_override')); ?>" placeholder="<?php echo esc_attr__('leer = automatisch', 'bs-awo-jobs-statistik'); ?>">
                        <p class="description"><?php echo esc_html__('Wenn ausgefüllt, hat dieser Wert Vorrang vor der Summe der fünf Kategorien. Leer lassen für automatische Summe.', 'bs-awo-jobs-statistik'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Effektiver Gesamt-Sollwert', 'bs-awo-jobs-statistik'); ?></th>
                    <td><strong><?php echo esc_html(number_format(EinrichtungenStammSollVza::effectiveGesamt($sumRow), 2, ',', '.')); ?></strong>
                        <?php if (EinrichtungenStammSollVza::nutztManuellenGesamtwert($sumRow)) : ?>
                            <span class="description"><?php echo esc_html__('(manueller Gesamtwert)', 'bs-awo-jobs-statistik'); ?></span>
                        <?php else : ?>
                            <span class="description"><?php echo esc_html__('(Summe der Kategorien)', 'bs-awo-jobs-statistik'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($row): ?>
                <tr>
                    <th scope="row"><?php echo esc_html__('Quelle', 'bs-awo-jobs-statistik'); ?></th>
                    <td><code><?php echo esc_html((string) ($row['quelle'] ?? '–')); ?></code>
                        <?php if (!empty($row['letzter_api_name'])): ?>
                            <p class="description"><?php echo esc_html__('Letzter Name aus API/Seed:', 'bs-awo-jobs-statistik'); ?> <code><?php echo esc_html((string) $row['letzter_api_name']); ?></code></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="bs_es_f_pruefstatus"><?php echo esc_html__('Prüfstatus', 'bs-awo-jobs-statistik'); ?></label></th>
                    <td>
                        <select name="pruefstatus" id="bs_es_f_pruefstatus">
                            <option value="<?php echo esc_attr(EinrichtungenMatching::PRUEFSTATUS_OK); ?>" <?php selected((string) ($row['pruefstatus'] ?? ''), EinrichtungenMatching::PRUEFSTATUS_OK); ?>><?php echo esc_html__('OK', 'bs-awo-jobs-statistik'); ?></option>
                            <option value="<?php echo esc_attr(EinrichtungenMatching::PRUEFSTATUS_PRUEFEN); ?>" <?php selected((string) ($row['pruefstatus'] ?? ''), EinrichtungenMatching::PRUEFSTATUS_PRUEFEN); ?>><?php echo esc_html__('Zu prüfen', 'bs-awo-jobs-statistik'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="bs_es_f_pruefhi"><?php echo esc_html__('Prüfhinweis', 'bs-awo-jobs-statistik'); ?></label></th>
                    <td><textarea name="pruef_hinweis" id="bs_es_f_pruefhi" class="large-text" rows="3"><?php echo esc_textarea((string) ($row['pruef_hinweis'] ?? '')); ?></textarea></td>
                </tr>
                <?php endif; ?>
            </table>
            <?php submit_button($id > 0 ? __('Speichern', 'bs-awo-jobs-statistik') : __('Anlegen', 'bs-awo-jobs-statistik')); ?>
        </form>
        <?php
    }

    private function maybeHandleEinrichtungStammPost(): void
    {
        if (!isset($_POST['bs_awo_action']) || !current_user_can('manage_options')) {
            return;
        }
        $action = sanitize_key((string) $_POST['bs_awo_action']);

        if ($action === 'einrichtung_seed') {
            if (!wp_verify_nonce($_POST['bs_awo_einrichtung_seed_nonce'] ?? '', 'bs_awo_einrichtung_seed')) {
                return;
            }
            $repo = new EinrichtungenStammRepository($this->wpdb);
            $sum = $repo->seedMissingFromAusschreibungen();
            $redirect = $this->einrichtungStammRedirectArgsFromPost();
            $redirect['bs_awo_notice'] = 'seeded';
            $redirect['bs_awo_notice_neu'] = (string) $sum['neu'];
            $redirect['bs_awo_notice_bereits'] = (string) $sum['bereits_zugeordnet'];
            $redirect['bs_awo_notice_pruef'] = (string) $sum['prueffaelle'];
            nocache_headers();
            wp_safe_redirect(add_query_arg($redirect, admin_url('admin.php')), 303);
            exit;
        }

        if ($action === 'einrichtung_save') {
            if (!wp_verify_nonce($_POST['bs_awo_einrichtung_save_nonce'] ?? '', 'bs_awo_einrichtung_save')) {
                return;
            }
            $repo = new EinrichtungenStammRepository($this->wpdb);
            $id = (int) ($_POST['einrichtung_id'] ?? 0);
            $einrichtung = sanitize_text_field(wp_unslash($_POST['einrichtung'] ?? ''));
            if ($einrichtung === '') {
                return;
            }
            $redirectBase = $this->einrichtungStammRedirectArgsFromPost();
            $masterPost = isset($_POST['master_einrichtung_id']) ? trim(wp_unslash((string) $_POST['master_einrichtung_id'])) : '';
            $masterParsed = null;
            if ($masterPost !== '' && is_numeric($masterPost)) {
                $mid = (int) $masterPost;
                if ($mid > 0) {
                    $masterParsed = $mid;
                }
            }
            $errMaster = $repo->validateMasterZuordnung($id, $masterParsed);
            if ($errMaster !== null) {
                $redirectBase['bs_awo_notice'] = 'master_invalid';
                $redirectBase['bs_awo_master_msg'] = rawurlencode($errMaster);
                if ($id > 0) {
                    $redirectBase['bs_es_edit'] = (string) $id;
                } else {
                    $redirectBase['bs_es_new'] = '1';
                }
                nocache_headers();
                wp_safe_redirect(add_query_arg($redirectBase, admin_url('admin.php')), 303);
                exit;
            }

            $tbl = $this->wpdb->prefix . Database::TABLE_EINRICHTUNGEN_STAMM;
            $dupId = (int) $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$tbl} WHERE einrichtung = %s AND id != %d LIMIT 1",
                $einrichtung,
                $id
            ));
            if (
                $dupId > 0
                && !$repo->allowExactEinrichtungsnameTrotzTreffer($id, $dupId, $masterParsed)
            ) {
                $this->redirectEinrichtungStammDuplicateError($redirectBase, 'duplicate_exact', $dupId, $id);
            }

            $matchKey = EinrichtungenMatching::matchKeyFromRaw($einrichtung);
            if ($matchKey !== '') {
                $normDupId = $repo->firstConflictingIdForSameMatchKeyAfterSave($id, $matchKey, $masterParsed);
                if ($normDupId !== null) {
                    $this->redirectEinrichtungStammDuplicateError($redirectBase, 'duplicate_normalized', $normDupId, $id);
                }
            }

            $data = [
                'einrichtung' => $einrichtung,
                'fachbereich_boerse' => sanitize_text_field(wp_unslash($_POST['fachbereich_boerse'] ?? '')),
                'fachbereich_intern' => sanitize_text_field(wp_unslash($_POST['fachbereich_intern'] ?? '')),
                'aktiv' => !empty($_POST['aktiv']) ? 1 : 0,
                'bemerkung' => sanitize_textarea_field(wp_unslash($_POST['bemerkung'] ?? '')),
                'soll_vza_fachkraefte' => $this->parseOptionalDecimal('soll_vza_fachkraefte'),
                'soll_vza_hilfskraefte' => $this->parseOptionalDecimal('soll_vza_hilfskraefte'),
                'soll_vza_3' => $this->parseOptionalDecimal('soll_vza_3'),
                'soll_vza_4' => $this->parseOptionalDecimal('soll_vza_4'),
                'soll_vza_5' => $this->parseOptionalDecimal('soll_vza_5'),
                'gesamt_vza_override' => $this->parseOptionalDecimal('gesamt_vza_override'),
                'pruefstatus' => isset($_POST['pruefstatus']) ? sanitize_key(wp_unslash((string) $_POST['pruefstatus'])) : EinrichtungenMatching::PRUEFSTATUS_OK,
                'pruef_hinweis' => sanitize_textarea_field(wp_unslash($_POST['pruef_hinweis'] ?? '')),
                'master_einrichtung_id' => $masterParsed,
            ];

            if ($id > 0) {
                $repo->update($id, $data);
            } else {
                $repo->insert($data);
            }

            $redirect = $this->einrichtungStammRedirectArgsFromPost();
            $redirect['bs_awo_notice'] = 'saved';
            nocache_headers();
            wp_safe_redirect(add_query_arg($redirect, admin_url('admin.php')), 303);
            exit;
        }
    }

    /**
     * Dubletten-Schutz: Redirect zurück ins Formular / neue Maske mit Fehler-Notice (kein wp_die).
     *
     * @param array<string, string|int> $redirectBase
     */
    private function redirectEinrichtungStammDuplicateError(array $redirectBase, string $noticeSlug, int $konfliktId, int $bearbeitungsId): void
    {
        $redirectBase['bs_awo_notice'] = $noticeSlug;
        $redirectBase['bs_awo_conflict_id'] = (string) $konfliktId;
        if ($bearbeitungsId > 0) {
            $redirectBase['bs_es_edit'] = (string) $bearbeitungsId;
        } else {
            $redirectBase['bs_es_new'] = '1';
        }
        nocache_headers();
        wp_safe_redirect(add_query_arg($redirectBase, admin_url('admin.php')), 303);
        exit;
    }

    /**
     * @return array<string, string|int>
     */
    private function einrichtungStammRedirectArgsFromPost(): array
    {
        $out = ['page' => self::PAGE_EINRICHTUNGEN];
        foreach ($_POST as $k => $v) {
            if (!is_string($k) || strpos($k, 'filter_ret_') !== 0) {
                continue;
            }
            $param = substr($k, strlen('filter_ret_'));
            if ($param === '' || $param === 'page') {
                continue;
            }
            $out[$param] = sanitize_text_field(wp_unslash((string) $v));
        }

        return $out;
    }

    private function parseOptionalDecimal(string $postKey): ?float
    {
        $raw = isset($_POST[$postKey]) ? wp_unslash((string) $_POST[$postKey]) : '';
        $raw = trim(str_replace(',', '.', $raw));
        if ($raw === '') {
            return null;
        }

        return is_numeric($raw) ? (float) $raw : null;
    }
}