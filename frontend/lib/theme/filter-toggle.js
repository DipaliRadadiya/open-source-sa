/**
 * One look for "pick one of these to narrow what you're seeing".
 *
 * Two controls do that job today — the log viewer's All / Errors / Warnings+,
 * and the bot traffic card's 7 / 30 / 90 days — and they had arrived at two
 * different answers. The range picker used `variant="ghost"` until selected,
 * which draws no border and no fill, so it was reported as not looking like a
 * button at all. The severity filter had the same fault for the same reason.
 *
 * The rules this encodes:
 *
 * - **Always a visible edge.** Rank belongs in colour, never in whether a
 *   control has a surface. A borderless button on a tinted band is a caption.
 * - **The active one is tinted, not filled grey.** Grey-on-grey was how the
 *   severity strip ended up looking like the tab bar above it; the accent says
 *   "this is the live filter" without borrowing another control's appearance.
 * - **Same height as its neighbours.** Both of these sit in a row of other
 *   controls, and one item a few pixels short is most of what makes a row read
 *   as unrelated parts.
 *
 * Classes rather than a component because the two render differently — one is
 * a `<button>` with an onClick, the other an `<a>` so the range survives a
 * reload. Sharing the markup would mean a component with two modes; sharing
 * the look is the part that actually drifts.
 */
export function filterToggleClass(active) {
  return active
    ? "border-primary/40 bg-primary/10 font-medium text-primary"
    : "border-input text-muted-foreground hover:bg-muted hover:text-foreground";
}
