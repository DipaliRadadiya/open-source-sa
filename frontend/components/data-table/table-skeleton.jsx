import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils";

const BAR_WIDTHS = ["w-32", "w-24", "w-16", "w-28", "w-20"];

// The last column is a narrow actions cell.
// `toolbar`: the search/filter row, drawn inside the card as on the real list pages.
export function TableSkeleton({ rows = 6, columns = 5, withAvatar = false, toolbar = null }) {
  return (
    // A white card like the list it stands in for, so it does not sink into the page.
    <div className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-e1">
      {toolbar ? <div className="border-b p-3">{toolbar}</div> : null}
      <div className="flex h-11 items-center gap-4 border-b px-4">
        {Array.from({ length: columns }).map((_, i) => (
          <div key={i} className={i === columns - 1 ? "w-8 shrink-0" : "min-w-0 flex-1"}>
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
                <div key={c} className="flex min-w-0 flex-1 items-center gap-2.5">
                  <Skeleton className="size-7 shrink-0 rounded-full" />
                  <Skeleton className="h-4 w-28" />
                </div>
              );
            }
            return (
              <div key={c} className="min-w-0 flex-1">
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
