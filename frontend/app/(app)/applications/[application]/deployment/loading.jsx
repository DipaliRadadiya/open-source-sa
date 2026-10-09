import { SkCard, SkFacts, SkHeader, SkNote, SkRows, SkTabs } from "@/components/ui/skeleton-kit";


// Deployment: the deploy card with its facts, the tabs, then the history.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <SkCard action>
        <SkNote tone="warning" />
        <SkFacts count={4} className="mt-4" />
      </SkCard>
      <SkTabs count={3} />
      <SkCard>
        <SkRows count={3} trailing="button" />
      </SkCard>
    </div>
  );
}
