import { Skeleton } from "@/components/ui/skeleton";

/** Mirrors `ClonePanel`: a 7/5 two-column grid with a full-width band below. */
export default function Loading() {
  return (
    <div className="space-y-6">
      <div className="space-y-2">
        <Skeleton className="h-8 w-24" />
        <Skeleton className="h-7 w-40" />
        <Skeleton className="h-4 w-80" />
      </div>
      <div className="grid gap-6 lg:grid-cols-12">
        <Skeleton className="h-[22.5rem] rounded-xl lg:col-span-7" />
        <Skeleton className="h-[22.5rem] rounded-xl lg:col-span-5" />
      </div>
      <Skeleton className="h-[8.5rem] w-full rounded-xl" />
    </div>
  );
}
