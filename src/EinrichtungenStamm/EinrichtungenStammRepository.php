<?php
/**
 * Zugriff auf bs_awojobs_einrichtungen_stamm (CRUD, Filter, Seed mit Matching).
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\EinrichtungenStamm;

use BS_Awo_Jobs_Statistik\AktiveStellen\AktiveStellenQuery;
use BS_Awo_Jobs_Statistik\Core\Database;

final class EinrichtungenStammRepository
{
    /** @var \wpdb */
    private $wpdb;

    public function __construct($wpdb)
    {
        $this->wpdb = $wpdb;
    }

    private function table(): string
    {
        return $this->wpdb->prefix . Database::TABLE_EINRICHTUNGEN_STAMM;
    }

    /** Spalte pruef_hinweis ist varchar(500). */
    private static function truncatePruefHinweisForDb(string $hint): string
    {
        if (mb_strlen($hint, 'UTF-8') <= 500) {
            return $hint;
        }

        return mb_substr($hint, 0, 497, 'UTF-8') . '...';
    }

    private static function selectColumnsSql(): string
    {
        return 'id, einrichtung, einrichtung_normalized, letzter_api_name, master_einrichtung_id, fachbereich_boerse, fachbereich_intern,
                aktiv, bemerkung,
                soll_vza_fachkraefte, soll_vza_hilfskraefte, soll_vza_3, soll_vza_4, soll_vza_5, gesamt_vza_override,
                pruefstatus, pruef_hinweis, quelle,
                erstellt_am, aktualisiert_am';
    }

    /**
     * Dieselben Regeln wie fetchFiltered, für eine einzelne Zeile (öffentlich für Auswertung).
     */
    public function rowMatchesStammFilter(array $row, EinrichtungenStammFilterInput $filter): bool
    {
        if ($filter->listenModus === 'wurzel' && (int) ($row['master_einrichtung_id'] ?? 0) > 0) {
            return false;
        }

        if ($filter->aktiv === '1' && (int) ($row['aktiv'] ?? 1) !== 1) {
            return false;
        }
        if ($filter->aktiv === '0' && (int) ($row['aktiv'] ?? 1) !== 0) {
            return false;
        }

        if (
            $filter->fachbereichBoerse !== ''
            && AktiveStellenQuery::normalizeFilterValue((string) ($row['fachbereich_boerse'] ?? ''))
                !== AktiveStellenQuery::normalizeFilterValue($filter->fachbereichBoerse)
        ) {
            return false;
        }

        if ($filter->mandantenfeld !== '') {
            $rowIntern = trim((string) ($row['fachbereich_intern'] ?? ''));
            if (
                AktiveStellenQuery::normalizeFilterValue($rowIntern)
                    !== AktiveStellenQuery::normalizeFilterValue($filter->mandantenfeld)
            ) {
                return false;
            }
        }

        if ($filter->einrichtung !== '') {
            if (
                AktiveStellenQuery::normalizeFilterValue(trim((string) ($row['einrichtung'] ?? '')))
                    !== AktiveStellenQuery::normalizeFilterValue(trim($filter->einrichtung))
            ) {
                return false;
            }
        }

        if ($filter->pruefstatus !== '') {
            $ps = (string) ($row['pruefstatus'] ?? EinrichtungenMatching::PRUEFSTATUS_OK);
            if ($ps !== $filter->pruefstatus) {
                return false;
            }
        }

        if ($filter->q !== '') {
            $haystack = mb_strtolower(implode(' ', [
                (string) ($row['einrichtung'] ?? ''),
                (string) ($row['letzter_api_name'] ?? ''),
                (string) ($row['fachbereich_boerse'] ?? ''),
                (string) ($row['fachbereich_intern'] ?? ''),
                (string) ($row['bemerkung'] ?? ''),
                (string) ($row['pruef_hinweis'] ?? ''),
            ]));
            $needle = mb_strtolower($filter->q);
            if (mb_strpos($haystack, $needle) === false) {
                return false;
            }
        }

        return true;
    }

    public function countDirectAliases(int $masterId): int
    {
        if ($masterId <= 0) {
            return 0;
        }
        $t = $this->table();

        return (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$t} WHERE master_einrichtung_id = %d",
            $masterId
        ));
    }

    /**
     * @return string|null deutschsprachiger Fehlertext oder null bei OK
     */
    public function validateMasterZuordnung(int $recordId, ?int $masterId): ?string
    {
        if ($masterId === null || $masterId <= 0) {
            return null;
        }
        if ($recordId > 0 && $masterId === $recordId) {
            return \__('Einrichtung kann nicht ihr eigener Master sein.', 'bs-awo-jobs-statistik');
        }
        if ($recordId <= 0) {
            $masterRow = $this->getById($masterId);
            if ($masterRow === null) {
                return \__('Der gewählte Master-Datensatz existiert nicht.', 'bs-awo-jobs-statistik');
            }
            if ((int) ($masterRow['master_einrichtung_id'] ?? 0) > 0) {
                return \__('Als Master kann nur ein eigenständiger Datensatz (ohne übergeordnete Einrichtung) gewählt werden.', 'bs-awo-jobs-statistik');
            }

            return null;
        }
        $masterRow = $this->getById($masterId);
        if ($masterRow === null) {
            return \__('Der gewählte Master-Datensatz existiert nicht.', 'bs-awo-jobs-statistik');
        }
        if ((int) ($masterRow['master_einrichtung_id'] ?? 0) > 0) {
            return \__('Als Master kann nur ein eigenständiger Datensatz (ohne übergeordnete Einrichtung) gewählt werden.', 'bs-awo-jobs-statistik');
        }
        if ($recordId > 0 && $this->isStrictDescendantOf($masterId, $recordId)) {
            return \__('Diese Zuordnung würde einen Zirkelbezug erzeugen.', 'bs-awo-jobs-statistik');
        }
        if ($recordId > 0 && $this->countDirectAliases($recordId) > 0) {
            return \__('Dieser Datensatz ist Master weiterer Einrichtungen. Bitte zuerst deren Zuordnung ändern oder aufheben.', 'bs-awo-jobs-statistik');
        }

        return null;
    }

    /**
     * Liegt $descendantCandidateId im Unterbaum von $ancestorId (Kinderkette master_einrichtung_id)?
     */
    private function isStrictDescendantOf(int $descendantCandidateId, int $ancestorId): bool
    {
        if ($descendantCandidateId <= 0 || $ancestorId <= 0 || $descendantCandidateId === $ancestorId) {
            return false;
        }
        $t = $this->table();
        $queue = [$ancestorId];
        $seen = [$ancestorId => true];
        while ($queue !== []) {
            $pid = (int) array_shift($queue);
            $children = $this->wpdb->get_col($this->wpdb->prepare(
                "SELECT id FROM {$t} WHERE master_einrichtung_id = %d",
                $pid
            ));
            foreach ($children ?: [] as $cid) {
                $cid = (int) $cid;
                if ($cid === $descendantCandidateId) {
                    return true;
                }
                if (!isset($seen[$cid])) {
                    $seen[$cid] = true;
                    $queue[] = $cid;
                }
            }
        }

        return false;
    }

    /**
     * Für Soll/Offen/Besetzt: eine Zeile pro kanonischem Master, der zum Filter passt (inkl. Sichtbarkeit über Alias-Zeilen).
     *
     * @return list<array<string, mixed>>
     */
    public function fetchRootMasterRowsForAuswertung(EinrichtungenStammFilterInput $filter): array
    {
        $all = $this->fetchAllOrdered();
        $idToRow = EinrichtungenStammCluster::idMap($all);
        $seen = [];
        foreach ($all as $r) {
            if (!$this->rowMatchesStammFilter($r, $filter)) {
                continue;
            }
            $mid = EinrichtungenStammCluster::resolveRootMasterId($r, $idToRow);
            if ($mid > 0) {
                $seen[$mid] = true;
            }
        }
        $out = [];
        foreach (array_keys($seen) as $mid) {
            if (isset($idToRow[$mid])) {
                $out[] = $idToRow[$mid];
            }
        }
        usort($out, static function (array $a, array $b): int {
            return strcmp((string) ($a['einrichtung'] ?? ''), (string) ($b['einrichtung'] ?? ''));
        });

        return $out;
    }

    /** Nach dbDelta: normalisierte Schlüssel für bestehende Zeilen setzen. */
    public function backfillEinrichtungenStammNachUpgrade(): void
    {
        $t = $this->table();
        $rows = $this->wpdb->get_results(
            "SELECT id, einrichtung FROM {$t}",
            ARRAY_A
        );
        foreach ($rows ?: [] as $row) {
            $norm = EinrichtungenMatching::matchKeyFromRaw((string) ($row['einrichtung'] ?? ''));
            $this->wpdb->update(
                $t,
                ['einrichtung_normalized' => $norm !== '' ? $norm : null],
                ['id' => (int) ($row['id'] ?? 0)],
                ['%s'],
                ['%d']
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAllOrdered(): array
    {
        $t = $this->table();
        $cols = self::selectColumnsSql();
        $rows = $this->wpdb->get_results(
            "SELECT {$cols}
             FROM {$t}
             ORDER BY einrichtung ASC",
            ARRAY_A
        );

        return $rows ? array_map(static fn ($r) => array_merge([], $r), $rows) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchFiltered(EinrichtungenStammFilterInput $filter): array
    {
        $rows = $this->fetchAllOrdered();

        return array_values(array_filter($rows, fn (array $row): bool => $this->rowMatchesStammFilter($row, $filter)));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $t = $this->table();
        $cols = self::selectColumnsSql();
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT {$cols} FROM {$t} WHERE id = %d",
            $id
        ), ARRAY_A);

        return $row ? array_merge([], $row) : null;
    }

    /**
     * Gleicher Match-Key ist erlaubt, wenn nach Speichern beide Datensätze im selben kanonischen Cluster sind.
     */
    public function allowNormalizedMatchKeyTrotzTreffer(int $editId, int $otherId, ?int $proposedMasterId): bool
    {
        return $this->allowGleicherClusterNachSave($editId, $otherId, $proposedMasterId);
    }

    /**
     * Erste andere Stammdaten-ID, die nach Speichern nicht im selben Cluster wie der bearbeitete Datensatz läge.
     * Prüft alle Zeilen mit gleichem Match-Key (nicht nur die erste aus der DB-Reihenfolge).
     *
     * @return int|null Konflikt-ID für Fehlertext, oder null wenn konsistent
     */
    public function firstConflictingIdForSameMatchKeyAfterSave(int $editId, string $matchKey, ?int $proposedMasterId): ?int
    {
        if ($matchKey === '') {
            return null;
        }
        foreach ($this->findAllIdsWithSameMatchKey($matchKey, $editId) as $otherId) {
            if (!$this->allowGleicherClusterNachSave($editId, $otherId, $proposedMasterId)) {
                return $otherId;
            }
        }

        return null;
    }

    /**
     * Gleicher Anzeigename (exakt) ist erlaubt, wenn nach Speichern beide im selben kanonischen Cluster sind.
     * (Zwei eigenständige Datensätze / zwei Cluster mit identischem Namen bleiben unzulässig.)
     */
    public function allowExactEinrichtungsnameTrotzTreffer(int $editId, int $otherId, ?int $proposedMasterId): bool
    {
        return $this->allowGleicherClusterNachSave($editId, $otherId, $proposedMasterId);
    }

    /**
     * @param int $editId 0 = Neuanlage (nur mit Master-Ziel sinnvoll)
     */
    private function allowGleicherClusterNachSave(int $editId, int $otherId, ?int $proposedMasterId): bool
    {
        if ($otherId <= 0) {
            return false;
        }
        $all = $this->fetchAllOrdered();
        $map = EinrichtungenStammCluster::idMap($all);
        $otherRow = $map[$otherId] ?? null;
        if ($otherRow === null) {
            return false;
        }
        $otherRoot = EinrichtungenStammCluster::resolveRootMasterId($otherRow, $map);
        if ($otherRoot <= 0) {
            return false;
        }

        if ($editId <= 0) {
            if ($proposedMasterId === null || $proposedMasterId <= 0) {
                return false;
            }
            $mRow = $map[$proposedMasterId] ?? null;
            if ($mRow === null) {
                return false;
            }
            $newRoot = EinrichtungenStammCluster::resolveRootMasterId($mRow, $map);

            return $newRoot === $otherRoot;
        }

        if ($otherId === $editId) {
            return true;
        }

        $selfRow = $map[$editId] ?? null;
        if ($selfRow === null) {
            return false;
        }
        $mid = ($proposedMasterId !== null && $proposedMasterId > 0) ? $proposedMasterId : null;
        $simRow = [
            'id' => (int) ($selfRow['id'] ?? 0),
            'master_einrichtung_id' => $mid,
        ];
        $selfRootAfter = EinrichtungenStammCluster::resolveRootMasterId($simRow, $map);

        return $selfRootAfter > 0 && $selfRootAfter === $otherRoot;
    }

    /**
     * @return list<int>
     */
    public function findAllIdsWithSameMatchKey(string $matchKey, int $excludeId): array
    {
        if ($matchKey === '') {
            return [];
        }
        $t = $this->table();
        $sql = $excludeId > 0
            ? $this->wpdb->prepare(
                "SELECT id, einrichtung, einrichtung_normalized FROM {$t} WHERE id != %d",
                $excludeId
            )
            : "SELECT id, einrichtung, einrichtung_normalized FROM {$t}";
        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        $out = [];
        foreach ($rows ?: [] as $r) {
            if (EinrichtungenMatching::matchKeyFromRow($r) === $matchKey) {
                $found = (int) ($r['id'] ?? 0);
                if ($found > 0) {
                    $out[] = $found;
                }
            }
        }

        return $out;
    }

    /**
     * Andere Stammdaten-Zeile mit demselben Match-Key (wie Seed/Matching), optional aktuelle ID ausschließen.
     */
    public function findOtherIdWithSameMatchKey(string $matchKey, int $excludeId): ?int
    {
        $all = $this->findAllIdsWithSameMatchKey($matchKey, $excludeId);

        return $all[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function distinctMandantenfelderFromStamm(): array
    {
        $t = $this->table();
        $vals = $this->wpdb->get_col(
            "SELECT DISTINCT fachbereich_intern FROM {$t}
             WHERE fachbereich_intern IS NOT NULL AND TRIM(fachbereich_intern) != ''
             ORDER BY fachbereich_intern ASC"
        );
        if (!$vals) {
            return [];
        }

        return array_values(array_map(static fn ($v) => (string) $v, $vals));
    }

    /**
     * @return list<string>
     */
    public function distinctFachbereichBoerseFromStamm(): array
    {
        $t = $this->table();
        $vals = $this->wpdb->get_col(
            "SELECT DISTINCT fachbereich_boerse FROM {$t}
             WHERE TRIM(fachbereich_boerse) != ''
             ORDER BY fachbereich_boerse ASC"
        );
        if (!$vals) {
            return [];
        }

        return array_values(array_map(static fn ($v) => (string) $v, $vals));
    }

    /**
     * Einrichtungsnamen aus Stamm für Dropdowns.
     *
     * @return list<string>
     */
    public function distinctEinrichtungsnamenFromStamm(): array
    {
        $t = $this->table();
        $vals = $this->wpdb->get_col(
            "SELECT DISTINCT einrichtung FROM {$t}
             WHERE einrichtung IS NOT NULL AND TRIM(einrichtung) != ''
             ORDER BY einrichtung ASC"
        );
        if (!$vals) {
            return [];
        }

        return array_values(array_map(static fn ($v) => (string) $v, $vals));
    }

    /**
     * Nur Wurzel-Master (kein master_einrichtung_id) — für Masterlisten-Dropdowns.
     *
     * @return list<string>
     */
    public function distinctEinrichtungsnamenNurWurzelMaster(): array
    {
        $t = $this->table();
        $vals = $this->wpdb->get_col(
            "SELECT DISTINCT einrichtung FROM {$t}
             WHERE einrichtung IS NOT NULL AND TRIM(einrichtung) != ''
               AND (master_einrichtung_id IS NULL OR master_einrichtung_id = 0)
             ORDER BY einrichtung ASC"
        );
        if (!$vals) {
            return [];
        }

        return array_values(array_map(static fn ($v) => (string) $v, $vals));
    }

    /**
     * @return list<string>
     */
    public function distinctEinrichtungenFromAusschreibungen(): array
    {
        $tblA = $this->wpdb->prefix . Database::TABLE_AUSSCHREIBUNGEN;
        $vals = $this->wpdb->get_col(
            "SELECT DISTINCT einrichtung FROM {$tblA}
             WHERE zuletzt_gesehen_api IS NOT NULL
               AND einrichtung IS NOT NULL AND TRIM(einrichtung) != ''
             ORDER BY einrichtung ASC"
        );
        if (!$vals) {
            return [];
        }

        return array_values(array_map(static fn ($v) => (string) $v, $vals));
    }

    /**
     * @return list<string>
     */
    public function mergedEinrichtungOptions(): array
    {
        $m = array_merge(
            $this->distinctEinrichtungsnamenFromStamm(),
            $this->distinctEinrichtungenFromAusschreibungen()
        );
        $out = [];
        $seen = [];
        foreach ($m as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $k = AktiveStellenQuery::normalizeFilterValue($name);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $name;
        }
        sort($out, SORT_STRING);

        return $out;
    }

    /**
     * Wie mergedEinrichtungOptions, aber Stammteil nur Wurzel-Master (für Masterlisten-Filter).
     *
     * @return list<string>
     */
    public function mergedEinrichtungOptionsFuerMasterliste(): array
    {
        $m = array_merge(
            $this->distinctEinrichtungsnamenNurWurzelMaster(),
            $this->distinctEinrichtungenFromAusschreibungen()
        );
        $out = [];
        $seen = [];
        foreach ($m as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $k = AktiveStellenQuery::normalizeFilterValue($name);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $name;
        }
        sort($out, SORT_STRING);

        return $out;
    }

    /**
     * Seed mit Matching: exakt gleicher Key → nur API-Namen nachpflegen; ähnlich → Prüffall-Zeile; sonst neu.
     *
     * @return array{neu: int, bereits_zugeordnet: int, prueffaelle: int}
     */
    public function seedMissingFromAusschreibungen(): array
    {
        $tblA = $this->wpdb->prefix . Database::TABLE_AUSSCHREIBUNGEN;
        $sql = "SELECT
                    TRIM(einrichtung) AS einrichtung,
                    COALESCE(NULLIF(MAX(TRIM(fachbereich_boerse)), ''), '') AS fachbereich_boerse,
                    MAX(fachbereich_intern) AS fachbereich_intern
                FROM {$tblA}
                WHERE einrichtung IS NOT NULL AND TRIM(einrichtung) != ''
                GROUP BY TRIM(einrichtung)
                ORDER BY einrichtung ASC";
        $agg = $this->wpdb->get_results($sql, ARRAY_A);
        if (!$agg) {
            return ['neu' => 0, 'bereits_zugeordnet' => 0, 'prueffaelle' => 0];
        }

        $stammRows = $this->fetchAllOrdered();
        $idToRow = EinrichtungenStammCluster::idMap($stammRows);
        $neu = 0;
        $bereits = 0;
        $pruef = 0;
        $now = \current_time('mysql');
        $t = $this->table();

        foreach ($agg as $row) {
            $raw = trim((string) ($row['einrichtung'] ?? ''));
            if ($raw === '') {
                continue;
            }
            $key = EinrichtungenMatching::matchKeyFromRaw($raw);
            if ($key === '') {
                continue;
            }

            $exactId = EinrichtungenMatching::resolveSeedUpdateStammId($stammRows, $key, $idToRow);
            if ($exactId !== null) {
                $fb = (string) ($row['fachbereich_boerse'] ?? '');
                $fbiRaw = $row['fachbereich_intern'] ?? null;
                $fbi = ($fbiRaw !== null && trim((string) $fbiRaw) !== '') ? trim((string) $fbiRaw) : null;

                $stammRow = null;
                foreach ($stammRows as $sr) {
                    if ((int) ($sr['id'] ?? 0) === $exactId) {
                        $stammRow = $sr;
                        break;
                    }
                }
                if ($stammRow === null) {
                    $bereits++;
                    continue;
                }

                $oldHint = isset($stammRow['pruef_hinweis']) ? (string) $stammRow['pruef_hinweis'] : '';
                $reasons = EinrichtungenMatching::detectApiStammAbweichungen($stammRow, $raw, $fb, $fbi);
                $mergedHint = self::truncatePruefHinweisForDb(
                    EinrichtungenMatching::mergeApiAbweichungInPruefHinweis(
                        $oldHint !== '' ? $oldHint : null,
                        $reasons
                    )
                );
                $normFromStamm = EinrichtungenMatching::matchKeyFromRaw((string) ($stammRow['einrichtung'] ?? ''));
                $pruefStatus = (string) ($stammRow['pruefstatus'] ?? EinrichtungenMatching::PRUEFSTATUS_OK);
                if ($reasons !== []) {
                    $pruefStatus = EinrichtungenMatching::PRUEFSTATUS_PRUEFEN;
                } elseif (
                    $pruefStatus === EinrichtungenMatching::PRUEFSTATUS_PRUEFEN
                    && trim(EinrichtungenMatching::stripApiAbweichungBlockFromHinweis($oldHint)) === ''
                ) {
                    $pruefStatus = EinrichtungenMatching::PRUEFSTATUS_OK;
                }

                $updateFields = [
                    'letzter_api_name' => $raw,
                    'aktualisiert_am' => $now,
                    'einrichtung_normalized' => $normFromStamm !== '' ? $normFromStamm : null,
                    'pruef_hinweis' => $mergedHint !== '' ? $mergedHint : null,
                    'pruefstatus' => $pruefStatus,
                ];
                $this->wpdb->update(
                    $t,
                    $updateFields,
                    ['id' => $exactId],
                    ['%s', '%s', '%s', '%s', '%s'],
                    ['%d']
                );

                foreach ($stammRows as $idx => $sr) {
                    if ((int) ($sr['id'] ?? 0) !== $exactId) {
                        continue;
                    }
                    $stammRows[$idx]['letzter_api_name'] = $raw;
                    $stammRows[$idx]['einrichtung_normalized'] = $normFromStamm !== '' ? $normFromStamm : null;
                    $stammRows[$idx]['pruef_hinweis'] = $updateFields['pruef_hinweis'];
                    $stammRows[$idx]['pruefstatus'] = $pruefStatus;
                    break;
                }

                $this->syncClusterErkennungsstringsForRoot($exactId, $stammRows, $idToRow);
                $refreshed = $this->getById($exactId);
                if ($refreshed !== null) {
                    foreach ($stammRows as $idx => $sr) {
                        if ((int) ($sr['id'] ?? 0) === $exactId) {
                            $stammRows[$idx] = $refreshed;
                            break;
                        }
                    }
                    $idToRow = EinrichtungenStammCluster::idMap($stammRows);
                }

                $bereits++;
                continue;
            }

            $existsSameDisplay = (int) $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$t} WHERE einrichtung = %s LIMIT 1",
                $raw
            ));
            if ($existsSameDisplay > 0) {
                $bereits++;
                continue;
            }

            $aehnlich = EinrichtungenMatching::findAehnlichstenNichtExakten($stammRows, $key);
            $fb = (string) ($row['fachbereich_boerse'] ?? '');
            $fbiRaw = $row['fachbereich_intern'] ?? null;
            $fbi = ($fbiRaw !== null && trim((string) $fbiRaw) !== '') ? trim((string) $fbiRaw) : null;

            if ($aehnlich !== null) {
                $hinweis = sprintf(
                    /* translators: 1: similar facility name, 2: ID, 3: similarity percent */
                    \__('Mögliche Dublette: ähnlich zu „%1$s“ (ID %2$d, ~%3$s %% Übereinstimmung). Bitte prüfen.', 'bs-awo-jobs-statistik'),
                    $aehnlich['name'],
                    $aehnlich['id'],
                    (string) $aehnlich['prozent']
                );
                $this->wpdb->insert($t, [
                    'einrichtung' => $raw,
                    'einrichtung_normalized' => $key,
                    'letzter_api_name' => $raw,
                    'master_einrichtung_id' => null,
                    'fachbereich_boerse' => $fb,
                    'fachbereich_intern' => $fbi,
                    'aktiv' => 1,
                    'bemerkung' => null,
                    'soll_vza_fachkraefte' => null,
                    'soll_vza_hilfskraefte' => null,
                    'soll_vza_3' => null,
                    'soll_vza_4' => null,
                    'soll_vza_5' => null,
                    'gesamt_vza_override' => null,
                    'pruefstatus' => EinrichtungenMatching::PRUEFSTATUS_PRUEFEN,
                    'pruef_hinweis' => $hinweis,
                    'quelle' => EinrichtungenMatching::QUELLE_SEED,
                    'erstellt_am' => $now,
                    'aktualisiert_am' => $now,
                ], ['%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

                $newId = (int) $this->wpdb->insert_id;
                if ($newId > 0) {
                    $stammRows[] = [
                        'id' => $newId,
                        'einrichtung' => $raw,
                        'einrichtung_normalized' => $key,
                        'letzter_api_name' => $raw,
                        'master_einrichtung_id' => null,
                        'fachbereich_boerse' => $fb,
                        'fachbereich_intern' => $fbi,
                        'aktiv' => 1,
                        'bemerkung' => null,
                        'soll_vza_fachkraefte' => null,
                        'soll_vza_hilfskraefte' => null,
                        'soll_vza_3' => null,
                        'soll_vza_4' => null,
                        'soll_vza_5' => null,
                        'gesamt_vza_override' => null,
                        'pruefstatus' => EinrichtungenMatching::PRUEFSTATUS_PRUEFEN,
                        'pruef_hinweis' => $hinweis,
                        'quelle' => EinrichtungenMatching::QUELLE_SEED,
                        'erstellt_am' => $now,
                        'aktualisiert_am' => $now,
                    ];
                    $this->syncClusterErkennungsstringsForRootByAnyId($newId);
                    $idToRow = EinrichtungenStammCluster::idMap($stammRows);
                }
                $neu++;
                $pruef++;
                continue;
            }

            $this->wpdb->insert($t, [
                'einrichtung' => $raw,
                'einrichtung_normalized' => $key,
                'letzter_api_name' => $raw,
                'master_einrichtung_id' => null,
                'fachbereich_boerse' => $fb,
                'fachbereich_intern' => $fbi,
                'aktiv' => 1,
                'bemerkung' => null,
                'soll_vza_fachkraefte' => null,
                'soll_vza_hilfskraefte' => null,
                'soll_vza_3' => null,
                'soll_vza_4' => null,
                'soll_vza_5' => null,
                'gesamt_vza_override' => null,
                'pruefstatus' => EinrichtungenMatching::PRUEFSTATUS_OK,
                'pruef_hinweis' => null,
                'quelle' => EinrichtungenMatching::QUELLE_SEED,
                'erstellt_am' => $now,
                'aktualisiert_am' => $now,
            ], ['%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

            $newId = (int) $this->wpdb->insert_id;
            if ($newId > 0) {
                $stammRows[] = [
                    'id' => $newId,
                    'einrichtung' => $raw,
                    'einrichtung_normalized' => $key,
                    'letzter_api_name' => $raw,
                    'master_einrichtung_id' => null,
                    'fachbereich_boerse' => $fb,
                    'fachbereich_intern' => $fbi,
                    'aktiv' => 1,
                    'bemerkung' => null,
                    'soll_vza_fachkraefte' => null,
                    'soll_vza_hilfskraefte' => null,
                    'soll_vza_3' => null,
                    'soll_vza_4' => null,
                    'soll_vza_5' => null,
                    'gesamt_vza_override' => null,
                    'pruefstatus' => EinrichtungenMatching::PRUEFSTATUS_OK,
                    'pruef_hinweis' => null,
                    'quelle' => EinrichtungenMatching::QUELLE_SEED,
                    'erstellt_am' => $now,
                    'aktualisiert_am' => $now,
                ];
                $this->syncClusterErkennungsstringsForRootByAnyId($newId);
                $idToRow = EinrichtungenStammCluster::idMap($stammRows);
            }
            $neu++;
        }

        return ['neu' => $neu, 'bereits_zugeordnet' => $bereits, 'prueffaelle' => $pruef];
    }

    /**
     * @param array{
     *   einrichtung: string,
     *   fachbereich_boerse: string,
     *   fachbereich_intern: string,
     *   aktiv: int,
     *   bemerkung: string,
     *   soll_vza_fachkraefte: ?float,
     *   soll_vza_hilfskraefte: ?float,
     *   soll_vza_3: ?float,
     *   soll_vza_4: ?float,
     *   soll_vza_5: ?float,
     *   gesamt_vza_override: ?float,
     *   pruefstatus?: string,
     *   pruef_hinweis?: ?string,
     * } $data
     */
    public function insert(array $data): int
    {
        $now = \current_time('mysql');
        $norm = EinrichtungenMatching::matchKeyFromRaw($data['einrichtung']);
        $insMaster = isset($data['master_einrichtung_id']) && (int) $data['master_einrichtung_id'] > 0
            ? (int) $data['master_einrichtung_id'] : null;
        $this->wpdb->insert($this->table(), [
            'einrichtung' => $data['einrichtung'],
            'einrichtung_normalized' => $norm !== '' ? $norm : null,
            'letzter_api_name' => null,
            'master_einrichtung_id' => $insMaster,
            'fachbereich_boerse' => $data['fachbereich_boerse'],
            'fachbereich_intern' => $data['fachbereich_intern'] !== '' ? $data['fachbereich_intern'] : null,
            'aktiv' => $data['aktiv'],
            'bemerkung' => $data['bemerkung'] !== '' ? $data['bemerkung'] : null,
            'soll_vza_fachkraefte' => self::decimalOrNull($data['soll_vza_fachkraefte'] ?? null),
            'soll_vza_hilfskraefte' => self::decimalOrNull($data['soll_vza_hilfskraefte'] ?? null),
            'soll_vza_3' => self::decimalOrNull($data['soll_vza_3'] ?? null),
            'soll_vza_4' => self::decimalOrNull($data['soll_vza_4'] ?? null),
            'soll_vza_5' => self::decimalOrNull($data['soll_vza_5'] ?? null),
            'gesamt_vza_override' => self::decimalOrNull($data['gesamt_vza_override'] ?? null),
            'pruefstatus' => self::sanitizePruefstatus($data['pruefstatus'] ?? null),
            'pruef_hinweis' => isset($data['pruef_hinweis']) && (string) $data['pruef_hinweis'] !== ''
                ? (string) $data['pruef_hinweis'] : null,
            'quelle' => EinrichtungenMatching::QUELLE_MANUELL,
            'erstellt_am' => $now,
            'aktualisiert_am' => $now,
        ], ['%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

        $newId = (int) $this->wpdb->insert_id;
        if ($newId > 0) {
            $this->syncClusterErkennungsstringsForRootByAnyId($newId);
        }

        return $newId;
    }

    /**
     * Bündelt alle Namens-Schreibweisen eines Clusters im Wurzel-Master (letzter_api_name, zeilenweise),
     * damit facilityKeys alle Varianten erkennt.
     *
     * @param list<array<string, mixed>>|null $allRows Wenn gesetzt (z. B. im Seed), diesen Snapshot statt DB lesen
     * @param array<int, array<string, mixed>>|null $idToRow Zu $allRows passende ID-Map; sonst wird sie gebildet
     */
    public function syncClusterErkennungsstringsForRoot(int $rootId, ?array $allRows = null, ?array $idToRow = null): void
    {
        if ($rootId <= 0) {
            return;
        }
        $all = $allRows ?? $this->fetchAllOrdered();
        $idMap = $idToRow ?? EinrichtungenStammCluster::idMap($all);
        if (!isset($idMap[$rootId])) {
            return;
        }
        $members = EinrichtungenStammCluster::membersOfMaster($rootId, $all);
        $spellings = [];
        foreach ($members as $m) {
            $e = trim((string) ($m['einrichtung'] ?? ''));
            if ($e !== '') {
                $spellings[$e] = true;
            }
            $lan = trim((string) ($m['letzter_api_name'] ?? ''));
            if ($lan === '') {
                continue;
            }
            foreach (preg_split('/\R+/u', $lan) ?: [$lan] as $part) {
                $p = trim((string) $part);
                if ($p !== '') {
                    $spellings[$p] = true;
                }
            }
        }
        if ($spellings === []) {
            return;
        }
        $list = array_keys($spellings);
        sort($list, SORT_STRING);
        $merged = implode("\n", $list);
        if (function_exists('mb_strlen') && mb_strlen($merged, 'UTF-8') > 255) {
            $merged = mb_substr($merged, 0, 255, 'UTF-8');
        } elseif (strlen($merged) > 255) {
            $merged = substr($merged, 0, 255);
        }
        $t = $this->table();
        $now = \current_time('mysql');
        $this->wpdb->update(
            $t,
            ['letzter_api_name' => $merged, 'aktualisiert_am' => $now],
            ['id' => $rootId],
            ['%s', '%s'],
            ['%d']
        );
    }

    /**
     * Synct den Cluster des Datensatzes $anyId (Mitglied oder Master).
     */
    private function syncClusterErkennungsstringsForRootByAnyId(int $anyId): void
    {
        if ($anyId <= 0) {
            return;
        }
        $all = $this->fetchAllOrdered();
        $idToRow = EinrichtungenStammCluster::idMap($all);
        $row = $idToRow[$anyId] ?? null;
        if ($row === null) {
            return;
        }
        $rootId = EinrichtungenStammCluster::resolveRootMasterId($row, $idToRow);
        $this->syncClusterErkennungsstringsForRoot($rootId);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        $now = \current_time('mysql');
        $fbi = isset($data['fachbereich_intern']) && (string) $data['fachbereich_intern'] !== ''
            ? (string) $data['fachbereich_intern'] : null;

        $einrichtung = (string) ($data['einrichtung'] ?? '');
        $norm = EinrichtungenMatching::matchKeyFromRaw($einrichtung);
        $masterRaw = $data['master_einrichtung_id'] ?? null;
        $masterEinrichtungId = ($masterRaw !== null && (string) $masterRaw !== '' && (int) $masterRaw > 0)
            ? (int) $masterRaw : null;

        $ok = $this->wpdb->update(
            $this->table(),
            [
                'einrichtung' => $einrichtung,
                'einrichtung_normalized' => $norm !== '' ? $norm : null,
                'master_einrichtung_id' => $masterEinrichtungId,
                'fachbereich_boerse' => $data['fachbereich_boerse'],
                'fachbereich_intern' => $fbi,
                'aktiv' => (int) $data['aktiv'],
                'bemerkung' => isset($data['bemerkung']) && (string) $data['bemerkung'] !== ''
                    ? (string) $data['bemerkung'] : null,
                'soll_vza_fachkraefte' => self::decimalOrNull($data['soll_vza_fachkraefte'] ?? null),
                'soll_vza_hilfskraefte' => self::decimalOrNull($data['soll_vza_hilfskraefte'] ?? null),
                'soll_vza_3' => self::decimalOrNull($data['soll_vza_3'] ?? null),
                'soll_vza_4' => self::decimalOrNull($data['soll_vza_4'] ?? null),
                'soll_vza_5' => self::decimalOrNull($data['soll_vza_5'] ?? null),
                'gesamt_vza_override' => self::decimalOrNull($data['gesamt_vza_override'] ?? null),
                'pruefstatus' => self::sanitizePruefstatus($data['pruefstatus'] ?? null),
                'pruef_hinweis' => isset($data['pruef_hinweis']) && (string) $data['pruef_hinweis'] !== ''
                    ? (string) $data['pruef_hinweis'] : null,
                'aktualisiert_am' => $now,
            ],
            ['id' => $id],
            ['%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );

        if ($ok !== false) {
            $this->syncClusterErkennungsstringsForRootByAnyId($id);
        }

        return $ok !== false;
    }

    private static function sanitizePruefstatus(?string $v): string
    {
        $v = $v !== null ? trim($v) : '';
        if ($v === EinrichtungenMatching::PRUEFSTATUS_PRUEFEN) {
            return EinrichtungenMatching::PRUEFSTATUS_PRUEFEN;
        }

        return EinrichtungenMatching::PRUEFSTATUS_OK;
    }

    /**
     * @param float|string|null $v
     */
    private static function decimalOrNull($v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return (string) (float) $v;
    }

    /**
     * @return list<string>
     */
    public function distinctMandantenfelderFromAusschreibungen(): array
    {
        $tblA = $this->wpdb->prefix . Database::TABLE_AUSSCHREIBUNGEN;
        $vals = $this->wpdb->get_col(
            "SELECT DISTINCT fachbereich_intern FROM {$tblA}
             WHERE fachbereich_intern IS NOT NULL AND TRIM(fachbereich_intern) != ''
             ORDER BY fachbereich_intern ASC"
        );
        if (!$vals) {
            return [];
        }

        return array_values(array_map(static fn ($v) => (string) $v, $vals));
    }

    /**
     * @return list<string>
     */
    public function distinctFachbereichBoerseFromAusschreibungen(): array
    {
        $tblA = $this->wpdb->prefix . Database::TABLE_AUSSCHREIBUNGEN;
        $vals = $this->wpdb->get_col(
            "SELECT DISTINCT fachbereich_boerse FROM {$tblA}
             WHERE TRIM(fachbereich_boerse) != ''
             ORDER BY fachbereich_boerse ASC"
        );
        if (!$vals) {
            return [];
        }

        return array_values(array_map(static fn ($v) => (string) $v, $vals));
    }

    /**
     * Eigenständige Datensätze (ohne Master) für Dropdown „Gehört zu …“, optional aktuellen Datensatz ausschließen.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchRootMasterKandidatenFuerSelect(int $excludeId): array
    {
        $rows = $this->fetchAllOrdered();
        $out = [];
        foreach ($rows as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id <= 0 || $id === $excludeId) {
                continue;
            }
            if ((int) ($r['master_einrichtung_id'] ?? 0) > 0) {
                continue;
            }
            $out[] = $r;
        }
        usort($out, static function (array $a, array $b): int {
            return strcmp((string) ($a['einrichtung'] ?? ''), (string) ($b['einrichtung'] ?? ''));
        });

        return $out;
    }

    /**
     * @return list<string>
     */
    public function mergedMandantenfeldOptions(): array
    {
        $m = array_merge(
            $this->distinctMandantenfelderFromStamm(),
            $this->distinctMandantenfelderFromAusschreibungen()
        );
        $m = array_unique($m);
        sort($m, SORT_STRING);

        return array_values($m);
    }

    /**
     * @return list<string>
     */
    public function mergedFachbereichBoerseOptions(): array
    {
        $m = array_merge(
            $this->distinctFachbereichBoerseFromStamm(),
            $this->distinctFachbereichBoerseFromAusschreibungen()
        );
        $m = array_unique($m);
        sort($m, SORT_STRING);

        return array_values($m);
    }
}
