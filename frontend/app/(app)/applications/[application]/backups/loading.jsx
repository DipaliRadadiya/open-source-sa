import { SkCard, SkHeader, SkRows, SkSummary } from "@/components/ui/skeleton-kit";

// Backups: the protection status (a plain card since 7 Oct), then recent backups.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <SkSummary />
      <SkCard action>
        <SkRows count={3} icon={false} />
      </SkCard>
    </div>
  );
}
