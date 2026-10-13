<?php

namespace App\Services;

use App\Models\ImportHistory;
use App\Models\ImportedFile;

/**
 * Fichier identique déjà importé, sans confirmation explicite : porte
 * l'historique de la tentative refusée.
 */
class DuplicateFileException extends \RuntimeException
{
    public function __construct(public ImportedFile $existing, public ImportHistory $history)
    {
        parent::__construct('Fichier identique déjà importé.');
    }
}
