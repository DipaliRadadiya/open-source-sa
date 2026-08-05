import { Skeleton } from "@/components/ui/skeleton";

export default function Loading() {
  return (
    <div className="space-y-6">
      <div className="space-y-2">
        <Skeleton className="h-8 w-40" />
        <Skeleton className="h-7 w-32" />
        <Skeleton className="h-4 w-80" />
      </div>
      <div className="grid gap-6 lg:grid-cols-[16.5rem_minmax(0,1fr)]">
        <Skeleton className="hidden h-[28rem] w-full rounded-xl lg:block" />
        <Skeleton className="h-[28rem] w-full rounded-xl" />
      </div>
    </div>
  );
}
