import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils";

const BAR_WIDTHS = ["w-32", "w-24", "w-16", "w-28", "w-20"];

/**
 * A table-shaped loading skeleton — a header row plus body rows with per-column
 * cells (not a solid block). `withAvatar` renders a circle + bar in the first
 * column; the last column is treated as a narrow actions cell.
 */
export function TableSkeleton({ rows = 6, columns = 5, withAvatar = false }) {
  return (
    <div className="overflow-hidden rounded-xl border">
      <div className="flex h-11 items-center gap-4 border-b bg-muted/40 px-4">
        {Array.from({ length: columns }).map((_, i) => (
          <div key={i} className={i === columns - 1 ? "w-8 shrink-0" : "flex-1"}>
            {i !== columns - 1 ? <Skeleton className="h-3.5 w-16" /> : null}
          </div>
        ))}
      </div>

      {Array.from({ length: rows }).map((_, r) => (
        <div
          key={r}
          className="flex items-center gap-4 border-b px-4 py-3 last:border-b-0"
        >
          {Array.from({ length: columns }).map((_, c) => {
            if (c === columns - 1) {
              return (
                <div key={c} className="w-8 shrink-0">
                  <Skeleton className="size-5 rounded-md" />
                </div>
              );
            }
            if (c === 0 && withAvatar) {
              return (
                <div key={c} className="flex flex-1 items-center gap-2.5">
                  <Skeleton className="size-7 shrink-0 rounded-full" />
                  <Skeleton className="h-4 w-28" />
                </div>
              );
            }
            return (
              <div key={c} className="flex-1">
                <Skeleton
                  className={cn("h-4", BAR_WIDTHS[c % BAR_WIDTHS.length])}
                />
              </div>
            );
          })}
        </div>
      ))}
    </div>
  );
}
