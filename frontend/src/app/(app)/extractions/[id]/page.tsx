"use client";

import { use, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { PageSkeleton } from "@/components/PageSkeleton";
import { ErrorState } from "@/components/ErrorState";
import { useData } from "@/hooks/useData";
import * as api from "@/services";
import { ApiError } from "@/services/api";
import { formatDateTime, formatNumber } from "@/lib/format";

interface DetailRow {
  label: string;
  value: React.ReactNode;
}

function Row({ label, value }: DetailRow) {
  return (
    <div className="flex items-start justify-between gap-lg py-2.5">
      <dt className="text-label-table-header uppercase tracking-wider text-on-surface-variant">{label}</dt>
      <dd className="text-right text-body-default text-on-surface">{value}</dd>
    </div>
  );
}

export default function ExtractionDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const router = useRouter();
  const extractionId = Number(id);

  const detail = useData(() => api.getExtraction(extractionId), [extractionId]);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ tone: "success" | "error"; text: string } | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);

  if (detail.loading) return <PageSkeleton rows={7} />;
  if (detail.error) return <ErrorState message={detail.error} onRetry={detail.retry} />;
  if (!detail.data) {
    router.replace("/extractions");
    return null;
  }

  const extraction = detail.data;

  async function restore() {
    setBusy(true);
    setConfirmOpen(false);
    try {
      const result = await api.restoreExtraction(extractionId);
      if (typeof result === "string") {
        setMessage({ tone: "error", text: result });
      } else {
        setMessage({
          tone: "success",
          text: `Restauration réussie : ${result.restored_count} contact(s) de nouveau actifs dans la fiche source.`,
        });
        await detail.retry();
      }
    } catch (error) {
      setMessage({ tone: "error", text: error instanceof ApiError ? error.message : "Restauration impossible." });
    } finally {
      setBusy(false);
    }
  }

  async function download() {
    setBusy(true);
    const ok = await api.exportExtraction(extractionId);
    if (!ok) setMessage({ tone: "error", text: "Téléchargement impossible." });
    setBusy(false);
  }

  return (
    <div className="space-y-lg">
      <nav className="flex items-center gap-xs text-body-compact text-on-surface-variant" aria-label="Fil d'ariane">
        <Link href="/extractions" className="hover:text-on-surface hover:underline">
          Extractions
        </Link>
        <span>/</span>
        <span className="font-mono font-semibold text-on-surface">#{extraction.id}</span>
      </nav>

      <div className="flex flex-wrap items-start justify-between gap-md">
        <div>
          <span className="text-label-table-header uppercase tracking-wider text-on-surface-variant">
            Opération d&apos;extraction
          </span>
          <h1 className="font-mono text-headline-lg tracking-tight text-on-surface">#{extraction.id}</h1>
          <p className="text-body-compact text-on-surface-variant">
            Fiche source :{" "}
            <Link href={`/fiches/${extraction.source_file_id}`} className="text-primary hover:underline">
              {extraction.source_file_name ?? `#${extraction.source_file_id}`}
            </Link>
          </p>
        </div>
        <div className="flex gap-md">
          <button
            type="button"
            onClick={() => void download()}
            disabled={busy}
            className="rounded bg-primary px-lg py-1.5 text-body-compact font-medium text-on-primary hover:bg-primary-container transition-colors disabled:opacity-50"
          >
            Exporter les contacts extraits (XLSX)
          </button>
          {extraction.can_restore ? (
            <button
              type="button"
              onClick={() => setConfirmOpen(true)}
              disabled={busy}
              className="rounded border border-outline-variant px-lg py-1.5 text-body-compact text-on-surface hover:bg-surface-low transition-colors disabled:opacity-50"
            >
              Restaurer dans la fiche…
            </button>
          ) : (
            <span className="self-center rounded bg-surface-container px-md py-1 text-label-badge text-on-surface-variant">
              opération restaurée — plus restaurable
            </span>
          )}
        </div>
      </div>

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

      <div className="grid grid-cols-1 gap-lg lg:grid-cols-2">
        <section className="rounded-xl bg-surface-lowest p-lg shadow-sm">
          <h2 className="mb-sm text-headline-sm text-on-surface">Information de l&apos;opération</h2>
          <dl className="divide-y divide-outline-variant/40">
            <Row label="Statut" value={extraction.status === "completed" ? "Complétée" : "Restaurée"} />
            <Row label="Contacts extraits" value={<span className="font-mono tabular">{formatNumber(extraction.extracted_count)}</span>} />
            <Row label="Administrateur" value={extraction.performed_by ?? "—"} />
            <Row label="Date d'extraction" value={formatDateTime(extraction.created_at)} />
            <Row label="Groupes téléphoniques" value={extraction.comment ?? "—"} />
          </dl>
        </section>

        <section className="rounded-xl bg-surface-lowest p-lg shadow-sm">
          <h2 className="mb-sm text-headline-sm text-on-surface">Restaurations</h2>
          {extraction.restorations.length === 0 ? (
            <p className="text-body-compact text-on-surface-variant">
              Aucune restauration. Les contacts extraits sont toujours hors du jeu actif de la fiche.
            </p>
          ) : (
            <ul className="divide-y divide-outline-variant/40">
              {extraction.restorations.map((r) => (
                <li key={r.id} className="py-2.5 text-body-compact">
                  <span className="font-medium text-on-surface">{formatNumber(r.restored_count)} contact(s)</span>
                  <span className="block text-on-surface-variant">
                    par {r.performed_by ?? "—"} · {formatDateTime(r.restored_at ?? "")}
                  </span>
                </li>
              ))}
            </ul>
          )}
          <p className="mt-sm text-label-badge text-on-surface-variant">
            La restauration ne recrée aucun contact : les enregistrements d&apos;origine sont réactivés et
            reparaissent dans les exports nettoyés.
          </p>
        </section>
      </div>

      <section className="rounded-xl bg-surface-lowest p-lg shadow-sm">
        <h2 className="mb-sm text-headline-sm text-on-surface">Contacts extraits ({extraction.extracted_contacts.length})</h2>
        <div className="overflow-x-auto">
          <table className="w-full text-left">
            <thead>
              <tr className="border-b border-outline-variant text-label-table-header uppercase tracking-wider text-on-surface-variant">
                {["NOM", "PRENOM", "ADRESSE", "CP", "VILLE", "TEL", "Email"].map((h) => (
                  <th key={h} className="px-3 py-2.5">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-outline-variant/40 text-body-compact">
              {extraction.extracted_contacts.map((c) => (
                <tr key={c.id}>
                  <td className="px-3 py-2.5 font-medium text-on-surface">{c.nom}</td>
                  <td className="px-3 py-2.5 text-on-surface">{c.prenom}</td>
                  <td className="px-3 py-2.5 max-w-48 truncate text-on-surface-variant">{c.adresse ?? "—"}</td>
                  <td className="px-3 py-2.5 font-mono tabular text-on-surface-variant">{c.cp ?? "—"}</td>
                  <td className="px-3 py-2.5 text-on-surface-variant">{c.ville ?? "—"}</td>
                  <td className="px-3 py-2.5 font-mono tabular text-on-surface">{c.tel ?? "—"}</td>
                  <td className="px-3 py-2.5 text-on-surface-variant">{c.email ?? "—"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      {confirmOpen ? (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-md"
          role="dialog"
          aria-modal="true"
          aria-label="Confirmation de restauration"
        >
          <div className="w-full max-w-xl rounded-xl bg-surface-lowest p-lg shadow-lg">
            <h3 className="text-headline-sm text-on-surface">Confirmer la restauration</h3>
            <p className="mt-md text-body-compact text-on-surface">
              Les {formatNumber(extraction.extracted_contacts.length)} contact(s) extraits redeviendront
              actifs dans la fiche source «{" "}
              <span className="font-medium">{extraction.source_file_name}</span> ».
            </p>
            <p className="mt-md rounded bg-warning-container px-md py-2 text-body-compact text-on-warning-container">
              Aucun contact n&apos;est recréé et aucune donnée — y compris l&apos;historique de
              l&apos;opération — n&apos;est effacé. L&apos;opération sera marquée « restaurée ».
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
                onClick={() => void restore()}
                className="rounded bg-primary px-lg py-1.5 text-body-compact font-medium text-on-primary hover:bg-primary-container transition-colors"
              >
                Confirmer la restauration
              </button>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  );
}
