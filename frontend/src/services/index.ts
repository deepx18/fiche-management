import type {
  Contract,
  ContractStatus,
  Session,
  User,
  Agent,
  AgentDetail,
  AgentSummary,
  Cancellation,
  Commission,
  DashboardStats as LegacyDashboardStats,
} from "@/types";
import type {
  AdminAccount,
  ContactRecord,
  ContactSearchRecord,
  DashboardStats,
  DuplicateFilters,
  DuplicateStats,
  DuplicateGroup,
  ExtractionDetail,
  ExtractionSummary,
  FileCounts,
  ImportHistoryEntry,
  ImportedFile,
} from "@/types/contact";
import type {
  ProductionFilterOptions,
  ProductionRecord,
  ProductionSyncSummary,
} from "@/types/production";
import type {
  CallRecord,
  CallSearch,
  CallSearchItem,
  TelephonyDiagnostics,
} from "@/types/telephony";
import * as mock from "@/data/mock";
import {
  ApiError,
  apiDownload,
  apiGet,
  apiGetBlob,
  apiSend,
  apiUpload,
  getToken,
  setToken,
  readRawSession,
  writeRawSession,
} from "./api";

/**
 * Couche d'accès aux données.
 *
 * Module « Fiches Contacts » : appels HTTP réels vers l'API Laravel
 * (authentification par token Sanctum, /api proxifié même origine).
 *
 * Pages de démonstration historiques (contrats, agents, commissions,
 * résiliations) : conservées sur le jeu de démonstration mock — elles ne
 * dépendent pas du module contact.
 */

export interface PageMeta {
  current_page: number;
  last_page: number;
  total: number;
  per_page: number;
}

export function toPaginated<T>(response: { data: T[]; meta: PageMeta }): Paginated<T> {
  return {
    data: response.data,
    total: response.meta.total,
    page: response.meta.current_page,
    pageSize: response.meta.per_page,
  };
}

export interface Paginated<T> {
  data: T[];
  total: number;
  page: number;
  pageSize: number;
}

/* ------------------------------------------------------------------ auth */

export { ApiError, setToken, getToken };

export async function getSession(): Promise<Session | null> {
  const raw = readRawSession();
  if (!raw) return null;
  try {
    return JSON.parse(raw) as Session;
  } catch {
    return null;
  }
}

export function saveSession(session: Session): void {
  writeRawSession(JSON.stringify(session));
}

export function clearSession(): void {
  writeRawSession(null);
  setToken(null);
}

/** Connexion réelle via /api/login (token Sanctum + profil utilisateur). */
export async function login(email: string, password: string): Promise<Session> {
  const result = await apiSend<{ token: string; user: User }>("POST", "/api/login", {
    email: email.trim(),
    password,
  });
  setToken(result.token);
  const session: Session = { user: result.user };
  saveSession(session);
  return session;
}

/** Déconnexion serveur (révocation du token) + nettoyage local. */
export async function logout(): Promise<void> {
  try {
    if (getToken()) await apiSend("POST", "/api/logout");
  } catch {
    // La session locale est purgée quoi qu'il arrive.
  }
  clearSession();
}

/* ------------------------------------------------------- fiches contacts */

export async function getContactStats(): Promise<DashboardStats> {
  const response = await apiGet<{ data: DashboardStats }>("/api/dashboard");
  return response.data;
}

export interface FileFilters {
  search?: string;
  status?: "all" | ImportHistoryStatusAlias | "archived";
  importedBy?: number | "all";
  importedFrom?: string;
  importedTo?: string;
  sortBy?: string;
  sortDirection?: SortDirection;
  page?: number;
  pageSize?: number;
}

type ImportHistoryStatusAlias = "imported" | "partial" | "failed";
export type SortDirection = "asc" | "desc";

export async function listFiles(filters: FileFilters = {}): Promise<Paginated<ImportedFile>> {
  const response = await apiGet<{ data: ImportedFile[]; meta: PageMeta }>("/api/files", {
    search: filters.search || undefined,
    status: filters.status && filters.status !== "all" ? filters.status : undefined,
    imported_by: filters.importedBy && filters.importedBy !== "all" ? filters.importedBy : undefined,
    imported_from: filters.importedFrom || undefined,
    imported_to: filters.importedTo || undefined,
    sort_by: filters.sortBy,
    sort_direction: filters.sortDirection,
    page: filters.page,
    per_page: filters.pageSize,
  });
  return toPaginated(response);
}

export interface DuplicateConflictPayload {
  duplicate_of: { id: number; original_filename: string; imported_at: string };
  history_id: number;
}

/**
 * Import d'une fiche Excel. Le refus de doublon (HTTP 409) est remonté à
 * l'appelant pour demander une confirmation explicite à l'administrateur.
 */
export async function importFile(
  file: File,
  confirmDuplicate: boolean = false,
): Promise<{ file: ImportedFile; historyId: number | null } | "duplicate_conflict"> {
  const formData = new FormData();
  formData.append("file", file);
  if (confirmDuplicate) formData.append("confirm_duplicate", "true");

  try {
    const response = await apiUpload<{
      data: { file: ImportedFile; history_id: number | null };
    }>("/api/files/import", formData);
    return { file: response.data.file, historyId: response.data.history_id };
  } catch (error) {
    if (error instanceof ApiError && error.status === 409) return "duplicate_conflict";
    throw error;
  }
}

export async function getFile(id: number): Promise<(ImportedFile & { counts: FileCounts }) | null> {
  try {
    const response = await apiGet<{ data: ImportedFile & { counts: FileCounts } }>(`/api/files/${id}`);
    return response.data;
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}

export interface FileContactFilters {
  search?: string;
  tel?: string;
  email?: string;
  ville?: string;
  cp?: string;
  sortBy?: string;
  sortDirection?: SortDirection;
  page?: number;
  pageSize?: number;
}

export async function listFileContacts(
  id: number,
  filters: FileContactFilters = {},
): Promise<Paginated<ContactRecord>> {
  const response = await apiGet<{ data: ContactRecord[]; meta: PageMeta }>(`/api/files/${id}/contacts`, {
    search: filters.search || undefined,
    tel: filters.tel || undefined,
    email: filters.email || undefined,
    ville: filters.ville || undefined,
    cp: filters.cp || undefined,
    sort_by: filters.sortBy,
    sort_direction: filters.sortDirection,
    page: filters.page,
    per_page: filters.pageSize,
  });
  return toPaginated(response);
}

export async function getDuplicates(
  id: number,
  filters: DuplicateFilters = {},
): Promise<{ groups: DuplicateGroup[]; stats: DuplicateStats; meta: PageMeta }> {
  const response = await apiGet<{
    data: DuplicateGroup[];
    stats: DuplicateStats;
    meta: PageMeta;
  }>(`/api/files/${fileIdOf(id)}/duplicates`, {
    phone: filters.phone || undefined,
    name: filters.name || undefined,
    min_contacts: filters.minContacts === "" || filters.minContacts === undefined ? undefined : filters.minContacts,
    page: filters.page,
    per_page: filters.pageSize,
  });
  return { groups: response.data, stats: response.stats, meta: response.meta };
}

const fileIdOf = (id: number): number => id;

export interface ExtractionResult {
  extraction: {
    id: number;
    status: string;
    extracted_count: number;
    performed_by: string | null;
    created_at: string;
    contacts: { id: number; nom: string; prenom: string; tel: string | null }[];
  };
  remaining_active_contacts: number;
}

export async function extractContacts(
  fileId: number,
  contactIds: number[],
): Promise<ExtractionResult> {
  return apiSend<ExtractionResult>("POST", `/api/files/${fileId}/duplicates/extract`, {
    contact_ids: contactIds,
  });
}

export interface ExtractionsFilters {
  status?: "all" | "completed" | "restored";
  sourceFileId?: number | "all";
  userId?: number | "all";
  contact?: string;
  extractedFrom?: string;
  extractedTo?: string;
  sortBy?: string;
  sortDirection?: SortDirection;
  page?: number;
  pageSize?: number;
}

export async function listExtractions(filters: ExtractionsFilters = {}): Promise<Paginated<ExtractionSummary>> {
  const response = await apiGet<{ data: ExtractionSummary[]; meta: PageMeta }>("/api/duplicate-extractions", {
    status: filters.status && filters.status !== "all" ? filters.status : undefined,
    source_file_id: filters.sourceFileId && filters.sourceFileId !== "all" ? filters.sourceFileId : undefined,
    user_id: filters.userId && filters.userId !== "all" ? filters.userId : undefined,
    contact: filters.contact || undefined,
    extracted_from: filters.extractedFrom || undefined,
    extracted_to: filters.extractedTo || undefined,
    sort_by: filters.sortBy,
    sort_direction: filters.sortDirection,
    page: filters.page,
    per_page: filters.pageSize,
  });
  return toPaginated(response);
}

export async function getExtraction(id: number): Promise<ExtractionDetail | null> {
  try {
    const response = await apiGet<{ data: ExtractionDetail }>(`/api/duplicate-extractions/${id}`);
    return response.data;
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}

export async function restoreExtraction(
  id: number,
): Promise<{ restoration_id: number; restored_count: number } | string> {
  try {
    const result = await apiSend<{ data: { restoration_id: number; restored_count: number } }>(
      "POST",
      `/api/duplicate-extractions/${id}/restore`,
    );
    return result.data;
  } catch (error) {
    if (error instanceof ApiError && error.status === 422) return extractMessage(error.payload, "Restauration impossible.");
    throw error;
  }
}

export interface HistoriesFilters {
  search?: string;
  status?: "all" | "completed" | "partial" | "failed";
  userId?: number | "all";
  importedFrom?: string;
  importedTo?: string;
  failedMin?: number | "";
  sortBy?: string;
  sortDirection?: SortDirection;
  page?: number;
  pageSize?: number;
}

export async function listImportHistory(filters: HistoriesFilters = {}): Promise<Paginated<ImportHistoryEntry>> {
  const response = await apiGet<{ data: ImportHistoryEntry[]; meta: PageMeta }>("/api/import-history", {
    search: filters.search || undefined,
    status: filters.status && filters.status !== "all" ? filters.status : undefined,
    user_id: filters.userId && filters.userId !== "all" ? filters.userId : undefined,
    imported_from: filters.importedFrom || undefined,
    imported_to: filters.importedTo || undefined,
    failed_min: filters.failedMin === "" || filters.failedMin === undefined ? undefined : filters.failedMin,
    sort_by: filters.sortBy,
    sort_direction: filters.sortDirection,
    page: filters.page,
    per_page: filters.pageSize,
  });
  return toPaginated(response);
}

export async function getImportHistory(id: number): Promise<ImportHistoryEntry | null> {
  try {
    const response = await apiGet<{ data: ImportHistoryEntry }>(`/api/import-history/${id}`);
    return response.data;
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}

function extractMessage(payload: Record<string, unknown> | null, fallback: string): string {
  if (!payload) return fallback;
  if (typeof payload.message === "string") return payload.message;
  return fallback;
}

/* ---------------------------------------------------------- export URLs */

/** Téléchargements autorisés par token via le helper du client HTTP. */

export function exportFileContents(fileId: number): Promise<boolean> {
  return apiDownload(`/api/files/${fileId}/export`, "fiche-contact.xlsx");
}

export function exportFileCleaned(fileId: number, filters: FileContactFilters = {}): Promise<boolean> {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries({
    search: filters.search,
    tel: filters.tel,
    email: filters.email,
    ville: filters.ville,
    cp: filters.cp,
  })) {
    if (value) params.set(key, String(value));
  }
  const query = params.toString();
  return apiDownload(`/api/files/${fileId}/cleaned-export${query ? `?${query}` : ""}`, "fiche-contact-cleaned.xlsx");
}

export function downloadOriginalFile(fileId: number, name: string): Promise<boolean> {
  return apiDownload(`/api/files/${fileId}/download`, name);
}

export function exportExtraction(extractionId: number): Promise<boolean> {
  return apiDownload(`/api/duplicate-extractions/${extractionId}/export`, "contacts-extraits.xlsx");
}

export function exportContactsAll(): Promise<boolean> {
  return apiDownload("/api/contacts/export", "contacts.xlsx");
}

/* ------------------------------------------------- recherche globale */

export interface ContactSearchFilters {
  q?: string;
  fileId?: number | "all";
  status?: "active" | "extracted" | "all";
  ville?: string;
  cp?: string;
  importedFrom?: string;
  importedTo?: string;
  extractedFrom?: string;
  extractedTo?: string;
  sortBy?: string;
  sortDirection?: SortDirection;
  page?: number;
  pageSize?: number;
}

/** Paramètres de recherche communs à la liste et à l'export filtré. */
function contactSearchParams(filters: ContactSearchFilters): Record<string, string | number | undefined> {
  return {
    q: filters.q || undefined,
    file_id: filters.fileId && filters.fileId !== "all" ? filters.fileId : undefined,
    status: filters.status,
    ville: filters.ville || undefined,
    cp: filters.cp || undefined,
    imported_from: filters.importedFrom || undefined,
    imported_to: filters.importedTo || undefined,
    extracted_from: filters.extractedFrom || undefined,
    extracted_to: filters.extractedTo || undefined,
    sort_by: filters.sortBy,
    sort_direction: filters.sortDirection,
    page: filters.page,
    per_page: filters.pageSize,
  };
}

export async function searchContacts(filters: ContactSearchFilters = {}): Promise<Paginated<ContactSearchRecord>> {
  const response = await apiGet<{ data: ContactSearchRecord[]; meta: PageMeta }>(
    "/api/contact-search",
    contactSearchParams(filters),
  );
  return toPaginated(response);
}

/** Export Excel de TOUS les résultats filtrés (toutes pages confondues). */
export function exportContactSearch(filters: ContactSearchFilters = {}): Promise<boolean> {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(contactSearchParams(filters))) {
    if (value !== undefined && key !== "page" && key !== "per_page") params.set(key, String(value));
  }
  const query = params.toString();
  return apiDownload(`/api/contact-search/export${query ? `?${query}` : ""}`, "recherche-contacts.xlsx");
}

/* -------------------------------------------------- administrateurs */

export interface AdminFilters {
  search?: string;
  active?: "all" | "active" | "suspended";
  createdFrom?: string;
  createdTo?: string;
  sortBy?: string;
  sortDirection?: SortDirection;
  page?: number;
  pageSize?: number;
}

export async function listAdmins(filters: AdminFilters = {}): Promise<Paginated<AdminAccount>> {
  const response = await apiGet<{ data: AdminAccount[]; meta: PageMeta }>("/api/admins", {
    search: filters.search || undefined,
    active: filters.active && filters.active !== "all" ? filters.active : undefined,
    created_from: filters.createdFrom || undefined,
    created_to: filters.createdTo || undefined,
    sort_by: filters.sortBy,
    sort_direction: filters.sortDirection,
    page: filters.page,
    per_page: filters.pageSize,
  });
  return toPaginated(response);
}

export async function createAdmin(
  payload: { name: string; email: string; password: string; password_confirmation?: string },
): Promise<AdminAccount> {
  const response = await apiSend<{ data: AdminAccount }>("POST", "/api/admins", {
    ...payload,
    password_confirmation: payload.password_confirmation ?? payload.password,
  });
  return response.data;
}

export async function updateAdmin(
  id: number,
  payload: { name?: string; email?: string; active?: boolean; password?: string },
): Promise<AdminAccount> {
  const response = await apiSend<{ data: AdminAccount }>("PATCH", `/api/admins/${id}`, {
    ...payload,
    ...(payload.password ? { password_confirmation: payload.password } : {}),
  });
  return response.data;
}

export async function deleteAdmin(id: number): Promise<void> {
  await apiSend("DELETE", `/api/admins/${id}`);
}

export async function changeOwnPassword(currentPassword: string, password: string): Promise<void> {
  await apiSend("POST", "/api/user/password", {
    current_password: currentPassword,
    password,
    password_confirmation: password,
  });
}

/* --------------------------------------------------- archive d'une fiche */

export async function archiveFile(id: number): Promise<void> {
  await apiSend("DELETE", `/api/files/${id}`);
}

/* ------------------------------------------------------ production (Phase 1) */

export interface ProductionFilters {
  search?: string;
  cie?: string;
  produit?: string;
  validation?: string;
  typeSignature?: string;
  hasPhone?: "all" | "yes" | "no";
  sortBy?: string;
  sortDirection?: SortDirection;
  page?: number;
  pageSize?: number;
}

/**
 * Lignes de production synchronisées, plus l'état de la dernière
 * synchronisation et les valeurs de filtre réellement disponibles.
 */
export interface ProductionPage extends Paginated<ProductionRecord> {
  lastSync: ProductionSyncSummary | null;
  filters: ProductionFilterOptions;
}

export async function listProduction(filters: ProductionFilters = {}): Promise<ProductionPage> {
  const response = await apiGet<{
    data: ProductionRecord[];
    meta: PageMeta;
    last_sync: ProductionSyncSummary | null;
    filters: ProductionFilterOptions;
  }>("/api/production", {
    search: filters.search || undefined,
    cie: filters.cie || undefined,
    produit: filters.produit || undefined,
    validation: filters.validation || undefined,
    type_signature: filters.typeSignature || undefined,
    has_phone: filters.hasPhone && filters.hasPhone !== "all" ? filters.hasPhone : undefined,
    sort_by: filters.sortBy,
    sort_direction: filters.sortDirection,
    page: filters.page,
    per_page: filters.pageSize,
  });

  return {
    ...toPaginated(response),
    lastSync: response.last_sync,
    filters: response.filters ?? { cies: [], produits: [], validations: [], type_signatures: [] },
  };
}

/** Lance une synchronisation Google Sheets (idempotente) et renvoie son résumé. */
export async function syncProduction(): Promise<ProductionSyncSummary> {
  const response = await apiSend<{ data: { sync: ProductionSyncSummary } }>(
    "POST",
    "/api/production/sync",
  );
  return response.data.sync;
}

/** Historique court des synchronisations (succès et échecs), du plus récent au plus ancien. */
export async function listProductionSyncs(limit = 10): Promise<ProductionSyncSummary[]> {
  const response = await apiGet<{ data: ProductionSyncSummary[] }>("/api/production/syncs", { limit });
  return response.data;
}

/** Ajout / modification du numéro client (champ possédé par l'application). */
export async function updateProductionPhone(id: number, phone: string): Promise<ProductionRecord> {
  const response = await apiSend<{ data: ProductionRecord }>(
    "PATCH",
    `/api/production/${id}/phone`,
    { phone },
  );
  return response.data;
}

/* ------------------------------------------------------ téléphonie (Phase 2) */

export interface CallSearchInput {
  recordIds: number[];
  from?: string;
  to?: string;
  providers?: string[];
  retrieveRecordings?: boolean;
}

/** Lance une recherche asynchrone : la requête ne contacte jamais un fournisseur. */
export async function searchProductionCalls(input: CallSearchInput): Promise<CallSearch> {
  const response = await apiSend<{ data: { search: CallSearch } }>(
    "POST",
    "/api/production/calls/search",
    {
      record_ids: input.recordIds,
      from: input.from || undefined,
      to: input.to || undefined,
      providers: input.providers && input.providers.length > 0 ? input.providers : undefined,
      retrieve_recordings: Boolean(input.retrieveRecordings),
    },
  );
  return response.data.search;
}

/** Statut par fournisseur et par ligne, sans payload fournisseur brut. */
export async function getCallSearch(
  id: number,
): Promise<{ search: CallSearch; items: CallSearchItem[] }> {
  const response = await apiGet<{
    data: { search: CallSearch; items: CallSearchItem[] };
  }>(`/api/production/calls/searches/${id}`);
  return response.data;
}

/** Appels stockés pour les lignes d'une recherche. */
export async function listCallSearchCalls(
  id: number,
  page = 1,
  pageSize = 100,
): Promise<Paginated<CallRecord>> {
  const response = await apiGet<{ data: CallRecord[]; meta: PageMeta }>(
    `/api/production/calls/searches/${id}/calls`,
    { page, per_page: pageSize },
  );
  return toPaginated(response);
}

/** Appels déjà associés à une sélection de lignes (sans relancer de job). */
export async function listRecordCalls(
  recordIds: number[],
  page = 1,
  pageSize = 100,
): Promise<Paginated<CallRecord>> {
  const params = new URLSearchParams();
  for (const id of recordIds) params.append("record_ids[]", String(id));
  params.set("page", String(page));
  params.set("per_page", String(pageSize));

  const response = await apiGet<{ data: CallRecord[]; meta: PageMeta }>(
    `/api/production/calls/matches?${params.toString()}`,
  );

  return toPaginated(response);
}

/** Récupère un enregistrement Issabel vers le stockage privé (asynchrone). */
export async function retrieveCallRecording(callId: number): Promise<string> {
  const response = await apiSend<{ data: { state: string } }>(
    "POST",
    `/api/calls/${callId}/recording/retrieve`,
  );
  return response.data.state;
}

/**
 * Audio authentifié pour le lecteur intégré (blob local, jamais une URL
 * publique). La route API vérifie les droits à chaque requête.
 */
export function fetchRecordingBlob(callId: number): Promise<Blob> {
  return apiGetBlob(`/api/calls/${callId}/recording/stream`);
}

/** Diagnostic de connexion en lecture seule (Kavkom : configuration, Issabel : CDR). */
export async function getTelephonyDiagnostics(provider: "issabel" | "kavkom"): Promise<TelephonyDiagnostics> {
  const response = await apiGet<{ data: TelephonyDiagnostics }>(
    `/api/telephony/diagnostics/${provider}`,
  );
  return response.data;
}

/* -------------------------------------------------------------- démos héritées (mock) */

export interface ContractFilters {
  search?: string;
  status?: "all" | ContractStatus;
  agentId?: number;
  page?: number;
  pageSize?: number;
}

const delay = (ms = 120) => new Promise((r) => setTimeout(r, ms));

export async function listUsers(): Promise<User[]> {
  await delay();
  return mock.users;
}

export async function listContracts(filters: ContractFilters = {}): Promise<Paginated<Contract>> {
  await delay();
  const { search = "", status = "all", agentId, page = 1, pageSize = 10 } = filters;
  const q = search.trim().toLowerCase();
  let rows = mock.contracts;
  if (status !== "all") rows = rows.filter((c) => c.status === status);
  if (agentId) rows = rows.filter((c) => c.agentId === agentId);
  if (q) {
    rows = rows.filter(
      (c) =>
        c.reference.toLowerCase().includes(q) ||
        c.client.toLowerCase().includes(q) ||
        mock.agents.find((a) => a.id === c.agentId)?.name.toLowerCase().includes(q),
    );
  }
  const total = rows.length;
  const start = (page - 1) * pageSize;
  return { data: rows.slice(start, start + pageSize), total, page, pageSize };
}

export async function listAgents(): Promise<Agent[]> {
  await delay();
  return mock.agents;
}

function summarize(a: Agent): AgentSummary {
  const rows = mock.contracts.filter((c) => c.agentId === a.id);
  return {
    ...a,
    contracts: rows.length,
    production: rows.filter((c) => c.status === "active").reduce((s, c) => s + c.premium, 0),
    commissions: rows.reduce((s, c) => s + c.commission, 0),
  };
}

export async function listAgentSummaries(): Promise<AgentSummary[]> {
  await delay();
  return mock.agents.map(summarize).sort((a, b) => b.production - a.production);
}

export async function getAgentDetail(id: number): Promise<AgentDetail | null> {
  await delay();
  const agent = mock.agents.find((a) => a.id === id);
  if (!agent) return null;
  const contracts = mock.contracts.filter((c) => c.agentId === id);
  const commissions = mock.commissions.filter((c) => c.agentId === id);
  const cancellations = mock.cancellations.filter((c) =>
    contracts.some((ct) => ct.id === c.contractId),
  );
  return {
    agent,
    contracts,
    commissions,
    cancellations,
    stats: {
      contracts: contracts.length,
      production: contracts.filter((c) => c.status === "active").reduce((s, c) => s + c.premium, 0),
      commissions: commissions.reduce((s, c) => s + c.commission, 0),
      cancellations: cancellations.length,
    },
  };
}

export async function listCommissions(
  filters: { search?: string; status?: string; page?: number; pageSize?: number } = {},
): Promise<Paginated<Commission>> {
  await delay();
  const { search = "", status = "all", page = 1, pageSize = 10 } = filters;
  const q = search.trim().toLowerCase();
  let rows = mock.commissions;
  if (status !== "all") rows = rows.filter((c) => c.status === status);
  if (q) {
    rows = rows.filter((c) => {
      const contract = mock.contracts.find((ct) => ct.id === c.contractId);
      const agent = mock.agents.find((a) => a.id === c.agentId);
      return (
        (contract?.reference.toLowerCase().includes(q) ?? false) ||
        (contract?.client.toLowerCase().includes(q) ?? false) ||
        (agent?.name.toLowerCase() ?? "").includes(q)
      );
    });
  }
  const total = rows.length;
  const start = (page - 1) * pageSize;
  return { data: rows.slice(start, start + pageSize), total, page, pageSize };
}

export async function listCancellations(
  filters: { reason?: string } = {},
): Promise<(Cancellation & { contractRef: string; client: string; agentName: string })[]> {
  await delay();
  let rows = mock.cancellations.map((c) => {
    const contract = mock.contracts.find((ct) => ct.id === c.contractId);
    return {
      ...c,
      contractRef: contract?.reference ?? "—",
      client: contract?.client ?? "—",
      agentName: mock.agents.find((a) => a.id === contract?.agentId)?.name ?? "—",
    };
  });
  if (filters.reason && filters.reason !== "all") {
    rows = rows.filter((c) => c.reason === filters.reason);
  }
  return rows;
}

export async function getDashboardStats(): Promise<LegacyDashboardStats> {
  await delay();
  const active = mock.contracts.filter((c) => c.status === "active");
  const cancelled = mock.contracts.filter((c) => c.status === "cancelled");

  const months: { month: string; production: number; premiums: number }[] = [];
  for (let m = 0; m < 12; m++) {
    const start = new Date(Date.UTC(2025, 9 + m, 1));
    const end = new Date(Date.UTC(2025, 10 + m, 1));
    const label = start.toLocaleDateString("fr-FR", { month: "short", timeZone: "UTC" });
    const inMonth = mock.contracts.filter((c) => {
      const d = new Date(c.createdAt);
      return d >= start && d < end;
    });
    months.push({
      month: label,
      production: inMonth.filter((c) => c.status === "active").length,
      premiums: inMonth.reduce((s, c) => s + c.premium, 0),
    });
  }

  const byProduct = new Map<string, number>();
  for (const c of active) byProduct.set(c.product, (byProduct.get(c.product) ?? 0) + c.premium);
  const totalProd = [...byProduct.values()].reduce((s, v) => s + v, 0);
  const productionByProduct = [...byProduct.entries()]
    .map(([product, premium]) => ({ product: product as LegacyDashboardStats["productionByProduct"][number]["product"], share: Math.round((premium / totalProd) * 100) }))
    .sort((a, b) => b.share - a.share);

  const recentCancellations = await listCancellations();

  return {
    productionTotal: active.reduce((s, c) => s + c.premium, 0),
    premiumsTotal: mock.contracts.reduce((s, c) => s + c.premium, 0),
    contractsTotal: mock.contracts.length,
    contractsActive: active.length,
    contractsCancelled: cancelled.length,
    commissionsTotal: mock.commissions.reduce((s, c) => s + c.commission, 0),
    productionByMonth: months,
    productionByProduct,
    topAgents: (await listAgentSummaries()).slice(0, 5),
    recentContracts: [...mock.contracts]
      .sort((a, b) => b.createdAt.localeCompare(a.createdAt))
      .slice(0, 5),
    recentCancellations: recentCancellations.slice(0, 3),
  };
}
