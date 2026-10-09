import { Skeleton } from "@/components/ui/skeleton";
import { SK_CARD, SkHeader, SkInput } from "@/components/ui/skeleton-kit";
import { cn } from "@/lib/utils";

// Site clone (7 Oct): the form card (two fields side by side, the note, the button),
// then the compact "what gets copied" card.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <div className={cn(SK_CARD, "overflow-hidden")}>
        <div className="space-y-2 border-b px-5 py-4">
          <Skeleton className="h-4 w-36" />
          <Skeleton className="h-3 w-80 max-w-full" />
        </div>
        <div className="space-y-5 p-5">
          <div className="grid gap-5 md:grid-cols-2">
            {[0, 1].map((i) => (
              <div key={i} className="space-y-2">
                <Skeleton className="h-3.5 w-32" />
                <SkInput />
              </div>
            ))}
          </div>
          <Skeleton className="h-24 w-full rounded-xl" />
        </div>
        <div className="flex justify-end border-t bg-muted/30 px-5 py-4">
          <Skeleton className="h-9 w-48 rounded-lg" />
        </div>
      </div>
      <div className={cn(SK_CARD, "overflow-hidden")}>
        <div className="border-b px-5 py-4">
          <Skeleton className="h-4 w-32" />
        </div>
        <div className="space-y-3 p-5">
          {[4, 5].map((count, row) => (
            <div key={row} className="flex flex-wrap items-center gap-x-5 gap-y-2">
              <Skeleton className="h-3 w-24" />
              {Array.from({ length: count }).map((_, i) => (
                <Skeleton key={i} className={cn("h-3.5", ["w-16", "w-24", "w-20", "w-28", "w-14"][i])} />
              ))}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
