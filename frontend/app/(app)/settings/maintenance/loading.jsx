import { SettingsCardSkeleton } from "@/components/settings/settings-skeleton";

// Shown when switching tabs: the layout (title, tabs) is preserved across
// navigations within this segment, so only the card area needs to stand in.
export default function Loading() {
  return (
    <div className="space-y-4">
      <SettingsCardSkeleton rows={2} />
      <SettingsCardSkeleton rows={2} />
      <SettingsCardSkeleton rows={1} />
    </div>
  );
}
