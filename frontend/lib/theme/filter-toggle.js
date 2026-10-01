/**
 * One look for filter toggles ("pick one to narrow what you're seeing"):
 *
 * - Always a visible edge; rank belongs in colour, not in having a surface.
 * - The active one is tinted with the accent, not filled grey, so it does not
 *   look like the tab bar.
 * - Same height as its neighbours.
 *
 * Classes rather than a component: one user renders a `<button>`, the other an
 * `<a>` so the range survives a reload.
 */
export function filterToggleClass(active) {
  return active
    ? "border-primary/40 bg-primary/10 font-medium text-[color-mix(in_oklch,var(--primary)_80%,var(--foreground))]"
    : "border-input text-muted-foreground hover:bg-muted hover:text-foreground";
}
