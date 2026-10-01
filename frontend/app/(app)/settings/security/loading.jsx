import { SettingsCardSkeleton } from "@/components/settings/settings-skeleton";

// The layout (title, tabs) persists across tab navigations; only the card area loads.
export default function Loading() {
  return <SettingsCardSkeleton rows={3} />;
}
