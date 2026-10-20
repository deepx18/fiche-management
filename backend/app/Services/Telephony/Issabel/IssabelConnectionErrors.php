<?php

declare(strict_types=1);

namespace App\Services\Telephony\Issabel;

use App\Services\Telephony\TelephonyException;
use Throwable;

/**
 * Traduit une erreur technique de connexion CDR Issabel en erreur typée.
 *
 * Partagé par la recherche d'appels et le diagnostic de connexion afin que
 * les deux surfaces produisent exactement le même message pour la même cause.
 * Le message d'origine n'est jamais renvoyé au navigateur : il peut contenir
 * un hôte, un utilisateur ou un chemin.
 */
final class IssabelConnectionErrors
{
    public static function map(Throwable $e): TelephonyException
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'access denied')) {
            return TelephonyException::authentication('Issabel');
        }

        if (str_contains($message, 'command denied') || str_contains($message, 'permission denied')) {
            return TelephonyException::permission('Issabel');
        }

        if (str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'unknown host')
            || str_contains($message, 'no such host')) {
            return TelephonyException::timeout('Issabel');
        }

        if (str_contains($message, 'unknown database') || str_contains($message, 'doesn\'t exist')) {
            return TelephonyException::configuration(
                'Base ou table CDR Issabel introuvable. Vérifiez ISSABEL_DB_DATABASE, ISSABEL_CDR_TABLE '
                .'et les droits du compte en lecture seule.'
            );
        }

        return TelephonyException::providerError('Issabel');
    }
}
