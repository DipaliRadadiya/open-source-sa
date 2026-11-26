import { Skeleton } from "@/components/ui/skeleton";
import { SkCard, SkChart, SkFacts, SkNote } from "@/components/ui/skeleton-kit";
import { DashboardOverviewSkeleton } from "@/components/dashboard/dashboard-overview-section";

export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <div className="h-36 rounded-2xl border border-primary/15 bg-primary/5 p-6">
        <Skeleton className="h-3 w-40" />
        <Skeleton className="mt-3 h-6 w-72" />
        <Skeleton className="mt-3 h-3 w-96 max-w-full" />
      </div>
      <div className="grid gap-2 lg:grid-cols-2">
        <SkNote tone="destructive" className="bg-card" />
        <SkNote tone="warning" className="bg-card" />
      </div>
      <DashboardOverviewSkeleton />
      <SkCard>
        <SkFacts count={8} />
      </SkCard>
      <div className="grid gap-4 lg:grid-cols-2">
        <SkCard><SkChart /></SkCard>
        <SkCard><SkChart /></SkCard>
      </div>
    </div>
  );
}
