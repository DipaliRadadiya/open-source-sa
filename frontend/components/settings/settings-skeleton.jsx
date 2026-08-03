import { Skeleton } from "@/components/ui/skeleton";

/**
 * The shape of a settings card while its data is in flight: header band, rows,
 * action band. Mirrors `Section` so the real card swaps in place instead of
 * shoving the page around when it arrives.
 */
export function SettingsCardSkeleton({ rows = 3, action = true }) {
  return (
    <div className="overflow-hidden rounded-xl border shadow-sm">
      <div className="flex items-center gap-2.5 border-b px-5 py-3.5">
        <Skeleton className="size-7 rounded-md" />
        <div className="space-y-1.5">
          <Skeleton className="h-5 w-40" />
          <Skeleton className="h-4 w-56" />
        </div>
      </div>

      <div className="px-5 py-2">
        {Array.from({ length: rows }).map((_, row) => (
          <div
            key={row}
            className="grid gap-x-8 gap-y-2 py-3.5 sm:grid-cols-[minmax(0,1fr)_14rem] sm:items-center"
          >
            <div className="space-y-1.5">
              <Skeleton className="h-4 w-44" />
              <Skeleton className="h-3 w-64" />
            </div>
            <Skeleton className="h-8 w-24" />
          </div>
        ))}
      </div>

      {action ? (
        <div className="flex justify-end border-t px-5 py-2.5">
          <Skeleton className="h-9 w-36" />
        </div>
      ) : null}
    </div>
  );
}
