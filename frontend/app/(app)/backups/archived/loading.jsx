import { Skeleton } from "@/components/ui/skeleton";
import { TableSkeleton } from "@/components/data-table/table-skeleton";

const TOOLBAR = (
  <div className="flex items-center justify-between gap-3">
    <div className="flex gap-2">
      <Skeleton className="h-9 w-36" />
      <Skeleton className="h-9 w-36" />
    </div>
    <Skeleton className="size-9" />
  </div>
);

// Archived backups: the note, then the list card.
export default function Loading() {
  return (
    <div className="space-y-4" aria-busy="true">
      <Skeleton className="h-4 w-full max-w-xl" />
      <TableSkeleton rows={5} columns={5} toolbar={TOOLBAR} />
    </div>
  );
}
