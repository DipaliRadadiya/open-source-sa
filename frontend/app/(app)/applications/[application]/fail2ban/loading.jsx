import { Skeleton } from "@/components/ui/skeleton";

/**
 * Mirrors `Fail2banPanel`, capped at `max-w-4xl` like the panel.
 *
 * Sized to the not-yet-set-up state (306px), which is what most sites are in:
 * a site that HAS a jail shows a status card and a tall editor instead, and
 * there is no way to know which before the page loads. Guessing the taller
 * one would leave a hole under every unconfigured site.
 */
export default function Loading() {
  return (
    <div className="space-y-6">
      <div className="space-y-2">
        <Skeleton className="h-8 w-24" />
        <Skeleton className="h-7 w-48" />
        <Skeleton className="h-4 w-80" />
      </div>
      <Skeleton className="h-[19rem] w-full max-w-4xl rounded-2xl" />
    </div>
  );
}
