import { SkHeader, SkSummary } from "@/components/ui/skeleton-kit";


// Panel update: the version card.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader wide />
      <SkSummary tone="success" />
    </div>
  );
}
