import { Skeleton } from "@/components/ui/skeleton";
import { SkCard, SkRows } from "@/components/ui/skeleton-kit";

// Mirrors the admin dashboard: four status tiles, the attention lines, then the
// activity feed beside the access card.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <div className="space-y-2">
        <Skeleton className="h-7 w-40" />
        <Skeleton className="h-4 w-80" />
      </div>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <div key={i} className="space-y-3 rounded-2xl border border-border/70 bg-card p-4 shadow-e1">
            <div className="flex items-center gap-3">
              <Skeleton className="size-9 rounded-xl" />
              <Skeleton className="h-3 w-20" />
            </div>
            <Skeleton className="h-4 w-28" />
            <Skeleton className="h-3 w-36" />
          </div>
        ))}
      </div>
      <div className="grid gap-2">
        {Array.from({ length: 2 }).map((_, i) => (
          <div key={i} className="flex items-center gap-3 rounded-xl bg-card px-3 py-2.5 shadow-e1 ring-1 ring-border/70">
            <Skeleton className="size-8 rounded-xl" />
            <Skeleton className="h-4 w-72" />
            <Skeleton className="ml-auto h-4 w-32" />
          </div>
        ))}
      </div>
      <div className="grid gap-4 lg:grid-cols-3">
        <SkCard className="lg:col-span-2">
          <SkRows count={6} icon={false} trailing="none" />
        </SkCard>
        <SkCard>
          <SkRows count={3} trailing="none" />
        </SkCard>
      </div>
    </div>
  );
}
