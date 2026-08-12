import { Skeleton } from "@/components/ui/skeleton";

// Mirrors the real page — disk card, grouped category rows, schedule card — so
// content swaps in place instead of shifting everything down.
export default function Loading() {
  return (
    <div className="space-y-6">
      <div className="space-y-2">
        <Skeleton className="h-7 w-40" />
        <Skeleton className="h-4 w-96" />
      </div>

      <div className="max-w-5xl space-y-4">
        <div className="rounded-xl border p-5 sm:p-6">
          <div className="flex flex-wrap items-start justify-between gap-6">
            <div className="space-y-2">
              <Skeleton className="h-5 w-28" />
              <Skeleton className="h-9 w-24" />
              <Skeleton className="h-4 w-56" />
            </div>
            <div className="space-y-2">
              <Skeleton className="h-4 w-28" />
              <Skeleton className="h-9 w-24" />
            </div>
          </div>
          <Skeleton className="mt-5 h-2 w-full rounded-full" />
        </div>

        <div className="space-y-4 rounded-xl border p-6">
          <div className="space-y-2">
            <Skeleton className="h-5 w-40" />
            <Skeleton className="h-4 w-72" />
          </div>
          {[3, 2].map((rows, group) => (
            <div key={group} className="space-y-2">
              <Skeleton className="h-3 w-20" />
              <div className="divide-y rounded-lg border">
                {Array.from({ length: rows }).map((_, row) => (
                  <div key={row} className="flex items-center gap-3 px-4 py-3">
                    <Skeleton className="size-4 rounded" />
                    <div className="min-w-0 flex-1 space-y-1.5">
                      <Skeleton className="h-4 w-40" />
                      <Skeleton className="h-3 w-64" />
                    </div>
                    <Skeleton className="h-4 w-16" />
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>

        <div className="rounded-xl border p-6">
          <Skeleton className="h-5 w-44" />
          <Skeleton className="mt-2 h-4 w-56" />
        </div>
      </div>
    </div>
  );
}
