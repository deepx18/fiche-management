<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validation d'un numéro client saisi dans l'application.
 *
 * Règle volontairement neutre (aucun format national imposé) : le « + » et
 * l'indicatif pays sont conservés, l'utilisateur choisit son formatage.
 * Formats acceptés à titre d'exemple : `0612345678`, `06 12 34 56 78`,
 * `+212 6 12 34 56 78`, `+212-612-345-678`, `(06) 12.34.56.78`,
 * `06/12/34/56/78`. On refuse uniquement ce qui n'est pas un numéro
 * exploitable (lettres, caractères interdits, trop court ou trop long).
 */
class ProductionPhone implements ValidationRule
{
    private const MAX_LENGTH = 30;

    private const MIN_DIGITS = 6;

    private const MAX_DIGITS = 20;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // null ou chaîne vide = effacer le numéro : autorisé.
        if ($value === null) {
            return;
        }

        if (! is_string($value)) {
            $fail('Le numéro de téléphone doit être une chaîne de caractères.');

            return;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return;
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            $fail('Le numéro de téléphone ne doit pas dépasser '.self::MAX_LENGTH.' caractères.');

            return;
        }

        // Chiffres et séparateurs courants uniquement. Le premier caractère
        // significatif doit être un chiffre (un « + » ou un « ( » initial est
        // toléré pour les formats comme « +212… » ou « (06) 12 … »).
        if (preg_match('/^[+(]?[0-9][0-9\s.\-\/()]*$/u', $trimmed) !== 1) {
            $fail('Format invalide : chiffres et séparateurs (espace . - / ( ) ) uniquement ; le « + » initial est conservé.');

            return;
        }

        $digits = preg_replace('/\D/u', '', $trimmed) ?? '';
        if (strlen($digits) < self::MIN_DIGITS) {
            $fail('Le numéro doit contenir au moins '.self::MIN_DIGITS.' chiffres.');

            return;
        }

        if (strlen($digits) > self::MAX_DIGITS) {
            $fail('Le numéro contient trop de chiffres ('.self::MAX_DIGITS.' maximum).');
        }
    }
}
