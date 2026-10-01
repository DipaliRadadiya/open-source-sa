// Classes, not a component: one user renders a `<button>`, the other an `<a>`.
export function filterToggleClass(active) {
  return active
    ? "border-primary/40 bg-primary/10 font-medium text-[color-mix(in_oklch,var(--primary)_80%,var(--foreground))]"
    : "border-input text-muted-foreground hover:bg-muted hover:text-foreground";
}
