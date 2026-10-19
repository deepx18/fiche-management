<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Models\ProductionRecord;
use Illuminate\Support\Collection;

/**
 * Rapproche un numéro fournisseur d'une ligne de production.
 *
 * Le rapprochement est volontairement conservateur : une correspondance
 * ambiguë (plusieurs lignes partageant le même numéro) n'est jamais associée
 * automatiquement.
 */
class ProductionPhoneMatcher
{
    /**
     * @return Collection<int, ProductionRecord>
     */
    public function matchingRecords(string $e164): Collection
    {
        $variants = TelephonyNumber::candidates($e164);

        if ($variants === []) {
            return collect();
        }

        $digits = preg_replace('/\D+/', '', $e164) ?? '';

        return ProductionRecord::query()
            ->where(function ($query) use ($variants, $digits): void {
                $query->whereIn('phone_normalise', $variants);
                if ($digits !== '') {
                    $query->orWhere('phone_normalise', $digits);
                }
            })
            ->get()
            ->filter(static fn (ProductionRecord $record): bool => TelephonyNumber::french($record->phone) === $e164)
            ->values();
    }

    /**
     * @return array{record: ProductionRecord|null, ambiguous: bool, count: int}
     */
    public function find(string $e164): array
    {
        $records = $this->matchingRecords($e164);

        return [
            'record' => $records->count() === 1 ? $records->first() : null,
            'ambiguous' => $records->count() > 1,
            'count' => $records->count(),
        ];
    }
}
