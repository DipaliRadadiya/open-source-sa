import { SkHeader } from "@/components/ui/skeleton-kit";
import { ListCardSkeleton } from "@/components/docker/list-card-skeleton";

// Docker: the Networks card, then the Volumes card.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <ListCardSkeleton rows={4} />
      <ListCardSkeleton rows={3} columns={2} />
    </div>
  );
}
