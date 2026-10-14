"use client";

import { use, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Pagination } from "@/components/Pagination";
import { SearchInput } from "@/components/SearchInput";
import { PageSkeleton } from "@/components/PageSkeleton";
import { ErrorState } from "@/components/ErrorState";
import { EmptyState } from "@/components/EmptyState";
import { useData } from "@/hooks/useData";
import * as api from "@/services";
import { ApiError } from "@/services/api";
import { formatDateTime, formatNumber } from "@/lib/format";
import type { DuplicateGroup, ImportedFile, FileCounts } from "@/types/contact";

const EXPORT_HEADERS = ["NOM", "PRENOM", "ADRESSE", "CP", "VILLE", "TEL", "Email", "Date de naissance"];

export default function FicheDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const router = useRouter();
  const fileId = Number(id);

  const detail = useData(() => api.getFile(fileId), [fileId]);

  // Déclarés avant les hooks de données qui s'en servent dans leurs deps.
  const [contactSearch, setContactSearch] = useState("");
  const [contactPage, setContactPage] = useState(1);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [scanShown, setScanShown] = useState(false);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ tone: "success" | "error"; text: string } | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [refreshFlag, setRefreshFlag] = useState(0);

  const contacts = useData(
    () => api.listFileContacts(fileId, { search: contactSearch, page: contactPage, pageSize: 10 }),
    [fileId, contactSearch, contactPage, refreshFlag],
  );
  const duplicates = useData(() => api.getDuplicates(fileId), [fileId, refreshFlag]);

  if (detail.loading) return <PageSkeleton rows={8} />;
  if (detail.error) return <ErrorState message={detail.error} onRetry={detail.retry} />;
  if (!detail.data) {
    router.replace("/fiches");
    return null;
  }

  const file: ImportedFile & { counts: FileCounts } = detail.data;

  async function refresh() {
    setRefreshFlag((v) => v + 1);
    await detail.retry();
  }

  async function confirmExtraction() {
    if (selected.size === 0) return;
    setBusy(true);
    setConfirmOpen(false);
    try {
      const result = await api.extractContacts(fileId, [...selected]);
      setMessage({
        tone: "success",
        text: `Extraction réussie : ${result.extraction.extracted_count} contact(s) extraits, ${result.remaining_active_contacts} contacts actifs restants. Consultez l'opération dans « Extractions Doublons ».`,
      });
      setSelected(new Set());
      setScanShown(false);
      await refresh();
    } catch (error) {
      const text = error instanceof ApiError ? error.message : "Extraction impossible.";
      setMessage({ tone: "error", text });
    } finally {
      setBusy(false);
    }
  }

  async function handleArchive() {
    setBusy(true);
    try {
      await api.archiveFile(fileId);
      router.push("/fiches");
    } catch (error) {
      setMessage({ tone: "error", text: error instanceof ApiError ? error.message : "Archivage impossible." });
      setBusy(false);
    }
  }

  async function download(kind: "export" | "cleaned" | "original") {
    setBusy(true);
    const ok =
      kind === "export"
        ? (await api.exportFileContents(fileId))
        : kind === "cleaned"
          ? await api.exportFileCleaned(fileId)
          : await api.downloadOriginalFile(fileId, file.original_filename);
    if (!ok) setMessage({ tone: "error", text: "Téléchargement impossible (fichier original indisponible ?)." });
    setBusy(false);
  }

  const toggle = (contactId: number) => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(contactId)) next.delete(contactId);
      else next.add(contactId);
      return next;
    });
  };

  const dupData = duplicates.data;
  const totals = {
    selected: selected.size,
    remaining: (file.counts?.contacts_total ?? 0) - selected.size,
  };

  return (
    <div className="space-y-lg">
      <nav className="flex items-center gap-xs text-body-compact text-on-surface-variant" aria-label="Fil d'ariane">
        <Link href="/fiches" className="hover:text-on-surface hover:underline">
          Fiches Contacts
        </Link>
        <span>/</span>
        <span className="font-medium text-on-surface">{file.original_filename}</span>
      </nav>

      {/* En-tête + actions */}
      <section className="rounded-xl bg-surface-lowest p-lg shadow-sm">
        <div className="flex flex-wrap items-start justify-between gap-md">
          <div>
            <span className="text-label-table-header uppercase tracking-wider text-on-surface-variant">
              {file.imported_by.name ? `Importée par ${file.imported_by.name}` : "Fiche importée"}
              {" · "}
              {formatDateTime(file.imported_at)}
            </span>
            <h1 className="text-headline-lg tracking-tight text-on-surface">{file.original_filename}</h1>
            <p className="text-body-compact text-on-surface-variant">
              {formatNumber(file.successful_rows)} contact(s) importé(s) sur {formatNumber(file.total_rows)} ligne(s)
              {file.failed_rows > 0 ? `, ${formatNumber(file.failed_rows)} réjetée(s)` : ""}
              {file.skipped_rows > 0 ? `, ${formatNumber(file.skipped_rows)} vide(s)` : ""}.
            </p>
          </div>
          <div className="flex flex-wrap gap-md">
            <button
              type="button"
              onClick={() => void download("export")}
              disabled={busy}
              className="rounded border border-outline-variant bg-surface-lowest px-md py-1.5 text-body-compact text-on-surface hover:bg-surface-low transition-colors disabled:opacity-50"
              title="Tous les contacts de la fiche (extraits inclus)"
            >
              Export complet (XLSX)
            </button>
            <button
              type="button"
              onClick={() => void download("cleaned")}
              disabled={busy}
              className="rounded bg-primary px-md py-1.5 text-body-compact font-medium text-on-primary hover:bg-primary-container transition-colors disabled:opacity-50"
              title="Contacts actifs uniquement (contacts extraits exclus)"
            >
              Export nettoyé (XLSX)
            </button>
            {file.original_available ? (
              <button
                type="button"
                onClick={() => void download("original")}
                disabled={busy}
                className="rounded border border-outline-variant bg-surface-lowest px-md py-1.5 text-body-compact text-on-surface hover:bg-surface-low transition-colors disabled:opacity-50"
              >
                Fichier original
              </button>
            ) : (
              <span className="self-center text-label-badge text-on-surface-variant">original non conservé</span>
            )}
            {!file.archived ? (
              <button
                type="button"
                onClick={() => void handleArchive()}
                disabled={busy}
                className="rounded border border-error/40 px-md py-1.5 text-body-compact text-error hover:bg-error-container transition-colors disabled:opacity-50"
                title="Archiver la fiche (suppression douce — l'historique est conservé)"
              >
                Archiver
              </button>
            ) : null}
          </div>
        </div>

        <div className="mt-md grid grid-cols-2 gap-md sm:grid-cols-3 xl:grid-cols-4">
          <StatMini label="Contacts actifs" value={formatNumber(file.counts?.contacts_total ?? 0)} />
          <StatMini label="Contacts extraits" value={formatNumber(file.counts?.contacts_extracted ?? 0)} />
          <StatMini label="Lignes échouées" value={formatNumber(file.failed_rows)} tone={file.failed_rows > 0 ? "error" : undefined} />
          <StatMini label="Dernier import" value={file.counts?.last_import ? formatDateTime(file.counts.last_import.completed_at ?? file.counts.last_import.created_at) : "—"} />
        </div>
      </section>

      {message ? (
        <p
          role="alert"
          className={`rounded px-md py-2 text-body-compact ${
            message.tone === "error"
              ? "bg-error-container text-on-error-container"
              : "bg-success-container text-on-success-container"
          }`}
        >
          {message.text}
        </p>
      ) : null}

      {/* Doublons */}
      <section className="rounded-xl bg-surface-lowest p-lg shadow-sm">
        <div className="mb-md flex flex-wrap items-center justify-between gap-md">
          <div>
            <h2 className="text-headline-sm text-on-surface">Doublons téléphoniques</h2>
            <p className="text-body-compact text-on-surface-variant">
              Regroupement conservatif par numéro normalisé (séparateurs ignorés). Les contacts sans
              numéro exploitable sont exclus. Un scan ne modifie jamais les fiches.
            </p>
          </div>
          {!file.archived ? (
            <button
              type="button"
              onClick={() => setScanShown((v) => !v)}
              className="rounded bg-secondary px-lg py-1.5 text-body-compact font-medium text-on-secondary hover:opacity-90 transition-opacity"
            >
              {scanShown ? "Masquer les groupes" : "Rechercher / afficher les groupes"}
            </button>
          ) : null}
        </div>

        {scanShown ? (duplicates.error ? (
          <ErrorState message={duplicates.error} onRetry={duplicates.retry} />
        ) : !dupData ? (
          <PageSkeleton rows={3} />
        ) : dupData.groups.length === 0 ? (
          <p className="text-body-compact text-on-surface-variant">
            Aucun groupe de doublons détecté
            {dupData.stats.without_usable_tel > 0
              ? ` — ${dupData.stats.without_usable_tel} contact(s) sans numéro exploitable exclu(s).`
              : "."}
          </p>
        ) : (
          <div className="space-y-md">
            <div className="flex flex-wrap gap-md text-label-badge text-on-surface-variant">
              <span>{dupData.stats.duplicate_groups} groupe(s)</span>
              <span>·</span>
              <span>{dupData.stats.contacts_in_groups} contact(s) impliqué(s)</span>
              <span>·</span>
              <span>{dupData.stats.without_usable_tel} sans numéro exploitable</span>
              <span>·</span>
              <span>{dupData.stats.not_in_any_group} hors doublon</span>
              <span>·</span>
              <span>{dupData.stats.total_active} contact(s) actifs</span>
            </div>

            {dupData.groups.map((group, i) => (
              <DuplicateGroupCard
                key={`${group.phone}-${i}`}
                group={group}
                selected={selected}
                toggle={toggle}
              />
            ))}

            {dupData.groups.length > 0 ? (
              <div className="flex flex-wrap items-center justify-between gap-md border-t border-outline-variant/40 pt-md">
                <p className="text-body-compact text-on-surface-variant">
                  {totals.selected} contact(s) sélectionné(s) pour extraction · {Math.max(0, totals.remaining)} resteront
                  actifs dans cette fiche.
                </p>
                <button
                  type="button"
                  disabled={totals.selected === 0 || busy}
                  onClick={() => setConfirmOpen(true)}
                  className="rounded bg-primary px-lg py-2 text-body-compact font-medium text-on-primary hover:bg-primary-container transition-colors disabled:opacity-50"
                >
                  Extraire la sélection…
                </button>
              </div>
            ) : null}
          </div>
        )) : null}
      </section>

      {confirmOpen ? (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-md"
          role="dialog"
          aria-modal="true"
          aria-label="Confirmation d'extraction"
        >
          <div className="w-full max-w-xl rounded-xl bg-surface-lowest p-lg shadow-lg">
            <h3 className="text-headline-sm text-on-surface">Confirmer l&apos;extraction</h3>
            <dl className="mt-md space-y-2 text-body-compact text-on-surface">
              <div className="flex justify-between">
                <dt className="text-on-surface-variant">Fiche source</dt>
                <dd>{file.original_filename}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-on-surface-variant">Contacts actuellement actifs</dt>
                <dd className="font-mono">{formatNumber(file.counts?.contacts_total ?? 0)}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-on-surface-variant">Contacts sélectionnés pour extraction</dt>
                <dd className="font-mono font-bold">{totals.selected}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-on-surface-variant">Contacts restants après extraction</dt>
                <dd className="font-mono font-bold">{Math.max(0, totals.remaining)}</dd>
              </div>
            </dl>
            <p className="mt-md rounded bg-warning-container px-md py-2 text-body-compact text-on-warning-container">
              Les contacts extraits n&apos;apparaîtront plus dans le jeu actif de la fiche (exports nettoyés,
              recherches de doublons). Ils sont préservés dans l&apos;opération d&apos;extraction et restent
              restaurables — aucune donnée n&apos;est supprimée.
            </p>
            <div className="mt-lg flex justify-end gap-md">
              <button
                type="button"
                onClick={() => setConfirmOpen(false)}
                className="rounded border border-outline-variant px-lg py-1.5 text-body-compact text-on-surface hover:bg-surface-low transition-colors"
              >
                Annuler
              </button>
              <button
                type="button"
                onClick={() => void confirmExtraction()}
                className="rounded bg-primary px-lg py-1.5 text-body-compact font-medium text-on-primary hover:bg-primary-container transition-colors"
              >
                Confirmer l&apos;extraction
              </button>
            </div>
          </div>
        </div>
      ) : null}

      {/* Contacts actifs */}
      <section className="rounded-xl bg-surface-lowest shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-md p-md">
          <h2 className="text-headline-sm text-on-surface">Contacts actifs</h2>
          <SearchInput
            value={contactSearch}
            onChange={(v) => {
              setContactSearch(v);
              setContactPage(1);
            }}
            placeholder="Nom, téléphone, email…"
          />
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-left">
            <thead>
              <tr className="border-b border-outline-variant text-label-table-header uppercase tracking-wider text-on-surface-variant">
                {EXPORT_HEADERS.slice(0, 6).map((header) => (
                  <th key={header} className="px-3 py-2.5">
                    {header}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-outline-variant/40 text-body-compact">
              {(contacts.data?.data ?? []).map((c) => (
                <tr key={c.id} className="hover:bg-surface-low transition-colors">
                  <td className="px-3 py-2.5 pl-lg font-medium text-on-surface">{c.nom}</td>
                  <td className="px-3 py-2.5 text-on-surface">{c.prenom}</td>
                  <td className="px-3 py-2.5 max-w-48 truncate text-on-surface-variant">{c.adresse ?? "—"}</td>
                  <td className="px-3 py-2.5 font-mono tabular text-on-surface-variant">{c.cp ?? "—"}</td>
                  <td className="px-3 py-2.5 text-on-surface-variant">{c.ville ?? "—"}</td>
                  <td className="px-3 py-2.5 font-mono tabular text-on-surface">{c.tel ?? "—"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {contacts.data && contacts.data.data.length === 0 ? (
          <EmptyState title="Aucun contact actif" message="Tous les contacts de la fiche ont été extraits." />
        ) : null}
        <div className="px-lg pb-md">
          {contacts.data ? (
            <Pagination
              page={contactPage}
              pageSize={contacts.data.pageSize}
              total={contacts.data.total}
              onPageChange={setContactPage}
            />
          ) : null}
        </div>
      </section>
    </div>
  );
}

function StatMini({ label, value, tone }: { label: string; value: string; tone?: "error" }) {
  return (
    <div className="rounded-lg bg-surface-low px-md py-sm">
      <dt className="text-label-badge text-on-surface-variant">{label}</dt>
      <dd className={`font-mono font-semibold tabular ${tone === "error" ? "text-error" : "text-on-surface"}`}>
        {value}
      </dd>
    </div>
  );
}

function DuplicateGroupCard({
  group,
  selected,
  toggle,
}: {
  group: DuplicateGroup;
  selected: Set<number>;
  toggle: (id: number) => void;
}) {
  const [open, setOpen] = useState(false);

  return (
    <div className="rounded-lg border border-outline-variant/60 bg-surface-low">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className="flex w-full items-center justify-between gap-md px-md py-sm text-left"
        aria-expanded={open}
      >
        <span className="flex items-center gap-md">
          <span className="font-mono text-body-compact font-bold text-on-surface">{group.phone}</span>
          <span className="rounded bg-primary-container px-2 py-0.5 text-label-badge font-semibold text-[#dce4f5]">
            {group.contacts.length} contacts
          </span>
        </span>
        <span className="text-label-badge text-on-surface-variant">
          {open ? "Replier" : "Déplier pour sélectionner"}
        </span>
      </button>

      {open ? (
        <div className="border-t border-outline-variant/40">
          {group.contacts.map((c, idx) => (
            <label
              key={c.id}
              className={`flex cursor-pointer items-start gap-md px-md py-sm text-body-compact ${
                idx > 0 ? "border-t border-outline-variant/30" : ""
              } ${selected.has(c.id) ? "bg-primary-container/40" : ""} hover:bg-surface-low`}
            >
              <input
                type="checkbox"
                className="mt-0.5"
                checked={selected.has(c.id)}
                onChange={() => toggle(c.id)}
              />
              <span className="min-w-0 flex-1">
                <span className="font-medium text-on-surface">
                  {c.nom} {c.prenom}
                </span>
                <span className="ml-2 font-mono text-on-surface-variant">{c.tel}</span>
                <span className="block text-on-surface-variant">
                  {c.email ?? "pas d'email"} · {c.ville ?? "ville inconnue"}
                </span>
              </span>
              <span className="font-mono text-label-badge text-on-surface-variant tabular">#{c.id}</span>
            </label>
          ))}
        </div>
      ) : null}
    </div>
  );
}
