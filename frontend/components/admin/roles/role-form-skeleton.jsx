import { Skeleton } from "@/components/ui/skeleton";

/**
 * The role form's loading shape, shared by "new" and "edit" because the form
 * is the same one — a name, a description, then the permission matrix, which is
 * most of the page and most of the wait.
 *
 * Its own component rather than a copy in each `loading.jsx`: the two would
 * drift the first time a field is added, and a skeleton that no longer matches
 * the form is worse than none — it moves the page twice instead of once.
 */
export function RoleFormSkeleton() {
  return (
    <div className="space-y-6" aria-busy="true">
      <div className="space-y-2">
        <Skeleton className="h-7 w-36" />
        <Skeleton className="h-4 w-80" />
      </div>

      <div className="max-w-[48rem] space-y-4">
        <div className="space-y-2">
          <Skeleton className="h-4 w-20" />
          <Skeleton className="h-9 w-full" />
        </div>
        <div className="space-y-2">
          <Skeleton className="h-4 w-24" />
          <Skeleton className="h-9 w-full" />
        </div>
      </div>

      {/* The permission matrix: a header row and one row per feature. */}
      <div className="space-y-2 rounded-xl border p-4">
        <Skeleton className="h-4 w-40" />
        {Array.from({ length: 8 }).map((_, row) => (
          <div key={row} className="flex items-center gap-4 py-2">
            <Skeleton className="h-4 min-w-0 flex-1" />
            <Skeleton className="size-4 shrink-0 rounded" />
            <Skeleton className="size-4 shrink-0 rounded" />
          </div>
        ))}
      </div>

      <div className="flex justify-end">
        <Skeleton className="h-9 w-28" />
      </div>
    </div>
  );
}
