import { Skeleton } from "@/components/ui/skeleton";
import { SkHeader } from "@/components/ui/skeleton-kit";
import { TableSkeleton } from "@/components/data-table/table-skeleton";


// Files: the folder trail and shortcuts, the toolbar card, then the file list.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <div className="flex items-center justify-between">
        <Skeleton className="h-4 w-40" />
        <Skeleton className="h-3 w-48" />
      </div>
      <div className="flex gap-2">
        {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-7 w-24 rounded-full" />)}
      </div>
      <div className="flex flex-wrap items-center gap-2 rounded-xl border bg-muted/30 p-2">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-9 w-24" />
        <Skeleton className="h-9 w-36" />
        <div className="ml-auto flex gap-2">
          <Skeleton className="h-9 w-28" />
          <Skeleton className="h-9 w-24" />
          <Skeleton className="h-9 w-24" />
        </div>
      </div>
      <TableSkeleton rows={8} columns={6} withAvatar />
    </div>
  );
}
