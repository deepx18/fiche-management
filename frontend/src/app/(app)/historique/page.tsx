"use client";

import { useState } from "react";
import Link from "next/link";
import { Pagination } from "@/components/Pagination";
import { SearchInput } from "@/components/SearchInput";
import { SelectFilter } from "@/components/SelectFilter";
import { DateInput, NumberInput } from "@/components/filters/DateInput";
import { FilterPanel } from "@/components/filters/FilterPanel";
import { PageSkeleton } from "@/components/PageSkeleton";
import { ErrorState } from "@/components/ErrorState";
import { EmptyState } from "@/components/EmptyState";
import { useData } from "@/hooks/useData";
import * as api from "@/services";
import { formatDateTime, formatNumber } from "@/lib/format";
import type { ImportHistoryEntry } from "@/types/contact";

const STATUS_BADGE: Record<string, { label: string; className: string }> = {
  completed: { label: "Réussi", className: "bg-success-container text-on-success-container" },
  partial: { label: "Partiel", className: "bg-warning-container text-on-warning-container" },
  failed: { label: "Échec", className: "bg-error-container text-on-error-container" },
};

const STATUS_OPTIONS = [
  { value: "all", label: "Tous les statuts" },
  { value: "completed", label: "Réussis" },
  { value: "partial", label: "Partiels" },
  { value: "failed", label: "Échecs" },
];

const SORT_OPTIONS = [
  { value: "created_at:desc", label: "Import — plus récent" },
  { value: "created_at:asc", label: "Import — plus ancien" },
  { value: "original_filename:asc", label: "Fichier (A→Z)" },
  { value: "original_filename:desc", label: "Fichier (Z→A)" },
  { value: "status:asc", label: "Statut" },
  { value: "successful_rows:desc", label: "Lignes réussies (décroissant)" },
  { value: "failed_rows:desc", label: "Lignes échouées (décroissant)" },
];

const PAGE_SIZES = [10, 25, 50, 100];

export default function HistoriquePage() {
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("all");
  const [userId, setUserId] = useState<number | "all">("all");
  const [importedFrom, setImportedFrom] = useState("");
  const [importedTo, setImportedTo] = useState("");
  const [failedMin, setFailedMin] = useState("");
  const [sort, setSort] = useState("created_at:desc");
  const [pageSize, setPageSize] = useState(10);
  const [page, setPage] = useState(1);

  const [sortBy, sortDirection] = sort.split(":");

  const admins = useData(() => api.listAdmins({ pageSize: 100 }), []);

  const histories = useData(
    () =>
      api.listImportHistory({
        search,
        status: status as api.HistoriesFilters["status"],
        userId,
        importedFrom,
        importedTo,
        failedMin: failedMin === "" ? "" : Number(failedMin),
        sortBy,
        sortDirection: sortDirection as api.SortDirection,
        page,
        pageSize,
      }),
    [search, status, userId, importedFrom, importedTo, failedMin, sort, pageSize, page],
  );

  const data = histories.data;

  function resetFilters() {
    setSearch("");
    setStatus("all");
    setUserId("all");
    setImportedFrom("");
    setImportedTo("");
    setFailedMin("");
    setSort("created_at:desc");
    setPageSize(10);
    setPage(1);
  }

  const adminOptions = [
    { value: "all", label: "Tous les administrateurs" },
    ...(admins.data?.data ?? []).map((a) => ({ value: String(a.id), label: a.name })),
  ];
  const advancedCount = [userId !== "all" ? "1" : "", importedFrom, importedTo, failedMin].filter(Boolean).length;

  return (
    <div className="space-y-lg">
      <div>
        <span className="text-label-table-header uppercase tracking-wider text-on-surface-variant">
          Traçabilité
        </span>
        <h1 className="text-headline-lg tracking-tight text-on-surface">Historique d&apos;import</h1>
        <p className="text-body-compact text-on-surface-variant">
          Chaque tentative d&apos;import est enregistrée, même en cas d&apos;échec — indépendamment de
          l&apos;existence de la fiche associée.
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
            <SearchInput
              value={search}
              onChange={(v) => {
                setSearch(v);
                setPage(1);
              }}
              placeholder="Nom du fichier…"
            />
            <SelectFilter
              value={status}
              onChange={(v) => {
                setStatus(v);
                setPage(1);
              }}
              options={STATUS_OPTIONS}
              ariaLabel="Filtrer par statut d'import"
            />
            <SelectFilter
              value={sort}
              onChange={(v) => {
                setSort(v);
                setPage(1);
              }}
              options={SORT_OPTIONS}
              ariaLabel="Trier l'historique"
            />
          </>
        }
        advanced={
          <>
            <label className="flex flex-col gap-1">
              <span className="text-label-badge text-on-surface-variant">Importé par</span>
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
            <DateInput label="Importé du" value={importedFrom} onChange={(v) => { setImportedFrom(v); setPage(1); }} />
            <DateInput label="Importé au" value={importedTo} onChange={(v) => { setImportedTo(v); setPage(1); }} />
            <NumberInput label="Lignes échouées minimum" value={failedMin} onChange={(v) => { setFailedMin(v); setPage(1); }} placeholder="0" />
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

      {histories.loading && !data ? (
        <PageSkeleton rows={6} />
      ) : histories.error ? (
        <ErrorState message={histories.error} onRetry={histories.retry} />
      ) : data && data.data.length === 0 ? (
        <div className="rounded-xl bg-surface-lowest shadow-sm">
          <EmptyState
            title="Aucune tentative d'import"
            message="L'historique apparaîtra dès le premier import."
          />
        </div>
      ) : data ? (
        <section className="rounded-xl bg-surface-lowest shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full text-left">
              <thead>
                <tr className="border-b border-outline-variant text-label-table-header uppercase tracking-wider text-on-surface-variant">
                  <th className="py-2.5 pl-lg pr-3">Fichier</th>
                  <th className="px-3 py-2.5">Statut</th>
                  <th className="px-3 py-2.5 text-right">Lignes tentées</th>
                  <th className="hidden px-3 py-2.5 text-right md:table-cell">OK / érronées / vides</th>
                  <th className="hidden px-3 py-2.5 md:table-cell">Importé par</th>
                  <th className="hidden px-3 py-2.5 lg:table-cell">Date</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-outline-variant/40 text-body-compact">
                {data.data.map((h) => (
                  <HistoryRow key={h.id} history={h} />
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

function HistoryRow({ history }: { history: ImportHistoryEntry }) {
  const [open, setOpen] = useState(false);
  const cfg = STATUS_BADGE[history.status];

  return (
    <>
      <tr
        className="cursor-pointer hover:bg-surface-low transition-colors"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
      >
        <td className="py-2.5 pl-lg pr-3">
          <span className="font-medium text-on-surface">{history.original_filename}</span>
          {history.imported_file_id ? (
            <Link
              href={`/fiches/${history.imported_file_id}`}
              onClick={(e) => e.stopPropagation()}
              className="ml-2 rounded bg-surface-container px-1.5 py-0.5 text-label-badge text-on-surface hover:bg-surface-highest"
            >
              voir la fiche
            </Link>
          ) : (
            <span className="ml-2 text-label-badge text-on-surface-variant">(tentative refusée)</span>
          )}
        </td>
        <td className="px-3 py-2.5">
          <span
            className={`inline-flex items-center px-2 py-0.5 rounded text-label-badge font-medium ${
              cfg?.className ?? ""
            }`}
          >
            {cfg?.label ?? history.status}
          </span>
        </td>
        <td className="px-3 py-2.5 text-right font-mono tabular">{formatNumber(history.total_rows)}</td>
        <td className="hidden px-3 py-2.5 text-right font-mono tabular md:table-cell">
          <span className="text-success">{formatNumber(history.successful_rows)}</span>
          <span className="text-error"> / {formatNumber(history.failed_rows)}</span>
          <span className="text-on-surface-variant"> / {formatNumber(history.skipped_rows)}</span>
        </td>
        <td className="hidden px-3 py-2.5 text-on-surface-variant md:table-cell">{history.user_name ?? "—"}</td>
        <td className="hidden px-3 py-2.5 text-on-surface-variant md:table-cell">{formatDateTime(history.created_at)}</td>
      </tr>

      {open ? (
        <tr className="bg-surface-low">
          <td colSpan={6} className="px-lg py-md">
            <div className="space-y-sm text-body-compact">
              <p className="text-on-surface">{history.error_summary ?? "Aucun résumé d'erreur."}</p>
              {history.row_errors && history.row_errors.length > 0 ? (
                <div className="max-h-64 overflow-y-auto rounded-lg bg-surface-lowest p-md">
                  <table className="w-full text-left">
                    <thead>
                      <tr className="text-label-badge uppercase text-on-surface-variant">
                        <th className="py-1 pr-4">Ligne Excel</th>
                        <th className="py-1 pr-4">Champ</th>
                        <th className="py-1">Motif</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-outline-variant/40">
                      {history.row_errors.map((e, i) => (
                        <tr key={`${e.row}-${e.field}-${i}`}>
                          <td className="py-1 pr-4 font-mono tabular">{e.row}</td>
                          <td className="py-1 pr-4 font-mono">{e.field}</td>
                          <td className="py-1 text-on-surface">{e.reason}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : null}
              <p className="text-label-badge text-on-surface-variant">
                Démarrée {formatDateTime(history.started_at ?? history.created_at)} · terminée{" "}
                {history.completed_at ? formatDateTime(history.completed_at) : "—"}
              </p>
            </div>
          </td>
        </tr>
      ) : null}
    </>
  );
}
