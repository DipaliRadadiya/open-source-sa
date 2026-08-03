import { Skeleton } from "@/components/ui/skeleton";

// Mirrors the real layout — back link, heading, four stat cards, chart, process
// list — so the page fills in place rather than jumping.
export default function Loading() {
  return (
    <div className="space-y-6">
      <div className="space-y-3">
        <Skeleton className="h-7 w-32" />
        <Skeleton className="h-8 w-56" />
      </div>

      <div className="max-w-5xl space-y-4">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-[72px] rounded-xl" />
          ))}
        </div>
        <Skeleton className="h-80 rounded-xl" />
        <Skeleton className="h-56 rounded-xl" />
      </div>
    </div>
  );
}
