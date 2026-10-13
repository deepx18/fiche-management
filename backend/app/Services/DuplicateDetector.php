<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\ImportedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Détection de doublons TÉLÉPHONIQUES au sein d'une fiche, calculée à chaud
 * sur les contacts actifs de la fiche. Aucune écriture : un scan ne modifie
 * jamais les contacts. Les contacts sans numéro utilisable (vide, non
 * numérique, trop court après normalisation) sont exclus du regroupement et
 * comptés à part.
 */
class DuplicateDetector
{
    /**
     * @return array{
     *   groups: LengthAwarePaginator,
     *   stats: array{total_active:int, duplicate_groups:int, contacts_in_groups:int,
     *                without_usable_tel:int, not_in_any_group:int, extracted_contacts:int},
     *   extracted_contacts: int
     * }
     */
    public function detect(ImportedFile $file, int $perPage = 20): array
    {
        $active = $file->contacts()->active()->orderBy('id')->get();

        $extractedCount = Contact::where('imported_file_id', $file->id)->extracted()->count();

        // Regroupement par numéro normalisé (contacts actifs uniquement).
        $byPhone = $active
            ->filter(fn (Contact $c) => $c->telephone_normalise !== null)
            ->groupBy('telephone_normalise');

        $groupData = $byPhone
            ->filter(fn (Collection $group) => $group->count() > 1)
            ->map(fn (Collection $group, string $tel) => [
                'phone' => $tel,
                'contacts' => $group->values()->map(fn (Contact $c) => $this->presentContact($c))->all(),
            ])
            ->values();

        $duplicatedIds = $groupData->flatMap(fn (array $g) => array_column($g['contacts'], 'id'));

        $page = LengthAwarePaginator::resolveCurrentPage();

        $paged = new \Illuminate\Pagination\LengthAwarePaginator(
            $groupData->slice(($page - 1) * $perPage, $perPage)->values(),
            $groupData->count(),
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()],
        );

        $withoutUsableTel = $active->filter(fn (Contact $c) => $c->telephone_normalise === null)->count();

        return [
            'groups' => $paged,
            'stats' => [
                'total_active' => $active->count(),
                'duplicate_groups' => $groupData->count(),
                'contacts_in_groups' => $duplicatedIds->unique()->count(),
                'without_usable_tel' => $withoutUsableTel,
                'not_in_any_group' => max(0, $active->count() - $duplicatedIds->unique()->count() - $withoutUsableTel),
                'extracted_contacts' => $extractedCount,
            ],
        ];
    }

    private function presentContact(Contact $c): array
    {
        return [
            'id' => $c->id,
            'nom' => $c->nom,
            'prenom' => $c->prenom,
            'tel' => $c->tel,
            'telephone_normalise' => $c->telephone_normalise,
            'email' => $c->email,
            'ville' => $c->ville,
        ];
    }
}
