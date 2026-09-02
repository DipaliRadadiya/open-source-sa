import { Card, CardContent, CardHeader } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";

/**
 * Stands in for one of the four dashboard charts while Recharts loads.
 *
 * The charting library is around 400 KB and the dashboard is where login
 * lands, so the four charts are dynamically imported and this holds their
 * place. It mirrors `LiveChartCard` exactly — same header shape, same `h-72`
 * plot area — because a placeholder of a different height moves the stat cards
 * above it when the real chart arrives, and a jumping page reads as a broken
 * one.
 */
export function ChartCardSkeleton() {
  return (
    <Card className="h-full">
      <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-x-4 gap-y-2 space-y-0">
        <div className="min-w-48 flex-1 space-y-2">
          <Skeleton className="h-5 w-40" />
          <Skeleton className="h-4 w-56" />
        </div>
        <Skeleton className="h-6 w-24 shrink-0" />
      </CardHeader>
      <CardContent className="pt-3">
        <Skeleton className="h-72 w-full" />
      </CardContent>
    </Card>
  );
}
