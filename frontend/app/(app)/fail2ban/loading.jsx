import { Skeleton } from "@/components/ui/skeleton";
import { SkCard, SkCentered, SkHeader, SkTabs } from "@/components/ui/skeleton-kit";


// Fail2ban: tabs, the jails card, then the banned addresses card.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <SkTabs count={2} />
      <SkCard>
        <div className="grid gap-3 sm:grid-cols-2">
          {Array.from({ length: 2 }).map((_, i) => (
            <div key={i} className="flex items-center justify-between rounded-xl border p-3">
              <div className="space-y-2">
                <Skeleton className="h-3.5 w-24" />
                <Skeleton className="h-3 w-36" />
              </div>
              <Skeleton className="h-5 w-9 rounded-full" />
            </div>
          ))}
        </div>
      </SkCard>
      <SkCard action>
        <SkCentered />
      </SkCard>
    </div>
  );
}
