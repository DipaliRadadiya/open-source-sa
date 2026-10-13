import { Skeleton } from "@/components/ui/skeleton";
import { SkCard, SkHeader, SkTabs } from "@/components/ui/skeleton-kit";
import { VersionsCardSkeleton } from "@/components/runtime/versions-skeleton";

// PHP: the Installed versions card, then the selected version's settings — heading
// with the version picker, the tabs, and the extensions grid.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <VersionsCardSkeleton rows={4} columns={4} />
      <div className="space-y-4">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div className="space-y-2">
            <Skeleton className="h-5 w-40" />
            <Skeleton className="h-3 w-72 max-w-full" />
          </div>
          <Skeleton className="h-9 w-44 rounded-lg" />
        </div>
        <SkTabs count={3} />
        <SkCard>
          <div className="flex flex-wrap gap-2">
            <Skeleton className="h-9 w-64 max-w-full" />
            <Skeleton className="h-9 w-14" />
            <Skeleton className="h-9 w-14" />
            <Skeleton className="h-9 w-14" />
          </div>
          <div className="@container/ext mt-4">
            <div className="grid grid-cols-1 gap-x-6 @xl/ext:grid-cols-2 @6xl/ext:grid-cols-3">
              {Array.from({ length: 9 }).map((_, i) => (
                <div key={i} className="flex items-center justify-between gap-3 border-b py-3">
                  <div className="space-y-1.5">
                    <Skeleton className={["h-3.5 w-20", "h-3.5 w-16", "h-3.5 w-24"][i % 3]} />
                    <Skeleton className="h-3 w-40" />
                  </div>
                  <Skeleton className="h-5 w-9 rounded-full" />
                </div>
              ))}
            </div>
          </div>
        </SkCard>
      </div>
    </div>
  );
}
