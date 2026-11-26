import { SkCard, SkFooter, SkForm, SkHeader, SkTabs } from "@/components/ui/skeleton-kit";

export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <SkTabs count={3} />
      <div className="">
        <SkCard>
          <SkForm fields={2} />
          <SkFooter />
        </SkCard>
      </div>
    </div>
  );
}
