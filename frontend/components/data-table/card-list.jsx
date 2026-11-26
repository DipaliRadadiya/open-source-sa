import { cn } from "@/lib/utils";
import { FACT_LABEL } from "@/lib/theme/facts";

export function CardList({ className, children }) {
  // grid-cols-1 gives minmax(0, 1fr) so nowrap content can't widen the list.
  return <ul className={cn("grid grid-cols-1 gap-3 sm:grid-cols-2", className)}>{children}</ul>;
}

export function CardListItem({ className, children }) {
  return (
    <li className={cn("flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-sm", className)}>
      {children}
    </li>
  );
}

export function CardFacts({ className, children }) {
  return (
    <dl className={cn("flex flex-col gap-2 border-t pt-3 text-sm", className)}>{children}</dl>
  );
}

export function CardFact({ label, value, children, className }) {
  return (
    <div className={cn("flex items-start justify-between gap-3", className)}>
      <dt className={cn("shrink-0 pt-px", FACT_LABEL)}>{label}</dt>
      <dd className={cn("min-w-0 flex-1 text-right", !children && "truncate")}>
        {children ?? value}
      </dd>
    </div>
  );
}

export function CardFactGrid({ className, children }) {
  return <dl className={cn("grid grid-cols-2 gap-x-4 gap-y-3 border-t pt-3 text-sm", className)}>{children}</dl>;
}

export function CardFactStacked({ label, children, className }) {
  return (
    <div className={cn("min-w-0 space-y-1", className)}>
      <dt className={FACT_LABEL}>{label}</dt>
      <dd className="min-w-0">{children}</dd>
    </div>
  );
}
