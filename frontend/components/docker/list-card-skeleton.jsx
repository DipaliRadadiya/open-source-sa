import { Skeleton } from "@/components/ui/skeleton";

// One list card as on the Docker and Registries pages: title and line with a button,
// then rows — two lines on the left, something in the middle, ⋯ on the right.
export function ListCardSkeleton({ rows = 3, columns = 1, test = false }) {
  return (
    <div className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-e1" aria-hidden>
      <div className="flex items-center justify-between gap-3 border-b px-5 py-3.5">
        <div className="space-y-2">
          <Skeleton className="h-4 w-28" />
          <Skeleton className="h-3 w-72 max-w-full" />
        </div>
        <Skeleton className="h-9 w-36 rounded-lg" />
      </div>
      <div className="divide-y">
        {Array.from({ length: rows }).map((_, i) => (
          <div key={i} className="flex items-center gap-4 px-5 py-4">
            <div className="w-[40%] min-w-0 space-y-2">
              <Skeleton className={["h-3.5 w-36", "h-3.5 w-44", "h-3.5 w-32"][i % 3]} />
              <Skeleton className={["h-3 w-48", "h-3 w-40", "h-3 w-56"][i % 3]} />
            </div>
            <div className="flex flex-1 gap-6">
              {Array.from({ length: columns }).map((_, c) => (
                <Skeleton key={c} className="h-3.5 w-28" />
              ))}
            </div>
            {test ? <Skeleton className="h-8 w-32 rounded-lg" /> : null}
            <Skeleton className="size-8 rounded-lg" />
          </div>
        ))}
      </div>
    </div>
  );
}
