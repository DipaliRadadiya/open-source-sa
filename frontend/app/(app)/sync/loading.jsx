import { Skeleton } from "@/components/ui/skeleton";
import { TableSkeleton } from "@/components/data-table/table-skeleton";

/** Sync waits on four calls before rendering, so it gets a full skeleton. */
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <div className="space-y-2">
        <Skeleton className="h-7 w-28" />
        <Skeleton className="h-4 w-96" />
      </div>
      {/* The scan summary, then the differences it found. */}
      <Skeleton className="h-24 w-full rounded-xl" />
      <TableSkeleton rows={6} columns={4} />
    </div>
  );
}
