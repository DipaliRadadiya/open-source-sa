import { Skeleton } from "@/components/ui/skeleton";

/**
 * Mirrors `SecuritySection`: one card, capped at `max-w-4xl` like the panel.
 *
 * The cap is the point. Without it the placeholder filled the content column
 * (1120px) and the real card arrived 224px narrower, so the page visibly
 * snapped inwards on load. Height is the rendered card's own (464px).
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
