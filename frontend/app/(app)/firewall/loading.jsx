import { Skeleton } from "@/components/ui/skeleton";
import { TableSkeleton } from "@/components/data-table/table-skeleton";

/**
 * Status banner, the quick-add row, then the rules table — the order the page
 * settles into. The banner is a fixed height because it is present whatever
 * the firewall turns out to be doing.
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
