import { Skeleton } from "@/components/ui/skeleton";
import { TableSkeleton } from "@/components/data-table/table-skeleton";

/**
 * Status banner, quick-add row, then the rules table, in the page's order. The
 * banner has a fixed height because it is always present.
 */
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <div className="space-y-2">
        <Skeleton className="h-7 w-28" />
        <Skeleton className="h-4 w-96" />
      </div>
      <Skeleton className="h-28 w-full rounded-xl" />
      <Skeleton className="h-32 w-full rounded-xl" />
      <TableSkeleton rows={6} columns={5} />
    </div>
  );
}
