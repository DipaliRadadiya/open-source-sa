import { Skeleton } from "@/components/ui/skeleton";
import { SK_CARD, SkCompactTile, SkHeader, SkRows } from "@/components/ui/skeleton-kit";
import { cn } from "@/lib/utils";

// Services, in the page's own shape: four compact tiles, then the running services card
// (a table from 900px of card width, cards below that).
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <div className="@container/tiles">
        <div className="grid gap-3 sm:gap-4 @min-[30rem]/tiles:grid-cols-2 @min-[52rem]/tiles:grid-cols-4">
          <SkCompactTile />
          <SkCompactTile />
          <SkCompactTile />
          <SkCompactTile />
        </div>
      </div>

      <div className={cn(SK_CARD, "overflow-hidden")} aria-hidden>
        <div className="flex items-start gap-3 px-4 pt-4 pb-3">
          <div className="min-w-0 flex-1 space-y-2">
            <Skeleton className="h-4 w-40" />
            <Skeleton className="h-3 w-72 max-w-full" />
          </div>
          <Skeleton className="size-9 rounded-lg" />
        </div>
        <div className="@container/svc">
          <div className="hidden @min-[900px]/svc:block">
            <div className="flex h-11 items-center gap-6 border-y bg-muted/40 px-4">
              {["w-16", "w-12", "w-14", "w-10", "w-24"].map((width, i) => (
                <Skeleton key={i} className={cn("h-3", width, i === 0 && "flex-1 max-w-16 me-auto")} />
              ))}
            </div>
            <div className="divide-y">
              {["w-36", "w-28", "w-40", "w-32", "w-24", "w-36"].map((width, i) => (
                <div key={i} className="flex items-center gap-6 px-4 py-3.5">
                  <div className="flex min-w-0 flex-1 items-center gap-3">
                    <Skeleton className="size-8 shrink-0 rounded-lg" />
                    <Skeleton className={cn("h-3.5", width)} />
                  </div>
                  <Skeleton className="h-6 w-20 rounded-full" />
                  <Skeleton className="h-3 w-14" />
                  <Skeleton className="h-3 w-10" />
                  <Skeleton className="h-5 w-9 rounded-full" />
                  <Skeleton className="h-8 w-24 rounded-lg" />
                </div>
              ))}
            </div>
          </div>
          <div className="px-5 pb-5 @min-[900px]/svc:hidden">
            <SkRows count={6} icon trailing="button" />
          </div>
        </div>
      </div>
    </div>
  );
}
