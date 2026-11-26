import { SettingsCardSkeleton } from "@/components/settings/settings-skeleton";

export default function Loading() {
  return (
    <div className="space-y-4">
      <SettingsCardSkeleton rows={2} />
      <SettingsCardSkeleton rows={2} />
      <SettingsCardSkeleton rows={1} />
    </div>
  );
}
