import { SkHeader } from "@/components/ui/skeleton-kit";
import { VersionsCardSkeleton } from "@/components/runtime/versions-skeleton";

export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <VersionsCardSkeleton rows={3} columns={5} />
    </div>
  );
}
