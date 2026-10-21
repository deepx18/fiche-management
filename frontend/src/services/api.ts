"use client";

/**
 * Client HTTP centralisé vers l'API Laravel.
 * - Token Sanctum conservé dans sessionStorage (frontends séparés du backend).
 * - Les erreurs API remontent sous forme d'ApiError avec statut et payload.
 * - Les téléchargements passent par fetch + blob (Authorization header).
 */

const TOKEN_KEY = "assurcore.token";
const SESSION_KEY = "assurcore.session";

export class ApiError extends Error {
  status: number;
  payload: Record<string, unknown> | null;

  constructor(status: number, message: string, payload: Record<string, unknown> | null = null) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.payload = payload;
  }
}

export function getToken(): string | null {
  if (typeof window === "undefined") return null;
  return window.sessionStorage.getItem(TOKEN_KEY);
}

export function setToken(token: string | null): void {
  if (typeof window === "undefined") return;
  if (token === null) window.sessionStorage.removeItem(TOKEN_KEY);
  else window.sessionStorage.setItem(TOKEN_KEY, token);
}

export function readRawSession(): string | null {
  if (typeof window === "undefined") return null;
  return window.sessionStorage.getItem(SESSION_KEY);
}

export function writeRawSession(raw: string | null): void {
  if (typeof window === "undefined") return;
  if (raw === null) window.sessionStorage.removeItem(SESSION_KEY);
  else window.sessionStorage.setItem(SESSION_KEY, raw);
}

function headers(json = true): HeadersInit {
  const base: Record<string, string> = { Accept: "application/json" };
  const token = getToken();
  if (token) base.Authorization = `Bearer ${token}`;
  if (json) base["Content-Type"] = "application/json";
  return base;
}

/** Extrait un message d'erreur utile (validation Laravel incluse). */
function extractMessage(payload: Record<string, unknown> | null, fallback: string): string {
  if (!payload) return fallback;
  if (typeof payload.message === "string") return payload.message;
  if (payload.errors && typeof payload.errors === "object") {
    const first = Object.values(payload.errors as Record<string, unknown[]>).flat()[0];
    if (typeof first === "string") return first;
  }
  return fallback;
}

async function handle<T>(response: Response): Promise<T> {
  if (response.ok) {
    // 204 ou corps vide.
    const text = await response.text();
    return (text ? JSON.parse(text) : null) as T;
  }

  let payload: Record<string, unknown> | null = null;
  try {
    const text = await response.text();
    payload = text ? (JSON.parse(text) as Record<string, unknown>) : null;
  } catch {
    payload = null;
  }
  throw new ApiError(response.status, extractMessage(payload, `Erreur ${response.status}`), payload);
}

export async function apiGet<T>(path: string, params?: Record<string, string | number | undefined>): Promise<T> {
  const url = new URL(path, window.location.origin);
  if (params) {
    for (const [key, value] of Object.entries(params)) {
      if (value !== undefined) url.searchParams.set(key, String(value));
    }
  }
  return fetch(url.pathname + url.search, { headers: headers() })
    .then((r) => handle<T>(r));
}

export async function apiSend<T>(
  method: "POST" | "PATCH" | "DELETE" | "PUT",
  path: string,
  body?: unknown,
): Promise<T> {
  return fetch(path, {
    method,
    headers: headers(),
    body: body === undefined ? undefined : JSON.stringify(body),
  }).then((r) => handle<T>(r));
}

export async function apiUpload<T>(path: string, formData: FormData): Promise<T> {
  return fetch(path, { method: "POST", headers: headers(false), body: formData })
    .then((r) => handle<T>(r));
}

/**
 * Récupère un binaire authentifié (lecture audio) sans déclencher de
 * téléchargement. Les erreurs API conservent leur message serveur.
 */
export async function apiGetBlob(path: string): Promise<Blob> {
  const response = await fetch(path, { headers: headers() });

  if (!response.ok) {
    let payload: Record<string, unknown> | null = null;
    try {
      const text = await response.text();
      payload = text ? (JSON.parse(text) as Record<string, unknown>) : null;
    } catch {
      payload = null;
    }

    throw new ApiError(response.status, extractMessage(payload, `Erreur ${response.status}`), payload);
  }

  return response.blob();
}

/** Télécharge un fichier (Authorization header) et déclenche l'enregistrement. */
export async function apiDownload(path: string, fallbackName: string): Promise<boolean> {
  try {
    const response = await fetch(path, { headers: headers() });
    if (!response.ok) throw new ApiError(response.status, `Erreur ${response.status}`);
    const blob = await response.blob();

    const disposition = response.headers.get("Content-Disposition") ?? "";
    const match = /filename\*?=(?:UTF-8''|")?([^";]+)/i.exec(disposition);
    const name = match ? decodeURIComponent(match[1].replace(/"/g, "")) : fallbackName;

    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
    return true;
  } catch {
    return false;
  }
}
