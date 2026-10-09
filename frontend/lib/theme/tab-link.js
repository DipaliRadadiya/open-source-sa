// Link-driven tabs (the choice lives in the URL) drawn like the panel's Tabs strip.
export const TAB_LINK_GROUP = "inline-flex w-fit max-w-full flex-wrap items-center gap-1 rounded-lg bg-muted p-1";

export function tabLinkClass(active) {
  return `inline-flex h-8 items-center justify-center rounded-md border border-transparent px-3 text-sm font-medium whitespace-nowrap transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring ${
    active
      ? "bg-card text-foreground shadow-sm dark:border-input dark:bg-input/30"
      : "text-foreground/70 hover:text-foreground dark:text-muted-foreground"
  }`;
}
