<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use InvalidArgumentException;

/** Registre minimal des adaptateurs disponibles, sans logique fournisseur. */
final class CallProviderRegistry
{
    /** @var array<string, CallProvider> */
    private array $providers = [];

    /** @param iterable<CallProvider> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->name()] = $provider;
        }
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->providers);
    }

    public function get(string $name): CallProvider
    {
        if (! isset($this->providers[$name])) {
            throw new InvalidArgumentException('Fournisseur téléphonique inconnu.');
        }

        return $this->providers[$name];
    }

    /** @return list<string> */
    public function searchableNames(): array
    {
        return array_values(array_keys(array_filter(
            $this->providers,
            static fn (CallProvider $provider): bool => $provider->supportsSearch(),
        )));
    }
}
