import { Skeleton } from "@/components/ui/skeleton";
import { SK_CARD } from "@/components/ui/skeleton-kit";
import { cn } from "@/lib/utils";

// Must match the application page (7 Oct): the header card with its details row, the
// status tiles, then two-by-two cards (title and button over a rule, rows below).
export function ApplicationDashboardSkeleton() {
  return (
    <div className="space-y-6" aria-busy="true">
      <div className={cn(SK_CARD, "overflow-hidden")}>
        <div className="flex flex-wrap items-center justify-between gap-4 px-5 py-4">
          <div className="flex min-w-0 items-center gap-3">
            <Skeleton className="size-10 shrink-0 rounded-xl" />
            <div className="space-y-2">
              <Skeleton className="h-5 w-48" />
              <Skeleton className="h-3.5 w-64" />
            </div>
          </div>
          <div className="flex gap-2">
            <Skeleton className="h-9 w-36 rounded-lg" />
            <Skeleton className="h-9 w-32 rounded-lg" />
            <Skeleton className="size-9 rounded-lg" />
          </div>
        </div>
        <div className="grid grid-cols-2 gap-x-6 gap-y-3 border-t bg-muted/20 px-5 py-3.5 sm:grid-cols-3 xl:grid-cols-6">
          {Array.from({ length: 6 }).map((_, i) => (
            <div key={i} className="space-y-2">
              <Skeleton className="h-3 w-16" />
              <Skeleton className={cn("h-4", ["w-24", "w-28", "w-12", "w-10", "w-16", "w-20"][i])} />
            </div>
          ))}
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4">
        {Array.from({ length: 3 }).map((_, i) => (
          <div key={i} className={cn(SK_CARD, "space-y-3 p-4")}>
            <div className="flex items-center gap-3">
              <Skeleton className="size-9 rounded-xl" />
              <Skeleton className="h-3 w-20" />
            </div>
            <Skeleton className="h-4 w-28" />
            <Skeleton className="h-3 w-40 max-w-full" />
          </div>
        ))}
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        {[1, 1, 2, 5].map((rows, i) => (
          <div key={i} className={cn(SK_CARD, "overflow-hidden")}>
            <div className="flex items-center justify-between gap-4 border-b px-4 py-3.5">
              <Skeleton className="h-4 w-32" />
              <Skeleton className="h-8 w-28 rounded-lg" />
            </div>
            <div className="divide-y">
              {Array.from({ length: rows }).map((_, r) => (
                <div key={r} className="flex items-center gap-3 px-4 py-3">
                  <Skeleton className="size-4 shrink-0 rounded" />
                  <Skeleton className={cn("h-3.5", ["w-56", "w-40", "w-36", "w-44", "w-32"][r % 5])} />
                  <Skeleton className="ml-auto h-3.5 w-16" />
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
