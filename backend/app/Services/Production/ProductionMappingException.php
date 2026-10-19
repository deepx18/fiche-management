<?php

namespace App\Services\Production;

use RuntimeException;

/**
 * La feuille ne peut pas être mappée sur les champs internes : en-tête
 * obligatoire absent, ou configuration de mapping invalide.
 *
 * Aucune ligne n'est écrite lorsque cette exception est levée : la
 * synchronisation est refusée plutôt que de deviner une correspondance.
 */
class ProductionMappingException extends RuntimeException
{
    /** @param list<string> $missing Libellés internes manquants (ex. « contract_reference »). */
    public static function missingHeaders(array $missing): self
    {
        return new self(
            'En-tête(s) obligatoire(s) introuvable(s) dans la feuille : '
            .implode(', ', $missing)
            .'. Vérifiez la ligne d\'en-têtes et la correspondance configurée (config/production.php).'
        );
    }

    public static function invalidConfiguration(string $detail): self
    {
        return new self('Configuration de synchronisation invalide : '.$detail);
    }
}
