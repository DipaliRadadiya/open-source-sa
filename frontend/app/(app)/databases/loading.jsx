import { Skeleton } from "@/components/ui/skeleton";
import { TableSkeleton } from "@/components/data-table/table-skeleton";

// Mirrors the real layout — heading, engine bar, search row, table — so the
// content swaps in place rather than pushing everything down when it lands.
export default function Loading() {
  return (
    <div className="space-y-6">
      <div className="space-y-2">
        <Skeleton className="h-7 w-36" />
        <Skeleton className="h-4 w-80" />
      </div>

      <div className="space-y-4">
        <div className="flex items-center justify-between rounded-xl border px-4 py-3">
          <Skeleton className="h-5 w-48" />
          <Skeleton className="h-5 w-32" />
        </div>

        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <Skeleton className="h-9 w-full sm:w-64" />
          <div className="flex gap-2">
            <Skeleton className="h-9 w-9" />
            <Skeleton className="h-9 w-40" />
          </div>
        </div>

        <TableSkeleton rows={5} columns={5} />
      </div>
    </div>
  );
}
