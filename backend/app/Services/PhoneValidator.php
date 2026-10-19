<?php

declare(strict_types=1);

namespace App\Services;

use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Validation et normalisation des numéros de téléphone français.
 *
 * Par défaut, le numéro est interprété comme un numéro français
 * (région « FR »). Les chiffres tapés sans indicatif international sont donc
 * toujours testés contre les règles françaises (06/07/01/02…).
 *
 * Règle clé : on exige `isValidNumberForRegion($num, "FR")`. Cela garantit
 * deux choses :
 *   - qu'un numéro français valide (même en national, 06 12 34 56 78) est
 *     accepté et normalisé en E.164 (+33612345678) ;
 *   - qu'un numéro qui porte explicitement un indicatif étranger
 *     (ex. +32 412 34 56 78) est REFUSÉ : il n'est jamais « réinterprété »
 *     comme un numéro français, même si l'on a passé FR comme région par
 *     défaut au parse (car PhoneNumberUtil garde l'indicatif explicite).
 *
 * La valeur saisie (original) est conservée pour affichage et audit.
 * La forme E.164 est utilisée pour les recherches auprès des fournisseurs
 * téléphoniques (Kavkom, Issabel, …).
 */
final class PhoneValidator
{
    private static ?PhoneNumberUtil $util = null;

    private static function util(): PhoneNumberUtil
    {
        if (self::$util === null) {
            self::$util = PhoneNumberUtil::getInstance();
        }

        return self::$util;
    }

    /**
     * Essaie de valider un numéro français.
     *
     * @return array{valid: bool, original: string, e164: string|null,
     *               international: string|null, region: string|null, error: string|null}
     */
    public static function validate(string $raw): array
    {
        $value = trim($raw);

        if ($value === '') {
            return self::invalid('Le numéro est vide.');
        }

        try {
            $number = self::util()->parse($value, 'FR');
        } catch (\Throwable $e) {
            return self::invalid(sprintf(
                'Ce caractère ne ressemble pas à un numéro de téléphone : %s.',
                $e->getMessage(),
            ));
        }

        // Le numéro complet (indicatif international éventuel) doit être un
        // numéro valide pour la France. Un +32… est donc refusé ici.
        if (! self::util()->isValidNumberForRegion($number, 'FR')) {
            $region = self::regionForNumber($number);

            if ($region !== 'FR') {
                return self::invalid(sprintf(
                    'Ce numéro semble appartenir à un autre pays (%s) et non à la France. '
                    .'Seuls les numéros français sont acceptés.%s',
                    $region,
                    self::hintForNumber($number),
                ));
            }

            // Numéro français structuré mais invalide (ex. 06 12 34 56 7).
            return self::invalid(sprintf(
                'Ce numéro n\'est pas valide pour la France.%s',
                self::hintForNumber($number),
            ));
        }

        return [
            'valid' => true,
            'original' => $value,
            'e164' => self::util()->format($number, PhoneNumberFormat::E164),
            'international' => self::util()->format($number, PhoneNumberFormat::INTERNATIONAL),
            'region' => 'FR',
            'error' => null,
        ];
    }

    /**
     * Forme E.164 d'un numéro déjà validé.
     *
     * @throws \InvalidArgumentException si le numéro n'est pas valide
     */
    public static function toE164(string $raw): string
    {
        $result = self::validate($raw);

        if (! $result['valid']) {
            throw new \InvalidArgumentException($result['error']);
        }

        return $result['e164'];
    }

    /**
     * Échec générique (pas de numéro d'identification concret).
     */
    private static function invalid(string $message): array
    {
        return [
            'valid' => false,
            'original' => '',
            'e164' => null,
            'international' => null,
            'region' => null,
            'error' => $message,
        ];
    }

    /**
     * Aide au message d'erreur : retourne un indice basé sur la forme
     * internationale d'un numéro (uniquement quand on l'a pu analyser).
     */
    private static function hintForNumber(PhoneNumber $number): string
    {
        try {
            $intl = self::util()->format($number, PhoneNumberFormat::INTERNATIONAL);

            return sprintf(
                ' Il a été interprété comme « %s ». Vérifiez le format tapé.',
                $intl,
            );
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Région à laquelle appartient réellement le numéro analysé.
     */
    private static function regionForNumber(PhoneNumber $number): string
    {
        try {
            $region = self::util()->getRegionCodeForNumber($number);

            return $region ?: 'inconnu';
        } catch (\Throwable) {
            return 'inconnu';
        }
    }
}
