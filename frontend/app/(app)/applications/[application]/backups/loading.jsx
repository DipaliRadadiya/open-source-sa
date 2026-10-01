import { Skeleton } from "@/components/ui/skeleton";

/**
 * Mirrors `BackupsPanel` (two full-width cards); without this file the route
 * would inherit the dashboard skeleton with its side rail.
 *
 * Heights are measured from the rendered cards. The active-restore banner is
 * omitted because it is usually absent.
 */
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      {/* PageHeader: back button, title, subtitle. */}
      <div className="space-y-3">
        <Skeleton className="h-8 w-36" />
        <div className="space-y-1">
          <Skeleton className="h-8 w-32" />
          <Skeleton className="h-4 w-96" />
        </div>
      </div>

      {/* Is this site protected, and what has been kept. */}
      <Skeleton className="h-[15.5rem] w-full rounded-xl" />
      <Skeleton className="h-[17.5rem] w-full rounded-xl" />
    </div>
  );
}
