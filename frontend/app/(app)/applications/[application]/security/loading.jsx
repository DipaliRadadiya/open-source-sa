import { Skeleton } from "@/components/ui/skeleton";

/**
 * Mirrors `SecuritySection`: one card capped at `max-w-4xl` (keep in step, or
 * the page snaps narrower on load). Height is the rendered card's (464px).
 */
export default function Loading() {
  return (
    <div className="space-y-6">
      <div className="space-y-2">
        <Skeleton className="h-8 w-24" />
        <Skeleton className="h-7 w-48" />
        <Skeleton className="h-4 w-80" />
      </div>
      <Skeleton className="h-[29rem] w-full max-w-4xl rounded-2xl" />
    </div>
  );
}
