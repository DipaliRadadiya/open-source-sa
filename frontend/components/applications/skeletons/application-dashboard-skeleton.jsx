import { Skeleton } from "@/components/ui/skeleton";

// Must match the dashboard layout. Heights are per row: the real grid stretches each row.
export function ApplicationDashboardSkeleton() {
  return (
    <div className="space-y-4" aria-busy="true">
      <div className="space-y-2">
        <Skeleton className="h-8 w-56" />
        <Skeleton className="h-4 w-72" />
      </div>

      {/* Reserves the attention strip so the cards do not shift down. */}
      <div className="pb-2">
        <Skeleton className="h-16 rounded-xl" />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Skeleton className="h-72 rounded-xl" />
        <Skeleton className="h-72 rounded-xl" />
        <Skeleton className="h-56 rounded-xl" />
        <Skeleton className="h-56 rounded-xl" />
      </div>
    </div>
  );
}
