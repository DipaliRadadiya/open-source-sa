import { Skeleton } from "@/components/ui/skeleton";

// Sized to the not-yet-set-up state, which most sites are in.
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
