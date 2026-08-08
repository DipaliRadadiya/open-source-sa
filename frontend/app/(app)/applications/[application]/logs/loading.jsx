import { Skeleton } from "@/components/ui/skeleton";

/**
 * Mirrors `ApplicationLogsPanel`, which this replaces for the moment it takes
 * to read the first log.
 *
 * It used to draw the SERVER logs layout — a 16.5rem sidebar beside the
 * console — copied from `app/(app)/logs/loading.jsx`, where that rail really
 * exists. This page has no rail: a site has two or three sources, so they are
 * tabs above one full-width viewer. The skeleton promised a column that never
 * arrived and then shifted the whole page sideways when the real panel landed.
 *
 * Heights track the panel's own `h-[calc(100svh-16rem)] min-h-[24rem]`, so the
 * console does not resize as it swaps in either.
 */
export default function Loading() {
  return (
    <div className="space-y-6">
      {/* PageHeader: back button, title, subtitle. */}
      <div className="space-y-3">
        <Skeleton className="h-8 w-36" />
        <div className="space-y-1">
          <Skeleton className="h-8 w-40" />
          <Skeleton className="h-4 w-72" />
        </div>
      </div>

      <div className="flex flex-col gap-4">
        {/* The source tabs. Two is the common case — access and error — so the
            placeholder does not imply more choice than a site usually has. */}
        <div className="flex w-fit flex-wrap gap-1 rounded-lg bg-muted p-1">
          <Skeleton className="h-9 w-28 rounded-md" />
          <Skeleton className="h-9 w-24 rounded-md" />
        </div>

        <Skeleton className="h-[calc(100svh-16rem)] min-h-[24rem] w-full rounded-xl" />
      </div>
    </div>
  );
}
