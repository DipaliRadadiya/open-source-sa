import { SkCard, SkFooter, SkHeader, SkRows, SkTerminal } from "@/components/ui/skeleton-kit";


// Environment: the .env editor with Save, then the change history.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <SkCard head={false}>
        <SkTerminal lines={10} className="min-h-80" />
        <SkFooter />
      </SkCard>
      <SkCard>
        <SkRows count={2} trailing="button" />
      </SkCard>
    </div>
  );
}
