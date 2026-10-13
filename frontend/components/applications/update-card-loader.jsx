import { getApplicationUpdate } from "@/lib/applications/get-applications";
import { UpdateCard } from "@/components/applications/update-card";
import { SkCard } from "@/components/ui/skeleton-kit";
import { Skeleton } from "@/components/ui/skeleton";

// Streamed on its own: a first visit can wait on a release lookup, and the rest of the
// dashboard must not wait with it.
export async function UpdateCardLoader({ appId, appTitle, canManage, backupsHref }) {
  const result = await getApplicationUpdate(appId);
  // Unsupported types have no card. A failed read hides it too: the card is an offer,
  // and a broken offer helps nobody; the version facts live in the files, not here.
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
