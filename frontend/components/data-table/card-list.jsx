import { cn } from "@/lib/utils";

/**
 * Shared building blocks for a table's narrow-screen card view. Two per row
 * from `sm` up.
 */
export function CardList({ className, children }) {
  // grid-cols-1 is not redundant: it gives `minmax(0, 1fr)`, so nowrap content
  // cannot widen the list past the viewport.
  return <ul className={cn("grid grid-cols-1 gap-3 sm:grid-cols-2", className)}>{children}</ul>;
}

// A flex column so a card can push its action row to the bottom with `mt-auto`.
export function CardListItem({ className, children }) {
  return (
    <li className={cn("flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-sm", className)}>
      {children}
    </li>
  );
}

/** Facts as one column of label-left / value-right rows. */
export function CardFacts({ className, children }) {
  return (
    <dl className={cn("flex flex-col gap-2 border-t pt-3 text-sm", className)}>{children}</dl>
  );
}

export function CardFact({ label, value, children, className }) {
  return (
    <div className={cn("flex items-start justify-between gap-3", className)}>
      {/* pt-px aligns the smaller label with its value. */}
      <dt className="shrink-0 pt-px text-xs text-muted-foreground">{label}</dt>
      {/* A plain value truncates; passed children handle their own overflow. */}
      <dd className={cn("min-w-0 flex-1 text-right", !children && "truncate")}>
        {children ?? value}
      </dd>
    </div>
  );
}
