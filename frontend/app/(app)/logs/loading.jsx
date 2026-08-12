import { Skeleton } from "@/components/ui/skeleton";

// Mirrors the two-pane layout at real proportions so the content swaps in
// place rather than shifting when it lands.
export default function Loading() {
  return (
    <div className="space-y-6">
      <div className="space-y-2">
        <Skeleton className="h-7 w-28" />
        <Skeleton className="h-4 w-96" />
      </div>
      <div className="grid gap-6 lg:grid-cols-[16.5rem_minmax(0,1fr)]">
        <div className="space-y-4">
          {[3, 2, 2].map((count, group) => (
            <div key={group} className="space-y-1.5">
              <Skeleton className="h-3 w-16" />
              {Array.from({ length: count }).map((_, i) => (
                <Skeleton key={i} className="h-8 w-full" />
              ))}
            </div>
          ))}
        </div>
        <div className="h-[calc(100svh-13rem)] min-h-[24rem] overflow-hidden rounded-xl border">
          <div className="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
            <Skeleton className="h-5 w-40" />
            <div className="flex flex-wrap gap-2">
              <Skeleton className="h-9 w-52" />
              <Skeleton className="h-9 w-24" />
              <Skeleton className="h-9 w-28" />
            </div>
          </div>
          <div className="space-y-2 p-4">
            {Array.from({ length: 14 }).map((_, i) => (
              <Skeleton key={i} className="h-4" style={{ width: `${45 + ((i * 13) % 50)}%` }} />
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}
