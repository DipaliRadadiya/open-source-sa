import { Skeleton } from "@/components/ui/skeleton";

/**
 * Central is a stack of settings cards, so it borrows that shape rather than a
 * table's. Width is capped to match the panel's settings column — a full-bleed
 * skeleton would reflow the moment the real cards arrive.
 */
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <div className="space-y-2">
        <Skeleton className="h-7 w-36" />
        <Skeleton className="h-4 w-96" />
      </div>
      <div className="max-w-[48rem] space-y-4">
        <Skeleton className="h-44 w-full rounded-xl" />
        <Skeleton className="h-56 w-full rounded-xl" />
      </div>
    </div>
  );
}
