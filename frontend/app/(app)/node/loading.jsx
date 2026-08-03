import { Skeleton } from "@/components/ui/skeleton";

// Same shape as the real page: heading, one version card with a footer of
// buttons, and the system-Node note underneath.
export default function Loading() {
  return (
    <div className="space-y-6">
      <div className="space-y-2">
        <Skeleton className="h-7 w-28" />
        <Skeleton className="h-4 w-72" />
      </div>

      <div className="max-w-5xl space-y-4">
        <div className="rounded-xl border">
          <div className="space-y-2 p-6">
            <Skeleton className="h-5 w-56" />
            <Skeleton className="h-4 w-80" />
          </div>
          <div className="flex items-center justify-between border-t bg-muted/30 px-6 py-4">
            <Skeleton className="h-9 w-36" />
            <div className="flex gap-2">
              <Skeleton className="h-9 w-40" />
              <Skeleton className="h-9 w-32" />
            </div>
          </div>
        </div>

        <Skeleton className="h-12 w-full rounded-lg" />
      </div>
    </div>
  );
}
