"use client";

import { useState } from "react";
import Link from "next/link";
import { Pagination } from "@/components/Pagination";
import { SelectFilter } from "@/components/SelectFilter";
import { PageSkeleton } from "@/components/PageSkeleton";
import { ErrorState } from "@/components/ErrorState";
import { EmptyState } from "@/components/EmptyState";
import { useData } from "@/hooks/useData";
import * as api from "@/services";
import { formatDateTime, formatNumber } from "@/lib/format";
import type { ExtractionStatus } from "@/types/contact";

const STATUS_BADGE: Record<string, { label: string; className: string }> = {
  completed: { label: "Complétée", className: "bg-success-container text-on-success-container" },
  restored: { label: "Restaurée", className: "bg-surface-container text-on-surface-variant" },
};

const STATUS_OPTIONS = [
  { value: "all", label: "Tous les statuts" },
  { value: "completed", label: "Complétées" },
  { value: "restored", label: "Restaurées" },
];

export default function ExtractionsPage() {
  const [status, setStatus] = useState("all");
  const [page, setPage] = useState(1);

  const extractions = useData(
    () => api.listExtractions({ status: status as api.ExtractionsFilters["status"], page, pageSize: 10 }),
    [status, page],
  );

  const data = extractions.data;

  return (
    <div className="space-y-lg">
      <div>
        <span className="text-label-table-header uppercase tracking-wider text-on-surface-variant">
          Nettoyage des fiches
        </span>
        <h1 className="text-headline-lg tracking-tight text-on-surface">Extractions de doublons</h1>
        <p className="text-body-compact text-on-surface-variant">
          Chaque opération d&apos;extraction est auditable : fiche source, administrateur, contacts et
          statut. Les opérations complétées peuvent être restaurées.
        </p>
      </div>

      <div className="flex flex-wrap items-center gap-md rounded-xl bg-surface-lowest p-md shadow-sm">
        <SelectFilter
          value={status}
          onChange={(v) => {
            setStatus(v);
            setPage(1);
          }}
          options={STATUS_OPTIONS}
          ariaLabel="Filtrer par statut d'extraction"
        />
      </div>

      {extractions.loading && !data ? (
        <PageSkeleton rows={5} />
      ) : extractions.error ? (
        <ErrorState message={extractions.error} onRetry={extractions.retry} />
      ) : data && data.data.length === 0 ? (
        <div className="rounded-xl bg-surface-lowest shadow-sm">
          <EmptyState
            title="Aucune extraction"
            message="Les extractions effectuées depuis une fiche apparaîtront ici."
          />
        </div>
      ) : data ? (
        <section className="rounded-xl bg-surface-lowest shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full text-left">
              <thead>
                <tr className="border-b border-outline-variant text-label-table-header uppercase tracking-wider text-on-surface-variant">
                  <th className="py-2.5 pl-lg pr-3">Opération</th>
                  <th className="px-3 py-2.5">Fiche source</th>
                  <th className="hidden px-3 py-2.5 text-right md:table-cell">Contacts extraits</th>
                  <th className="hidden px-3 py-2.5 md:table-cell">Groupes téléphoniques</th>
                  <th className="px-3 py-2.5">Statut</th>
                  <th className="hidden px-3 py-2.5 md:table-cell">Par</th>
                  <th className="hidden px-3 py-2.5 lg:table-cell">Date</th>
                  <th className="py-2.5 pl-3 pr-lg text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-outline-variant/40 text-body-compact">
                {data.data.map((e) => (
                  <tr key={e.id} className="hover:bg-surface-low transition-colors">
                    <td className="py-2.5 pl-lg pr-3 font-mono font-semibold text-on-surface">#{e.id}</td>
                    <td className="px-3 py-2.5">
                      <Link href={`/fiches/${e.source_file_id}`} className="text-on-surface hover:text-primary hover:underline">
                        {e.source_file_name ?? `fiche #${e.source_file_id}`}
                      </Link>
                    </td>
                    <td className="hidden px-3 py-2.5 text-right font-mono tabular md:table-cell">
                      {formatNumber(e.extracted_count)}
                    </td>
                    <td className="hidden max-w-56 truncate px-3 py-2.5 text-on-surface-variant md:table-cell">
                      {e.comment ?? "—"}
                    </td>
                    <td className="px-3 py-2.5">
                      <span
                        className={`inline-flex items-center px-2 py-0.5 rounded text-label-badge font-medium ${
                          STATUS_BADGE[e.status]?.className ?? ""
                        }`}
                      >
                        {STATUS_BADGE[e.status]?.label ?? e.status}
                      </span>
                    </td>
                    <td className="hidden px-3 py-2.5 text-on-surface-variant md:table-cell">{e.performed_by ?? "—"}</td>
                    <td className="hidden px-3 py-2.5 text-on-surface-variant md:table-cell">
                      {formatDateTime(e.created_at)}
                    </td>
                    <td className="py-2.5 pl-3 pr-lg text-right">
                      <Link
                        href={`/extractions/${e.id}`}
                        className="rounded bg-surface-container px-2 py-1 text-label-badge font-medium text-on-surface hover:bg-surface-highest transition-colors"
                      >
                        Détails
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="px-lg pb-md">
            <Pagination page={page} pageSize={data.pageSize} total={data.total} onPageChange={setPage} />
          </div>
        </section>
      ) : null}
    </div>
  );
}

export type { ExtractionStatus };
