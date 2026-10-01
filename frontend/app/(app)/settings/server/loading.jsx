import { SettingsCardSkeleton } from "@/components/settings/settings-skeleton";

// The layout (title, tabs) persists across tab switches; only the card area needs a skeleton.
export default function Loading() {
  return <SettingsCardSkeleton rows={3} />;
}
