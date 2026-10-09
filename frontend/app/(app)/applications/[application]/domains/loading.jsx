import { SkCard, SkHeader, SkRows, SkTabs } from "@/components/ui/skeleton-kit";


// Domains & SSL: the two tabs, then the domains card.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <SkTabs count={2} />
      <SkCard action>
        <SkRows count={2} trailing="button" />
      </SkCard>
    </div>
  );
}
