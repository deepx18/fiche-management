<?php

namespace App\Support;

/**
 * Normalisation conservatrice des numéros de téléphone, uniquement pour la
 * détection de doublons. La valeur originale (tel) n'est jamais altérée.
 *
 * Règles (documentées dans le README) :
 *  - suppression des séparateurs inoffensifs : espaces, points, tirets,
 *    parenthèses, barres obliques ;
 *  - le premier "+" (préfixe international) est retiré de la même façon qu'un
 *    préfixe "00" : il est supprimé en tête de numéro ;
 *  - le reste doit être purement numérique et compter au moins 6 chiffres,
 *    sinon le numéro est jugé inutilisable (exclus du regroupement) ;
 *  - aucune hypothèse de pays : "0612345678" (local) et "+212612345678"
 *    (international) ne sont PAS considérés équivalents — ce choix évite les
 *    faux positifs, il est assumé et documenté.
 */
class PhoneNormalizer
{
    private const SEPARATORS = [' ', "\u{00a0}", '.', '-', '(', ')', '/', '_', '+'];

    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = trim(mb_convert_encoding($raw, 'UTF-8', 'UTF-8'));
        if ($value === '') {
            return null;
        }

        $digits = str_replace(self::SEPARATORS, '', $value);

        // Les lettres rendent le numéro ambigu : inutilisable pour le
        // regroupement (ex. "06 12 34 56 78 (bureau)").
        if (preg_match('/[^\d]/u', $digits) === 1) {
            return null;
        }

        // Préfixe international "00" retiré en tête uniquement.
        $digits = preg_replace('/^0{2}/', '', $digits) ?? $digits;

        if (strlen($digits) < 6) {
            return null;
        }

        return $digits;
    }
}
