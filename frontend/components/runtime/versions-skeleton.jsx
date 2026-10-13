import { Skeleton } from "@/components/ui/skeleton";
import { SK_CARD } from "@/components/ui/skeleton-kit";
import { cn } from "@/lib/utils";

// The Installed versions card (PHP and Node.js): header with the install button, the
// column band from 48rem of card width, one row per version, and the footnote.
export function VersionsCardSkeleton({ rows = 3, columns = 4 }) {
  return (
    <div className={cn(SK_CARD, "@container/versions overflow-hidden")} aria-hidden>
      <div className="flex items-start justify-between gap-4 px-4 pt-4 pb-4">
        <div className="space-y-2">
          <Skeleton className="h-4 w-36" />
          <Skeleton className="h-3 w-72 max-w-full" />
        </div>
        <Skeleton className="h-8 w-32 rounded-lg" />
      </div>
      <div className="hidden h-11 items-center gap-6 border-y bg-muted/40 px-4 @3xl/versions:flex">
        {Array.from({ length: columns }).map((_, i) => (
          <Skeleton key={i} className={cn("h-3 w-16", i === columns - 1 && "ms-auto")} />
        ))}
      </div>
      <div className="divide-y border-t @3xl/versions:border-t-0">
        {Array.from({ length: rows }).map((_, i) => (
          <div key={i} className="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3.5">
            <Skeleton className={cn("h-3.5", ["w-24", "w-20", "w-28"][i % 3])} />
            <Skeleton className="h-5 w-20 rounded-full" />
            <Skeleton className="h-3 w-16" />
            <div className="ms-auto flex gap-2">
              <Skeleton className="h-8 w-24 rounded-lg" />
              <Skeleton className="h-8 w-16 rounded-lg" />
            </div>
          </div>
        ))}
      </div>
      <div className="border-t bg-muted/20 px-4 py-3">
        <Skeleton className="h-3 w-96 max-w-full" />
      </div>
    </div>
  );
}
