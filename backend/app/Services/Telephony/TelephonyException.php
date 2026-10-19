<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use RuntimeException;

/**
 * Échec d'une opération téléphonie.
 *
 * `kind()` permet à l'interface et aux jobs de distinguer une erreur de
 * configuration, d'authentification, de permission, de quota, de délai, de
 * réponse malformée ou une capacité non prise en charge. Les messages sont
 * destinés à l'utilisateur : jamais de token, de mot de passe, d'URL de
 * fournisseur ni de numéro complet.
 */
class TelephonyException extends RuntimeException
{
    public const KIND_CONFIGURATION = 'configuration';

    public const KIND_AUTHENTICATION = 'authentication';

    public const KIND_PERMISSION = 'permission';

    public const KIND_RATE_LIMITED = 'rate_limited';

    public const KIND_TIMEOUT = 'timeout';

    public const KIND_PROVIDER_ERROR = 'provider_error';

    public const KIND_MALFORMED_RESPONSE = 'malformed_response';

    public const KIND_UNSUPPORTED = 'unsupported';

    public const KIND_INVALID_NUMBER = 'invalid_number';

    public const KIND_RECORDING = 'recording';

    private string $kind;

    private function __construct(string $kind, string $message)
    {
        parent::__construct($message);
        $this->kind = $kind;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public static function configuration(string $message): self
    {
        return new self(self::KIND_CONFIGURATION, $message);
    }

    public static function authentication(string $provider): self
    {
        return new self(
            self::KIND_AUTHENTICATION,
            'Authentification refusée par '.$provider.' (401). Vérifiez les identifiants du backend.',
        );
    }

    public static function permission(string $provider): self
    {
        return new self(
            self::KIND_PERMISSION,
            'Accès refusé par '.$provider.' (403). Vérifiez les droits du compte ou du fichier configuré.',
        );
    }

    public static function rateLimited(string $provider): self
    {
        return new self(
            self::KIND_RATE_LIMITED,
            $provider.' limite temporairement les requêtes. La recherche sera réessayée automatiquement.',
        );
    }

    public static function timeout(string $provider): self
    {
        return new self(
            self::KIND_TIMEOUT,
            $provider.' ne répond pas dans le délai imparti. Vérifiez la connectivité puis relancez la recherche.',
        );
    }

    public static function providerError(string $provider, ?int $httpStatus = null): self
    {
        $suffix = $httpStatus === null ? '' : ' (HTTP '.$httpStatus.')';

        return new self(
            self::KIND_PROVIDER_ERROR,
            $provider.' a retourné une erreur'.$suffix.'. Consultez les journaux du backend pour le diagnostic.',
        );
    }

    public static function malformedResponse(string $provider): self
    {
        return new self(
            self::KIND_MALFORMED_RESPONSE,
            'Réponse inattendue de '.$provider.' : les données d\'appel sont inexploitables.',
        );
    }

    public static function unsupported(string $message): self
    {
        return new self(self::KIND_UNSUPPORTED, $message);
    }

    public static function invalidNumber(string $message): self
    {
        return new self(self::KIND_INVALID_NUMBER, $message);
    }

    public static function recording(string $message): self
    {
        return new self(self::KIND_RECORDING, $message);
    }
}
