import { SkHeader } from "@/components/ui/skeleton-kit";
import { SettingsCardSkeleton } from "@/components/settings/settings-skeleton";

// Container: the Image card, the Network and limits card, then Volumes.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader wide />
      <SettingsCardSkeleton rows={2} />
      <SettingsCardSkeleton rows={4} />
      <SettingsCardSkeleton rows={1} action={false} />
    </div>
  );
}
