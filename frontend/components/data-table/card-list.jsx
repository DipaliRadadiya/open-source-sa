import { cn } from "@/lib/utils";

/**
 * The shared vocabulary for a table's narrow-screen stand-in.
 *
 * Ten tables needed a card view and each one invented its own paddings, its own
 * divider and its own way of labelling a value, which is how a panel ends up
 * looking assembled rather than designed. These four pieces are the whole
 * vocabulary; a card list should not need anything else.
 *
 * Two per row from `sm` up: one card alone on a 600px tablet row is mostly
 * empty space.
 */
export function CardList({ className, children }) {
  // grid-cols-1 is not redundant. Without it the single column is `auto`, which
  // sizes to content — one nowrap timestamp inside a card then widens the whole
  // list past the viewport. Spelling out the count gives `minmax(0, 1fr)`.
  return <ul className={cn("grid grid-cols-1 gap-3 sm:grid-cols-2", className)}>{children}</ul>;
}

// A flex column so a card can push its action row to the bottom with `mt-auto`.
// Cards in a grid row stretch to the tallest of them, and a short card that
// stops halfway down reads as unfinished.
export function CardListItem({ className, children }) {
  return (
    <li className={cn("flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-sm", className)}>
      {children}
    </li>
  );
}

/**
 * Facts as one column of label-left / value-right rows.
 *
 * Not a two-column grid: an odd number of facts leaves a hole, values of
 * different heights stop lining up with their labels, and the whole thing reads
 * as a form rather than a list. One row per fact has a single alignment edge
 * down each side, so a card can be scanned without reading it.
 */
export function CardFacts({ className, children }) {
  return (
    <dl className={cn("flex flex-col gap-2 border-t pt-3 text-sm", className)}>{children}</dl>
  );
}

export function CardFact({ label, value, children, className }) {
  return (
    <div className={cn("flex items-start justify-between gap-3", className)}>
      {/* pt-px: the label is a size smaller than its value, so their box tops
          do not agree even though their baselines should. */}
      <dt className="shrink-0 pt-px text-xs text-muted-foreground">{label}</dt>
      {/* A plain value truncates; a passed element decides for itself, since
          those are usually two stacked lines that truncating would flatten. */}
      <dd className={cn("min-w-0 flex-1 text-right", !children && "truncate")}>
        {children ?? value}
      </dd>
    </div>
  );
}
