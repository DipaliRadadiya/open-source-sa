import { SkCard, SkCentered, SkHeader } from "@/components/ui/skeleton-kit";


// Git: the connected accounts card.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <div className="">
        <SkCard>
          <SkCentered />
        </SkCard>
      </div>
    </div>
  );
}
