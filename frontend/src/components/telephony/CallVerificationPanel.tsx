"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import * as api from "@/services";
import { ApiError } from "@/services/api";
import { apiDownload } from "@/services/api";
import { formatDateTime, formatNumber } from "@/lib/format";
import type { ProductionRecord } from "@/types/production";
import {
  CALL_SEARCH_BADGE,
  CALL_SEARCH_ITEM_BADGE,
  RECORDING_LABEL,
  formatDuration,
  type CallRecord,
  type CallSearch,
  type CallSearchItem,
} from "@/types/telephony";

const PROVIDER_LABEL: Record<string, string> = {
  kavkom: "Kavkom",
  issabel: "Issabel",
};

function isoDate(date: Date): string {
  return date.toISOString().slice(0, 10);
}

function defaultFrom(): string {
  return isoDate(new Date(Date.now() - 30 * 24 * 60 * 60 * 1000));
}

function isTerminal(status: CallSearch["status"]): boolean {
  return status === "completed" || status === "partial" || status === "failed";
}

function errorMessage(error: unknown): string {
  if (error instanceof ApiError) return error.message;
  if (error instanceof Error) return error.message;
  return "Opération impossible.";
}

/**
 * Phase 2 — vérification des appels pour les lignes sélectionnées.
 *
 * La recherche est asynchrone : le panneau interroge le statut par
 * fournisseur. Un échec, une capacité non prise en charge et un vrai « aucun
 * appel » restent trois états distincts. Aucun enregistrement n'est exposé
 * publiquement : le téléchargement passe par l'API authentifiée.
 */
export function CallVerificationPanel({
  records,
  onClose,
}: {
  records: ProductionRecord[];
  onClose: () => void;
}) {
  const [from, setFrom] = useState(defaultFrom());
  const [to, setTo] = useState(() => isoDate(new Date()));
  const [retrieveRecordings, setRetrieveRecordings] = useState(false);
  const [search, setSearch] = useState<CallSearch | null>(null);
  const [items, setItems] = useState<CallSearchItem[]>([]);
  const [calls, setCalls] = useState<CallRecord[]>([]);
  const [busy, setBusy] = useState(false);
  const [callsLoading, setCallsLoading] = useState(false);
  const [recordingBusy, setRecordingBusy] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [pollError, setPollError] = useState<string | null>(null);

  const recordIds = useMemo(() => records.map((record) => record.id), [records]);

  const refreshCalls = useCallback(async (searchId: number) => {
    setCallsLoading(true);
    try {
      const page = await api.listCallSearchCalls(searchId, 1, 200);
      setCalls(page.data);
    } catch (e) {
      setPollError(errorMessage(e));
    } finally {
      setCallsLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!search || isTerminal(search.status)) return;

    let cancelled = false;
    const tick = async () => {
      try {
        const result = await api.getCallSearch(search.id);
        if (cancelled) return;
        setSearch(result.search);
        setItems(result.items);
        setPollError(null);
        if (isTerminal(result.search.status)) {
          await refreshCalls(result.search.id);
        }
      } catch (e) {
        if (!cancelled) setPollError(errorMessage(e));
      }
    };

    const interval = window.setInterval(() => void tick(), 2500);
    return () => {
      cancelled = true;
      window.clearInterval(interval);
    };
  }, [search, refreshCalls]);

  async function start() {
    if (recordIds.length === 0) {
      setError("Sélectionnez au moins une ligne de production.");
      return;
    }

    if (recordIds.length > 50) {
      setError("La recherche est limitée à 50 lignes par lot. Réduisez la sélection.");
      return;
    }

    setBusy(true);
    setError(null);
    setPollError(null);
    setCalls([]);

    try {
      const result = await api.searchProductionCalls({
        recordIds,
        from,
        to,
        retrieveRecordings,
      });
      setSearch(result);
      setItems(result.items ?? []);
      if (isTerminal(result.status)) await refreshCalls(result.id);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  async function download(call: CallRecord) {
    setError(null);
    setRecordingBusy(call.id);
    try {
      const extension = call.recording.mime?.includes("wav")
        ? "wav"
        : call.recording.mime?.includes("ogg")
          ? "ogg"
          : "mp3";
      const ok = await apiDownload(
        `/api/calls/${call.id}/recording`,
        `enregistrement-${call.id}.${extension}`,
      );
      if (!ok) setError("Téléchargement de l'enregistrement impossible ou non autorisé.");
    } finally {
      setRecordingBusy(null);
    }
  }

  async function retrieve(call: CallRecord) {
    if (!search) return;
    setError(null);
    setRecordingBusy(call.id);
    try {
      await api.retrieveCallRecording(call.id);
      window.setTimeout(() => void refreshCalls(search.id), 1200);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setRecordingBusy(null);
    }
  }

  const terminal = search !== null && isTerminal(search.status);
  const allNoResults =
    terminal &&
    items.length > 0 &&
    items.every((item) => item.status === "no_results");
  const hasProblems =
    terminal &&
    items.some((item) =>
      ["failed", "unsupported", "ambiguous", "invalid_phone", "skipped_no_phone"].includes(
        item.status,
      ),
    );

  return (
    <section className="rounded-xl bg-surface-lowest p-lg shadow-sm">
      <div className="flex flex-wrap items-start justify-between gap-md">
        <div>
          <h2 className="text-headline-md text-on-surface">Vérification des appels</h2>
          <p className="text-body-compact text-on-surface-variant">
            {formatNumber(records.length)} ligne{records.length > 1 ? "s" : ""} sélectionnée
            {records.length > 1 ? "s" : ""} sur cette page. La recherche est traitée en arrière-plan.
          </p>
        </div>
        <button
          type="button"
          onClick={onClose}
          className="rounded border border-outline-variant px-md py-1.5 text-body-compact text-on-surface hover:bg-surface-low transition-colors"
        >
          Fermer
        </button>
      </div>

      <div className="mt-md flex flex-wrap items-end gap-md">
        <label className="flex flex-col gap-1">
          <span className="text-label-badge text-on-surface-variant">Du</span>
          <input
            type="date"
            value={from}
            max={to}
            onChange={(event) => setFrom(event.target.value)}
            className="rounded border border-outline-variant bg-surface-lowest px-md py-1.5 text-body-compact text-on-surface focus:outline-none focus:border-primary"
          />
        </label>
        <label className="flex flex-col gap-1">
          <span className="text-label-badge text-on-surface-variant">Au</span>
          <input
            type="date"
            value={to}
            min={from}
            onChange={(event) => setTo(event.target.value)}
            className="rounded border border-outline-variant bg-surface-lowest px-md py-1.5 text-body-compact text-on-surface focus:outline-none focus:border-primary"
          />
        </label>
        <label className="flex items-center gap-sm pb-1.5 text-body-compact text-on-surface">
          <input
            type="checkbox"
            checked={retrieveRecordings}
            onChange={(event) => setRetrieveRecordings(event.target.checked)}
          />
          Récupérer les enregistrements disponibles
        </label>
        <button
          type="button"
          onClick={() => void start()}
          disabled={busy}
          className="rounded bg-primary px-lg py-2 text-body-compact font-medium text-on-primary hover:bg-primary-container transition-colors disabled:opacity-50"
        >
          {busy ? "Lancement…" : "Lancer la recherche"}
        </button>
      </div>

      {error ? (
        <p role="alert" className="mt-md rounded bg-error-container px-md py-2 text-body-compact text-on-error-container">
          {error}
        </p>
      ) : null}

      {search ? (
        <div className="mt-lg space-y-md">
          <div className="flex flex-wrap items-center justify-between gap-sm rounded-lg bg-surface-low px-md py-2">
            <div className="flex items-center gap-sm">
              <span
                className={`inline-flex items-center rounded px-2 py-0.5 text-label-badge font-medium ${
                  CALL_SEARCH_BADGE[search.status].className
                }`}
              >
                {CALL_SEARCH_BADGE[search.status].label}
              </span>
              <span className="text-body-compact text-on-surface-variant">
                {search.error_summary ?? "Recherche en cours."}
              </span>
            </div>
            <span className="text-label-badge text-on-surface-variant tabular">
              {formatNumber(search.completed_count)} terminée(s) ·{" "}
              {formatNumber(search.failed_count)} échec(s) ·{" "}
              {formatNumber(search.skipped_count)} non exécutée(s)
            </span>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-left">
              <thead>
                <tr className="border-b border-outline-variant text-label-table-header uppercase tracking-wider text-on-surface-variant">
                  <th className="py-2 pl-md pr-3">Ligne</th>
                  <th className="px-3 py-2">Fournisseur</th>
                  <th className="px-3 py-2">État</th>
                  <th className="px-3 py-2">Appels</th>
                  <th className="px-3 py-2">Détail</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-outline-variant/40 text-body-compact">
                {items.map((item) => (
                  <tr key={item.id}>
                    <td className="py-2 pl-md pr-3">
                      <div className="font-mono text-on-surface">
                        {item.record?.contract_reference ?? "—"}
                      </div>
                      <div className="text-label-badge text-on-surface-variant">
                        {item.record?.client_name ?? "—"}
                      </div>
                    </td>
                    <td className="px-3 py-2 text-on-surface">
                      {PROVIDER_LABEL[item.provider] ?? item.provider}
                    </td>
                    <td className="px-3 py-2">
                      <span
                        className={`inline-flex items-center rounded px-2 py-0.5 text-label-badge font-medium ${
                          CALL_SEARCH_ITEM_BADGE[item.status].className
                        }`}
                      >
                        {CALL_SEARCH_ITEM_BADGE[item.status].label}
                      </span>
                    </td>
                    <td className="px-3 py-2 font-mono tabular text-on-surface">
                      {item.status === "no_results" ? "0" : formatNumber(item.result_count)}
                    </td>
                    <td className="px-3 py-2 text-label-badge text-on-surface-variant">
                      {item.error_message ?? (item.completed_at ? formatDateTime(item.completed_at) : "—")}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {pollError ? (
            <p role="status" className="rounded bg-warning-container px-md py-2 text-body-compact text-on-warning-container">
              {pollError}
            </p>
          ) : null}

          {callsLoading ? (
            <p className="text-body-compact text-on-surface-variant">Chargement des appels…</p>
          ) : calls.length > 0 ? (
            <div className="overflow-x-auto">
              <table className="w-full text-left">
                <thead>
                  <tr className="border-b border-outline-variant text-label-table-header uppercase tracking-wider text-on-surface-variant">
                    <th className="py-2 pl-md pr-3">Fournisseur</th>
                    <th className="px-3 py-2">Début</th>
                    <th className="px-3 py-2">Durée</th>
                    <th className="px-3 py-2">Sens / statut</th>
                    <th className="px-3 py-2">Agent</th>
                    <th className="px-3 py-2">Enregistrement</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-outline-variant/40 text-body-compact">
                  {calls.map((call) => (
                    <tr key={call.id}>
                      <td className="py-2 pl-md pr-3 text-on-surface">
                        {PROVIDER_LABEL[call.provider] ?? call.provider}
                        <div className="text-label-badge text-on-surface-variant">
                          {call.contract_reference ?? "—"}
                        </div>
                      </td>
                      <td className="px-3 py-2 tabular text-on-surface-variant">
                        {call.started_at ? formatDateTime(call.started_at) : "—"}
                      </td>
                      <td className="px-3 py-2 font-mono tabular text-on-surface">
                        {formatDuration(call.duration_seconds)}
                      </td>
                      <td className="px-3 py-2 text-on-surface-variant">
                        {call.direction ?? "—"}
                        {call.status ? ` · ${call.status}` : ""}
                      </td>
                      <td className="px-3 py-2 text-on-surface-variant">{call.agent ?? "—"}</td>
                      <td className="px-3 py-2">
                        <div className="flex flex-col gap-1">
                          <span className="text-label-badge text-on-surface-variant">
                            {RECORDING_LABEL[call.recording.state]}
                          </span>
                          {call.recording.available ? (
                            <button
                              type="button"
                              onClick={() => void download(call)}
                              disabled={recordingBusy === call.id}
                              className="w-fit rounded bg-surface-container px-2 py-1 text-label-badge font-medium text-on-surface hover:bg-surface-highest transition-colors disabled:opacity-50"
                            >
                              {recordingBusy === call.id ? "Préparation…" : "Télécharger"}
                            </button>
                          ) : call.provider === "issabel" &&
                            (call.recording.state === "reference_only" ||
                              call.recording.state === "failed") ? (
                            <button
                              type="button"
                              onClick={() => void retrieve(call)}
                              disabled={recordingBusy === call.id}
                              className="w-fit rounded border border-outline-variant px-2 py-1 text-label-badge text-on-surface hover:bg-surface-low transition-colors disabled:opacity-50"
                            >
                              {recordingBusy === call.id ? "Récupération…" : "Récupérer"}
                            </button>
                          ) : null}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : allNoResults ? (
            <p className="rounded bg-surface-low px-md py-2 text-body-compact text-on-surface-variant">
              Les fournisseurs interrogés ont répondu sans aucun appel sur la période.
            </p>
          ) : hasProblems ? (
            <p className="rounded bg-warning-container px-md py-2 text-body-compact text-on-warning-container">
              Aucun appel n&apos;est affiché, mais au moins une recherche n&apos;a pas abouti. Aucune
              conclusion « aucun appel » n&apos;est tirée d&apos;un échec ou d&apos;une capacité non
              prise en charge.
            </p>
          ) : null}
        </div>
      ) : null}
    </section>
  );
}
