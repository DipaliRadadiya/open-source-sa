import { Skeleton } from "@/components/ui/skeleton";
import { SkCard, SkHeader, SkRows, SkTabs } from "@/components/ui/skeleton-kit";


// PHP: the version card, the tabs, the extensions list.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <div className="space-y-4">
        <SkCard action>
          <div className="flex gap-2">
            {Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-6 w-20 rounded-md" />)}
          </div>
        </SkCard>
        <SkTabs count={2} />
        <SkCard>
          <div className="flex gap-2">
            <Skeleton className="h-9 w-64" />
            <Skeleton className="h-9 w-14" />
            <Skeleton className="h-9 w-14" />
            <Skeleton className="h-9 w-14" />
          </div>
          <div className="mt-4">
            <SkRows count={6} icon={false} trailing="switch" />
          </div>
        </SkCard>
      </div>
    </div>
  );
}
