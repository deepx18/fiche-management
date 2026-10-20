/**
 * Types du module « Production » — correspondance directe avec les réponses
 * JSON de l'API Laravel (`/api/production`).
 *
 * Les 11 champs issus de la feuille Google (DATE, CIAL, NOM, PRENOM,
 * COTISATION, PRIME ANNUELLE, CIE, PRODUIT, Date d'effet, VALIDATION,
 * TYPE DE SIGNATURE) sont mis à jour à chaque synchronisation.
 *
 * `reference_contrat` est générée par l'application (jamais par la feuille).
 * Le téléphone est saisi dans l'application et n'est jamais écrasé par la
 * synchronisation.
 */

export type ProductionSyncStatus = "completed" | "partial" | "failed";

export interface ProductionRecord {
  id: number;
  reference_contrat: string | null;
  /** Date ISO `aaaa-mm-jj` (colonne DATE de la feuille). */
  date: string | null;
  cial: string | null;
  nom: string | null;
  prenom: string | null;
  /** Montant décimal sous forme de chaîne, tel que validé (sans arrondi). */
  cotisation: string | null;
  prime_annuelle: string | null;
  cie: string | null;
  produit: string | null;
  /** Date ISO `aaaa-mm-jj` (colonne Date d'effet). */
  date_effet: string | null;
  validation: string | null;
  type_signature: string | null;
  phone: string | null;
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
  /** Colonnes inconnues, lecture tronquée, etc. (jamais un succès masqué). */
  warnings: string[] | null;
  row_errors: ProductionRowError[] | null;
  performed_by: string | null;
  started_at: string | null;
  completed_at: string | null;
  created_at: string | null;
}

/** Valeurs réellement présentes en base, proposées comme filtres. */
export interface ProductionFilterOptions {
  cies: string[];
  produits: string[];
  validations: string[];
  type_signatures: string[];
}

export const PRODUCTION_SYNC_BADGE: Record<
  ProductionSyncStatus,
  { label: string; className: string }
> = {
  completed: { label: "Réussie", className: "bg-success-container text-on-success-container" },
  partial: { label: "Partielle", className: "bg-warning-container text-on-warning-container" },
  failed: { label: "Échec", className: "bg-error-container text-on-error-container" },
};
