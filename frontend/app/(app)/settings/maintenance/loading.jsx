import { SettingsCardSkeleton } from "@/components/settings/settings-skeleton";

// The layout (title, tabs) persists across tab navigations, so only the card
// area needs a placeholder.
export default function Loading() {
  return (
    <div className="space-y-4">
      <SettingsCardSkeleton rows={2} />
      <SettingsCardSkeleton rows={2} />
      <SettingsCardSkeleton rows={1} />
    </div>
  );
}
