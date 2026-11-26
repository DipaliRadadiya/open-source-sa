import { getApplicationUpdate } from "@/lib/applications/get-applications";
import { UpdateCard } from "@/components/applications/update-card";
import { SkCard } from "@/components/ui/skeleton-kit";
import { Skeleton } from "@/components/ui/skeleton";

export async function UpdateCardLoader({ appId, appTitle, canManage, backupsHref }) {
  const result = await getApplicationUpdate(appId);
  if (result.failed || !result.data?.update?.supported) return null;

  return (
    <UpdateCard
      appId={appId}
      appTitle={appTitle}
      initial={result.data}
      canManage={canManage}
      backupsHref={backupsHref}
    />
  );
}

export function UpdateCardSkeleton() {
  return (
    <SkCard action>
      <div className="grid gap-4 sm:grid-cols-3">
        {[0, 1, 2].map((i) => (
          <div key={i} className="space-y-2">
            <Skeleton className="h-3 w-20" />
            <Skeleton className="h-4 w-28" />
          </div>
        ))}
      </div>
    </SkCard>
  );
}
