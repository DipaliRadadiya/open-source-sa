/**
 * One card chrome, written once, so eleven cards on a page cannot disagree.
 *
 * They already had. The metric cards kept `Card`'s default `ring-foreground/10`
 * while the chart, info and process cards were given `ring-foreground/[0.07]` —
 * a 3% difference nobody would name but everybody sees, and it was found by
 * being asked "do all the cards have the same border?" rather than by looking.
 *
 * The shadow carries `!` for a reason worth writing down, because it looks like
 * laziness and is not.
 *
 * `Card` hardcodes `shadow-sm`. `shadow-e1` is a different class NAME for the
 * same property, so tailwind-merge does not know the two conflict and keeps
 * both — after which the winner is whichever rule the generated stylesheet
 * happens to emit last, and that was `shadow-sm`. Two rounds of "softer shadow"
 * therefore changed nothing at all: the computed value stayed
 * `rgba(0,0,0,0.1) 0 1px 3px` throughout, which I only found by reading the
 * computed style instead of the class list.
 *
 * `shadow-[var(--shadow-e1)]` does not fix it either — tailwind-merge's
 * arbitrary-shadow test wants a value beginning with a length, so a `var()`
 * falls through to the shadow-COLOR group and `shadow-sm` survives again.
 * Spelling the shadow out as a literal would work and would duplicate a token,
 * which is the drift this file exists to prevent. So: the named utility, forced.
 *
 * One elevation for all of them, not a ladder: none of these cards sits above
 * another, they are peers on a flat page.
 */
export const PANEL_CARD = "shadow-e1! ring-foreground/[0.07]";
