import { Skeleton } from "@/components/ui/skeleton";
import { SkCard, SkChart, SkHeader, SkSummary, SkTabs, SkTiles } from "@/components/ui/skeleton-kit";


// Database health: engine tabs, verdict banner, four tiles, the day's chart beside running queries.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader back />
      <SkTabs count={3} />
      <SkSummary tone="success" buttons={0} />
      <SkTiles />
      <div className="grid gap-4 xl:grid-cols-3 xl:items-start">
        <SkCard className="xl:col-span-2">
          <div className="grid gap-2 sm:grid-cols-3">
            {Array.from({ length: 3 }).map((_, i) => (
              <div key={i} className="space-y-2 rounded-xl border bg-muted/30 px-3 py-2.5">
                <Skeleton className="h-3 w-14" />
                <Skeleton className="h-4 w-16" />
              </div>
            ))}
          </div>
          <SkChart className="mt-4 h-72" />
        </SkCard>
        <SkCard>
          <div className="space-y-3">
            {Array.from({ length: 2 }).map((_, i) => (
              <div key={i} className="space-y-2.5 rounded-xl border p-3">
                <Skeleton className="h-5 w-24 rounded-md" />
                <Skeleton className="h-3 w-40" />
                <Skeleton className="h-10 w-full rounded-md" />
                <Skeleton className="h-8 w-full rounded-lg" />
              </div>
            ))}
          </div>
        </SkCard>
      </div>
    </div>
  );
}
