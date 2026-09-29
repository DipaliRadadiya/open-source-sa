import { Skeleton } from "@/components/ui/skeleton";
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
      <Skeleton className="h-9 w-full max-w-sm" />
      <Skeleton className="h-72 w-full rounded-xl" />
    </div>
  );
}
