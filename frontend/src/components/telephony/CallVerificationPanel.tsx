"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import * as api from "@/services";
import { ApiError, apiDownload } from "@/services/api";
import { formatDateTime, formatNumber } from "@/lib/format";
import type { ProductionRecord } from "@/types/production";
import {
  CALL_SEARCH_BADGE,
  CALL_SEARCH_ITEM_BADGE,
  DIAGNOSTIC_BADGE,
  RECORDING_LABEL,
  formatDuration,
  type CallRecord,
  type CallSearch,
  type CallSearchItem,
  type TelephonyDiagnostics,
} from "@/types/telephony";

const PROVIDER_LABEL: Record<string, string> = {
  kavkom: "Kavkom",
  issabel: "Issabel",
};

const RETRIEVABLE_STATES = ["reference_only", "failed", "missing"] as const;

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

function formatBytes(size: number | null): string | null {
  if (size === null || !Number.isFinite(size) || size <= 0) return null;
  if (size < 1024) return `${size} o`;
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} Ko`;
  return `${(size / (1024 * 1024)).toFixed(1)} Mo`;
}

function recordingExtension(call: CallRecord): string {
  if (call.recording.mime?.includes("wav")) return "wav";
  if (call.recording.mime?.includes("ogg")) return "ogg";
  if (call.recording.mime?.includes("m4a")) return "m4a";
  return "mp3";
}

/**
 * Phase 2 — vérification des appels pour les lignes sélectionnées.
 *
 * Deux sources distinctes sont affichées :
 *  - les appels déjà associés aux lignes (lecture seule, sans job) ;
 *  - le résultat de la recherche lancée depuis ce panneau.
 *
 * La récupération des enregistrements est déclenchée automatiquement par la
 * recherche (case cochée par défaut). L'état `pending` est posé côté serveur
 * avant le dispatch : l'interface reflète l'attente, puis le résultat.
 * Aucun enregistrement n'est exposé publiquement : lecture et téléchargement
 * passent par l'API authentifiée.
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
  const [retrieveRecordings, setRetrieveRecordings] = useState(true);
  const [search, setSearch] = useState<CallSearch | null>(null);
  const [items, setItems] = useState<CallSearchItem[]>([]);
  const [calls, setCalls] = useState<CallRecord[]>([]);
  const [callsTotal, setCallsTotal] = useState(0);
  const [callsPage, setCallsPage] = useState(1);
  const [callsLastPage, setCallsLastPage] = useState(1);
  const [callsLoaded, setCallsLoaded] = useState(false);
  const [busy, setBusy] = useState(false);
  const [callsLoading, setCallsLoading] = useState(true);
  const [recordingBusy, setRecordingBusy] = useState<number | null>(null);
  const [audioUrl, setAudioUrl] = useState<string | null>(null);
  const [audioCall, setAudioCall] = useState<CallRecord | null>(null);
  const [diagnostics, setDiagnostics] = useState<TelephonyDiagnostics | null>(null);
  const [diagnosticsBusy, setDiagnosticsBusy] = useState(false);
  const [diagnosticsError, setDiagnosticsError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [pollError, setPollError] = useState<string | null>(null);
  const [callsError, setCallsError] = useState<string | null>(null);

  const recordIds = useMemo(() => records.map((record) => record.id), [records]);

  const applyMatches = useCallback(
    (result: Awaited<ReturnType<typeof api.listRecordCalls>>, append: boolean) => {
      setCalls((current) => (append ? [...current, ...result.data] : result.data));
      setCallsTotal(result.total);
      setCallsPage(result.page);
      setCallsLastPage(Math.max(1, Math.ceil(result.total / Math.max(1, result.pageSize))));
      setCallsLoaded(true);
      setCallsError(null);
    },
    [],
  );

  /** Charge les appels déjà associés à la sélection (aucun job déclenché). */
  const loadMatches = useCallback(
    async (page = 1, append = false) => {
      if (recordIds.length === 0) return;

      setCallsLoading(true);
      setCallsError(null);
      try {
        applyMatches(await api.listRecordCalls(recordIds, page, 100), append);
      } catch (e) {
        setCallsError(errorMessage(e));
        setCallsLoaded(true);
      } finally {
        setCallsLoading(false);
      }
    },
    [recordIds, applyMatches],
  );

  // Chargement initial : les mises à jour d'état se font après la promesse,
  // jamais synchroniquement dans le corps de l'effet.
  useEffect(() => {
    let cancelled = false;

    void (async () => {
      try {
        const result = await api.listRecordCalls(recordIds, 1, 100);
        if (cancelled) return;
        applyMatches(result, false);
      } catch (e) {
        if (cancelled) return;
        setCallsError(errorMessage(e));
        setCallsLoaded(true);
      } finally {
        if (!cancelled) setCallsLoading(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [recordIds, applyMatches]);

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
          await loadMatches(1, false);
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
  }, [search, loadMatches]);

  const hasPendingRecording = calls.some((call) => call.recording.state === "pending");

  useEffect(() => {
    if (!hasPendingRecording) return;

    const interval = window.setInterval(() => void loadMatches(1, false), 3000);
    return () => window.clearInterval(interval);
  }, [hasPendingRecording, loadMatches]);

  useEffect(() => {
    return () => {
      if (audioUrl) URL.revokeObjectURL(audioUrl);
    };
  }, [audioUrl]);

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

    try {
      const result = await api.searchProductionCalls({
        recordIds,
        from,
        to,
        retrieveRecordings,
      });
      setSearch(result);
      setItems(result.items ?? []);
      await loadMatches(1, false);
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
      const ok = await apiDownload(
        `/api/calls/${call.id}/recording`,
        `enregistrement-${call.id}.${recordingExtension(call)}`,
      );
      if (!ok) setError("Téléchargement de l'enregistrement impossible ou non autorisé.");
    } finally {
      setRecordingBusy(null);
    }
  }

  async function play(call: CallRecord) {
    setError(null);
    setRecordingBusy(call.id);
    try {
      const blob = await api.fetchRecordingBlob(call.id);
      if (audioUrl) URL.revokeObjectURL(audioUrl);
      setAudioUrl(URL.createObjectURL(blob));
      setAudioCall(call);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setRecordingBusy(null);
    }
  }

  function closePlayer() {
    if (audioUrl) URL.revokeObjectURL(audioUrl);
    setAudioUrl(null);
    setAudioCall(null);
  }

  async function retrieve(call: CallRecord) {
    setError(null);
    setRecordingBusy(call.id);
    try {
      await api.retrieveCallRecording(call.id);
      await loadMatches(1, false);
      // Avec une file asynchrone, le job peut se terminer juste après la
      // réponse : un second rafraîchissement borné affiche l'état final.
      window.setTimeout(() => void loadMatches(1, false), 1500);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setRecordingBusy(null);
    }
  }

  async function runDiagnostics() {
    setDiagnosticsBusy(true);
    setDiagnosticsError(null);
    try {
      setDiagnostics(await api.getTelephonyDiagnostics("issabel"));
    } catch (e) {
      setDiagnosticsError(errorMessage(e));
    } finally {
      setDiagnosticsBusy(false);
    }
  }

  const terminal = search !== null && isTerminal(search.status);
  const allNoResults =
    terminal && items.length > 0 && items.every((item) => item.status === "no_results");
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
          Récupérer automatiquement les enregistrements disponibles
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

      <details className="mt-md rounded-lg bg-surface-low px-md py-2">
        <summary className="cursor-pointer text-body-compact text-on-surface">
          Diagnostic de connexion Issabel (lecture seule)
        </summary>
        <div className="mt-sm space-y-sm">
          <button
            type="button"
            onClick={() => void runDiagnostics()}
            disabled={diagnosticsBusy}
            className="rounded border border-outline-variant px-md py-1.5 text-body-compact text-on-surface hover:bg-surface-low transition-colors disabled:opacity-50"
          >
            {diagnosticsBusy ? "Test en cours…" : "Tester la connexion CDR et les enregistrements"}
          </button>

          {diagnosticsError ? (
            <p role="alert" className="rounded bg-error-container px-md py-2 text-body-compact text-on-error-container">
              {diagnosticsError}
            </p>
          ) : null}

          {diagnostics ? (
            <div role="status" className="space-y-1">
              <div className="flex items-center gap-sm">
                <span
                  className={`inline-flex items-center rounded px-2 py-0.5 text-label-badge font-medium ${
                    DIAGNOSTIC_BADGE[diagnostics.status].className
                  }`}
                >
                  {DIAGNOSTIC_BADGE[diagnostics.status].label}
                </span>
                <span className="text-label-badge text-on-surface-variant">
                  {formatDateTime(diagnostics.checked_at)} · {diagnostics.duration_ms} ms
                </span>
              </div>
              <ul className="space-y-1">
                {diagnostics.checks.map((check) => (
                  <li key={check.key} className="rounded bg-surface-lowest px-md py-1.5">
                    <div className="flex items-center gap-sm">
                      <span
                        className={`inline-flex items-center rounded px-1.5 py-0.5 text-label-badge font-medium ${
                          DIAGNOSTIC_BADGE[check.status].className
                        }`}
                      >
                        {DIAGNOSTIC_BADGE[check.status].label}
                      </span>
                      <span className="text-body-compact text-on-surface">{check.label}</span>
                    </div>
                    <p className="mt-0.5 text-label-badge text-on-surface-variant">{check.message}</p>
                  </li>
                ))}
              </ul>
            </div>
          ) : null}
        </div>
      </details>

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

          {hasProblems && calls.length === 0 ? (
            <p className="rounded bg-warning-container px-md py-2 text-body-compact text-on-warning-container">
              Aucun appel n&apos;est affiché, mais au moins une recherche n&apos;a pas abouti. Aucune
              conclusion « aucun appel » n&apos;est tirée d&apos;un échec ou d&apos;une capacité non
              prise en charge.
            </p>
          ) : null}
        </div>
      ) : null}

      <div className="mt-lg space-y-md">
        <div className="flex flex-wrap items-center justify-between gap-sm">
          <div>
            <h3 className="text-headline-sm text-on-surface">Appels trouvés</h3>
            <p className="text-body-compact text-on-surface-variant">
              {callsLoaded
                ? `${formatNumber(callsTotal)} appel${callsTotal > 1 ? "s" : ""} associé${
                    callsTotal > 1 ? "s" : ""
                  } à ces lignes.`
                : "Chargement des appels déjà associés…"}
            </p>
          </div>
          <button
            type="button"
            onClick={() => void loadMatches(1, false)}
            disabled={callsLoading}
            className="rounded border border-outline-variant px-md py-1.5 text-body-compact text-on-surface hover:bg-surface-low transition-colors disabled:opacity-50"
          >
            {callsLoading ? "Actualisation…" : "Actualiser"}
          </button>
        </div>

        {audioUrl && audioCall ? (
          <div className="rounded-lg bg-surface-low p-md">
            <div className="flex flex-wrap items-center justify-between gap-sm">
              <div className="text-body-compact text-on-surface">
                Enregistrement de l&apos;appel du{" "}
                {audioCall.started_at ? formatDateTime(audioCall.started_at) : "—"}
                {audioCall.provider_call_id ? (
                  <span className="ml-1 font-mono text-label-badge text-on-surface-variant">
                    {audioCall.provider_call_id}
                  </span>
                ) : null}
              </div>
              <button
                type="button"
                onClick={closePlayer}
                className="rounded border border-outline-variant px-md py-1 text-label-badge text-on-surface hover:bg-surface-lowest transition-colors"
              >
                Fermer le lecteur
              </button>
            </div>
            <audio controls autoPlay src={audioUrl} className="mt-sm w-full" />
          </div>
        ) : null}

        {callsError ? (
          <p role="alert" className="rounded bg-error-container px-md py-2 text-body-compact text-on-error-container">
            {callsError}
          </p>
        ) : null}

        {callsLoading && !callsLoaded ? (
          <p className="text-body-compact text-on-surface-variant">Chargement des appels…</p>
        ) : calls.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="w-full text-left">
              <thead>
                <tr className="border-b border-outline-variant text-label-table-header uppercase tracking-wider text-on-surface-variant">
                  <th className="py-2 pl-md pr-3">Appel</th>
                  <th className="px-3 py-2">Début</th>
                  <th className="px-3 py-2">Durée</th>
                  <th className="px-3 py-2">Numéro</th>
                  <th className="px-3 py-2">Sens / statut</th>
                  <th className="px-3 py-2">Agent</th>
                  <th className="px-3 py-2">Enregistrement</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-outline-variant/40 text-body-compact">
                {calls.map((call) => {
                  const retrievable =
                    call.provider === "issabel" &&
                    RETRIEVABLE_STATES.includes(
                      call.recording.state as (typeof RETRIEVABLE_STATES)[number],
                    );
                  const size = formatBytes(call.recording.size);

                  return (
                    <tr key={call.id}>
                      <td className="py-2 pl-md pr-3 text-on-surface">
                        {PROVIDER_LABEL[call.provider] ?? call.provider}
                        <div className="font-mono text-label-badge text-on-surface-variant">
                          {call.provider_call_id ?? "—"}
                        </div>
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
                      <td className="px-3 py-2 font-mono tabular text-on-surface-variant">
                        <div>{call.phone ?? "—"}</div>
                        {call.raw_destination ? (
                          <div className="text-label-badge text-on-surface-variant">
                            → {call.raw_destination}
                          </div>
                        ) : null}
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

                          {call.recording.error ? (
                            <span
                              className="text-label-badge text-on-error-container"
                              title={call.recording.error}
                            >
                              {call.recording.error}
                            </span>
                          ) : null}

                          {call.recording.available ? (
                            <div className="flex flex-wrap items-center gap-1">
                              <button
                                type="button"
                                onClick={() => void play(call)}
                                disabled={recordingBusy === call.id}
                                className="w-fit rounded bg-surface-container px-2 py-1 text-label-badge font-medium text-on-surface hover:bg-surface-highest transition-colors disabled:opacity-50"
                              >
                                {recordingBusy === call.id ? "Préparation…" : "Écouter"}
                              </button>
                              <button
                                type="button"
                                onClick={() => void download(call)}
                                disabled={recordingBusy === call.id}
                                className="w-fit rounded border border-outline-variant px-2 py-1 text-label-badge text-on-surface hover:bg-surface-low transition-colors disabled:opacity-50"
                              >
                                Télécharger
                              </button>
                            </div>
                          ) : retrievable ? (
                            <button
                              type="button"
                              onClick={() => void retrieve(call)}
                              disabled={recordingBusy === call.id}
                              className="w-fit rounded border border-outline-variant px-2 py-1 text-label-badge text-on-surface hover:bg-surface-low transition-colors disabled:opacity-50"
                            >
                              {recordingBusy === call.id ? "Récupération…" : "Récupérer"}
                            </button>
                          ) : null}

                          {size ? (
                            <span className="text-label-badge text-on-surface-variant">{size}</span>
                          ) : null}
                          {call.recording.retrieved_at ? (
                            <span className="text-label-badge text-on-surface-variant">
                              Récupéré le {formatDateTime(call.recording.retrieved_at)}
                            </span>
                          ) : null}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>

            {callsPage < callsLastPage ? (
              <div className="mt-sm flex justify-center">
                <button
                  type="button"
                  onClick={() => void loadMatches(callsPage + 1, true)}
                  disabled={callsLoading}
                  className="rounded border border-outline-variant px-md py-1.5 text-body-compact text-on-surface hover:bg-surface-low transition-colors disabled:opacity-50"
                >
                  {callsLoading ? "Chargement…" : "Charger plus d'appels"}
                </button>
              </div>
            ) : null}
          </div>
        ) : callsLoaded ? (
          <p className="rounded bg-surface-low px-md py-2 text-body-compact text-on-surface-variant">
            {allNoResults
              ? "Les fournisseurs interrogés ont répondu sans aucun appel sur la période."
              : "Aucun appel n'est encore associé à ces lignes. Lancez une recherche pour interroger Issabel."}
          </p>
        ) : null}
      </div>
    </section>
  );
}
