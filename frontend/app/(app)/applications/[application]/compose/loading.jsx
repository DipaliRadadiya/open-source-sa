import { Skeleton } from "@/components/ui/skeleton";
import { SkHeader } from "@/components/ui/skeleton-kit";

// Compose file: the file card — name and Copy, the three rules, the editor with its
// line numbers, and Save in the footer.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader wide />
      <div className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-e1" aria-hidden>
        <div className="flex items-center justify-between gap-4 border-b px-5 py-4">
          <div className="flex items-center gap-2">
            <Skeleton className="size-4" />
            <Skeleton className="h-4 w-28" />
            <Skeleton className="h-3 w-14" />
          </div>
          <Skeleton className="h-8 w-20 rounded-lg" />
        </div>
        <div className="space-y-4 p-5">
          <div className="flex flex-wrap gap-5">
            <Skeleton className="h-3 w-44" />
            <Skeleton className="h-3 w-40" />
            <Skeleton className="h-3 w-56" />
          </div>
          <div className="flex overflow-hidden rounded-lg border">
            <div className="space-y-[7.5px] border-r bg-muted/50 px-3 py-3">
              {Array.from({ length: 14 }).map((_, i) => (
                <Skeleton key={i} className="h-3 w-4" />
              ))}
            </div>
            <div className="flex-1 space-y-[7.5px] px-3 py-3">
              {[24, 0, 20, 12, 40, 32, 0, 18, 44, 0, 22, 52, 0, 28].map((w, i) => (
                <Skeleton key={i} className="h-3" style={{ width: w ? `${w}%` : 0, opacity: w ? 1 : 0 }} />
              ))}
            </div>
          </div>
        </div>
        <div className="flex flex-wrap items-center justify-between gap-3 border-t bg-muted/30 px-5 py-4">
          <Skeleton className="h-3 w-96 max-w-full" />
          <div className="flex gap-2">
            <Skeleton className="h-8 w-20 rounded-lg" />
            <Skeleton className="h-8 w-28 rounded-lg" />
          </div>
        </div>
      </div>
    </div>
  );
}
