import { Skeleton } from "@/components/ui/skeleton";
import { SkCard, SkHeader, SkRows } from "@/components/ui/skeleton-kit";


// Services: the summary line, then the installed services list.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <div className="flex items-center justify-between rounded-2xl border border-border/70 bg-card px-4 py-3 shadow-e1">
        <Skeleton className="h-4 w-48" />
        <Skeleton className="size-8 rounded-lg" />
      </div>
      <SkCard>
        <SkRows count={8} icon={false} trailing="button" />
      </SkCard>
    </div>
  );
}
