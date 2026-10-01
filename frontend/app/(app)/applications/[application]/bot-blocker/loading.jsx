import { Skeleton } from "@/components/ui/skeleton";

/**
 * Mirrors `BotBlockerSection`: the policy card and the traffic card beneath it,
 * both capped at `max-w-4xl`; heights measured from the rendered page.
 */
export default function Loading() {
  return (
    <div className="space-y-6">
      <div className="space-y-2">
        <Skeleton className="h-8 w-24" />
        <Skeleton className="h-7 w-48" />
        <Skeleton className="h-4 w-80" />
      </div>
      <div className="max-w-4xl space-y-4">
        <Skeleton className="h-[55rem] w-full rounded-2xl" />
        <Skeleton className="h-[14.5rem] w-full rounded-2xl" />
      </div>
    </div>
  );
}
