import { Skeleton } from "@/components/ui/skeleton";

// Mirrors the real layout — back link, name, facts line, connection card, tab
// strip, section, delete card — so the page fills in place instead of jumping
// when the data lands. Keep this in step with page.jsx: every card added there
// and not here is a jump.
export default function Loading() {
  return (
    <div className="space-y-6">
      <div className="space-y-3">
        <Skeleton className="h-7 w-32" />
        <div className="space-y-2">
          <Skeleton className="h-8 w-48" />
          <Skeleton className="h-4 w-64" />
        </div>
      </div>

      <div className="max-w-4xl space-y-4">
        <div className="rounded-xl border">
          <div className="flex items-center justify-between border-b px-5 py-3.5">
            <div className="space-y-1.5">
              <Skeleton className="h-5 w-40" />
              <Skeleton className="h-4 w-56" />
            </div>
            <Skeleton className="h-7 w-56" />
          </div>
          <div className="grid grid-cols-2 gap-x-4 gap-y-3 px-5 py-4 sm:grid-cols-3 lg:grid-cols-5">
            {Array.from({ length: 5 }).map((_, i) => (
              <div key={i} className="space-y-1.5">
                <Skeleton className="h-3 w-14" />
                <Skeleton className="h-4 w-20" />
              </div>
            ))}
          </div>
        </div>

        <Skeleton className="h-9 w-72 rounded-lg" />

        <div className="rounded-xl border">
          <div className="flex items-center justify-between border-b px-5 py-3.5">
            <div className="space-y-1.5">
              <Skeleton className="h-5 w-20" />
              <Skeleton className="h-4 w-56" />
            </div>
            <Skeleton className="h-9 w-28" />
          </div>
          <div className="space-y-4 px-5 py-4">
            {Array.from({ length: 2 }).map((_, i) => (
              <div key={i} className="space-y-2">
                <Skeleton className="h-4 w-40" />
                <Skeleton className="h-3 w-72" />
              </div>
            ))}
          </div>
        </div>

        <div className="flex items-center justify-between rounded-xl border px-5 py-4">
          <div className="space-y-1.5">
            <Skeleton className="h-5 w-40" />
            <Skeleton className="h-4 w-64" />
          </div>
          <Skeleton className="h-9 w-24" />
        </div>
      </div>
    </div>
  );
}
