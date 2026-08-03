import { Skeleton } from "@/components/ui/skeleton";
import { TableSkeleton } from "@/components/data-table/table-skeleton";

// Mirrors the real layout — heading, version card with a footer of buttons,
// then the extensions card with its search row and table — so content swaps in
// place instead of shifting everything down when it arrives.
export default function Loading() {
  return (
    <div className="space-y-6">
      <div className="space-y-2">
        <Skeleton className="h-7 w-24" />
        <Skeleton className="h-4 w-96" />
      </div>

      <div className="max-w-5xl space-y-4">
        <div className="rounded-xl border">
          <div className="space-y-2 p-6">
            <Skeleton className="h-5 w-48" />
            <Skeleton className="h-4 w-72" />
          </div>
          <div className="flex items-center justify-between border-t bg-muted/30 px-6 py-4">
            <Skeleton className="h-9 w-36" />
            <Skeleton className="h-9 w-32" />
          </div>
        </div>

        <div className="rounded-xl border">
          <div className="space-y-2 p-6 pb-0">
            <Skeleton className="h-5 w-28" />
            <Skeleton className="h-4 w-64" />
          </div>
          <div className="space-y-3 p-6">
            <div className="flex gap-2">
              <Skeleton className="h-9 w-full sm:w-64" />
              <Skeleton className="h-9 w-32" />
            </div>
            <TableSkeleton rows={6} columns={2} />
          </div>
        </div>
      </div>
    </div>
  );
}
