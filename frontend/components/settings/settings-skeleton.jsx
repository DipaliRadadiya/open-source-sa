import { Skeleton } from "@/components/ui/skeleton";

// Mirrors `Section` so the real card swaps in place.
export function SettingsCardSkeleton({ rows = 3, action = true }) {
  return (
    <div className="@container/section overflow-hidden rounded-2xl border border-border/70 bg-card shadow-e1">
      <div className="space-y-1.5 border-b px-5 py-4">
        <Skeleton className="h-5 w-40" />
        <Skeleton className="h-4 w-56" />
      </div>

      <div className="grid gap-x-6 gap-y-5 px-5 py-5 @3xl/section:grid-cols-2">
        {Array.from({ length: rows }).map((_, row) => (
          <div key={row} className="space-y-2">
            <Skeleton className="h-4 w-44" />
            <Skeleton className="h-3 w-64 max-w-full" />
            <Skeleton className="h-9 w-full" />
          </div>
        ))}
      </div>

      {action ? (
        <div className="flex justify-end border-t px-5 py-3">
          <Skeleton className="h-9 w-36" />
        </div>
      ) : null}
    </div>
  );
}
