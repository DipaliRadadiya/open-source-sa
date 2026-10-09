// Classes, not a component: one user renders a `<button>`, the other an `<a>`.
export function filterToggleClass(active) {
  return active
    ? "border-primary/40 bg-primary/10 font-medium text-[color-mix(in_oklch,var(--primary)_80%,var(--foreground))]"
    : "border-input text-muted-foreground hover:bg-muted hover:text-foreground";
}

// The same look for Radix ToggleGroup items, which carry their state as data-state.
export const FILTER_TOGGLE_ITEM =
  "h-9 rounded-lg border border-input px-3 text-sm text-muted-foreground hover:bg-muted hover:text-foreground data-[state=on]:border-primary/40 data-[state=on]:bg-primary/10 data-[state=on]:font-medium data-[state=on]:text-[color-mix(in_oklch,var(--primary)_80%,var(--foreground))]";
