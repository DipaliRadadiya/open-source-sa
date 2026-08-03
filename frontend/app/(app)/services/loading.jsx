import { Skeleton } from "@/components/ui/skeleton";
import { TableSkeleton } from "@/components/data-table/table-skeleton";

// Heading, the checked-at line and refresh button on the right, then the
// services table — the same shape the real page settles into.
export default function Loading() {
  return (
    <div className="space-y-6">
      <div className="space-y-2">
        <Skeleton className="h-7 w-32" />
        <Skeleton className="h-4 w-80" />
      </div>

      <div className="space-y-4">
        <div className="flex items-center justify-end gap-2">
          <Skeleton className="h-4 w-36" />
          <Skeleton className="h-9 w-9" />
        </div>
        <TableSkeleton rows={7} columns={4} />
      </div>
    </div>
  );
}
