<?php
/**
 * Filtereingaben für den Tab „Aktive Stellen“ (GET/Formular),
 * unabhängig von WordPress – reine String-Werte.
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\AktiveStellen;

final class AktiveStellenFilterInput
{
    public function __construct(
        public string $q = '',
        public string $fachbereich = '',
        public string $mandantenfeld = '',
        public string $einrichtung = '',
        public string $plz = '',
        public string $quelleStunden = ''
    ) {
    }
}
