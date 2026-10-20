const moneyFmt = new Intl.NumberFormat("fr-FR", { maximumFractionDigits: 0 });
const dateFmt = new Intl.DateTimeFormat("fr-FR", { dateStyle: "medium" });
const dateTimeFmt = new Intl.DateTimeFormat("fr-FR", {
  dateStyle: "short",
  timeStyle: "short",
});

export function formatMoney(v: number): string {
  return `${moneyFmt.format(v)} MAD`;
}

export function formatNumber(v: number): string {
  return moneyFmt.format(v);
}

export function formatDate(iso: string): string {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? iso : dateFmt.format(d);
}

export function formatDateTime(iso: string): string {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? iso : dateTimeFmt.format(d);
}

const dateOnlyFmt = new Intl.DateTimeFormat("fr-FR", {
  day: "2-digit",
  month: "2-digit",
  year: "numeric",
});

/**
 * Date pure (`aaaa-mm-jj`) affichée en `jj/mm/aaaa`, sans décalage de fuseau
 * horaire. La valeur stockée n'est jamais modifiée.
 */
export function formatDateOnly(value: string | null): string {
  if (!value) return "—";
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);
  if (!match) return value;

  return dateOnlyFmt.format(new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
}

const decimalFmt = new Intl.NumberFormat("fr-FR", {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
});

/**
 * Montant de la feuille (séparateur décimal « . »). Aucun symbole monétaire
 * n'est ajouté : la source n'en contient pas. L'arrondi est uniquement visuel,
 * la valeur stockée reste celle validée à la synchronisation.
 */
export function formatAmount(value: string | null): string {
  if (value === null || value === "") return "—";
  const parsed = Number(value);

  return Number.isFinite(parsed) ? decimalFmt.format(parsed) : value;
}
