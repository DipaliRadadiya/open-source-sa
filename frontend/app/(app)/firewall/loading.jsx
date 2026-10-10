import { Skeleton } from "@/components/ui/skeleton";
import { SK_CARD, SkCard, SkHeader, SkRows } from "@/components/ui/skeleton-kit";

// Firewall: the status card (headline and its button, three facts), then the rules card.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <div className="space-y-4">
        <div className={`${SK_CARD} overflow-hidden`} aria-hidden>
          <div className="flex items-center gap-3 p-4">
            <Skeleton className="size-10 shrink-0 rounded-xl" />
            <div className="min-w-0 flex-1 space-y-2">
              <Skeleton className="h-4 w-48" />
              <Skeleton className="h-3 w-80" />
            </div>
            <Skeleton className="hidden h-9 w-40 shrink-0 rounded-lg sm:block" />
          </div>
          <div className="grid divide-y border-t sm:grid-cols-3 sm:divide-x sm:divide-y-0">
            {[0, 1, 2].map((i) => (
              <div key={i} className="flex items-center gap-2.5 px-4 py-3 sm:px-5">
                <Skeleton className="size-8 shrink-0 rounded-lg" />
                <div className="space-y-1.5">
                  <Skeleton className="h-3 w-16" />
                  <Skeleton className="h-3.5 w-20" />
                </div>
              </div>
            ))}
          </div>
        </div>
        <SkCard action>
          <SkRows count={3} icon={false} trailing="pill" />
        </SkCard>
      </div>
    </div>
  );
}
