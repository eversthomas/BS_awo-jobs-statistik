<?php
/**
 * Steuerung Excel-Export „Aktive Stellen“: Umfang (alle vs. gefiltert) und Checkbox-Logik.
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\AktiveStellen;

final class AktiveStellenExportOptions
{
    public const SCOPE_ALL = 'all';
    public const SCOPE_FILTERED = 'filtered';

    public const STAT_ALLE = 'alle';
    public const STAT_NUR_BERUECKSICHTIGT = 'nur_beruecksichtigt';

    public function __construct(
        public string $scope = self::SCOPE_FILTERED,
        public string $beruecksichtigung = self::STAT_ALLE
    ) {
    }

    public function useUiFilters(): bool
    {
        return $this->scope === self::SCOPE_FILTERED;
    }

    public function nurBeruecksichtigt(): bool
    {
        return $this->beruecksichtigung === self::STAT_NUR_BERUECKSICHTIGT;
    }
}
