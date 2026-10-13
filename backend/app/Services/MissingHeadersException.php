<?php

namespace App\Services;

use App\Models\ImportHistory;

/**
 * En-têtes obligatoires manquants : porte l'historique persisté pour que le
 * contrôleur réponde proprement sans perdre la trace de la tentative.
 */
class MissingHeadersException extends \RuntimeException
{
    public function __construct(public ImportHistory $history, public array $missing)
    {
        parent::__construct('En-têtes manquants : '.implode(', ', $missing));
    }
}
