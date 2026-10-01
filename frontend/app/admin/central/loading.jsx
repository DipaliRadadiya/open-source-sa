import { Skeleton } from "@/components/ui/skeleton";

/** Settings-card skeleton, width-capped like the real cards to avoid reflow. */
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
