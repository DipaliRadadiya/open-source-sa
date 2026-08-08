import { Skeleton } from "@/components/ui/skeleton";

/**
 * Mirrors `StagingPanel`, capped at `max-w-4xl` like the panel.
 *
 * 153px is the card for a site that already has a copy — the shorter of the
 * two states, so the placeholder never leaves a gap larger than the content
 * that replaces it.
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
