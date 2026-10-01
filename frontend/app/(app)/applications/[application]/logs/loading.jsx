import { Skeleton } from "@/components/ui/skeleton";

/**
 * Mirrors `ApplicationLogsPanel`: source tabs above one full-width viewer, no
 * sidebar rail (unlike the server logs page), so the page does not shift when
 * the real panel lands. Heights track the panel's
 * `h-[calc(100svh-16rem)] min-h-[24rem]`.
 */
export default function Loading() {
  return (
    <div className="space-y-6">
      <div className="space-y-3">
        <Skeleton className="h-8 w-36" />
        <div className="space-y-1">
          <Skeleton className="h-8 w-40" />
          <Skeleton className="h-4 w-72" />
        </div>
      </div>

      <div className="flex flex-col gap-4">
        {/* Two tabs: access and error is the common case. */}
        <div className="flex w-fit flex-wrap gap-1 rounded-lg bg-muted p-1">
          <Skeleton className="h-9 w-28 rounded-md" />
          <Skeleton className="h-9 w-24 rounded-md" />
        </div>

        <Skeleton className="h-[calc(100svh-16rem)] min-h-[24rem] w-full rounded-xl" />
      </div>
    </div>
  );
}
