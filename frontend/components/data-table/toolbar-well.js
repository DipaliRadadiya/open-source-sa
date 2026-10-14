// List toolbars: search boxes and filter dropdowns share one well. Steel UI (Krishna,
// 14 Oct): a hairline edge on a fill a step darker than the card, since the borderless
// grey well vanished into the graphite card; a green edge and ring while in use. The
// `dark:` repeats keep the input's own `dark:bg-input/30` from winning.
const WELL =
  "h-9 rounded-lg border-input bg-background shadow-none dark:bg-background focus-visible:border-primary/70 focus-visible:ring-2 focus-visible:ring-primary/30";

export const SEARCH_WELL = WELL;

export const SELECT_WELL = `${WELL} hover:border-[color-mix(in_oklch,var(--input),var(--foreground)_20%)] hover:bg-background dark:hover:bg-background data-[state=open]:border-primary/70 data-[state=open]:ring-2 data-[state=open]:ring-primary/30`;
