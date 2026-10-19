<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Telephony\CallProvider;
use App\Services\Telephony\CallSearchQuery;
use App\Services\Telephony\ProviderCall;
use App\Services\Telephony\TelephonyException;

/** Fournisseur simulé : aucune connexion réseau, aucun identifiant réel. */
class FakeCallProvider implements CallProvider
{
    /** @var list<ProviderCall> */
    public array $calls = [];

    public ?TelephonyException $error = null;

    public int $callCount = 0;

    public ?CallSearchQuery $lastQuery = null;

    public function __construct(
        private readonly string $providerName = 'issabel',
        private bool $searchSupported = true,
    ) {}

    public function name(): string
    {
        return $this->providerName;
    }

    public function supportsSearch(): bool
    {
        return $this->searchSupported;
    }

    public function capabilities(): array
    {
        return [
            'search' => $this->searchSupported,
            'recording_download' => true,
            'recording_reference' => true,
        ];
    }

    public function search(CallSearchQuery $query): array
    {
        $this->callCount++;
        $this->lastQuery = $query;

        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->calls;
    }
}
