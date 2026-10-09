import { SkHeader, SkTabs } from "@/components/ui/skeleton-kit";
import { SettingsCardSkeleton } from "@/components/settings/settings-skeleton";

// Settings: the tab strip, then a settings card.
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader wide />
      <SkTabs count={4} />
      <SettingsCardSkeleton rows={3} />
    </div>
  );
}
