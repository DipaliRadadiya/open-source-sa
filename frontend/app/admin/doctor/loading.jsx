import { Skeleton } from "@/components/ui/skeleton";
import { SkHeader, SkSummary } from "@/components/ui/skeleton-kit";


// System health: the summary card, then one card per check.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <SkSummary buttons={2} />
      <div className="space-y-3">
        {Array.from({ length: 6 }).map((_, i) => (
          <div key={i} className="flex items-center gap-3 rounded-2xl border border-border/70 bg-card p-4 shadow-e1">
            <Skeleton className="size-9 rounded-xl" />
            <div className="space-y-2">
              <Skeleton className="h-3.5 w-40" />
              <Skeleton className="h-3 w-56" />
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
