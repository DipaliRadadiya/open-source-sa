// List toolbars: search boxes and filter dropdowns share one soft filled well
// that turns white with a brand ring while in use. The `dark:` repeats are
// needed because `dark:bg-*` would otherwise outrank the in-use background.
const WELL =
  "h-9 rounded-lg border-transparent bg-muted/70 shadow-none dark:bg-muted/50 focus-visible:border-transparent focus-visible:bg-card focus-visible:ring-2 focus-visible:ring-primary/60 dark:focus-visible:bg-card";

export const SEARCH_WELL = WELL;

export const SELECT_WELL = `${WELL} hover:bg-muted dark:hover:bg-muted/70 data-[state=open]:bg-card data-[state=open]:ring-2 data-[state=open]:ring-primary/60 dark:data-[state=open]:bg-card`;
