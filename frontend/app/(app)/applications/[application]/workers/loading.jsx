import { Skeleton } from "@/components/ui/skeleton";

export default function Loading() {
  return (
    <div className="space-y-6">
      <div className="space-y-2">
        <Skeleton className="h-8 w-40" />
        <Skeleton className="h-7 w-36" />
        <Skeleton className="h-4 w-80" />
      </div>
      <Skeleton className="h-10 w-32 self-end" />
      <Skeleton className="h-64 w-full rounded-xl" />
    </div>
  );
}
