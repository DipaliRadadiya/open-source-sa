import { Skeleton } from "@/components/ui/skeleton";
import { SkCard, SkFooter, SkForm, SkHeader, SkTabs } from "@/components/ui/skeleton-kit";


// PHP settings: the facts strip, then the tabbed form.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <div className="space-y-4">
        <div className="flex flex-wrap gap-4 rounded-xl border bg-muted/30 px-4 py-3">
          {Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-3.5 w-24" />)}
        </div>
        <SkCard head={false}>
          <SkTabs count={3} />
          <div className="mt-5">
            <SkForm fields={4} />
          </div>
          <SkFooter />
        </SkCard>
      </div>
    </div>
  );
}
