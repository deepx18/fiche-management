/** Champ date étiqueté, aligné sur le style des autres contrôles de filtre. */
export function DateInput({
  label,
  value,
  onChange,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
}) {
  return (
    <label className="flex flex-col gap-1">
      <span className="text-label-badge text-on-surface-variant">{label}</span>
      <input
        type="date"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="bg-surface-lowest border border-outline-variant rounded px-md py-1.5 text-body-compact text-on-surface focus:outline-none focus:border-primary transition-colors"
      />
    </label>
  );
}

/** Champ numérique étiqueté (filtres « au moins N lignes », taille de groupe…). */
export function NumberInput({
  label,
  value,
  onChange,
  min = 0,
  placeholder,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  min?: number;
  placeholder?: string;
}) {
  return (
    <label className="flex flex-col gap-1">
      <span className="text-label-badge text-on-surface-variant">{label}</span>
      <input
        type="number"
        min={min}
        value={value}
        placeholder={placeholder}
        onChange={(e) => onChange(e.target.value)}
        className="w-32 bg-surface-lowest border border-outline-variant rounded px-md py-1.5 text-body-compact text-on-surface placeholder:text-outline focus:outline-none focus:border-primary transition-colors"
      />
    </label>
  );
}
