import { SkHeader, SkTabs } from "@/components/ui/skeleton-kit";
import { SettingsCardSkeleton } from "@/components/settings/settings-skeleton";

export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader wide />
      <SkTabs count={4} />
      <SettingsCardSkeleton rows={3} />
    </div>
  );
}
