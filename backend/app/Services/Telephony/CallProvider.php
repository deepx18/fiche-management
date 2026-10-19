<?php

declare(strict_types=1);

namespace App\Services\Telephony;

/**
 * Contrat indépendant du fournisseur.
 *
 * Les différences réelles restent explicites : `capabilities()` indique si la
 * recherche et la récupération d'enregistrement sont disponibles, plutôt que
 * de laisser croire que tous les fournisseurs se ressemblent.
 */
interface CallProvider
{
    /** Nom stable stocké en base (`kavkom`, `issabel`). */
    public function name(): string;

    public function supportsSearch(): bool;

    /**
     * @return array{search: bool, recording_download: bool, recording_reference: bool, ingest?: string}
     */
    public function capabilities(): array;

    /**
     * Recherche bornée par numéro E.164 et plage de dates.
     *
     * @return list<ProviderCall>
     *
     * @throws TelephonyException configuration, authentification, permission,
     *                            quota, délai, réponse malformée ou capacité
     *                            non prise en charge
     */
    public function search(CallSearchQuery $query): array;
}
