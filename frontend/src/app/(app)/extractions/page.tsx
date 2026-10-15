"use client";

import { useState } from "react";
import Link from "next/link";
import { Pagination } from "@/components/Pagination";
import { SearchInput } from "@/components/SearchInput";
import { SelectFilter } from "@/components/SelectFilter";
import { DateInput } from "@/components/filters/DateInput";
import { FilterPanel } from "@/components/filters/FilterPanel";
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

const SORT_OPTIONS = [
  { value: "created_at:desc", label: "Extraction — plus récente" },
  { value: "created_at:asc", label: "Extraction — plus ancienne" },
  { value: "extracted_count:desc", label: "Contacts extraits (décroissant)" },
  { value: "source_file:asc", label: "Fiche source (A→Z)" },
  { value: "performed_by:asc", label: "Administrateur (A→Z)" },
  { value: "status:asc", label: "Statut" },
];

const PAGE_SIZES = [10, 25, 50, 100];

export default function ExtractionsPage() {
  const [status, setStatus] = useState("all");
  const [sourceFileId, setSourceFileId] = useState<number | "all">("all");
  const [userId, setUserId] = useState<number | "all">("all");
  const [contact, setContact] = useState("");
  const [extractedFrom, setExtractedFrom] = useState("");
  const [extractedTo, setExtractedTo] = useState("");
  const [sort, setSort] = useState("created_at:desc");
  const [pageSize, setPageSize] = useState(10);
  const [page, setPage] = useState(1);

  const [sortBy, sortDirection] = sort.split(":");

  const files = useData(() => api.listFiles({ pageSize: 100 }), []);
  const admins = useData(() => api.listAdmins({ pageSize: 100 }), []);

  const extractions = useData(
    () =>
      api.listExtractions({
        status: status as api.ExtractionsFilters["status"],
        sourceFileId,
        userId,
        contact,
        extractedFrom,
        extractedTo,
        sortBy,
        sortDirection: sortDirection as api.SortDirection,
        page,
        pageSize,
      }),
    [status, sourceFileId, userId, contact, extractedFrom, extractedTo, sort, pageSize, page],
  );

  const data = extractions.data;

  function resetFilters() {
    setStatus("all");
    setSourceFileId("all");
    setUserId("all");
    setContact("");
    setExtractedFrom("");
    setExtractedTo("");
    setSort("created_at:desc");
    setPageSize(10);
    setPage(1);
  }

  const fileOptions = [
    { value: "all", label: "Toutes les fiches" },
    ...(files.data?.data ?? []).map((f) => ({ value: String(f.id), label: f.original_filename })),
  ];
  const adminOptions = [
    { value: "all", label: "Tous les administrateurs" },
    ...(admins.data?.data ?? []).map((a) => ({ value: String(a.id), label: a.name })),
  ];
  const advancedCount = [
    sourceFileId !== "all" ? "1" : "",
    userId !== "all" ? "1" : "",
    extractedFrom,
    extractedTo,
  ].filter(Boolean).length;

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

      <FilterPanel
        activeCount={advancedCount}
        reset={
          <button
            type="button"
            onClick={resetFilters}
            className="rounded border border-outline-variant px-md py-1.5 text-body-compact text-on-surface hover:bg-surface-low transition-colors"
          >
            Effacer
          </button>
        }
        basic={
          <>
            <SelectFilter
              value={status}
              onChange={(v) => {
                setStatus(v);
                setPage(1);
              }}
              options={STATUS_OPTIONS}
              ariaLabel="Filtrer par statut d'extraction"
            />
            <SearchInput
              value={contact}
              onChange={(v) => {
                setContact(v);
                setPage(1);
              }}
              placeholder="Contact extrait (nom, téléphone)…"
            />
            <SelectFilter
              value={sort}
              onChange={(v) => {
                setSort(v);
                setPage(1);
              }}
              options={SORT_OPTIONS}
              ariaLabel="Trier les extractions"
            />
          </>
        }
        advanced={
          <>
            <label className="flex flex-col gap-1">
              <span className="text-label-badge text-on-surface-variant">Fiche source</span>
              <select
                aria-label="Filtrer par fiche source"
                value={String(sourceFileId)}
                onChange={(e) => {
                  setSourceFileId(e.target.value === "all" ? "all" : Number(e.target.value));
                  setPage(1);
                }}
                className="bg-surface-lowest border border-outline-variant rounded px-md py-1.5 text-body-compact text-on-surface focus:outline-none focus:border-primary transition-colors"
              >
                {fileOptions.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
            </label>
            <label className="flex flex-col gap-1">
              <span className="text-label-badge text-on-surface-variant">Administrateur</span>
              <select
                aria-label="Filtrer par administrateur"
                value={String(userId)}
                onChange={(e) => {
                  setUserId(e.target.value === "all" ? "all" : Number(e.target.value));
                  setPage(1);
                }}
                className="bg-surface-lowest border border-outline-variant rounded px-md py-1.5 text-body-compact text-on-surface focus:outline-none focus:border-primary transition-colors"
              >
                {adminOptions.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
            </label>
            <DateInput label="Extrait du" value={extractedFrom} onChange={(v) => { setExtractedFrom(v); setPage(1); }} />
            <DateInput label="Extrait au" value={extractedTo} onChange={(v) => { setExtractedTo(v); setPage(1); }} />
            <label className="flex flex-col gap-1">
              <span className="text-label-badge text-on-surface-variant">Lignes par page</span>
              <select
                aria-label="Lignes par page"
                value={String(pageSize)}
                onChange={(e) => { setPageSize(Number(e.target.value)); setPage(1); }}
                className="bg-surface-lowest border border-outline-variant rounded px-md py-1.5 text-body-compact text-on-surface focus:outline-none focus:border-primary transition-colors"
              >
                {PAGE_SIZES.map((n) => (
                  <option key={n} value={n}>{n} / page</option>
                ))}
              </select>
            </label>
          </>
        }
      />

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
