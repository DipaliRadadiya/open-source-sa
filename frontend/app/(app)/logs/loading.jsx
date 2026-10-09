import { Skeleton } from "@/components/ui/skeleton";
import { SkCard, SkHeader, SkTerminal } from "@/components/ui/skeleton-kit";


// System logs: the list of logs beside the open log.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <div className="grid gap-4 lg:grid-cols-[16rem_minmax(0,1fr)]">
        <SkCard head={false}>
          <div className="space-y-4">
            {Array.from({ length: 4 }).map((_, g) => (
              <div key={g} className="space-y-2">
                <Skeleton className="h-3 w-16" />
                <Skeleton className="h-8 w-full rounded-lg" />
                <Skeleton className="h-8 w-full rounded-lg" />
              </div>
            ))}
          </div>
        </SkCard>
        <SkCard>
          <div className="flex gap-2">
            <Skeleton className="h-9 w-56" />
            <Skeleton className="h-9 w-40" />
          </div>
          <SkTerminal lines={14} className="mt-4" />
        </SkCard>
      </div>
    </div>
  );
}
