"use client";

import { useState } from "react";
import { PageSkeleton } from "@/components/PageSkeleton";
import { useAuth } from "@/hooks/useAuth";
import { RequireRole } from "@/components/RequireRole";
import { ErrorState } from "@/components/ErrorState";
import { EmptyState } from "@/components/EmptyState";
import { useData } from "@/hooks/useData";
import * as api from "@/services";
import { ApiError } from "@/services/api";
import type { AdminAccount } from "@/types/contact";

export default function UsersPage() {
  return (
    <RequireRole roles={["admin"]}>
      <UsersContent />
    </RequireRole>
  );
}

function UsersContent() {
  const { session } = useAuth();
  const admins = useData(() => api.listAdmins({ pageSize: 50 }), []);
  const [message, setMessage] = useState<{ tone: "success" | "error"; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [createOpen, setCreateOpen] = useState(false);
  const [passwordTarget, setPasswordTarget] = useState<AdminAccount | null>(null);

  async function run(action: () => Promise<void>, success: string) {
    setBusy(true);
    setMessage(null);
    try {
      await action();
      setMessage({ tone: "success", text: success });
      await admins.retry();
    } catch (error) {
      setMessage({
        tone: "error",
        text: error instanceof ApiError ? error.message : "Opération impossible.",
      });
    } finally {
      setBusy(false);
      setCreateOpen(false);
      setPasswordTarget(null);
    }
  }

  if (admins.loading && !admins.data) return <PageSkeleton rows={6} />;
  if (admins.error) return <ErrorState message={admins.error} onRetry={admins.retry} />;

  const rows = admins.data?.data ?? [];

  return (
    <div className="space-y-lg">
      <div className="flex flex-wrap items-start justify-between gap-md">
        <div>
          <span className="text-label-table-header uppercase tracking-wider text-on-surface-variant">
            Administration
          </span>
          <h1 className="text-headline-lg tracking-tight text-on-surface">Administrateurs</h1>
          <p className="text-body-compact text-on-surface-variant">
            {rows.length} compte(s). Le dernier administrateur actif ne peut jamais être désactivé ni
            supprimé ; votre propre compte ne peut pas être supprimé depuis cette vue.
          </p>
        </div>
        <button
          type="button"
          onClick={() => setCreateOpen(true)}
          disabled={busy}
          className="rounded bg-primary px-lg py-2 text-body-compact font-medium text-on-primary hover:bg-primary-container transition-colors disabled:opacity-50"
        >
          Nouvel administrateur…
        </button>
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

      {rows.length === 0 ? (
        <div className="rounded-xl bg-surface-lowest shadow-sm">
          <EmptyState title="Aucun administrateur" message="Créez un premier compte administrateur." />
        </div>
      ) : (
        <section className="overflow-hidden rounded-xl bg-surface-lowest shadow-sm">
          <table className="w-full text-left">
            <thead>
              <tr className="border-b border-outline-variant text-label-table-header uppercase tracking-wider text-on-surface-variant">
                <th className="py-2.5 pl-lg pr-3">Nom</th>
                <th className="px-3 py-2.5">Email</th>
                <th className="px-3 py-2.5">Rôle</th>
                <th className="px-3 py-2.5">Statut</th>
                <th className="py-2.5 pl-3 pr-lg text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-outline-variant/40 text-body-compact">
              {rows.map((u) => {
                const isSelf = session?.user.id === u.id;
                const activeAdmins = rows.filter((x) => x.active).length;
                const isLastActive = u.active && activeAdmins === 1;
                return (
                  <tr key={u.id} className="hover:bg-surface-low transition-colors">
                    <td className="py-2.5 pl-lg pr-3 font-medium text-on-surface">
                      {u.name}
                      {isSelf ? (
                        <span className="ml-2 rounded bg-surface-container px-1.5 py-0.5 text-label-badge text-on-surface-variant">
                          vous
                        </span>
                      ) : null}
                    </td>
                    <td className="px-3 py-2.5 font-mono text-on-surface-variant">{u.email}</td>
                    <td className="px-3 py-2.5">
                      <span className="inline-flex rounded px-2 py-0.5 text-label-badge font-medium bg-primary-container text-[#dce4f5]">
                        Administrateur
                      </span>
                    </td>
                    <td className="px-3 py-2.5">
                      <span
                        className={`inline-flex items-center px-2 py-0.5 rounded text-label-badge font-medium ${
                          u.active
                            ? "bg-success-container text-on-success-container"
                            : "bg-surface-container text-on-surface-variant"
                        }`}
                      >
                        {u.active ? "Actif" : "Suspendu"}
                      </span>
                    </td>
                    <td className="py-2.5 pl-3 pr-lg text-right">
                      <span className="inline-flex gap-sm">
                        <button
                          type="button"
                          disabled={busy || isLastActive || isSelf}
                          onClick={() =>
                            void run(
                              async () => {
                                await api.updateAdmin(u.id, { active: !u.active });
                              },
                              u.active
                                ? `Compte ${u.email} suspendu.`
                                : `Compte ${u.email} réactivé.`,
                            )
                          }
                          className="rounded bg-surface-container px-2 py-1 text-label-badge font-medium text-on-surface hover:bg-surface-highest disabled:opacity-40 disabled:cursor-not-allowed"
                          title={
                            isLastActive
                              ? "Le dernier administrateur actif ne peut être désactivé."
                              : isSelf
                                ? "Suspendez votre compte via un autre administrateur."
                                : u.active
                                  ? "Suspendre ce compte (révoque ses sessions)."
                                  : "Réactiver ce compte."
                          }
                        >
                          {u.active ? "Suspendre" : "Réactiver"}
                        </button>
                        <button
                          type="button"
                          disabled={busy || isSelf}
                          onClick={() => setPasswordTarget(u)}
                          className="rounded bg-surface-container px-2 py-1 text-label-badge font-medium text-on-surface hover:bg-surface-highest disabled:opacity-40 disabled:cursor-not-allowed"
                          title="Définir un nouveau mot de passe."
                        >
                          Mot de passe
                        </button>
                        <button
                          type="button"
                          disabled={busy || isLastActive || isSelf}
                          onClick={() => void run(() => api.deleteAdmin(u.id), `Compte ${u.email} supprimé.`)}
                          className="rounded bg-error-container px-2 py-1 text-label-badge font-medium text-on-error-container hover:opacity-80 disabled:opacity-40 disabled:cursor-not-allowed"
                          title={
                            isLastActive
                              ? "Le dernier administrateur actif ne peut être supprimé."
                              : isSelf
                                ? "Vous ne pouvez pas supprimer votre propre compte."
                                : "Supprimer définitivement ce compte."
                          }
                        >
                          Supprimer
                        </button>
                      </span>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </section>
      )}

      {/* Création */}
      {createOpen ? (
        <AdminFormModal
          title="Nouvel administrateur"
          busy={busy}
          onClose={() => setCreateOpen(false)}
          onSubmit={(payload) =>
            run(async () => {
              await api.createAdmin({
                name: payload.name,
                email: payload.email,
                password: payload.password,
                password_confirmation: payload.password_confirmation,
              });
            }, `Administrateur ${payload.email} créé.`)
          }
        />
      ) : null}

      {/* Nouveau mot de passe */}
      {passwordTarget ? (
        <AdminFormModal
          title={`Nouveau mot de passe — ${passwordTarget.name}`}
          busy={busy}
          passwordOnly
          onClose={() => setPasswordTarget(null)}
          onSubmit={(payload) =>
            run(async () => {
              await api.updateAdmin(passwordTarget.id, { password: payload.password });
            }, `Mot de passe mis à jour pour ${passwordTarget.email}.`)
          }
        />
      ) : null}

      <p className="text-label-badge text-on-surface-variant">
        Astuce : comptez les administrateurs actifs avant toute suspension ou suppression — l&apos;API
        refusera de retirer le dernier administrateur actif.
      </p>
    </div>
  );
}

function AdminFormModal({
  title,
  busy,
  passwordOnly = false,
  onClose,
  onSubmit,
}: {
  title: string;
  busy: boolean;
  passwordOnly?: boolean;
  onClose: () => void;
  onSubmit: (payload: { name: string; email: string; password: string; password_confirmation: string }) => void;
}) {
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");

  const valid = passwordOnly
    ? password.length >= 8 && password === confirmation
    : name.trim().length > 0 && /.+@.+\..+/.test(email) && password.length >= 8 && password === confirmation;

  return (      
      <div className="fixed h-full inset-0 z-50 flex items-center justify-center bg-black/40 px-md" role="dialog" aria-modal="true">
      <div className="w-full max-w-3xl rounded-xl bg-surface-lowest p-lg shadow-lg">
        <h3 className="text-headline-sm text-on-surface">{title}</h3>
        <form
          className="mt-md space-y-md"
          onSubmit={(e) => {
            e.preventDefault();
            if (!valid || busy) return;
            onSubmit({ name: name.trim(), email: email.trim(), password, password_confirmation: confirmation });
          }}
        >
          {!passwordOnly ? (
            <>
              <div>
                <label htmlFor="admin-name" className="mb-1.5 block text-label-badge text-on-surface-variant">
                  Nom
                </label>
                <input
                  id="admin-name"
                  type="text"
                  required
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  className="w-full rounded border border-outline-variant bg-surface-lowest px-md py-2 text-body-compact text-on-surface focus:border-primary focus:outline-none"
                />
              </div>
              <div>
                <label htmlFor="admin-email" className="mb-1.5 block text-label-badge text-on-surface-variant">
                  Email
                </label>
                <input
                  id="admin-email"
                  type="email"
                  required
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  className="w-full rounded border border-outline-variant bg-surface-lowest px-md py-2 text-body-compact text-on-surface focus:border-primary focus:outline-none"
                />
              </div>
            </>
          ) : null}
          <div>
            <label htmlFor="admin-password" className="mb-1.5 block text-label-badge text-on-surface-variant">
              Mot de passe (8 caractères minimum)
            </label>
            <input
              id="admin-password"
              type="password"
              required
              minLength={8}
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="w-full rounded border border-outline-variant bg-surface-lowest px-md py-2 text-body-compact text-on-surface focus:border-primary focus:outline-none"
            />
          </div>
          <div>
            <label htmlFor="admin-password-confirm" className="mb-1.5 block text-label-badge text-on-surface-variant">
              Confirmation
            </label>
            <input
              id="admin-password-confirm"
              type="password"
              required
              minLength={8}
              value={confirmation}
              onChange={(e) => setConfirmation(e.target.value)}
              className="w-full rounded border border-outline-variant bg-surface-lowest px-md py-2 text-body-compact text-on-surface focus:border-primary focus:outline-none"
            />
          </div>
          <div className="flex justify-end gap-md pt-sm">
            <button
              type="button"
              onClick={onClose}
              className="rounded border border-outline-variant px-lg py-1.5 text-body-compact text-on-surface hover:bg-surface-low"
            >
              Annuler
            </button>
            <button
              type="submit"
              disabled={!valid || busy}
              className="rounded bg-primary px-lg py-1.5 text-body-compact font-medium text-on-primary hover:bg-primary-container disabled:opacity-50"
            >
              {busy ? "Enregistrement…" : "Enregistrer"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
