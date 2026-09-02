import { Skeleton } from "@/components/ui/skeleton";
import { TableSkeleton } from "@/components/data-table/table-skeleton";

/**
 * See the History skeleton: the section-level one draws the Overview tab, and
 * the heading and tabs are layout that is already on screen.
 */
export default function Loading() {
  return (
    <div className="space-y-4" aria-busy="true">
      <div className="flex flex-col gap-3 sm:flex-row">
        <Skeleton className="h-9 w-full sm:max-w-xs" />
        <Skeleton className="h-9 w-40" />
      </div>
      <TableSkeleton rows={6} columns={5} />
      <div className="flex justify-end">
        <Skeleton className="h-9 w-64" />
      </div>
    </div>
  );
}
