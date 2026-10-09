import { Skeleton } from "@/components/ui/skeleton";
import { SkHeader } from "@/components/ui/skeleton-kit";
import { TableSkeleton } from "@/components/data-table/table-skeleton";


// Workers: the list card.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <TableSkeleton rows={3} columns={5} toolbar={<div className="flex justify-end gap-2"><Skeleton className="size-9" /><Skeleton className="h-9 w-32" /></div>} />
    </div>
  );
}
