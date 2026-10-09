import { Skeleton } from "@/components/ui/skeleton";
import { SkCard, SkFacts, SkMasthead, SkTabs } from "@/components/ui/skeleton-kit";

// One database: masthead with its button, tabs, then the Overview tab's cards.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <div className="space-y-3">
        <Skeleton className="h-4 w-32" />
        <SkMasthead buttons={1} />
      </div>
      <div className="space-y-4">
        <SkTabs count={4} />
        <SkCard>
          <div className="space-y-5">
            <Skeleton className="h-20 w-full rounded-xl" />
            <Skeleton className="h-16 w-full rounded-xl" />
            <Skeleton className="h-16 w-full rounded-xl" />
          </div>
        </SkCard>
        <SkCard>
          <SkFacts count={6} />
        </SkCard>
      </div>
    </div>
  );
}
