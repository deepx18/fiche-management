<?php

namespace App\Services\Production;

use RuntimeException;

/**
 * La feuille ne peut pas être mappée de façon sûre sur les champs internes :
 * en-tête obligatoire absent ou dupliqué, ou configuration invalide.
 *
 * Aucune ligne n'est écrite lorsque cette exception est levée : la
 * synchronisation est refusée plutôt que de deviner une correspondance.
 */
class ProductionMappingException extends RuntimeException
{
    /**
     * @param  list<string>  $missing  Champs canoniques manquants
     * @param  list<string>  $duplicates  Libellés présents plusieurs fois
     */
    public static function invalidHeaders(array $missing, array $duplicates = []): self
    {
        $parts = [];

        if ($missing !== []) {
            $parts[] = 'en-tête(s) obligatoire(s) introuvable(s) : '
                .implode(', ', array_map(
                    static fn (string $field): string => ProductionSheetSchema::FIELD_LABELS[$field] ?? $field,
                    $missing,
                ));
        }

        if ($duplicates !== []) {
            $parts[] = 'en-tête(s) présent(s) plusieurs fois : '.implode(', ', $duplicates);
        }

        return new self(
            'Feuille « Production » inexploitable — '.implode(' ; ', $parts)
            .'. Vérifiez la ligne d\'en-têtes (les 11 colonnes doivent être présentes une seule fois). '
            .'Aucune donnée n\'a été modifiée.'
        );
    }

    public static function headerRowNotFound(): self
    {
        return new self(
            'Aucune ligne d\'en-têtes trouvée dans la plage configurée. Vérifiez GOOGLE_SHEETS_RANGE '
            .'et GOOGLE_SHEETS_HEADER_ROW. Aucune donnée n\'a été modifiée.'
        );
    }

    public static function invalidConfiguration(string $detail): self
    {
        return new self('Configuration de synchronisation invalide : '.$detail);
    }
}
