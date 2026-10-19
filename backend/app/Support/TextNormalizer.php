<?php

namespace App\Support;

/**
 * Normalisation des libellés (en-têtes de feuille, colonnes de fichier) :
 * minuscules, accents retirés, espaces multiples réduits. Utilisée par
 * l'import de fiches et la synchronisation Production pour que la
 * reconnaissance des en-têtes reste identique partout.
 */
class TextNormalizer
{
    public static function header(string $header): string
    {
        $value = mb_strtolower(trim($header));
        $trans = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
        if ($trans !== null) {
            $value = $trans->transliterate($value) ?: $value;
        }

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }
}
