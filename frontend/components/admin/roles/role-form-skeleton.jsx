import { Skeleton } from "@/components/ui/skeleton";
import { SkCard, SkForm, SkHeader } from "@/components/ui/skeleton-kit";

// Shared by "new" and "edit" so the two `loading.jsx` files cannot drift: the details
// card, then the permissions card with its View / Manage columns.
export function RoleFormSkeleton() {
  return (
    <div className="space-y-6" aria-busy="true">
      <SkHeader />
      <div className="space-y-4">
        <SkCard>
          <SkForm fields={2} />
        </SkCard>
        <SkCard>
          <div className="-mx-5 -mb-5 divide-y border-t">
            {Array.from({ length: 8 }).map((_, row) => (
              <div key={row} className="flex items-center gap-4 px-5 py-3">
                <div className="min-w-0 flex-1 space-y-2">
                  <Skeleton className="h-3.5 w-32" />
                  <Skeleton className="h-3 w-56" />
                </div>
                <Skeleton className="size-4 shrink-0 rounded" />
                <Skeleton className="size-4 shrink-0 rounded" />
              </div>
            ))}
          </div>
        </SkCard>
      </div>
    </div>
  );
}
