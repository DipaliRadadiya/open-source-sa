import { Skeleton } from "@/components/ui/skeleton";
import { SettingsCardSkeleton } from "@/components/settings/settings-skeleton";

export default function Loading() {
  return (
    <div className="space-y-6">
      <div className="space-y-2">
        <Skeleton className="h-7 w-32" />
        <Skeleton className="h-4 w-96" />
      </div>

      {/* The tab strip is part of the layout, which awaits the same settings
          call for its badges — so on a cold load it is pending too. */}
      <Skeleton className="h-10 w-full max-w-lg rounded-lg" />

      <div className="max-w-[48rem]">
        <SettingsCardSkeleton rows={3} />
      </div>
    </div>
  );
}
