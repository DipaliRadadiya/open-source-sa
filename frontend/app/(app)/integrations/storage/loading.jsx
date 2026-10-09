import { SkCard, SkHeader, SkRows } from "@/components/ui/skeleton-kit";


// Storage: the destinations card.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader wide />
      <div className="">
        <SkCard action>
          <SkRows count={2} trailing="button" />
        </SkCard>
      </div>
    </div>
  );
}
