import { Skeleton } from "@/components/ui/skeleton";
import { SkHeader, SkSummary } from "@/components/ui/skeleton-kit";
import { TableSkeleton } from "@/components/data-table/table-skeleton";

const TOOLBAR = (
  <div className="flex items-center justify-between gap-3">
          <div className="flex gap-2">
            <Skeleton className="h-9 w-64" />
          </div>
          <div className="flex gap-2">
          </div>
        </div>
);

// Server sync: the scan summary with its buttons, then the results list.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader wide />
      <SkSummary buttons={3} />
      <TableSkeleton rows={5} columns={4} toolbar={TOOLBAR} />
    </div>
  );
}
