"use client";

import { useRef, useState } from "react";
import Link from "next/link";
import { StatCard } from "@/components/StatCard";
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
import { ApiError } from "@/services/api";
import { formatDateTime, formatNumber } from "@/lib/format";
import type { ImportedFile } from "@/types/contact";
import type { DashboardStats } from "@/types/contact";

const STATUS_BADGE: Record<string, { label: string; className: string }> = {
  imported: { label: "Importé", className: "bg-success-container text-on-success-container" },
  partial: { label: "Partiel", className: "bg-warning-container text-on-warning-container" },
  failed: { label: "Échec", className: "bg-error-container text-on-error-container" },
};

const STATUS_OPTIONS = [
  { value: "all", label: "Tous les statuts" },
  { value: "imported", label: "Importés" },
  { value: "partial", label: "Partiels" },
  { value: "failed", label: "Échecs" },
  { value: "archived", label: "Archivés" },
];

const MAX_SIZE_LABEL = "10 Mo";

const SORT_OPTIONS = [
  { value: "created_at:desc", label: "Importé — plus récent" },
  { value: "created_at:asc", label: "Importé — plus ancien" },
  { value: "original_filename:asc", label: "Fichier (A→Z)" },
  { value: "original_filename:desc", label: "Fichier (Z→A)" },
  { value: "successful_rows:desc", label: "Contacts (décroissant)" },
  { value: "successful_rows:asc", label: "Contacts (croissant)" },
  { value: "status:asc", label: "Statut" },
];

const PAGE_SIZES = [10, 25, 50, 100];

export default function FichesPage() {
  return <FichesContent />;
}

function FichesContent() {
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("all");
  const [adminId, setAdminId] = useState<number | "all">("all");
  const [importedFrom, setImportedFrom] = useState("");
  const [importedTo, setImportedTo] = useState("");
  const [sort, setSort] = useState("created_at:desc");
  const [pageSize, setPageSize] = useState(10);
  const [page, setPage] = useState(1);

  const [sortBy, sortDirection] = sort.split(":");

  const stats = useData(() => api.getContactStats(), []);
  const admins = useData(() => api.listAdmins({ pageSize: 100 }), []);
  const files = useData(
    () =>
      api.listFiles({
        search,
        status: status as api.FileFilters["status"],
        importedBy: adminId,
        importedFrom,
        importedTo,
        sortBy,
        sortDirection: sortDirection as api.SortDirection,
        page,
        pageSize,
      }),
    [search, status, adminId, importedFrom, importedTo, sort, pageSize, page],
  );

  function resetPage() {
    setPage(1);
  }

  function resetFilters() {
    setSearch("");
    setStatus("all");
    setAdminId("all");
    setImportedFrom("");
    setImportedTo("");
    setSort("created_at:desc");
    setPageSize(10);
    setPage(1);
  }

  const advancedCount = [adminId !== "all" ? "1" : "", importedFrom, importedTo].filter(Boolean).length;
  const adminOptions = [
    { value: "all", label: "Tous les administrateurs" },
    ...(admins.data?.data ?? []).map((a) => ({ value: String(a.id), label: a.name })),
  ];

  const data = files.data as api.Paginated<ImportedFile> | null;

  return (
    <div className="space-y-lg">
      <div className="flex flex-wrap items-start justify-between gap-md">
        <div>
          <span className="text-label-table-header uppercase tracking-wider text-on-surface-variant">
            Gestion des fichiers de contacts
          </span>
          <h1 className="text-headline-lg tracking-tight text-on-surface">Fiches Contacts</h1>
          <p className="text-body-compact text-on-surface-variant">
            Import des fichiers « Fiche Contact » (XLSX/XLS), suivi des doublons téléphoniques,
            extraction et exports nettoyés.
          </p>
        </div>
        <Link
          href="/historique"
          className="rounded border border-outline-variant bg-surface-lowest px-lg py-1.5 text-body-compact text-on-surface hover:bg-surface-low transition-colors"
        >
          Historique d&apos;import →
        </Link>
      </div>

      {stats.error ? null : stats.data ? <FicheStats stats={stats.data} /> : null}

      <ImportCard onImported={() => files.retry()} />

      {/* Filtres */}
      <FilterPanel
        basic={
          <>
            <SearchInput
              value={search}
              onChange={(v) => {
                setSearch(v);
                resetPage();
              }}
              placeholder="Nom du fichier…"
            />
            <SelectFilter
              value={status}
              onChange={(v) => {
                setStatus(v);
                resetPage();
              }}
              options={STATUS_OPTIONS}
              ariaLabel="Filtrer par statut d'import"
            />
            <SelectFilter
              value={sort}
              onChange={(v) => {
                setSort(v);
                resetPage();
              }}
              options={SORT_OPTIONS}
              ariaLabel="Trier les fiches"
            />
          </>
        }
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
        advanced={
          <>
            <label className="flex flex-col gap-1">
              <span className="text-label-badge text-on-surface-variant">Importé par</span>
              <select
                aria-label="Filtrer par administrateur importateur"
                value={String(adminId)}
                onChange={(e) => {
                  setAdminId(e.target.value === "all" ? "all" : Number(e.target.value));
                  resetPage();
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
            <DateInput label="Importé du" value={importedFrom} onChange={(v) => { setImportedFrom(v); resetPage(); }} />
            <DateInput label="Importé au" value={importedTo} onChange={(v) => { setImportedTo(v); resetPage(); }} />
            <label className="flex flex-col gap-1">
              <span className="text-label-badge text-on-surface-variant">Lignes par page</span>
              <select
                aria-label="Lignes par page"
                value={String(pageSize)}
                onChange={(e) => { setPageSize(Number(e.target.value)); resetPage(); }}
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

      {files.loading && !data ? (
        <PageSkeleton rows={6} />
      ) : files.error ? (
        <ErrorState message={files.error} onRetry={files.retry} />
      ) : data && data.data.length === 0 ? (
        <div className="rounded-xl bg-surface-lowest shadow-sm">
          <EmptyState
            title="Aucune fiche importée"
            message="Importez un premier fichier Excel « Fiche Contact » pour démarrer."
          />
        </div>
      ) : data ? (
        <section className="rounded-xl bg-surface-lowest shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full text-left">
              <thead>
                <tr className="border-b border-outline-variant text-label-table-header uppercase tracking-wider text-on-surface-variant">
                  <th className="py-2.5 pl-lg pr-3">Fichier</th>
                  <th className="hidden px-3 py-2.5 md:table-cell">Importé par</th>
                  <th className="hidden px-3 py-2.5 lg:table-cell">Date</th>
                  <th className="px-3 py-2.5 text-right">Lignes</th>
                  <th className="hidden px-3 py-2.5 text-right md:table-cell">OK / Échecs</th>
                  <th className="px-3 py-2.5">Statut</th>
                  <th className="py-2.5 pl-3 pr-lg text-right">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-outline-variant/40 text-body-compact">
                {data.data.map((f) => (
                  <tr key={f.id} className="hover:bg-surface-low transition-colors">
                    <td className="py-2.5 pl-lg pr-3">
                      <Link
                        href={`/fiches/${f.id}`}
                        className="font-medium text-on-surface hover:text-primary hover:underline"
                      >
                        {f.original_filename}
                      </Link>
                      {f.archived ? (
                        <span className="ml-2 rounded bg-surface-container px-1.5 py-0.5 text-label-badge text-on-surface-variant">
                          archivé
                        </span>
                      ) : null}
                    </td>
                    <td className="hidden px-3 py-2.5 text-on-surface-variant md:table-cell">
                      {f.imported_by.name ?? "—"}
                    </td>
                    <td className="hidden px-3 py-2.5 text-on-surface-variant md:table-cell">
                      {formatDateTime(f.imported_at)}
                    </td>
                    <td className="px-3 py-2.5 text-right font-mono tabular">
                      {formatNumber(f.successful_rows)}
                      <span className="text-on-surface-variant"> / {formatNumber(f.total_rows)}</span>
                    </td>
                    <td className="hidden px-3 py-2.5 text-right font-mono tabular md:table-cell">
                      <span className="text-success">{f.successful_rows}</span>
                      {f.failed_rows > 0 ? (
                        <span className="text-error"> / {f.failed_rows}</span>
                      ) : null}
                    </td>
                    <td className="px-3 py-2.5">
                      <Badge forFile={f} />
                    </td>
                    <td className="py-2.5 pl-3 pr-lg text-right">
                      <Link
                        href={`/fiches/${f.id}`}
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

function Badge({ forFile }: { forFile: ImportedFile }) {
  const key = forFile.archived ? "archived" : forFile.status;
  const cfg =
    key === "archived"
      ? { label: "Archivé", className: "bg-surface-container text-on-surface-variant" }
      : STATUS_BADGE[key];
  return (
    <span
      className={`inline-flex items-center px-2 py-0.5 rounded text-label-badge font-medium ${
        cfg?.className ?? ""
      }`}
    >
      {cfg?.label ?? key}
    </span>
  );
}

function FicheStats({ stats }: { stats: DashboardStats }) {
  return (
    <div className="grid grid-cols-2 gap-md sm:grid-cols-3 xl:grid-cols-6">
      <StatCard label="Fiches actives" value={formatNumber(stats.files_total)} hint={`${stats.files_archived} archivée(s)`} />
      <StatCard label="Contacts actifs" value={formatNumber(stats.contacts_active)} hint={`${formatNumber(stats.contacts_total)} importés au total`} />
      <StatCard label="Extraits" value={formatNumber(stats.contacts_extracted)} hint="Hors fiches actives (extractions en cours)" />
      <StatCard label="Tentatives d'import" value={formatNumber(stats.import_attempts)} hint={`${stats.imports_successful} réussies · ${stats.imports_failed} échouées`} />
      <StatCard label="Fiches avec doublons" value={formatNumber(stats.files_with_duplicates)} hint="Numéros identiques détectés" tone={stats.files_with_duplicates > 0 ? "error" : "neutral"} />
      <StatCard label="Extractions" value={formatNumber(stats.extractions_completed)} hint={`${formatNumber(stats.extractions_restored)} restaurée(s)`} />
    </div>
  );
}

function ImportCard({ onImported }: { onImported: () => void }) {
  const inputRef = useRef<HTMLInputElement | null>(null);
  const [selected, setSelected] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ tone: "success" | "error" | "warn"; text: string } | null>(null);
  const [duplicate, setDuplicate] = useState<{ name: string; date: string } | null>(null);

  async function submit(duplicateConfirm = false) {
    if (!selected) return;
    setBusy(true);
    setMessage(null);
    try {
      const result = await api.importFile(selected, duplicateConfirm);
      if (result === "duplicate_conflict") {
        setDuplicate({ name: "Un fichier de même contenu", date: "déjà importé" });
        setMessage({
          tone: "warn",
          text: "Un fichier identique a déjà été importé. Cochez la confirmation ci-dessous pour importer tout de même (aucune fusion : nouvelle fiche distincte).",
        });
        return;
      }
      const status = result.file.status;
      const stat = ` ${result.file.successful_rows}/${result.file.total_rows} lignes`;
      setMessage({
        tone: status === "failed" ? "error" : status === "partial" ? "warn" : "success",
        text:
          status === "failed"
            ? `Import échoué : ${result.file.original_filename}. Consultez l'historique pour le détail.`
            : `Import ${status === "partial" ? "partiel" : "réussi"} :${stat}.`,
      });
      setSelected(null);
      if (inputRef.current) inputRef.current.value = "";
      onImported();
    } catch (error) {
      const detail =
        error instanceof ApiError
          ? error.message
          : error instanceof Error
            ? error.message
            : "Import impossible.";
      setMessage({ tone: "error", text: detail });
    } finally {
      setBusy(false);
    }
  }

  const isExcel =
    selected !== null &&
    (selected.name.toLowerCase().endsWith(".xlsx") || selected.name.toLowerCase().endsWith(".xls"));

  return (
    <section className="rounded-xl bg-surface-lowest p-lg shadow-sm">
      <div className="mb-sm flex items-center justify-between">
        <div>
          <h2 className="text-headline-sm text-on-surface">Nouvel import</h2>
          <p className="text-body-compact text-on-surface-variant">
            Formats acceptés : XLSX, XLS · Taille maximum : {MAX_SIZE_LABEL} · En-têtes requis :
            NOM, PRENOM.
          </p>
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-md">
        <label className="flex cursor-pointer items-center gap-md rounded border border-outline-variant bg-surface-lowest px-md py-2 text-body-compact hover:border-primary transition-colors">
          <input
            ref={inputRef}
            type="file"
            accept=".xlsx,.xls"
            className="hidden"
            onChange={(e) => {
              setSelected(e.target.files?.[0] ?? null);
              setMessage(null);
              setDuplicate(null);
            }}
          />
          Choisir un fichier…
          <span className="text-on-surface-variant">{selected?.name ?? "aucun fichier"}</span>
        </label>

        <button
          type="button"
          disabled={!selected || !isExcel || busy}
          onClick={() => void submit()}
          className="rounded bg-primary px-lg py-2 text-body-compact font-medium text-on-primary hover:bg-primary-container transition-colors disabled:opacity-50"
        >
          {busy ? "Import en cours…" : "Importer"}
        </button>
      </div>

      {selected && !isExcel ? (
        <p className="mt-md text-body-compact text-error">Fichier non supporté : XLSX ou XLS attendus.</p>
      ) : null}

      <label className="mt-md flex items-center gap-sm text-body-compact text-on-surface-variant">
        <input
          type="checkbox"
          checked={duplicate !== null}
          onChange={(e) => setDuplicate(e.target.checked ? duplicate ?? { name: "", date: "" } : null)}
          disabled={duplicate === null && message?.tone !== "warn"}
        />
        Confirmer l&apos;import d&apos;un fichier identique (décision explicite)
      </label>

      {message ? (
        <p
          role="alert"
          className={`mt-md rounded px-md py-2 text-body-compact ${
            message.tone === "error"
              ? "bg-error-container text-on-error-container"
              : message.tone === "warn"
                ? "bg-warning-container text-on-warning-container"
                : "bg-success-container text-on-success-container"
          }`}
        >
          {message.text}
        </p>
      ) : null}
      {message?.tone === "warn" ? (
        <button
          type="button"
          onClick={() => void submit(true)}
          className="mt-sm rounded bg-primary px-lg py-1.5 text-body-compact font-medium text-on-primary hover:bg-primary-container transition-colors"
        >
          Confirmer et importer quand même
        </button>
      ) : null}
    </section>
  );
}
