import { Skeleton } from "@/components/ui/skeleton";
import { TableSkeleton } from "@/components/data-table/table-skeleton";
import { ApplicationRouteSkeleton } from "@/components/applications/skeletons/application-route-skeleton";

// Also shown while an application's own layout loads, so an application URL
// gets that page's skeleton rather than the list's.
export default function Loading() {
  return <ApplicationRouteSkeleton fallback={<ListSkeleton />} />;
}

function ListSkeleton() {
  return (
    <div className="space-y-6" aria-busy="true">
      <div className="space-y-2"><Skeleton className="h-8 w-40" /><Skeleton className="h-4 w-80" /></div>
      {/* The list card: search, two filters and the buttons on top, then the rows. */}
      <TableSkeleton
        rows={6}
        columns={6}
        withAvatar
        toolbar={
          <div className="flex items-center justify-between gap-3">
            <div className="flex gap-2">
              <Skeleton className="h-9 w-64" />
              <Skeleton className="h-9 w-32" />
              <Skeleton className="h-9 w-32" />
            </div>
            <div className="flex gap-2">
              <Skeleton className="size-9" />
              <Skeleton className="h-9 w-40" />
            </div>
          </div>
        }
      />
    </div>
  );
}
