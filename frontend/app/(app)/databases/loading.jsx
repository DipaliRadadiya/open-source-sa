import { Skeleton } from "@/components/ui/skeleton";
import { SkHeader, SkNote } from "@/components/ui/skeleton-kit";
import { TableSkeleton } from "@/components/data-table/table-skeleton";

const TOOLBAR = (
  <div className="flex items-center justify-between gap-3">
          <div className="flex gap-2">
            <Skeleton className="h-9 w-64" />
          </div>
          <div className="flex gap-2">
            <Skeleton className="h-9 size-9" />
            <Skeleton className="h-9 w-40" />
          </div>
        </div>
);

// Databases: the engine strip, the unlinked note, then the list card.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <div className="flex items-center justify-between rounded-2xl border border-border/70 bg-card px-4 py-3 shadow-e1">
        <div className="flex gap-2">
          <Skeleton className="h-7 w-32 rounded-lg" />
          <Skeleton className="h-7 w-32 rounded-lg" />
          <Skeleton className="h-7 w-32 rounded-lg" />
        </div>
        <Skeleton className="h-8 w-24 rounded-lg" />
      </div>
      <SkNote tone="warning" button />
      <TableSkeleton rows={6} columns={6} toolbar={TOOLBAR} />
    </div>
  );
}
