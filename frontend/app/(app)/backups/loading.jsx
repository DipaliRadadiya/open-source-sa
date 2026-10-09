import { Skeleton } from "@/components/ui/skeleton";
import { SkCard, SkFacts, SkNote } from "@/components/ui/skeleton-kit";


// Backups overview: the warning, the filters, the coverage cards.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkNote tone="warning" button />
      <div className="flex flex-wrap gap-2">
        <Skeleton className="h-9 w-48" />
        <Skeleton className="h-9 w-56" />
        <Skeleton className="h-9 w-36" />
      </div>
      <div className="grid gap-4 md:grid-cols-2">
        {Array.from({ length: 4 }).map((_, i) => (
          <SkCard key={i} action>
            <SkFacts count={4} className="sm:grid-cols-2 xl:grid-cols-2" />
          </SkCard>
        ))}
      </div>
    </div>
  );
}
