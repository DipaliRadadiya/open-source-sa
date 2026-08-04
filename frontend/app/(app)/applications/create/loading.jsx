import { Skeleton } from "@/components/ui/skeleton";

export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <div className="space-y-2"><Skeleton className="h-8 w-52" /><Skeleton className="h-4 w-96" /></div>
      <Skeleton className="h-96 w-full rounded-xl" />
    </div>
  );
}
