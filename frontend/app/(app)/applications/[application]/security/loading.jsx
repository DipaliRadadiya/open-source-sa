import { Skeleton } from "@/components/ui/skeleton";
import { SkCard, SkFooter, SkHeader, SkNote } from "@/components/ui/skeleton-kit";


// Password protection: the switch, the note, Save.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <div className="">
        <SkCard head={false}>
          <div className="flex items-center gap-3 rounded-xl border p-4">
            <Skeleton className="size-8 rounded-lg" />
            <div className="flex-1 space-y-2">
              <Skeleton className="h-3.5 w-40" />
              <Skeleton className="h-3 w-64" />
            </div>
            <Skeleton className="h-5 w-9 rounded-full" />
          </div>
          <SkNote className="mt-4" />
          <SkFooter />
        </SkCard>
      </div>
    </div>
  );
}
