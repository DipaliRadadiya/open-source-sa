import { Skeleton } from "@/components/ui/skeleton";

/**
 * Mirrors `BotBlockerSection`: the policy card and the traffic card beneath
 * it, both capped at `max-w-4xl` like the panel.
 *
 * Sized from the rendered page — 878px and 233px — rather than guessed. The
 * previous single `h-72` block was both the wrong count and the wrong width.
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
