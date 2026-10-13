import { Skeleton } from "@/components/ui/skeleton";
import { SK_CARD, SkCompactTile, SkHeader } from "@/components/ui/skeleton-kit";
import { cn } from "@/lib/utils";

// Disk cleaner, in the page's own shape: three compact tiles, then the list card with
// its header buttons, select-all band, item tiles two across, and the footer band.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <div className="space-y-4">
        <div className="@container/tiles">
          <div className="grid gap-3 sm:gap-4 @min-[52rem]/tiles:grid-cols-[minmax(0,1fr)_minmax(0,0.8fr)_minmax(0,1.3fr)]">
            <SkCompactTile />
            <SkCompactTile />
            <SkCompactTile action />
          </div>
        </div>

        <div className={cn(SK_CARD, "overflow-hidden")} aria-hidden>
          <div className="flex flex-wrap items-start justify-between gap-3 px-4 pt-4 pb-4">
            <div className="space-y-2">
              <Skeleton className="h-4 w-40" />
              <Skeleton className="h-3 w-80 max-w-full" />
              <Skeleton className="h-3 w-24" />
            </div>
            <div className="flex gap-2">
              <Skeleton className="h-9 w-40 rounded-lg" />
              <Skeleton className="size-9 rounded-lg" />
            </div>
          </div>
          <div className="flex items-center gap-3 border-y bg-muted/40 px-7 py-3">
            <Skeleton className="size-4 rounded" />
            <Skeleton className="h-3 w-28" />
          </div>
          <div className="@container/items p-4">
            <div className="grid grid-cols-1 gap-3 @2xl/items:grid-cols-2">
              {["w-32", "w-28", "w-24", "w-36"].map((width, i) => (
                <div key={i} className="flex items-start gap-3 rounded-xl border p-3">
                  <Skeleton className="mt-0.5 size-4 shrink-0 rounded" />
                  <div className="min-w-0 flex-1 space-y-2">
                    <div className="flex justify-between gap-3">
                      <Skeleton className={cn("h-3.5", width)} />
                      <Skeleton className="h-3.5 w-14" />
                    </div>
                    <Skeleton className="h-3 w-full max-w-72" />
                  </div>
                </div>
              ))}
            </div>
            <Skeleton className="mt-4 h-3 w-64 max-w-full" />
          </div>
          <div className="flex items-center justify-between gap-3 border-t bg-muted/30 px-4 py-4">
            <Skeleton className="h-3.5 w-48" />
            <Skeleton className="h-9 w-24 rounded-lg" />
          </div>
        </div>
      </div>
    </div>
  );
}
