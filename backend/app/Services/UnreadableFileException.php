<?php

namespace App\Services;

use App\Models\ImportHistory;

/**
 * Fichier illisible / type non supporté : porte l'historique persisté.
 */
class UnreadableFileException extends \RuntimeException
{
    public function __construct(public ImportHistory $history)
    {
        parent::__construct('Fichier illisible.');
    }
}
