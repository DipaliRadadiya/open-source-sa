import { Skeleton } from "@/components/ui/skeleton";
import { TableSkeleton } from "@/components/data-table/table-skeleton";

/**
 * Its own, rather than inheriting the section skeleton: that one draws the
 * Overview tab — two banners and a coverage table — so switching to History
 * flashed a shape this tab never takes.
 *
 * The heading and tab strip are omitted for the opposite reason. They belong
 * to the layout, which is already on screen when you click the tab; redrawing
 * them as skeletons would blank two things that never went away.
 */
export default function Loading() {
  return (
    <div className="space-y-4" aria-busy="true">
      {/* Search, status filter, type filter. */}
      <div className="flex flex-col gap-3 sm:flex-row">
        <Skeleton className="h-9 w-full sm:max-w-xs" />
        <Skeleton className="h-9 w-40" />
        <Skeleton className="h-9 w-40" />
      </div>
      <TableSkeleton rows={8} columns={5} />
      <div className="flex justify-end">
        <Skeleton className="h-9 w-64" />
      </div>
    </div>
  );
}
