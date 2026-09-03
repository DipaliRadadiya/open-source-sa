import { SettingsCardSkeleton } from "@/components/settings/settings-skeleton";

export default function Loading() {
  return (
    <div className="space-y-4">
      <SettingsCardSkeleton rows={3} />
      <SettingsCardSkeleton rows={4} />
    </div>
  );
}
