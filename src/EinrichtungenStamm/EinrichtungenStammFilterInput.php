<?php
/**
 * Filter für Stammdatenliste und Dashboard-Auswertung Einrichtungen (GET), analog zu „Aktive Stellen“.
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\EinrichtungenStamm;

final class EinrichtungenStammFilterInput
{
    /**
     * @param string $aktiv '' | '1' | '0' — leer = alle
     * @param string $einrichtung Auswahl wie bei bs_as_einr (exakter Anzeigename oder Wert aus Dropdown)
     * @param string $pruefstatus '' | 'ok' | 'pruefen' — leer = alle
     * @param string $listenModus '' = alle Stammzeilen; 'wurzel' = nur eigenständige Master (ohne master_einrichtung_id)
     */
    public function __construct(
        public string $q = '',
        public string $fachbereichBoerse = '',
        public string $mandantenfeld = '',
        public string $aktiv = '',
        public string $einrichtung = '',
        public string $pruefstatus = '',
        public string $listenModus = ''
    ) {
    }
}
