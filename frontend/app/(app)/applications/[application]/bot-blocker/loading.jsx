import { SkCard, SkFooter, SkHeader, SkNote, SkOptions, SkRows } from "@/components/ui/skeleton-kit";


// AI bot blocker: the note, the four choices, then the visits card.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <div className="space-y-6">
        <SkNote />
        <SkCard>
          <SkOptions count={4} />
          <SkFooter />
        </SkCard>
        <SkCard>
          <SkRows count={3} icon={false} />
        </SkCard>
      </div>
    </div>
  );
}
