/**
 * One card chrome, shared so cards on a page cannot disagree.
 *
 * The `!` on the shadow is required: `Card` hardcodes `shadow-sm`, and
 * tailwind-merge does not know `shadow-e1` conflicts with it, so without `!`
 * whichever rule the stylesheet emits last wins (`shadow-sm`).
 * `shadow-[var(--shadow-e1)]` does not help either: tailwind-merge treats a
 * `var()` as a shadow colour. Verify by computed style, not class names.
 */
export const PANEL_CARD = "shadow-e1! ring-foreground/[0.07]";
