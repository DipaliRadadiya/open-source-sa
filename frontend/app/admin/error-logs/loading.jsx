import { Skeleton } from "@/components/ui/skeleton";
import { SkHeader, SkSummary } from "@/components/ui/skeleton-kit";


// Error log: the summary, the search row, then one card per kind of error.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <SkSummary buttons={0} />
      <div className="flex gap-2">
        <Skeleton className="h-9 w-56" />
        <Skeleton className="h-9 w-64" />
        <Skeleton className="ml-auto h-9 w-24" />
      </div>
      <div className="space-y-3">
        {Array.from({ length: 3 }).map((_, i) => (
          <div key={i} className="flex items-center gap-3 rounded-2xl border border-border/70 bg-card p-4 shadow-e1">
            <Skeleton className="size-9 rounded-xl" />
            <div className="flex-1 space-y-2">
              <Skeleton className="h-3.5 w-56" />
              <Skeleton className="h-3 w-40" />
              <Skeleton className="h-3 w-64" />
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
