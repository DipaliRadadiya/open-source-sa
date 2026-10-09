import { Skeleton } from "@/components/ui/skeleton";
import { SkCard, SkHeader, SkRows, SkSummary } from "@/components/ui/skeleton-kit";


// Firewall: protected banner, quick add tiles, the rules list.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <SkSummary tone="success" />
      <SkCard>
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="flex gap-3 rounded-xl border p-3">
              <Skeleton className="size-7 rounded-lg" />
              <div className="space-y-2">
                <Skeleton className="h-3.5 w-20" />
                <Skeleton className="h-3 w-36" />
              </div>
            </div>
          ))}
        </div>
      </SkCard>
      <SkCard action>
        <div className="flex gap-2">
          <Skeleton className="h-9 w-56" />
          <Skeleton className="h-9 w-32" />
          <Skeleton className="h-9 w-32" />
          <Skeleton className="h-9 w-32" />
        </div>
        <div className="mt-4">
          <SkRows count={5} icon={false} trailing="switch" />
        </div>
      </SkCard>
    </div>
  );
}
