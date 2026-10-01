import { Skeleton } from "@/components/ui/skeleton";

/**
 * Mirrors `StagingPanel`, capped at `max-w-4xl`. 153px matches the shorter
 * of the two states, so the placeholder never exceeds the real content.
 */
export default function Loading() {
  return (
    <div className="space-y-6">
      <div className="space-y-2">
        <Skeleton className="h-8 w-24" />
        <Skeleton className="h-7 w-48" />
        <Skeleton className="h-4 w-80" />
      </div>
      <Skeleton className="h-[9.5rem] w-full max-w-4xl rounded-2xl" />
    </div>
  );
}
