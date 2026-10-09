import { Skeleton } from "@/components/ui/skeleton";
import { SkCard, SkHeader, SkRows } from "@/components/ui/skeleton-kit";


// Disk cleaner: disk space and the schedule side by side, then what can be cleaned.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <div className="grid gap-4 lg:grid-cols-2">
        <SkCard head={false}>
          <Skeleton className="h-3 w-20" />
          <Skeleton className="mt-3 h-6 w-28" />
          <Skeleton className="mt-3 h-2 w-full rounded-full" />
          <Skeleton className="mt-3 h-3 w-56" />
        </SkCard>
        <SkCard head={false}>
          <Skeleton className="h-3 w-28" />
          <Skeleton className="mt-3 h-5 w-64" />
          <Skeleton className="mt-3 h-3 w-24" />
        </SkCard>
      </div>
      <SkCard>
        <SkRows count={6} icon={false} trailing="pill" />
      </SkCard>
    </div>
  );
}
