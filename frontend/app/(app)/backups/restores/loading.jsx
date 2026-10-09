import { Skeleton } from "@/components/ui/skeleton";
import { TableSkeleton } from "@/components/data-table/table-skeleton";

const TOOLBAR = (
  <div className="flex items-center justify-between gap-3">
          <div className="flex gap-2">
            <Skeleton className="h-9 w-40" />
            <Skeleton className="h-9 w-36" />
            <Skeleton className="h-9 w-36" />
            <Skeleton className="h-9 w-36" />
          </div>
          <div className="flex gap-2">
            <Skeleton className="h-9 size-9" />
          </div>
        </div>
);

// Restores: the list card.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <TableSkeleton rows={6} columns={6} toolbar={TOOLBAR} />
    </div>
  );
}
