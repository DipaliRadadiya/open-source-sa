import { Skeleton } from "@/components/ui/skeleton";
import { SkCard, SkHeader, SkNote } from "@/components/ui/skeleton-kit";


// Central: the connection card with its warning and button.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <SkCard>
        <SkNote tone="destructive" />
        <Skeleton className="mt-4 h-9 w-48 rounded-lg" />
      </SkCard>
    </div>
  );
}
