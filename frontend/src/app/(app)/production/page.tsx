"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { StatCard } from "@/components/StatCard";
import { Pagination } from "@/components/Pagination";
import { SearchInput } from "@/components/SearchInput";
import { SelectFilter } from "@/components/SelectFilter";
import { FilterPanel } from "@/components/filters/FilterPanel";
import { PageSkeleton } from "@/components/PageSkeleton";
import { ErrorState } from "@/components/ErrorState";
import { EmptyState } from "@/components/EmptyState";
import { useData } from "@/hooks/useData";
import { useAuth } from "@/hooks/useAuth";
import { CallVerificationPanel } from "@/components/telephony/CallVerificationPanel";
import * as api from "@/services";
import { ApiError } from "@/services/api";
import { formatAmount, formatDateOnly, formatDateTime, formatNumber } from "@/lib/format";
import {
  PRODUCTION_SYNC_BADGE,
  type ProductionRecord,
  type ProductionSyncSummary,
} from "@/types/production";

/**
 * Table de production.
 *
 * Une ligne = un contrat de la feuille Google Sheets : DATE, CIAL, NOM,
 * PRENOM, COTISATION, PRIME ANNUELLE, CIE, PRODUIT, Date d'effet, VALIDATION
 * et TYPE DE SIGNATURE. La `reference_contrat` est générée par l'application
 * (elle n'existe pas dans la feuille). Le téléphone, lui, est saisi ici et
 * n'est jamais écrasé par une synchronisation.
 *
 * Sélection : elle porte sur les lignes affichées de la page courante. Tout
 * changement de page, de filtre, de tri ou de recherche l'efface
 * explicitement — aucune sélection « fantôme » n'est soumise.
 *
 * « Vérifier les appels » ouvre le panneau téléphonique existant ; aucun
 * résultat d'appel ne vaut validation de contrat.
 */

const SORT_OPTIONS = [
  { value: "id:asc", label: "Ordre d'import" },
  { value: "reference_contrat:asc", label: "Référence contrat (A→Z)" },
  { value: "date:desc", label: "DATE — plus récente" },
  { value: "date:asc", label: "DATE — plus ancienne" },
  { value: "cial:asc", label: "CIAL (A→Z)" },
  { value: "nom:asc", label: "NOM (A→Z)" },
  { value: "nom:desc", label: "NOM (Z→A)" },
  { value: "prenom:asc", label: "PRENOM (A→Z)" },
  { value: "cie:asc", label: "CIE (A→Z)" },
  { value: "produit:asc", label: "PRODUIT (A→Z)" },
  { value: "date_effet:desc", label: "Date d'effet — plus récente" },
  { value: "validation:asc", label: "VALIDATION (A→Z)" },
  { value: "last_synced_at:desc", label: "Synchronisé — plus récent" },
];

const PHONE_OPTIONS = [
  { value: "all", label: "Téléphone : tous" },
  { value: "yes", label: "Avec numéro" },
  { value: "no", label: "Sans numéro" },
];

const PAGE_SIZES = [10, 25, 50, 100];

export default function ProductionPage() {
  useAuth(); // session requise par le shell

  const [search, setSearch] = useState("");
  const [cie, setCie] = useState("");
  const [produit, setProduit] = useState("");
  const [validation, setValidation] = useState("");
  const [typeSignature, setTypeSignature] = useState("");
  const [hasPhone, setHasPhone] = useState("all");
  const [sort, setSort] = useState("id:asc");
  const [pageSize, setPageSize] = useState(25);
  const [page, setPage] = useState(1);
  const [refreshKey, setRefreshKey] = useState(0);

  const [selected, setSelected] = useState<Record<number, ProductionRecord>>({});
  const [selectionNotice, setSelectionNotice] = useState<string | null>(null);
  const [verificationOpen, setVerificationOpen] = useState(false);

  const [sortBy, sortDirection] = sort.split(":");

  const records = useData(
    () =>
      api.listProduction({
        search,
        cie,
        produit,
        validation,
        typeSignature,
        hasPhone: hasPhone as "all" | "yes" | "no",
        sortBy,
        sortDirection: sortDirection as api.SortDirection,
        page,
        pageSize,
      }),
    [search, cie, produit, validation, typeSignature, hasPhone, sort, pageSize, page, refreshKey],
  );

  const data = records.data;
  const showSkeleton = records.loading && !data;
  const selectedIds = useMemo(() => Object.keys(selected).map(Number), [selected]);

  /** Efface la sélection quand le contenu affiché change (page, filtres, tri). */
  function clearSelection(reason: string) {
    if (selectedIds.length === 0) return;
    setSelected({});
    setSelectionNotice(`Sélection effacée : ${reason}.`);
  }

  function changeFilter(apply: () => void, reason: string) {
    apply();
    setPage(1);
    clearSelection(reason);
  }

  function resetFilters() {
    setSearch("");
    setCie("");
    setProduit("");
    setValidation("");
    setTypeSignature("");
    setHasPhone("all");
    setSort("id:asc");
    setPageSize(25);
    setPage(1);
    clearSelection("réinitialisation des filtres");
  }

  function toggleRow(record: ProductionRecord) {
    setSelectionNotice(null);
    setVerificationOpen(false);
    setSelected((current) => {
      const next = { ...current };
      if (next[record.id]) delete next[record.id];
      else next[record.id] = record;
      return next;
    });
  }

  const visibleIds = data?.data.map((r) => r.id) ?? [];
  const allVisibleSelected = visibleIds.length > 0 && visibleIds.every((id) => selected[id]);

  const advancedCount = [
    cie,
    produit,
    validation,
    typeSignature,
    hasPhone !== "all" ? "yes" : "",
  ].filter(Boolean).length;

  const cieOptions = [
    { value: "", label: "Toutes les CIE" },
    ...(data?.filters.cies ?? []).map((c) => ({ value: c, label: c })),
  ];
  const produitOptions = [
    { value: "", label: "Tous les produits" },
    ...(data?.filters.produits ?? []).map((p) => ({ value: p, label: p })),
  ];
  const validationOptions = [
    { value: "", label: "Toutes les validations" },
    ...(data?.filters.validations ?? []).map((v) => ({ value: v, label: v })),
  ];
  const typeSignatureOptions = [
    { value: "", label: "Tous les types de signature" },
    ...(data?.filters.type_signatures ?? []).map((t) => ({ value: t, label: t })),
  ];

  return (
    <div className="space-y-lg">
      <div className="flex flex-wrap items-start justify-between gap-md">
        <div>
          <span className="text-label-table-header uppercase tracking-wider text-on-surface-variant">
            Souscription &amp; Portefeuille
          </span>
          <h1 className="text-headline-lg tracking-tight text-on-surface">Production</h1>
          <p className="text-body-compact text-on-surface-variant">
            {formatNumber(data?.total ?? 0)} ligne{data && data.total > 1 ? "s" : ""} synchronisée
            {data && data.total > 1 ? "s" : ""} depuis Google Sheets. Les 11 colonnes de la feuille
            sont affichées ; la référence contrat est générée par l&apos;application et le numéro de
            téléphone est saisi ici, jamais écrasé par une synchronisation.
          </p>
        </div>
        <SyncButton onSynced={() => setRefreshKey((k) => k + 1)} />
      </div>

      <LastSyncPanel sync={data?.lastSync ?? null} />

      <FilterPanel
        basic={
          <>
            <SearchInput
              value={search}
              onChange={(v) => changeFilter(() => setSearch(v), "recherche modifiée")}
              placeholder="Référence, nom, prénom, CIAL, CIE, téléphone…"
            />
            <SelectFilter
              value={sort}
              onChange={(v) => changeFilter(() => setSort(v), "tri modifié")}
              options={SORT_OPTIONS}
              ariaLabel="Trier les lignes de production"
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
            <SelectFilter
              value={cie}
              onChange={(v) => changeFilter(() => setCie(v), "filtre CIE modifié")}
              options={cieOptions}
              ariaLabel="Filtrer par CIE"
            />
            <SelectFilter
              value={produit}
              onChange={(v) => changeFilter(() => setProduit(v), "filtre produit modifié")}
              options={produitOptions}
              ariaLabel="Filtrer par produit"
            />
            <SelectFilter
              value={validation}
              onChange={(v) => changeFilter(() => setValidation(v), "filtre validation modifié")}
              options={validationOptions}
              ariaLabel="Filtrer par validation"
            />
            <SelectFilter
              value={typeSignature}
              onChange={(v) =>
                changeFilter(() => setTypeSignature(v), "filtre type de signature modifié")
              }
              options={typeSignatureOptions}
              ariaLabel="Filtrer par type de signature"
            />
            <SelectFilter
              value={hasPhone}
              onChange={(v) => changeFilter(() => setHasPhone(v), "filtre téléphone modifié")}
              options={PHONE_OPTIONS}
              ariaLabel="Filtrer selon la présence d'un numéro"
            />
            <label className="flex flex-col gap-1">
              <span className="text-label-badge text-on-surface-variant">Lignes par page</span>
              <select
                aria-label="Lignes par page"
                value={String(pageSize)}
                onChange={(e) => {
                  const value = Number(e.target.value);
                  changeFilter(() => setPageSize(value), "nombre de lignes par page modifié");
                }}
                className="bg-surface-lowest border border-outline-variant rounded px-md py-1.5 text-body-compact text-on-surface focus:outline-none focus:border-primary transition-colors"
              >
                {PAGE_SIZES.map((n) => (
                  <option key={n} value={n}>
                    {n} / page
                  </option>
                ))}
              </select>
            </label>
          </>
        }
      />

      {selectionNotice ? (
        <p
          role="status"
          className="rounded bg-warning-container px-md py-2 text-body-compact text-on-warning-container"
        >
          {selectionNotice}
        </p>
      ) : null}

      {selectedIds.length > 0 ? (
        <div className="flex flex-wrap items-center justify-between gap-md rounded-xl bg-surface-container px-lg py-md">
          <div className="text-body-compact text-on-surface">
            <strong className="font-mono tabular">{selectedIds.length}</strong> ligne
            {selectedIds.length > 1 ? "s" : ""} sélectionnée{selectedIds.length > 1 ? "s" : ""} sur
            cette page.
            <span className="ml-1 text-on-surface-variant">
              La sélection ne s&apos;étend pas aux autres pages.
            </span>
          </div>
          <div className="flex items-center gap-sm">
            <button
              type="button"
              onClick={() => {
                setSelected({});
                setSelectionNotice(null);
                setVerificationOpen(false);
              }}
              className="rounded border border-outline-variant bg-surface-lowest px-md py-1.5 text-body-compact text-on-surface hover:bg-surface-low transition-colors"
            >
              Effacer la sélection
            </button>
            <button
              type="button"
              onClick={() => setVerificationOpen(true)}
              className="rounded bg-primary px-md py-1.5 text-body-compact font-medium text-on-primary hover:bg-primary-container transition-colors"
            >
              Vérifier les appels
            </button>
          </div>
        </div>
      ) : null}

      {verificationOpen && selectedIds.length > 0 ? (
        <CallVerificationPanel
          key={selectedIds.join("-")}
          records={Object.values(selected)}
          onClose={() => setVerificationOpen(false)}
        />
      ) : null}

      {showSkeleton ? (
        <PageSkeleton rows={6} />
      ) : records.error ? (
        <ErrorState message={records.error} onRetry={records.retry} />
      ) : data && data.data.length === 0 ? (
        <div className="rounded-xl bg-surface-lowest shadow-sm">
          <EmptyState
            title="Aucune ligne de production"
            message={
              data.total === 0 && !hasActiveFilters({
                search,
                cie,
                produit,
                validation,
                typeSignature,
                hasPhone,
              })
                ? "Lancez une première synchronisation Google Sheets pour importer les lignes de production."
                : "Aucune ligne ne correspond aux critères. Modifiez la recherche ou réinitialisez les filtres."
            }
          />
        </div>
      ) : data ? (
        <section className="rounded-xl bg-surface-lowest shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[1560px] text-left">
              <thead>
                <tr className="border-b border-outline-variant text-label-table-header uppercase tracking-wider text-on-surface-variant">
                  <th className="w-10 py-2.5 pl-lg pr-3">
                    <input
                      type="checkbox"
                      aria-label="Sélectionner toutes les lignes affichées"
                      checked={allVisibleSelected}
                      ref={(el) => {
                        if (el) el.indeterminate = selectedIds.length > 0 && !allVisibleSelected;
                      }}
                      onChange={(e) => {
                        setSelectionNotice(null);
                        if (e.target.checked) {
                          const next: Record<number, ProductionRecord> = {};
                          for (const row of data.data) next[row.id] = row;
                          setSelected(next);
                        } else {
                          setSelected({});
                        }
                      }}
                    />
                  </th>
                  <th className="whitespace-nowrap px-3 py-2.5">Référence contrat</th>
                  <th className="whitespace-nowrap px-3 py-2.5">DATE</th>
                  <th className="whitespace-nowrap px-3 py-2.5">CIAL</th>
                  <th className="min-w-[150px] px-3 py-2.5">NOM</th>
                  <th className="min-w-[120px] px-3 py-2.5">PRENOM</th>
                  <th className="whitespace-nowrap px-3 py-2.5 text-right">COTISATION</th>
                  <th className="whitespace-nowrap px-3 py-2.5 text-right">PRIME ANNUELLE</th>
                  <th className="min-w-[110px] px-3 py-2.5">CIE</th>
                  <th className="min-w-[160px] px-3 py-2.5">PRODUIT</th>
                  <th className="whitespace-nowrap px-3 py-2.5">Date d&apos;effet</th>
                  <th className="min-w-[110px] px-3 py-2.5">VALIDATION</th>
                  <th className="min-w-[150px] px-3 py-2.5">TYPE DE SIGNATURE</th>
                  <th className="min-w-[150px] px-3 py-2.5">Téléphone</th>
                  <th className="hidden whitespace-nowrap px-3 py-2.5 xl:table-cell">
                    Synchronisé le
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-outline-variant/40 text-body-compact">
                {data.data.map((row) => (
                  <tr
                    key={row.id}
                    className={`hover:bg-surface-low transition-colors ${
                      selected[row.id] ? "bg-surface-low" : ""
                    }`}
                  >
                    <td className="py-2.5 pl-lg pr-3">
                      <input
                        type="checkbox"
                        aria-label={`Sélectionner la ligne ${row.reference_contrat ?? row.id}`}
                        checked={Boolean(selected[row.id])}
                        onChange={() => toggleRow(row)}
                      />
                    </td>
                    <td className="whitespace-nowrap px-3 py-2.5 font-mono font-semibold text-on-surface">
                      {row.reference_contrat ?? "—"}
                    </td>
                    <td className="whitespace-nowrap px-3 py-2.5 tabular text-on-surface">
                      {formatDateOnly(row.date)}
                    </td>
                    <td className="whitespace-nowrap px-3 py-2.5 font-mono tabular text-on-surface">
                      {row.cial ?? "—"}
                    </td>
                    <td className="px-3 py-2.5 text-on-surface">{row.nom ?? "—"}</td>
                    <td className="px-3 py-2.5 text-on-surface">{row.prenom ?? "—"}</td>
                    <td className="whitespace-nowrap px-3 py-2.5 text-right tabular text-on-surface">
                      {formatAmount(row.cotisation)}
                    </td>
                    <td className="whitespace-nowrap px-3 py-2.5 text-right tabular text-on-surface">
                      {formatAmount(row.prime_annuelle)}
                    </td>
                    <td className="px-3 py-2.5 text-on-surface-variant">{row.cie ?? "—"}</td>
                    <td className="px-3 py-2.5 text-on-surface-variant">{row.produit ?? "—"}</td>
                    <td className="whitespace-nowrap px-3 py-2.5 tabular text-on-surface">
                      {formatDateOnly(row.date_effet)}
                    </td>
                    <td className="px-3 py-2.5 text-on-surface-variant">{row.validation ?? "—"}</td>
                    <td className="px-3 py-2.5 text-on-surface-variant">
                      {row.type_signature ?? "—"}
                    </td>
                    <td className="px-3 py-2.5">
                      <PhoneCell
                        record={row}
                        onSaved={(updated) => {
                          setSelected((current) =>
                            current[updated.id] ? { ...current, [updated.id]: updated } : current,
                          );
                          setRefreshKey((k) => k + 1);
                        }}
                      />
                    </td>
                    <td className="hidden whitespace-nowrap px-3 py-2.5 tabular text-on-surface-variant xl:table-cell">
                      {row.last_synced_at ? formatDateTime(row.last_synced_at) : "—"}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="px-lg pb-md">
            <Pagination
              page={data.page}
              pageSize={data.pageSize}
              total={data.total}
              onPageChange={(next) => changeFilter(() => setPage(next), "changement de page")}
            />
          </div>
        </section>
      ) : null}
    </div>
  );
}

function hasActiveFilters(filters: {
  search: string;
  cie: string;
  produit: string;
  validation: string;
  typeSignature: string;
  hasPhone: string;
}): boolean {
  return Boolean(
    filters.search ||
      filters.cie ||
      filters.produit ||
      filters.validation ||
      filters.typeSignature ||
      filters.hasPhone !== "all",
  );
}

/** Bouton de synchronisation + résumé du résultat de l'opération courante. */
function SyncButton({ onSynced }: { onSynced: () => void }) {
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<ProductionSyncSummary | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function run() {
    setBusy(true);
    setError(null);
    setResult(null);
    try {
      const sync = await api.syncProduction();
      setResult(sync);
      onSynced();
    } catch (e) {
      setError(
        e instanceof ApiError
          ? e.message
          : e instanceof Error
            ? e.message
            : "Synchronisation impossible.",
      );
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="flex flex-col items-end gap-sm">
      <button
        type="button"
        onClick={() => void run()}
        disabled={busy}
        className="rounded bg-primary px-lg py-2 text-body-compact font-medium text-on-primary hover:bg-primary-container transition-colors disabled:opacity-50"
      >
        {busy ? "Synchronisation en cours…" : "Synchroniser Google Sheets"}
      </button>

      {error ? (
        <p
          role="alert"
          className="max-w-3xl rounded bg-error-container px-md py-2 text-body-compact text-on-error-container"
        >
          {error}
        </p>
      ) : null}

      {result ? (
        <div
          role="status"
          className="max-w-md rounded bg-surface-low p-md text-body-compact text-on-surface shadow-sm"
        >
          <div className="flex items-center gap-sm">
            <span
              className={`inline-flex items-center rounded px-2 py-0.5 text-label-badge font-medium ${
                PRODUCTION_SYNC_BADGE[result.status].className
              }`}
            >
              {PRODUCTION_SYNC_BADGE[result.status].label}
            </span>
            <span>{result.error_summary}</span>
          </div>
          <dl className="mt-sm grid grid-cols-2 gap-x-md gap-y-1 sm:grid-cols-3">
            <SyncCount label="Lues" value={result.rows_read} />
            <SyncCount label="Créées" value={result.created_count} />
            <SyncCount label="Mises à jour" value={result.updated_count} />
            <SyncCount label="Vides ignorées" value={result.skipped_count} />
            <SyncCount label="Rejetées" value={result.failed_count} />
          </dl>
          <SyncWarnings warnings={result.warnings} />
          <SyncRowErrors rowErrors={result.row_errors} />
        </div>
      ) : null}
    </div>
  );
}

function SyncCount({ label, value }: { label: string; value: number }) {
  return (
    <div className="flex items-baseline justify-between gap-sm">
      <dt className="text-on-surface-variant">{label}</dt>
      <dd className="font-mono tabular">{formatNumber(value)}</dd>
    </div>
  );
}

/** Avertissements non bloquants (colonne inconnue, lecture tronquée…). */
function SyncWarnings({ warnings }: { warnings: string[] | null }) {
  if (!warnings || warnings.length === 0) return null;

  return (
    <ul className="mt-sm space-y-1 rounded bg-warning-container px-md py-2 text-label-badge text-on-warning-container">
      {warnings.map((warning, index) => (
        <li key={index}>{warning}</li>
      ))}
    </ul>
  );
}

function SyncRowErrors({ rowErrors }: { rowErrors: ProductionSyncSummary["row_errors"] }) {
  if (!rowErrors || rowErrors.length === 0) return null;

  return (
    <details className="mt-sm">
      <summary className="cursor-pointer text-on-surface-variant">
        Détail des lignes rejetées ({rowErrors.length})
      </summary>
      <ul className="mt-1 max-h-48 space-y-0.5 overflow-y-auto">
        {rowErrors.map((rowError) => (
          <li key={`${rowError.row}-${rowError.field}`} className="text-label-badge">
            Ligne {rowError.row} · {rowError.field} — {rowError.reason}
          </li>
        ))}
      </ul>
    </details>
  );
}

/** Bandeau de la dernière synchronisation connue (même après rechargement). */
function LastSyncPanel({ sync }: { sync: ProductionSyncSummary | null }) {
  if (!sync) {
    return (
      <div className="rounded-xl bg-surface-lowest p-lg shadow-sm">
        <h2 className="text-headline-sm text-on-surface">Dernière synchronisation</h2>
        <p className="text-body-compact text-on-surface-variant">
          Aucune synchronisation enregistrée. Lancez « Synchroniser Google Sheets » pour importer les
          lignes de production.
        </p>
      </div>
    );
  }

  const badge = PRODUCTION_SYNC_BADGE[sync.status];

  return (
    <section className="rounded-xl bg-surface-lowest p-lg shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-sm">
        <h2 className="text-headline-sm text-on-surface">Dernière synchronisation</h2>
        <span
          className={`inline-flex items-center rounded px-2 py-0.5 text-label-badge font-medium ${badge.className}`}
        >
          {badge.label}
        </span>
      </div>
      <p className="mt-1 text-body-compact text-on-surface-variant">
        {sync.completed_at ? formatDateTime(sync.completed_at) : formatDateTime(sync.created_at ?? "")}
        {sync.performed_by ? ` · par ${sync.performed_by}` : ""}
      </p>
      <div className="mt-md grid grid-cols-2 gap-md sm:grid-cols-5">
        <StatCard label="Lues" value={formatNumber(sync.rows_read)} hint="lignes de la plage" />
        <StatCard label="Créées" value={formatNumber(sync.created_count)} />
        <StatCard label="Mises à jour" value={formatNumber(sync.updated_count)} />
        <StatCard label="Vides ignorées" value={formatNumber(sync.skipped_count)} />
        <StatCard
          label="Rejetées"
          value={formatNumber(sync.failed_count)}
          tone={sync.failed_count > 0 ? "error" : "neutral"}
          hint={sync.failed_count > 0 ? "voir le détail" : undefined}
        />
      </div>
      {sync.error_summary ? (
        <p className="mt-md rounded bg-surface-low px-md py-2 text-body-compact text-on-surface">
          {sync.error_summary}
        </p>
      ) : null}
      <SyncWarnings warnings={sync.warnings} />
      <SyncRowErrors rowErrors={sync.row_errors} />
    </section>
  );
}

/**
 * Cellule téléphone éditable. La valeur saisie est envoyée telle quelle
 * (validation côté serveur) : un refus affiche le message à côté du champ et
 * laisse la valeur précédente intacte.
 */
function PhoneCell({
  record,
  onSaved,
}: {
  record: ProductionRecord;
  onSaved: (updated: ProductionRecord) => void;
}) {
  const [editing, setEditing] = useState(false);
  const [value, setValue] = useState(record.phone ?? "");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const inputRef = useRef<HTMLInputElement | null>(null);

  useEffect(() => {
    if (editing) inputRef.current?.focus();
  }, [editing]);

  function startEditing() {
    setValue(record.phone ?? "");
    setError(null);
    setEditing(true);
  }

  function cancel() {
    setEditing(false);
    setError(null);
  }

  async function save() {
    setSaving(true);
    setError(null);
    try {
      const updated = await api.updateProductionPhone(record.id, value);
      onSaved(updated);
      setEditing(false);
    } catch (e) {
      setError(phoneErrorMessage(e));
    } finally {
      setSaving(false);
    }
  }

  if (!editing) {
    return (
      <div className="flex items-center gap-sm">
        <span className={record.phone ? "font-mono tabular text-on-surface" : "text-on-surface-variant"}>
          {record.phone ?? "—"}
        </span>
        <button
          type="button"
          onClick={startEditing}
          className="rounded bg-surface-container px-2 py-0.5 text-label-badge font-medium text-on-surface hover:bg-surface-highest transition-colors"
        >
          {record.phone ? "Modifier" : "Ajouter"}
        </button>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-1">
      <div className="flex items-center gap-1">
        <input
          ref={inputRef}
          type="tel"
          value={value}
          aria-label={`Numéro de téléphone du client ${[record.nom, record.prenom].filter(Boolean).join(" ") || record.id}`}
          aria-invalid={error !== null}
          placeholder="+212 6 12 34 56 78"
          onChange={(e) => setValue(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter") void save();
            if (e.key === "Escape") cancel();
          }}
          className="w-44 rounded border border-outline-variant bg-surface-lowest px-2 py-1 font-mono text-body-compact text-on-surface focus:outline-none focus:border-primary"
        />
        <button
          type="button"
          onClick={() => void save()}
          disabled={saving}
          className="rounded bg-primary px-2 py-1 text-label-badge font-medium text-on-primary hover:bg-primary-container transition-colors disabled:opacity-50"
        >
          {saving ? "…" : "OK"}
        </button>
        <button
          type="button"
          onClick={cancel}
          className="rounded border border-outline-variant px-2 py-1 text-label-badge text-on-surface hover:bg-surface-low transition-colors"
        >
          Annuler
        </button>
      </div>
      {error ? (
        <p role="alert" className="text-label-badge text-error">
          {error}
        </p>
      ) : (
        <p className="text-label-badge text-on-surface-variant">
          Valeur conservée telle quelle (« + » et indicatif inclus).
        </p>
      )}
    </div>
  );
}

/** Message de validation lisible pour le champ téléphone. */
function phoneErrorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    const errors = error.payload?.errors;
    if (errors && typeof errors === "object") {
      const first = (errors as Record<string, unknown>).phone;
      if (Array.isArray(first) && typeof first[0] === "string") return first[0];
    }
    if (error.status === 422) return "Numéro refusé : vérifiez le format saisi.";
    return error.message;
  }
  if (error instanceof Error) return error.message;
  return "Enregistrement impossible.";
}
