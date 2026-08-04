import { Skeleton } from "@/components/ui/skeleton";

export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <div className="space-y-2"><Skeleton className="h-8 w-40" /><Skeleton className="h-4 w-80" /></div>
      <Skeleton className="h-9 w-full max-w-sm" />
      <Skeleton className="h-72 w-full rounded-xl" />
    </div>
  );
}
