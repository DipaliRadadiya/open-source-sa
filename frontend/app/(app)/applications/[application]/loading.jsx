import { Skeleton } from "@/components/ui/skeleton";

/**
 * A skeleton is a promise about the shape of what is coming, so it has to
 * describe the CURRENT layout. This one still drew the old 1fr + 20rem rail:
 * the left column came in at 792px, then snapped to 556px the moment the real
 * cards arrived — a 236px jump on every single load, plus a downward shift for
 * the attention strip it never reserved.
 *
 * Two equal columns, two rows, with the strip's line above them. Heights are
 * per-row rather than per-card because the real grid stretches each row to its
 * tallest card.
 */
export default function Loading() {
  return (
    <div className="space-y-4" aria-busy="true">
      <div className="space-y-2">
        <Skeleton className="h-8 w-56" />
        <Skeleton className="h-4 w-72" />
      </div>

      {/* The attention strip. Reserving its line is the difference between the
          cards arriving in place and the cards being shoved down. */}
      <div className="pb-2">
        <Skeleton className="h-16 rounded-xl" />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Skeleton className="h-72 rounded-xl" />
        <Skeleton className="h-72 rounded-xl" />
        <Skeleton className="h-56 rounded-xl" />
        <Skeleton className="h-56 rounded-xl" />
      </div>
    </div>
  );
}
