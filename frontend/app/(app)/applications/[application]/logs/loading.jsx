import { Skeleton } from "@/components/ui/skeleton";
import { SK_CARD, SkHeader, SkTabs, SkTerminal } from "@/components/ui/skeleton-kit";
import { cn } from "@/lib/utils";

// Logs (7 Oct): one card whose header is the Access / Error switch with Live on the
// right, then the tools row, then the log itself.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <div className={cn(SK_CARD, "overflow-hidden")}>
        <div className="flex items-center justify-between gap-4 border-b px-4 py-3">
          <div className="space-y-2">
            <SkTabs count={2} />
            <Skeleton className="h-3 w-40" />
          </div>
          <Skeleton className="h-5 w-20 rounded-full" />
        </div>
        <div className="flex flex-wrap items-center gap-2 border-b px-4 py-3">
          <Skeleton className="h-9 w-72 rounded-lg" />
          <div className="ml-auto flex flex-wrap gap-2">
            <Skeleton className="h-9 w-40 rounded-lg" />
            <Skeleton className="h-9 w-32 rounded-lg" />
            <Skeleton className="h-9 w-28 rounded-lg" />
            <Skeleton className="h-9 w-28 rounded-lg" />
          </div>
        </div>
        <SkTerminal lines={14} className="rounded-none" />
      </div>
    </div>
  );
}
