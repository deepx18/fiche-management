"use client";

import { useState, type ReactNode } from "react";

/**
 * Barre de filtres réutilisable : les contrôles essentiels (recherche, statut)
 * restent toujours visibles, les filtres avancés sont repliés par défaut.
 * Le bouton indique le nombre de filtres avancés actifs.
 */
export function FilterPanel({
  basic,
  advanced,
  activeCount = 0,
  reset,
}: {
  basic: ReactNode;
  advanced?: ReactNode;
  activeCount?: number;
  reset?: ReactNode;
}) {
  const [open, setOpen] = useState(activeCount > 0);

  return (
    <section className="rounded-xl bg-surface-lowest p-md shadow-sm">
      <div className="flex flex-wrap items-center gap-md">
        {basic}
        {reset ? <div className="flex items-center gap-sm">{reset}</div> : null}
        {advanced ? (
          <button
            type="button"
            onClick={() => setOpen((v) => !v)}
            aria-expanded={open}
            className="ml-auto rounded border border-outline-variant bg-surface-lowest px-md py-1.5 text-body-compact text-on-surface hover:bg-surface-low transition-colors"
          >
            {open ? "Masquer les filtres" : `Filtres avancés${activeCount > 0 ? ` (${activeCount})` : ""}`}
          </button>
        ) : null}
      </div>

      {open && advanced ? (
        <div className="mt-md grid grid-cols-1 gap-md border-t border-outline-variant/40 pt-md sm:grid-cols-2 lg:grid-cols-4">
          {advanced}
        </div>
      ) : null}
    </section>
  );
}
