<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Models\CallSearch;
use App\Models\CallSearchItem;

/** Agrège les états des éléments d'un lot sans masquer les erreurs. */
class CallSearchProgress
{
    public function refresh(CallSearch $search): CallSearch
    {
        $counts = $search->items()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        $total = array_sum($counts);
        $running = ($counts[CallSearchItem::STATUS_QUEUED] ?? 0)
            + ($counts[CallSearchItem::STATUS_RUNNING] ?? 0);
        $completed = ($counts[CallSearchItem::STATUS_COMPLETED] ?? 0)
            + ($counts[CallSearchItem::STATUS_NO_RESULTS] ?? 0);
        $failed = $counts[CallSearchItem::STATUS_FAILED] ?? 0;
        $skipped = $counts[CallSearchItem::STATUS_UNSUPPORTED] ?? 0;
        $skipped += $counts[CallSearchItem::STATUS_AMBIGUOUS] ?? 0;
        $skipped += $counts[CallSearchItem::STATUS_SKIPPED_NO_PHONE] ?? 0;
        $skipped += $counts[CallSearchItem::STATUS_INVALID_PHONE] ?? 0;

        if ($running > 0) {
            $status = $search->started_at === null ? CallSearch::STATUS_QUEUED : CallSearch::STATUS_RUNNING;
            $completedAt = null;
            $summary = 'Recherche en cours.';
        } elseif ($total === 0) {
            $status = CallSearch::STATUS_FAILED;
            $completedAt = now();
            $summary = 'Aucune ligne exploitable n\'a été soumise à la recherche.';
        } elseif ($failed === $total) {
            $status = CallSearch::STATUS_FAILED;
            $completedAt = now();
            $summary = 'Toutes les recherches ont échoué. Consultez le détail par fournisseur.';
        } elseif ($failed > 0 || $skipped > 0 || $completed < $total) {
            $status = CallSearch::STATUS_PARTIAL;
            $completedAt = now();
            $summary = $this->partialSummary($completed, $failed, $skipped, $total);
        } else {
            $status = CallSearch::STATUS_COMPLETED;
            $completedAt = now();
            $summary = 'Recherche terminée.';
        }

        $search->update([
            'status' => $status,
            'requested_count' => $total,
            'completed_count' => $completed,
            'failed_count' => $failed,
            'skipped_count' => $skipped,
            'error_summary' => $summary,
            'started_at' => $search->started_at ?? now(),
            'completed_at' => $completedAt,
        ]);

        return $search->refresh();
    }

    private function partialSummary(int $completed, int $failed, int $skipped, int $total): string
    {
        $parts = [$completed.' recherche(s) terminée(s)', $failed.' en échec', $skipped.' non exécutée(s)'];

        return implode(', ', $parts).' sur '.$total.'. Consultez le détail par fournisseur.';
    }
}
