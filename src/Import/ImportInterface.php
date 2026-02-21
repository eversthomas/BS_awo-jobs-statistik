<?php
/**
 * Interface für alle Importer (Excel/CSV, API).
 * Keine WordPress-Abhängigkeiten.
 */

declare(strict_types=1);

namespace BS_Awo_Jobs_Statistik\Import;

interface ImportInterface
{
    /**
     * Importiert aus einer Datei.
     *
     * @return array{success: int, errors: list<string>} success = Anzahl erfolgreich importierter Zeilen
     */
    public function import(string $filePath): array;
}
