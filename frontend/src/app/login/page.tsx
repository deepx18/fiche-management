"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useAuth } from "@/hooks/useAuth";

const ROLE_HOME: Record<string, string> = {
  admin: "/dashboard",
  supervisor: "/dashboard",
  agent: "/my-space",
};

export default function LoginPage() {
  const { login, session, loading } = useAuth();
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  // Déjà connecté → rediriger vers l'espace approprié (via useEffect)
  useEffect(() => {
    if (!loading && session) {
      router.replace(ROLE_HOME[session.user.role] ?? "/dashboard");
    }
  }, [loading, session, router]);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      const s = await login(email, password);
      router.replace(ROLE_HOME[s.user.role] ?? "/dashboard");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Connexion impossible.");
      setSubmitting(false);
    }
  }

  return (
    <main className="flex min-h-screen flex-col bg-surface">
      <header className="px-xl py-sm">
        <div className="flex items-center gap-sm">
          <span className="h-2 w-2 rounded-full bg-success" />
          <span className="font-mono text-label-table-header uppercase tracking-wider text-secondary">
            AssurCore // Authentification
          </span>
        </div>
      </header>

      <div className="flex flex-1 items-center justify-center px-md py-xl">
        <div className="w-full max-w-3xl overflow-hidden rounded-xl bg-surface-lowest shadow-[0_4px_24px_rgba(11,28,48,0.10)]">
          <div className="grid grid-cols-1 md:grid-cols-2">
            {/* Colonne formulaire */}
            <div className="flex flex-col justify-center gap-lg p-8 md:p-10">
              <div className="flex items-center gap-md">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary text-on-primary">
                  <span className="text-lg font-bold">A</span>
                </div>
                <div className="leading-none">
                  <span className="block text-headline-sm tracking-tight text-on-surface">AssurCore</span>
                  <span className="font-mono text-[10px] uppercase tracking-widest text-secondary">
                    Enterprise v2.4
                  </span>
                </div>
              </div>

              <div>
                <h1 className="text-headline-lg tracking-tight text-on-surface">
                  Connexion à votre espace professionnel
                </h1>
                <p className="mt-1 text-body-default text-secondary">
                  Production, contrats, agents et commissions.
                </p>
              </div>

              <form onSubmit={handleSubmit} className="space-y-md" noValidate>
                <div>
                  <label
                    htmlFor="email"
                    className="mb-1.5 block text-label-badge text-on-surface-variant"
                  >
                    Identifiant ou email d&apos;entreprise
                  </label>
                  <input
                    id="email"
                    type="email"
                    autoComplete="username"
                    required
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    placeholder="ex. admin@assurcore.ma"
                    className="w-full rounded border border-outline-variant bg-surface-lowest px-md py-2 text-body-default text-on-surface placeholder:text-outline focus:border-primary focus:outline-none transition-colors"
                  />
                </div>

                <div>
                  <label
                    htmlFor="password"
                    className="mb-1.5 block text-label-badge text-on-surface-variant"
                  >
                    Mot de passe
                  </label>
                  <input
                    id="password"
                    type="password"
                    autoComplete="current-password"
                    required
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    placeholder="••••••••"
                    className="w-full rounded border border-outline-variant bg-surface-lowest px-md py-2 text-body-default text-on-surface placeholder:text-outline focus:border-primary focus:outline-none transition-colors"
                  />
                </div>

                {error ? (
                  <p role="alert" className="rounded bg-error-container px-md py-2 text-body-compact text-on-error-container">
                    {error}
                  </p>
                ) : null}

                <button
                  type="submit"
                  disabled={submitting}
                  className="w-full rounded bg-primary py-2 text-body-medium font-medium text-on-primary hover:bg-primary-container transition-colors disabled:opacity-50"
                >
                  {submitting ? "Connexion…" : "Se connecter"}
                </button>
              </form>
            </div>

            {/* Colonne démo */}
            <div className="hidden flex-col justify-center gap-md border-l border-outline-variant/60 bg-surface-low p-8 md:flex">
              <span className="text-label-table-header uppercase tracking-wider text-on-surface-variant">
                Compte de démonstration
              </span>
              <p className="text-body-compact text-on-surface-variant">
                Compte créé par <code className="font-mono text-on-surface">php artisan admin:create</code>.
              </p>
              <button
                type="button"
                onClick={() => {
                  setEmail("admin@fiches.ma");
                  setPassword("admin1234");
                }}
                className="flex items-center justify-between rounded border border-outline-variant bg-surface-lowest px-md py-2 text-left hover:border-primary transition-colors"
              >
                <span>
                  <span className="block text-body-compact font-medium text-on-surface">admin@fiches.ma</span>
                  <span className="text-label-badge text-on-surface-variant">Administrateur</span>
                </span>
                <span className="text-label-badge text-secondary">Utiliser</span>
              </button>
              <p className="text-label-badge text-on-surface-variant">
                Authentification réelle via l&apos;API Laravel (token Sanctum). L&apos;inscription publique
                n&apos;existe pas : les comptes sont créés par un administrateur.
              </p>
            </div>
          </div>
        </div>
      </div>
    </main>
  );
}
