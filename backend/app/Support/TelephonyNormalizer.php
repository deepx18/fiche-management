<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\PhoneValidator;
use InvalidArgumentException;

/**
 * Normalisation partagée pour les requêtes auprès des fournisseurs téléphoniques
 * (Kavkom, Issabel, …) en Phase 2/3.
 *
 * Règle : on normalise toujours un numéro déjà validé en E.164 via le même
 * validateur français que la saisie (`App\Services\PhoneValidator`). Ainsi
 * Kavkom et Issabel reçoivent le même format, et le comportement reste
 * cohérent entre la saisie et la recherche d'appels.
 *
 * Cette classe ne valide pas à nouveau un numéro qui n'a pas été validé : elle
 * s'attend à recevoir un numéro préalablement accepté par
 * `PhoneValidator::validate()`. Pour un input non fiable, utiliser d'abord
 * `PhoneValidator::validate()` et rejeter l'input invalide avant d'appeler
 * cette classe.
 */
final class TelephonyNormalizer
{
    /**
     * E.164 d'un numéro français préalablement validé.
     *
     * @throws InvalidArgumentException si le numéro n'est pas un numéro français valide
     */
    public static function toE164(string $raw): string
    {
        return PhoneValidator::toE164($raw);
    }

    /**
     * Prépare une valeur E.164 prête à être injectée dans une requête de
     * fournisseur téléphonique.
     *
     * Défensive : si le numéro n'est pas valide, l'exception remonte avec le
     * message lisible de `PhoneValidator`, de façon à ce que l'erreur soit
     * traçable même en amont de la couche téléphonique.
     */
    public static function forProviderQuery(string $raw): string
    {
        return self::toE164($raw);
    }
}
