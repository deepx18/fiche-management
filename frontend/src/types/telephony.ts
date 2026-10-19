/** Types de la Phase 2 — recherche d'appels et enregistrements privés. */

export type CallSearchStatus = "queued" | "running" | "completed" | "partial" | "failed";

export type CallSearchItemStatus =
  | "queued"
  | "running"
  | "completed"
  | "no_results"
  | "failed"
  | "unsupported"
  | "ambiguous"
  | "skipped_no_phone"
  | "invalid_phone";

export interface CallSearchRecordSummary {
  id: number;
  contract_reference: string | null;
  client_name: string | null;
}

export interface CallSearchItem {
  id: number;
  provider: "kavkom" | "issabel" | string;
  status: CallSearchItemStatus;
  result_count: number;
  error_code: string | null;
  error_message: string | null;
  record: CallSearchRecordSummary | null;
  completed_at: string | null;
}

export interface CallSearch {
  id: number;
  status: CallSearchStatus;
  from_date: string | null;
  to_date: string | null;
  providers: string[];
  retrieve_recordings: boolean;
  requested_count: number;
  completed_count: number;
  failed_count: number;
  skipped_count: number;
  error_summary: string | null;
  started_at: string | null;
  completed_at: string | null;
  created_at: string | null;
  items?: CallSearchItem[];
}

export type RecordingState =
  | "unavailable"
  | "reference_only"
  | "pending"
  | "stored"
  | "missing"
  | "failed"
  | "unsupported"
  | "expired";

export interface CallRecordingInfo {
  state: RecordingState;
  available: boolean;
  mime: string | null;
  size: number | null;
  retrieved_at: string | null;
}

export interface CallRecord {
  id: number;
  provider: "kavkom" | "issabel" | string;
  record_id: number | null;
  contract_reference: string | null;
  client_name: string | null;
  phone: string | null;
  direction: string | null;
  status: string | null;
  started_at: string | null;
  duration_seconds: number | null;
  billable_seconds: number | null;
  agent: string | null;
  match_basis: string | null;
  recording: CallRecordingInfo;
}

export const CALL_SEARCH_BADGE: Record<
  CallSearchStatus,
  { label: string; className: string }
> = {
  queued: { label: "En attente", className: "bg-surface-high text-on-surface-variant" },
  running: { label: "Recherche…", className: "bg-warning-container text-on-warning-container" },
  completed: { label: "Terminée", className: "bg-success-container text-on-success-container" },
  partial: { label: "Partielle", className: "bg-warning-container text-on-warning-container" },
  failed: { label: "Échec", className: "bg-error-container text-on-error-container" },
};

export const CALL_SEARCH_ITEM_BADGE: Record<
  CallSearchItemStatus,
  { label: string; className: string }
> = {
  queued: { label: "En attente", className: "bg-surface-high text-on-surface-variant" },
  running: { label: "Recherche…", className: "bg-warning-container text-on-warning-container" },
  completed: { label: "Appels trouvés", className: "bg-success-container text-on-success-container" },
  no_results: { label: "Aucun appel", className: "bg-secondary-container text-on-secondary-container" },
  failed: { label: "Erreur", className: "bg-error-container text-on-error-container" },
  unsupported: { label: "Non pris en charge", className: "bg-surface-high text-on-surface-variant" },
  ambiguous: { label: "Correspondance ambiguë", className: "bg-warning-container text-on-warning-container" },
  skipped_no_phone: { label: "Sans numéro", className: "bg-surface-high text-on-surface-variant" },
  invalid_phone: { label: "Numéro non français", className: "bg-warning-container text-on-warning-container" },
};

export const RECORDING_LABEL: Record<RecordingState, string> = {
  unavailable: "Aucun enregistrement signalé",
  reference_only: "Référence signalée — non récupérée",
  pending: "Récupération en cours…",
  stored: "Enregistrement privé disponible",
  missing: "Fichier absent",
  failed: "Récupération en échec",
  unsupported: "Récupération non prise en charge",
  expired: "Enregistrement expiré",
};

export function formatDuration(seconds: number | null): string {
  if (seconds === null || Number.isNaN(seconds)) return "—";
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return `${m}:${String(s).padStart(2, "0")}`;
}
