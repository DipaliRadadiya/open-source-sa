import { Skeleton } from "@/components/ui/skeleton";
import { SkHeader } from "@/components/ui/skeleton-kit";
import { TableSkeleton } from "@/components/data-table/table-skeleton";

const TOOLBAR = (
  <div className="flex items-center justify-between gap-3">
          <div className="flex gap-2">
            <Skeleton className="h-9 w-64" />
            <Skeleton className="h-9 w-36" />
          </div>
          <div className="flex gap-2">
            <Skeleton className="h-9 size-9" />
          </div>
        </div>
);

// Activity log: one list card with search and type filter.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <TableSkeleton rows={10} columns={5} toolbar={TOOLBAR} />
    </div>
  );
}
