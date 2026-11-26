import { SkHeader } from "@/components/ui/skeleton-kit";
import { ListCardSkeleton } from "@/components/docker/list-card-skeleton";

export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <ListCardSkeleton rows={2} test />
    </div>
  );
}
