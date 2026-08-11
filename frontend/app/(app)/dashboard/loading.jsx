import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";

export default function DashboardLoading() {
  return (
    <div className="space-y-6">
      <div className="space-y-2">
        <Skeleton className="h-8 w-40" />
        <Skeleton className="h-4 w-72" />
      </div>

      {/* Server info band. */}
      <Skeleton className="h-36 rounded-xl" />

      <Skeleton className="h-6 w-24 rounded-full" />

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        {Array.from({ length: 5 }).map((_, i) => (
          <Card key={i} className="gap-0 py-0">
            <CardContent className="space-y-3 px-4 py-3.5">
              <div className="flex items-center gap-2">
                <Skeleton className="size-7 rounded-md" />
                <Skeleton className="h-4 w-20" />
              </div>
              <Skeleton className="h-6 w-24" />
              <Skeleton className="h-1.5 w-full rounded-full" />
              <Skeleton className="h-3 w-28" />
            </CardContent>
          </Card>
        ))}
      </div>

      {/* Two chart rows: load + usage, then network + disk I/O. */}
      {Array.from({ length: 2 }).map((_, row) => (
        <div key={row} className="grid gap-4 lg:grid-cols-2">
          <Skeleton className="h-[26rem] rounded-xl" />
          <Skeleton className="h-[26rem] rounded-xl" />
        </div>
      ))}

      <Skeleton className="h-72 rounded-xl" />
    </div>
  );
}
