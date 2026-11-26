import { SkCard, SkCentered, SkHeader } from "@/components/ui/skeleton-kit";

export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <div className="">
        <SkCard head={false}>
          <SkCentered />
        </SkCard>
      </div>
    </div>
  );
}
