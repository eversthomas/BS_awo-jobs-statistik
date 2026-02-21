<?php
/**
 * Deduplizierung: Logische Stellen aus Ausschreibungen (titel + einrichtung).
 * ARCHITECTURE.md: Variante C – automatisch mit manueller Korrektur.
 * Keine WordPress-Abhängigkeiten; Datenbank über injiziertes Objekt (z. B. $wpdb).
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\Dedup;

use BS_Awo_Jobs_Statistik\Core\Database;

final class LogischeStellen
{
    /** @var object mit prefix, prepare(), query(), get_var(), get_results(), insert(), update(), delete() */
    private $db;

    public function __construct(object $db)
    {
        $this->db = $db;
    }

    /**
     * Alle Ausschreibungen gruppieren, logische Stellen anlegen/zuordnen.
     *
     * @return array{erstellt: int, zugeordnet: int}
     */
    public function run(): array
    {
        $tblA = $this->db->prefix . Database::TABLE_AUSSCHREIBUNGEN;
        $tblL = $this->db->prefix . Database::TABLE_LOGISCHE_STELLEN;
        $tblZ = $this->db->prefix . Database::TABLE_ZUORDNUNGEN;

        $rows = $this->db->get_results(
            "SELECT stellennummer, titel, einrichtung, fachbereich_boerse, fachbereich_intern, anstellungsart FROM {$tblA}",
            ARRAY_A
        );
        if (empty($rows)) {
            return ['erstellt' => 0, 'zugeordnet' => 0];
        }

        $groups = [];
        foreach ($rows as $row) {
            $titel = trim((string) ($row['titel'] ?? ''));
            $einrichtung = trim((string) ($row['einrichtung'] ?? ''));
            $key = mb_strtolower($titel) . "\n" . mb_strtolower($einrichtung);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'titel' => $titel,
                    'einrichtung' => $einrichtung,
                    'fachbereich_boerse' => $row['fachbereich_boerse'] ?? null,
                    'fachbereich_intern' => $row['fachbereich_intern'] ?? null,
                    'anstellungsart' => $row['anstellungsart'] ?? null,
                    'stellennummern' => [],
                ];
            }
            $groups[$key]['stellennummern'][] = $row['stellennummer'];
        }

        $erstellt = 0;
        $zugeordnet = 0;
        $now = date('Y-m-d H:i:s');

        foreach ($groups as $group) {
            $titel = $group['titel'];
            $einrichtung = $group['einrichtung'];
            $keyLower = mb_strtolower($titel) . "\n" . mb_strtolower($einrichtung);

            $logischeStelleId = $this->db->get_var($this->db->prepare(
                "SELECT id FROM {$tblL} WHERE LOWER(TRIM(titel)) = %s AND LOWER(TRIM(einrichtung)) = %s",
                mb_strtolower($titel),
                mb_strtolower($einrichtung)
            ));

            if ($logischeStelleId === null || $logischeStelleId === '') {
                $this->db->insert($tblL, [
                    'titel' => $titel,
                    'einrichtung' => $einrichtung,
                    'fachbereich_boerse' => $group['fachbereich_boerse'],
                    'fachbereich_intern' => $group['fachbereich_intern'],
                    'anstellungsart' => $group['anstellungsart'],
                    'manuell_verifiziert' => 0,
                    'erstellt_am' => $now,
                    'aktualisiert_am' => $now,
                ], ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']);
                $logischeStelleId = $this->db->insert_id;
                if ($logischeStelleId) {
                    $erstellt++;
                }
            } else {
                $logischeStelleId = (int) $logischeStelleId;
            }

            if (!$logischeStelleId) {
                continue;
            }

            foreach ($group['stellennummern'] as $stellennummer) {
                $exists = $this->db->get_var($this->db->prepare(
                    "SELECT 1 FROM {$tblZ} WHERE stellennummer = %s",
                    $stellennummer
                ));
                if ($exists) {
                    continue;
                }
                $ok = $this->db->insert($tblZ, [
                    'logische_stelle_id' => $logischeStelleId,
                    'stellennummer' => $stellennummer,
                    'zuordnungstyp' => 'auto',
                    'erstellt_am' => $now,
                ], ['%d', '%s', '%s', '%s']);
                if ($ok) {
                    $zugeordnet++;
                }
            }
        }

        return ['erstellt' => $erstellt, 'zugeordnet' => $zugeordnet];
    }

    /**
     * Ausschreibung manuell einer logischen Stelle zuordnen; setzt zuordnungstyp = 'manuell', manuell_verifiziert = 1.
     */
    public function manuellZuordnen(string $stellennummer, int $logischeStellId): bool
    {
        $tblZ = $this->db->prefix . Database::TABLE_ZUORDNUNGEN;
        $tblL = $this->db->prefix . Database::TABLE_LOGISCHE_STELLEN;
        $now = date('Y-m-d H:i:s');

        $updatedZ = $this->db->update(
            $tblZ,
            ['logische_stelle_id' => $logischeStellId, 'zuordnungstyp' => 'manuell'],
            ['stellennummer' => $stellennummer],
            ['%d', '%s'],
            ['%s']
        );

        if ($updatedZ === false) {
            return false;
        }

        $this->db->update(
            $tblL,
            ['manuell_verifiziert' => 1, 'aktualisiert_am' => $now],
            ['id' => $logischeStellId],
            ['%d', '%s'],
            ['%d']
        );

        return true;
    }

    /**
     * Zuordnung entfernen und neue eigenständige logische Stelle für diese Ausschreibung anlegen.
     */
    public function zuordnungTrennen(string $stellennummer): bool
    {
        $tblA = $this->db->prefix . Database::TABLE_AUSSCHREIBUNGEN;
        $tblL = $this->db->prefix . Database::TABLE_LOGISCHE_STELLEN;
        $tblZ = $this->db->prefix . Database::TABLE_ZUORDNUNGEN;

        $row = $this->db->get_row($this->db->prepare(
            "SELECT titel, einrichtung, fachbereich_boerse, fachbereich_intern, anstellungsart FROM {$tblA} WHERE stellennummer = %s",
            $stellennummer
        ), ARRAY_A);

        if ($row === null) {
            return false;
        }

        $this->db->delete($tblZ, ['stellennummer' => $stellennummer], ['%s']);

        $now = date('Y-m-d H:i:s');
        $this->db->insert($tblL, [
            'titel' => trim((string) $row['titel']),
            'einrichtung' => trim((string) $row['einrichtung']),
            'fachbereich_boerse' => $row['fachbereich_boerse'] ?? null,
            'fachbereich_intern' => $row['fachbereich_intern'] ?? null,
            'anstellungsart' => $row['anstellungsart'] ?? null,
            'manuell_verifiziert' => 1,
            'erstellt_am' => $now,
            'aktualisiert_am' => $now,
        ], ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']);

        $newId = $this->db->insert_id;
        if (!$newId) {
            return false;
        }

        $ok = $this->db->insert($tblZ, [
            'logische_stelle_id' => (int) $newId,
            'stellennummer' => $stellennummer,
            'zuordnungstyp' => 'manuell',
            'erstellt_am' => $now,
        ], ['%d', '%s', '%s', '%s']);

        return (bool) $ok;
    }
}
