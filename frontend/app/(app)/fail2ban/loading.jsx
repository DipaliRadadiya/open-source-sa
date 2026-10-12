import { Skeleton } from "@/components/ui/skeleton";
import { SkCard, SkHeader, SkTabs } from "@/components/ui/skeleton-kit";

// Same shape and breakpoints as Protection: two comfortable jail items, then bans.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <SkTabs count={2} />
          <div className="flex items-center gap-2" aria-hidden>
            <Skeleton className="h-6 w-24 rounded-full" />
            <Skeleton className="h-3 w-20" />
          </div>
        </div>
        <SkCard action>
          <div className="grid gap-3 lg:grid-cols-2">
            {Array.from({ length: 2 }).map((_, i) => (
              <div key={i} className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 rounded-lg border p-4">
                <div className="min-w-0 space-y-2">
                  <div className="flex flex-wrap items-center gap-2">
                    <Skeleton className="h-3.5 w-24 max-w-full" />
                    <Skeleton className="h-6 w-20 max-w-full rounded-full" />
                  </div>
                  <Skeleton className="h-3 w-20 max-w-full" />
                </div>
                <Skeleton className="h-5 w-9 rounded-full" />
              </div>
            ))}
          </div>
        </SkCard>
        <SkCard head={false}>
          <div className="mb-4 flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
            <div className="min-w-0 space-y-2">
              <Skeleton className="h-4 w-36 max-w-full" />
              <Skeleton className="h-3 w-64 max-w-full" />
            </div>
            <div className="flex flex-wrap items-center gap-2 xl:justify-end">
              <div className="flex flex-wrap items-center gap-2">
                <Skeleton className="h-9 w-36 rounded-lg" />
                <Skeleton className="h-9 w-24 rounded-lg" />
              </div>
              <div className="flex flex-wrap items-center gap-2">
                <Skeleton className="size-9 rounded-lg" />
                <Skeleton className="h-9 w-28 rounded-lg" />
              </div>
            </div>
          </div>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:hidden">
            {Array.from({ length: 4 }).map((_, i) => (
              <div key={i} className="space-y-3 rounded-xl border p-4">
                <Skeleton className="h-3.5 w-48 max-w-full" />
                <Skeleton className="h-5 w-20 rounded-full" />
                <Skeleton className="h-3 w-40 max-w-full" />
                <div className="flex items-end justify-between gap-3 border-t pt-3">
                  <div className="space-y-2">
                    <Skeleton className="h-3 w-16" />
                    <Skeleton className="h-3 w-24" />
                  </div>
                  <Skeleton className="h-8 w-20 rounded-lg" />
                </div>
              </div>
            ))}
          </div>
          <div className="hidden rounded-lg border lg:block">
            {Array.from({ length: 5 }).map((_, i) => (
              <div key={i} className="grid grid-cols-[28fr_16fr_24fr_20fr_12fr] items-center gap-4 border-b px-4 py-3 last:border-0">
                <Skeleton className="h-3 w-4/5" />
                <Skeleton className="h-3 w-3/4" />
                <Skeleton className="h-3 w-4/5" />
                <Skeleton className="h-3 w-3/4" />
                <Skeleton className={i === 0 ? "h-3 w-3/4" : "h-8 w-full rounded-lg"} />
              </div>
            ))}
          </div>
        </SkCard>
      </div>
    </div>
  );
}
