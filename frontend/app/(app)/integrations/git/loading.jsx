import { Skeleton } from "@/components/ui/skeleton";

// Mirrors the real layout — title, subtitle, one card with a header row and
// two account rows — so the page fills in place instead of jumping. Keep in
// step with page.jsx: a card added there and not here is a jump.
export default function Loading() {
  return (
    <div className="space-y-6">
      <div className="space-y-2">
        <Skeleton className="h-8 w-32" />
        <Skeleton className="h-4 w-80" />
      </div>

      <div className="max-w-4xl">
        <div className="rounded-xl border">
          <div className="flex items-center justify-between border-b px-5 py-3.5">
            <div className="space-y-1.5">
              <Skeleton className="h-5 w-40" />
              <Skeleton className="h-4 w-56" />
            </div>
            <Skeleton className="h-9 w-40" />
          </div>
          <div className="divide-y px-5">
            {Array.from({ length: 2 }).map((_, i) => (
              <div key={i} className="flex items-start gap-3 py-3.5">
                <Skeleton className="size-8 rounded-md" />
                <div className="space-y-2">
                  <Skeleton className="h-4 w-56" />
                  <Skeleton className="h-4 w-40" />
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}
