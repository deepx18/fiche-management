"use client";

import { Suspense, useCallback, useMemo, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
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
import { formatDate, formatDateTime, formatNumber } from "@/lib/format";
import type { ContactSearchRecord } from "@/types/contact";

const SORT_FIELDS = ["id", "nom", "prenom", "adresse", "cp", "ville", "tel", "email", "date_naissance", "created_at"];
const PAGE_SIZES = [10, 25, 50, 100];

interface SearchFilters {
  q: string;
  fileId: number | "all";
  status: "active" | "extracted" | "all";
  ville: string;
  cp: string;
  importedFrom: string;
  importedTo: string;
  extractedFrom: string;
  extractedTo: string;
  sortBy: string;
  sortDirection: "asc" | "desc";
  page: number;
  pageSize: number;
}

function readFilters(params: URLSearchParams): SearchFilters {
  const [sortBy, sortDirection] = (params.get("sort") ?? "id:asc").split(":");
  const perPage = Number(params.get("per_page"));

  return {
    q: params.get("q") ?? "",
    fileId: params.get("file") ? Number(params.get("file")) : "all",
    status: (params.get("status") as SearchFilters["status"]) ?? "active",
    ville: params.get("ville") ?? "",
    cp: params.get("cp") ?? "",
    importedFrom: params.get("imported_from") ?? "",
    importedTo: params.get("imported_to") ?? "",
    extractedFrom: params.get("extracted_from") ?? "",
    extractedTo: params.get("extracted_to") ?? "",
    sortBy: SORT_FIELDS.includes(sortBy) ? sortBy : "id",
    sortDirection: sortDirection === "desc" ? "desc" : "asc",
    page: Math.max(1, Number(params.get("page") ?? 1) || 1),
    pageSize: PAGE_SIZES.includes(perPage) ? perPage : 25,
  };
}

const STATUS_OPTIONS = [
  { value: "active", label: "Contacts actifs" },
  { value: "extracted", label: "Contacts extraits" },
  { value: "all", label: "Actifs + extraits" },
];

const SORT_OPTIONS = [
  { value: "id:asc", label: "Plus anciens d'abord" },
  { value: "id:desc", label: "Plus récents d'abord" },
  { value: "nom:asc", label: "Nom (A→Z)" },
  { value: "nom:desc", label: "Nom (Z→A)" },
  { value: "prenom:asc", label: "Prénom (A→Z)" },
  { value: "ville:asc", label: "Ville (A→Z)" },
  { value: "cp:asc", label: "Code postal (croissant)" },
  { value: "tel:asc", label: "Téléphone (croissant)" },
  { value: "date_naissance:desc", label: "Naissance (récent d'abord)" },
];

export default function RecherchePage() {
  return (
    <Suspense fallback={<PageSkeleton rows={7} />}>
      <RechercheContent />
    </Suspense>
  );
}

function RechercheContent() {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const paramsString = searchParams.toString();
  const filters = useMemo(() => readFilters(new URLSearchParams(paramsString)), [paramsString]);

  /**
   * Champs édités localement (recherche + filtres avancés). L'état local est
   * "clé" par la chaîne d'URL : dès que l'URL change (retour/avance navigateur,
   * application d'un filtre), les valeurs dérivées de l'URL reprennent la main.
   */
  const [local, setLocal] = useState<{ key: string; q: string; draft: SearchFilters }>({
    key: paramsString,
    q: filters.q,
    draft: filters,
  });
  const localIsCurrent = local.key === paramsString;
  const qInput = localIsCurrent ? local.q : filters.q;
  const draft = localIsCurrent ? local.draft : filters;

  const setQInput = useCallback(
    (value: string) => setLocal({ key: paramsString, q: value, draft }),
    [draft, paramsString],
  );
  const setDraft = useCallback(
    (updater: (current: SearchFilters) => SearchFilters) =>
      setLocal({ key: paramsString, q: qInput, draft: updater(draft) }),
    [draft, paramsString, qInput],
  );

  const [busy, setBusy] = useState(false);
  const [exportMessage, setExportMessage] = useState<string | null>(null);

  const { data, loading, error, retry } = useData(
    () =>
      api.searchContacts({
        q: filters.q,
        fileId: filters.fileId,
        status: filters.status,
        ville: filters.ville,
        cp: filters.cp,
        importedFrom: filters.importedFrom,
        importedTo: filters.importedTo,
        extractedFrom: filters.extractedFrom,
        extractedTo: filters.extractedTo,
        sortBy: filters.sortBy,
        sortDirection: filters.sortDirection,
        page: filters.page,
        pageSize: filters.pageSize,
      }),
    [paramsString],
  );

  const files = useData(() => api.listFiles({ pageSize: 100 }), []);

  const writeFilters = useCallback(
    (next: SearchFilters) => {
      const params = new URLSearchParams();
      if (next.q) params.set("q", next.q);
      if (next.fileId !== "all") params.set("file", String(next.fileId));
      if (next.status !== "active") params.set("status", next.status);
      if (next.ville) params.set("ville", next.ville);
      if (next.cp) params.set("cp", next.cp);
      if (next.importedFrom) params.set("imported_from", next.importedFrom);
      if (next.importedTo) params.set("imported_to", next.importedTo);
      if (next.extractedFrom) params.set("extracted_from", next.extractedFrom);
      if (next.extractedTo) params.set("extracted_to", next.extractedTo);
      if (`${next.sortBy}:${next.sortDirection}` !== "id:asc") params.set("sort", `${next.sortBy}:${next.sortDirection}`);
      if (next.pageSize !== 25) params.set("per_page", String(next.pageSize));
      if (next.page > 1) params.set("page", String(next.page));
      const query = params.toString();
      router.replace(query ? `${pathname}?${query}` : pathname, { scroll: false });
    },
    [pathname, router],
  );

  /** Applique immédiatement un filtre (réinitialise la pagination). */
  const apply = useCallback((patch: Partial<SearchFilters>) => writeFilters({ ...filters, ...patch, page: 1 }), [filters, writeFilters]);
  const submitSearch = useCallback(() => writeFilters({ ...filters, ...draft, q: qInput, page: 1 }), [draft, filters, qInput, writeFilters]);

  const resetAll = useCallback(() => {
    setLocal({
      key: paramsString,
      q: "",
      draft: { ...filters, q: "", ville: "", cp: "", importedFrom: "", importedTo: "", extractedFrom: "", extractedTo: "", fileId: "all" },
    });
    router.replace(pathname, { scroll: false });
  }, [filters, pathname, paramsString, router]);

  const fileOptions = [
    { value: "all", label: "Toutes les fiches" },
    ...(files.data?.data ?? []).map((f) => ({ value: String(f.id), label: f.original_filename })),
  ];

  const advancedCount = [
    filters.ville,
    filters.cp,
    filters.importedFrom,
    filters.importedTo,
    filters.extractedFrom,
    filters.extractedTo,
    filters.fileId !== "all" ? "1" : "",
  ].filter(Boolean).length;

  const rows = data?.data ?? [];

  async function exportResults() {
    setBusy(true);
    setExportMessage(null);
    const ok = await api.exportContactSearch(filters);
    if (!ok) setExportMessage("Export impossible.");
    setBusy(false);
  }

  return (
    <div className="space-y-lg">
      <div className="flex flex-wrap items-start justify-between gap-md">
        <div>
          <span className="text-label-table-header uppercase tracking-wider text-on-surface-variant">
            Recherche transversale
          </span>
          <h1 className="text-headline-lg tracking-tight text-on-surface">Recherche globale</h1>
          <p className="text-body-compact text-on-surface-variant">
            Recherchez un contact dans toutes les fiches importées (nom, prénom, téléphone, email,
            adresse, ville, code postal ou fiche source). Les contacts extraits n&apos;apparaissent
            que si vous les demandez explicitement.
          </p>
        </div>
        <button
          type="button"
          onClick={() => void exportResults()}
          disabled={busy || rows.length === 0}
          className="rounded bg-primary px-lg py-1.5 text-body-compact font-medium text-on-primary hover:bg-primary-container transition-colors disabled:opacity-50"
          title="Exporte tous les résultats filtrés (toutes les pages)"
        >
          {busy ? "Export…" : "Exporter les résultats (XLSX)"}
        </button>
      </div>

      <form
        onSubmit={(e) => {
          e.preventDefault();
          submitSearch();
        }}
      >
        <FilterPanel
          basic={
            <>
              <SearchInput
                value={qInput}
                onChange={setQInput}
                placeholder="Nom, prénom, téléphone, email, ville, CP…"
              />
              <button
                type="submit"
                className="rounded bg-primary px-lg py-1.5 text-body-compact font-medium text-on-primary hover:bg-primary-container transition-colors"
              >
                Rechercher
              </button>
              <SelectFilter value={filters.status} onChange={(v) => apply({ status: v as SearchFilters["status"] })} options={STATUS_OPTIONS} ariaLabel="Statut du contact" />
              <SelectFilter
                value={`${filters.sortBy}:${filters.sortDirection}`}
                onChange={(v) => {
                  const [sortBy, sortDirection] = v.split(":");
                  apply({ sortBy, sortDirection: sortDirection as "asc" | "desc" });
                }}
                options={SORT_OPTIONS}
                ariaLabel="Trier les résultats"
              />
              <SelectFilter
                value={String(filters.pageSize)}
                onChange={(v) => apply({ pageSize: Number(v) })}
                options={PAGE_SIZES.map((n) => ({ value: String(n), label: `${n} / page` }))}
                ariaLabel="Résultats par page"
              />
            </>
          }
          activeCount={advancedCount}
          reset={
            <button
              type="button"
              onClick={resetAll}
              className="rounded border border-outline-variant px-md py-1.5 text-body-compact text-on-surface hover:bg-surface-low transition-colors"
            >
              Effacer
            </button>
          }
          advanced={
            <>
              <DateInput label="Importé du" value={draft.importedFrom} onChange={(v) => setDraft((current) => ({ ...current, importedFrom: v }))} />
              <DateInput label="Importé au" value={draft.importedTo} onChange={(v) => setDraft((current) => ({ ...current, importedTo: v }))} />
              <label className="flex flex-col gap-1">
                <span className="text-label-badge text-on-surface-variant">Source fiche</span>
                <select
                  aria-label="Filtrer par fiche source"
                  value={String(filters.fileId)}
                  onChange={(e) => apply({ fileId: e.target.value === "all" ? "all" : Number(e.target.value) })}
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
                <span className="text-label-badge text-on-surface-variant">Ville</span>
                <input
                  value={draft.ville}
                  onChange={(e) => setDraft((current) => ({ ...current, ville: e.target.value }))}
                  placeholder="Casablanca…"
                  className="bg-surface-lowest border border-outline-variant rounded px-md py-1.5 text-body-compact text-on-surface placeholder:text-outline focus:outline-none focus:border-primary transition-colors"
                />
              </label>
              <label className="flex flex-col gap-1">
                <span className="text-label-badge text-on-surface-variant">Code postal</span>
                <input
                  value={draft.cp}
                  onChange={(e) => setDraft((current) => ({ ...current, cp: e.target.value }))}
                  placeholder="20000…"
                  className="bg-surface-lowest border border-outline-variant rounded px-md py-1.5 text-body-compact text-on-surface placeholder:text-outline focus:outline-none focus:border-primary transition-colors"
                />
              </label>
              {filters.status !== "active" ? (
                <>
                  <DateInput label="Extrait du" value={draft.extractedFrom} onChange={(v) => setDraft((current) => ({ ...current, extractedFrom: v }))} />
                  <DateInput label="Extrait au" value={draft.extractedTo} onChange={(v) => setDraft((current) => ({ ...current, extractedTo: v }))} />
                </>
              ) : null}
              <div className="flex items-end">
                <button
                  type="submit"
                  className="rounded bg-primary px-lg py-1.5 text-body-compact font-medium text-on-primary hover:bg-primary-container transition-colors"
                >
                  Appliquer les filtres
                </button>
              </div>
            </>
          }
        />
      </form>

      {exportMessage ? (
        <p role="alert" className="rounded px-md py-2 text-body-compact bg-error-container text-on-error-container">
          {exportMessage}
        </p>
      ) : null}

      {loading && !data ? (
        <PageSkeleton rows={7} />
      ) : error ? (
        <ErrorState message={error} onRetry={retry} />
      ) : data ? (
        <section className="rounded-xl bg-surface-lowest shadow-sm">
          <div className="flex flex-wrap items-center justify-between gap-md p-md">
            <h2 className="text-headline-sm text-on-surface">
              {formatNumber(data.total)} résultat{data.total > 1 ? "s" : ""}
            </h2>
            <span className="text-label-badge text-on-surface-variant">
              {filters.status === "active"
                ? "Contacts actifs uniquement"
                : filters.status === "extracted"
                  ? "Contacts extraits uniquement"
                  : "Contacts actifs et extraits"}
            </span>
          </div>

          {rows.length === 0 ? (
            <EmptyState
              title="Aucun contact trouvé"
              message="Ajustez votre recherche ou effacez les filtres. Les contacts extraits ne sont inclus que si le statut « Contacts extraits » est sélectionné."
            />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left">
                <thead>
                  <tr className="border-b border-outline-variant text-label-table-header uppercase tracking-wider text-on-surface-variant">
                    <th className="px-3 py-2.5 pl-lg">NOM</th>
                    <th className="px-3 py-2.5">PRENOM</th>
                    <th className="px-3 py-2.5">TEL</th>
                    <th className="hidden px-3 py-2.5 lg:table-cell">Email</th>
                    <th className="hidden px-3 py-2.5 md:table-cell">CP</th>
                    <th className="px-3 py-2.5">VILLE</th>
                    <th className="hidden px-3 py-2.5 md:table-cell">Source fiche</th>
                    <th className="px-3 py-2.5">Statut</th>
                    <th className="py-2.5 pl-3 pr-lg text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-outline-variant/40 text-body-compact">
                  {rows.map((c) => (
                    <SearchRow key={c.id} contact={c} />
                  ))}
                </tbody>
              </table>
            </div>
          )}

          <div className="px-lg pb-md">
            <Pagination
              page={filters.page}
              pageSize={data.pageSize}
              total={data.total}
              onPageChange={(page) => writeFilters({ ...filters, page })}
            />
          </div>
        </section>
      ) : null}
    </div>
  );
}

function SearchRow({ contact }: { contact: ContactSearchRecord }) {
  const [open, setOpen] = useState(false);

  return (
    <>
      <tr className="hover:bg-surface-low transition-colors">
        <td className="px-3 py-2.5 pl-lg font-medium text-on-surface">{contact.nom}</td>
        <td className="px-3 py-2.5 text-on-surface">{contact.prenom}</td>
        <td className="px-3 py-2.5 font-mono tabular text-on-surface">{contact.tel ?? "—"}</td>
        <td className="hidden px-3 py-2.5 text-on-surface-variant lg:table-cell">{contact.email ?? "—"}</td>
        <td className="hidden px-3 py-2.5 font-mono tabular text-on-surface-variant md:table-cell">{contact.cp ?? "—"}</td>
        <td className="px-3 py-2.5 text-on-surface-variant">{contact.ville ?? "—"}</td>
        <td className="hidden max-w-56 truncate px-3 py-2.5 md:table-cell">
          {contact.source_file?.id ? (
            <Link href={`/fiches/${contact.source_file.id}`} className="text-on-surface hover:text-primary hover:underline">
              {contact.source_file.original_filename ?? `fiche #${contact.source_file.id}`}
            </Link>
          ) : (
            "—"
          )}
        </td>
        <td className="px-3 py-2.5">
          <span
            className={`inline-flex items-center px-2 py-0.5 rounded text-label-badge font-medium ${
              contact.status === "extracted"
                ? "bg-warning-container text-on-warning-container"
                : "bg-success-container text-on-success-container"
            }`}
          >
            {contact.status === "extracted" ? "Extrait" : "Actif"}
          </span>
        </td>
        <td className="py-2.5 pl-3 pr-lg text-right">
          <span className="inline-flex gap-sm">
            <button
              type="button"
              onClick={() => setOpen((v) => !v)}
              aria-expanded={open}
              className="rounded bg-surface-container px-2 py-1 text-label-badge font-medium text-on-surface hover:bg-surface-highest transition-colors"
            >
              {open ? "Masquer" : "Détails"}
            </button>
            {contact.source_file?.id ? (
              <Link
                href={`/fiches/${contact.source_file.id}`}
                className="rounded bg-surface-container px-2 py-1 text-label-badge font-medium text-on-surface hover:bg-surface-highest transition-colors"
              >
                Fiche source
              </Link>
            ) : null}
          </span>
        </td>
      </tr>
      {open ? (
        <tr className="bg-surface-low">
          <td colSpan={9} className="px-lg py-md text-body-compact text-on-surface">
            <dl className="grid grid-cols-1 gap-sm sm:grid-cols-3">
              <div>
                <dt className="text-label-badge uppercase text-on-surface-variant">Adresse</dt>
                <dd>{contact.adresse ?? "—"}</dd>
              </div>
              <div>
                <dt className="text-label-badge uppercase text-on-surface-variant">Date de naissance</dt>
                <dd>{contact.date_naissance ? formatDate(contact.date_naissance) : "—"}</dd>
              </div>
              <div>
                <dt className="text-label-badge uppercase text-on-surface-variant">Extrait le</dt>
                <dd>{contact.extracted_at ? formatDateTime(contact.extracted_at) : "—"}</dd>
              </div>
            </dl>
          </td>
        </tr>
      ) : null}
    </>
  );
}
