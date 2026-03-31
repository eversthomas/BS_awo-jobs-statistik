<?php
/**
 * Dashboard-Tab: Soll-VZÄ (effektiv), Offen (aus berücksichtigten aktiven Stellen), Besetzt = Differenz.
 * Pro kanonischem Master: Soll nur vom Master-Datensatz; Offen über alle Cluster-Mitglieder (Namen/Keys).
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\EinrichtungenStamm;

use BS_Awo_Jobs_Statistik\AktiveStellen\AktiveStellenQuery;

final class EinrichtungenAuswertungService
{
    /** @var \wpdb */
    private $wpdb;

    private int $vollzeitStunden;

    /**
     * @param \wpdb $wpdb
     */
    public function __construct($wpdb, int $vollzeitStunden)
    {
        $this->wpdb = $wpdb;
        $this->vollzeitStunden = $vollzeitStunden;
    }

    /**
     * Offene VZÄ werden aus Zeilen mit zuletzt_gesehen_api IS NOT NULL gebildet,
     * eingeschränkt auf in_statistik_beruecksichtigen = 1 (s. README im Aufrufer).
     *
     * Eine Ausgabezeile je Master-Datensatz (Eigenständige); Aliase fließen in Offen-Summe ein, nicht als eigene Zeile.
     *
     * @return list<array<string, mixed>> Stammdaten-Zeilen plus soll_vza_effektiv, offen_vza, besetzt_vza, kanonisch_alias_anzahl
     */
    public function buildAuswertungRows(EinrichtungenStammFilterInput $filter): array
    {
        $repo = new EinrichtungenStammRepository($this->wpdb);
        $stammMasters = $repo->fetchRootMasterRowsForAuswertung($filter);
        $allStamm = $repo->fetchAllOrdered();

        $aktiv = AktiveStellenQuery::fetchAktiveZeilen($this->wpdb);
        $aktiv = AktiveStellenQuery::filterNurBeruecksichtigt($aktiv);

        $out = [];
        foreach ($stammMasters as $r) {
            $mid = (int) ($r['id'] ?? 0);
            if ($mid <= 0) {
                continue;
            }
            $cluster = EinrichtungenStammCluster::membersOfMaster($mid, $allStamm);
            $offen = round(EinrichtungenStammCluster::summeOffeneVzaFuerCluster(
                $this->wpdb,
                $aktiv,
                $cluster,
                $this->vollzeitStunden
            ), 2);
            $soll = EinrichtungenStammSollVza::effectiveGesamt($r);
            $besetzt = round($soll - $offen, 2);
            $aliasAnzahl = count($cluster) > 0 ? count($cluster) - 1 : 0;
            $out[] = array_merge($r, [
                'soll_vza_effektiv' => $soll,
                'offen_vza' => $offen,
                'besetzt_vza' => $besetzt,
                'kanonisch_alias_anzahl' => $aliasAnzahl,
            ]);
        }

        return $out;
    }
}
