<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Normalisation téléphonique de la Phase 2.
 *
 * Règles :
 *  - un numéro national sans indicatif est interprété avec la région FR ;
 *  - un numéro portant explicitement un indicatif étranger n'est jamais
 *    réécrit en numéro français : `french()` le refuse, `e164()` le conserve ;
 *  - la comparaison se fait en E.164, jamais par égalité de chaînes brutes ;
 *  - les valeurs fournisseur nationales, internationales et « chiffres
 *    seuls » sont toutes ramenées à la même forme avant comparaison.
 *
 * `PhoneNormalizer` (Phase 1) reste inchangé : il sert uniquement à la
 * recherche interne par sous-chaîne dans la table de production.
 */
final class TelephonyNumber
{
    private static ?PhoneNumberUtil $util = null;

    private static function util(): PhoneNumberUtil
    {
        return self::$util ??= PhoneNumberUtil::getInstance();
    }

    /**
     * Forme E.164 d'un numéro valide, quel que soit son pays.
     * Le code pays explicite est conservé tel quel.
     */
    public static function e164(?string $raw): ?string
    {
        $raw = $raw === null ? '' : trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            $number = self::util()->parse($raw, self::defaultRegion());
        } catch (NumberParseException) {
            return null;
        }

        if (! self::util()->isValidNumber($number)) {
            return null;
        }

        return self::util()->format($number, PhoneNumberFormat::E164);
    }

    /**
     * Forme E.164 d'un numéro valide pour la France uniquement.
     * Un `+212…` ou un `+32…` retourne null : il n'est jamais converti.
     */
    public static function french(?string $raw): ?string
    {
        return self::frenchWithReason($raw)['e164'];
    }

    /**
     * @return array{e164: string|null, reason: string|null}
     */
    public static function frenchWithReason(?string $raw): array
    {
        $raw = $raw === null ? '' : trim($raw);
        if ($raw === '') {
            return ['e164' => null, 'reason' => 'Aucun numéro client n\'est renseigné pour cette ligne.'];
        }

        try {
            $number = self::util()->parse($raw, self::defaultRegion());
        } catch (NumberParseException) {
            return ['e164' => null, 'reason' => 'Le numéro client n\'est pas reconnu comme un numéro de téléphone.'];
        }

        if (! self::util()->isValidNumberForRegion($number, 'FR')) {
            $region = self::util()->getRegionCodeForNumber($number);

            if ($region !== null && $region !== 'FR') {
                return [
                    'e164' => null,
                    'reason' => 'Le numéro client porte un indicatif étranger ('.$region
                        .') : seuls les numéros français sont recherchés.',
                ];
            }

            return ['e164' => null, 'reason' => 'Le numéro client n\'est pas un numéro français valide.'];
        }

        return [
            'e164' => self::util()->format($number, PhoneNumberFormat::E164),
            'reason' => null,
        ];
    }

    /** Vrai si la valeur fournisseur correspond exactement au numéro cible. */
    public static function matches(?string $raw, string $targetE164): bool
    {
        $e164 = self::e164($raw);

        return $e164 !== null && hash_equals($targetE164, $e164);
    }

    /**
     * Variantes utiles pour interroger des colonnes fournisseur sans perdre
     * les numéros nationaux ou « chiffres seuls ».
     *
     * @return list<string>
     */
    public static function candidates(string $e164): array
    {
        $digits = preg_replace('/\D+/', '', $e164) ?? '';
        if ($digits === '') {
            return [];
        }

        $values = [$e164, $digits];

        if (str_starts_with($e164, '+33') && strlen($digits) === 11) {
            $values[] = '0'.substr($digits, 2);
            $values[] = '0033'.substr($digits, 2);
            // Certains PBX stockent le numéro national sans le zéro initial.
            $values[] = substr($digits, 2);
        }

        return array_values(array_unique(array_filter($values, static fn (string $v): bool => $v !== '')));
    }

    /** Région par défaut, configurable (FR par défaut). */
    public static function defaultRegion(): string
    {
        $region = strtoupper((string) config('telephony.default_region', 'FR'));

        return $region !== '' ? $region : 'FR';
    }
}
