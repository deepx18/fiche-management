<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use DateTimeInterface;

/**
 * Appel normalisé, indépendant du format brut du fournisseur.
 *
 * `providerCallId` est l'identité stable. Si un fournisseur n'en expose pas,
 * l'adaptateur doit fournir une empreinte conservatrice documentée ; le DTO
 * refuse un identifiant vide pour ne jamais dédupliquer par numéro seul.
 */
final class ProviderCall
{
    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public function __construct(
        public readonly string $providerCallId,
        public readonly ?string $rawPhone = null,
        public readonly ?string $rawDestination = null,
        public readonly ?string $matchedPhoneE164 = null,
        public readonly ?string $direction = null,
        public readonly ?string $status = null,
        public readonly ?DateTimeInterface $startedAt = null,
        public readonly ?DateTimeInterface $endedAt = null,
        public readonly ?int $durationSeconds = null,
        public readonly ?int $billableSeconds = null,
        public readonly ?string $agent = null,
        public readonly string $recordingState = 'unavailable',
        public readonly ?string $recordingProviderReference = null,
        public readonly array $metadata = [],
    ) {
        if (trim($this->providerCallId) === '') {
            throw TelephonyException::malformedResponse('fournisseur sans identifiant d\'appel stable');
        }

        if (mb_strlen($this->providerCallId) > 190) {
            throw TelephonyException::malformedResponse('identifiant d\'appel fournisseur trop long');
        }
    }
}
