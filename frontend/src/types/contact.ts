/**
 * Types du module « Fiches Contacts » — correspondance avec les réponses
 * JSON de l'API Laravel (champs data + meta).
 */

export type ImportStatus = "imported" | "partial" | "failed";
export type HistoryStatus = "completed" | "partial" | "failed";
export type ExtractionStatus = "completed" | "restored";

export interface ImportedFile {
  id: number;
  original_filename: string;
  file_size: number | null;
  file_hash: string;
  status: ImportStatus;
  archived: boolean;
  imported_by: { id: number | null; name: string | null };
  imported_at: string;
  total_rows: number;
  successful_rows: number;
  failed_rows: number;
  skipped_rows: number;
  original_available: boolean;
}

export interface ContactRecord {
  id: number;
  imported_file_id: number;
  nom: string;
  prenom: string;
  adresse: string | null;
  cp: string | null;
  ville: string | null;
  tel: string | null;
  email: string | null;
  date_naissance: string | null;
  source_file?: { id: number; original_filename: string | null } | null;
}

/** Statut d'un contact dans la recherche globale. */
export type ContactStatus = "active" | "extracted";

/** Résultat de la recherche globale (GET /api/contact-search). */
export interface ContactSearchRecord extends ContactRecord {
  status: ContactStatus;
  extracted_at: string | null;
  source_file: { id: number; original_filename: string | null } | null;
}

export interface DuplicateContact {
  id: number;
  nom: string;
  prenom: string;
  tel: string | null;
  telephone_normalise: string | null;
  email: string | null;
  ville: string | null;
}

export interface DuplicateGroup {
  phone: string;
  contacts: DuplicateContact[];
}

export interface DuplicateFilters {
  phone?: string;
  name?: string;
  minContacts?: number | "";
  page?: number;
  pageSize?: number;
}

export interface DuplicateStats {
  total_active: number;
  duplicate_groups: number;
  contacts_in_groups: number;
  without_usable_tel: number;
  not_in_any_group: number;
  extracted_contacts: number;
  filtered_groups: number;
}

export interface ExtractionSummary {
  id: number;
  source_file_id: number;
  source_file_name: string | null;
  status: ExtractionStatus;
  extracted_count: number;
  comment: string | null;
  performed_by: string | null;
  created_at: string;
}

export interface ExtractionDetail extends ExtractionSummary {
  source_file: { id: number; original_filename: string | null; archived: boolean } | null;
  performed_by: string;
  extracted_contacts: (Omit<ContactRecord, "imported_file_id" | "source_file">)[];
  restorations: { id: number; restored_count: number; performed_by: string | null; restored_at: string | null }[];
  can_restore: boolean;
}

export interface ImportErrorRow {
  row: number;
  field: string;
  reason: string;
}

export interface ImportHistoryEntry {
  id: number;
  imported_file_id: number | null;
  imported_file_name: string | null;
  original_filename: string;
  user_name: string | null;
  status: HistoryStatus;
  total_rows: number;
  successful_rows: number;
  failed_rows: number;
  skipped_rows: number;
  error_summary: string | null;
  row_errors: ImportErrorRow[] | null;
  started_at: string | null;
  completed_at: string | null;
  created_at: string;
}

export interface FileCounts {
  contacts_total: number;
  contacts_extracted: number;
  last_import: ImportHistoryEntry | null;
}

export interface DashboardStats {
  files_total: number;
  files_archived: number;
  contacts_total: number;
  contacts_active: number;
  contacts_extracted: number;
  import_attempts: number;
  imports_successful: number;
  imports_failed: number;
  files_with_duplicates: number;
  extractions_completed: number;
  extractions_restored: number;
  recent_imports: {
    id: number;
    original_filename: string;
    status: HistoryStatus;
    successful_rows: number;
    total_rows: number;
    performed_by: string | null;
    created_at: string;
  }[];
  recent_extractions: {
    id: number;
    source_file: string | null;
    status: ExtractionStatus;
    extracted_count: number;
    performed_by: string | null;
    created_at: string;
  }[];
}

export interface AdminAccount {
  id: number;
  name: string;
  email: string;
  role: string;
  active: boolean;
}
