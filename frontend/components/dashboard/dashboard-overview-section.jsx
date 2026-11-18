import { Skeleton } from "@/components/ui/skeleton";
import { SkCard } from "@/components/ui/skeleton-kit";
import { DashboardOverview } from "@/components/dashboard/dashboard-overview";

// Streamed in after the rest of the dashboard: the Firewall tile reads `/firewall`,
// which takes about a second (18 Nov). `read` is the page's getDashboardOverview()
// promise, started beside its other reads.
export async function DashboardOverviewSection({ applications, read, canManageFirewall }) {
  const overview = await read;
  if (!overview) return null;
  return <DashboardOverview applications={applications} overview={overview} canManageFirewall={canManageFirewall} />;
}

// The strip's own shape, so nothing moves when it arrives. Also the page's loading state.
export function DashboardOverviewSkeleton() {
  return (
    <SkCard>
      <div className="grid gap-3 sm:grid-cols-3 xl:grid-cols-5">
        {Array.from({ length: 5 }).map((_, i) => (
          <div key={i} className="flex items-center gap-3 rounded-xl border p-3">
            <Skeleton className="size-11 rounded-full" />
            <div className="space-y-2">
              <Skeleton className="h-3.5 w-16" />
              <Skeleton className="h-3 w-20" />
            </div>
          </div>
        ))}
      </div>
    </SkCard>
  );
}
