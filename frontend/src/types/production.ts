/**
 * Types du module « Production » (Phase 1) — correspondance directe avec les
 * réponses JSON de l'API Laravel (`/api/production`).
 *
 * Les champs issus de la feuille Google (contrat, client, ville, produit,
 * statut) sont mis à jour à chaque synchronisation ; le téléphone est saisi
 * dans l'application et n'est jamais écrasé par la synchronisation.
 */

export type ProductionSyncStatus = "completed" | "partial" | "failed";

export interface ProductionRecord {
  id: number;
  contract_reference: string | null;
  client_name: string | null;
  phone: string | null;
  city: string | null;
  product: string | null;
  status: string | null;
  source_row: number | null;
  last_synced_at: string | null;
  updated_at: string | null;
}

export interface ProductionRowError {
  row: number;
  field: string;
  reason: string;
}

export interface ProductionSyncSummary {
  id: number;
  status: ProductionSyncStatus;
  rows_read: number;
  created_count: number;
  updated_count: number;
  skipped_count: number;
  failed_count: number;
  error_summary: string | null;
  row_errors: ProductionRowError[] | null;
  performed_by: string | null;
  started_at: string | null;
  completed_at: string | null;
  created_at: string | null;
}

/** Valeurs réellement présentes en base, proposées comme filtres. */
export interface ProductionFilterOptions {
  cities: string[];
  products: string[];
  statuses: string[];
}

export const PRODUCTION_SYNC_BADGE: Record<
  ProductionSyncStatus,
  { label: string; className: string }
> = {
  completed: { label: "Réussie", className: "bg-success-container text-on-success-container" },
  partial: { label: "Partielle", className: "bg-warning-container text-on-warning-container" },
  failed: { label: "Échec", className: "bg-error-container text-on-error-container" },
};
