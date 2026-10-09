import { Skeleton } from "@/components/ui/skeleton";
import { SkCard, SkHeader } from "@/components/ui/skeleton-kit";


// Node.js: the version card.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <div className="">
        <SkCard action>
          <div className="flex gap-2">
            {Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-6 w-20 rounded-md" />)}
          </div>
        </SkCard>
      </div>
    </div>
  );
}
