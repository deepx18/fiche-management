<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\DuplicateExtraction;
use App\Models\DuplicateExtractionItem;
use App\Models\ExtractionRestoration;
use App\Models\ImportedFile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Extraction transactionnelle de contacts sélectionnés, et restauration.
 *
 * Extraction : validation sélective (ids existants, appartenant tous à la
 * fiche source, jamais déjà extraits), verrouillés pour sérialiser les
 * extractions concurrentes, puis associés à une opération "completed" créée
 * dans la même transaction.
 * L'opération se construit en statut "completed" uniquement — une opération
 * partielle n'exclut jamais de contacts du jeu actif.
 *
 * Restauration : la fiche et les contacts sont vérifiés, les associations
 * (items) sont supprimées — le contact redevient actif — l'opération repasse
 * en "restored" et un record de restauration archive les identifiants des
 * contacts restitués. Aucun contact n'est recréé, aucune valeur modifiée.
 */
class DuplicateExtractor
{
    /**
     * @param  list<int>  $contactIds
     * @return array{extraction: DuplicateExtraction, remaining_active: int}
     */
    public function extract(ImportedFile $file, User $user, array $contactIds): array
    {
        $contactIds = array_values(array_unique(array_map(intval(...), $contactIds)));

        if ($contactIds === []) {
            throw new ExtractionValidationException(['Aucun contact sélectionné.']);
        }

        return DB::transaction(function () use ($file, $user, $contactIds) {
            // Verrou : sérialise les extractions concurrentes sur cette fiche.
            $contacts = Contact::where('imported_file_id', $file->id)
                ->whereIn('id', $contactIds)
                ->lockForUpdate()
                ->get();

            $errors = [];

            $foundIds = $contacts->pluck('id')->all();
            sort($foundIds);
            foreach (array_diff($contactIds, $foundIds) as $missingId) {
                $errors[] = "Contact #{$missingId} introuvable ou n'appartient pas à la fiche source.";
            }

            foreach ($contacts as $contact) {
                $already = DB::table('duplicate_extraction_items')
                    ->join('duplicate_extractions', 'duplicate_extractions.id', '=', 'duplicate_extraction_items.duplicate_extraction_id')
                    ->where('contact_id', $contact->id)
                    ->where('duplicate_extractions.status', 'completed')
                    ->exists();
                if ($already) {
                    $errors[] = "Contact #{$contact->id} déjà extrait par une opération en cours.";
                }
            }

            if ($errors !== []) {
                throw new ExtractionValidationException($errors);
            }

            // $contacts contient exactement les ids demandés de CETTE fiche —
            // tout id étranger a déjà été signalé ci-dessus.
            $contacts = $contacts->sortBy('id');

            $extraction = DuplicateExtraction::create([
                'source_file_id' => $file->id,
                'user_id' => $user->id,
                'extracted_count' => $contacts->count(),
                'status' => DuplicateExtraction::STATUS_COMPLETED,
                'comment' => null,
            ]);

            $items = $contacts->map(fn (Contact $c) => [
                'duplicate_extraction_id' => $extraction->id,
                'contact_id' => $c->id,
                'original_file_id' => $file->id,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            DB::table('duplicate_extraction_items')->insert($items);

            // Résumé des groupes téléphoniques impliqués (traçabilité).
            $groups = $contacts->filter(fn (Contact $c) => $c->telephone_normalise !== null)
                ->groupBy('telephone_normalise')
                ->filter(fn ($g) => $g->count() > 1)
                ->map(fn ($g, $tel) => $tel.' ×'.$g->count())
                ->values()
                ->implode(', ');

            $extraction->update(['comment' => $groups !== '' ? $groups : null]);

            return [
                'extraction' => $extraction->fresh(['items.contact', 'user', 'sourceFile']),
                'remaining_active' => $this->remainingActive($file),
            ];
        });
    }

    private function remainingActive(ImportedFile $file): int
    {
        return $file->contacts()->active()->count();
    }

    public function restore(DuplicateExtraction $extraction, User $user): ExtractionRestoration
    {
        return DB::transaction(function () use ($extraction, $user) {
            $extraction = DuplicateExtraction::whereKey($extraction->id)
                ->lockForUpdate()
                ->firstOrFail();
            $extraction->load(['items.contact', 'sourceFile']);

            if ($extraction->status !== DuplicateExtraction::STATUS_COMPLETED) {
                throw new \RuntimeException('Cette opération a déjà été restaurée.');
            }

            $contacts = $extraction->items->map(fn (DuplicateExtractionItem $item) => $item->contact);
            if ($contacts->contains(null) || $contacts->contains(fn (Contact $c) => $c->importedFile === null)) {
                throw new \RuntimeException('Fiche source ou contacts introuvables : restauration impossible.');
            }

            $contactIds = $contacts->map(fn (Contact $c) => $c->id)->values()->all();

            $restoration = ExtractionRestoration::create([
                'duplicate_extraction_id' => $extraction->id,
                'user_id' => $user->id,
                'restored_count' => count($contactIds),
                'restored_contact_ids' => $contactIds,
            ]);

            // Retrait des associations : les contacts redeviennent actifs (le
            // scope "active" ne les voit plus liés à une opération complétée).
            $extraction->items()->delete();
            $extraction->update([
                'status' => DuplicateExtraction::STATUS_RESTORED,
            ]);

            return $restoration;
        });
    }
}

class ExtractionValidationException extends \RuntimeException
{
    public function __construct(public array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
