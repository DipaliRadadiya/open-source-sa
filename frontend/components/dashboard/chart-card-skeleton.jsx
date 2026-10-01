import { Card, CardContent, CardHeader } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";

// Mirrors `LiveChartCard` (header and `h-72` plot) so the page does not jump while Recharts loads.
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
