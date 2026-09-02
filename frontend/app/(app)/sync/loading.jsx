import { Skeleton } from "@/components/ui/skeleton";
import { TableSkeleton } from "@/components/data-table/table-skeleton";

/**
 * Sync compares what the panel believes against what is on the server, so the
 * page cannot render until four calls come back — the longest wait in the
 * panel and, until now, the one with nothing on screen.
 */
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
