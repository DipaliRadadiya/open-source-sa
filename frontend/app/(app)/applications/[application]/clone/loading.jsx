import { Skeleton } from "@/components/ui/skeleton";

/**
 * Mirrors `ClonePanel`.
 *
 * There was no `loading.jsx` here, so this route fell through to the one at
 * `applications/[application]/` — the site dashboard, a two-column grid with
 * a 20rem rail. Clone is also two columns, but a 12-column split (7 / 5) with
 * a full-width band underneath, so the inherited placeholder was the wrong
 * shape in both halves.
 *
 * Sized from the rendered page: 360px for each column, 137px for the band.
 */
export default function Loading() {
  return (
    <div className="space-y-6">
      <div className="space-y-2">
        <Skeleton className="h-8 w-24" />
        <Skeleton className="h-7 w-40" />
        <Skeleton className="h-4 w-80" />
      </div>
      <div className="grid gap-6 lg:grid-cols-12">
        <Skeleton className="h-[22.5rem] rounded-xl lg:col-span-7" />
        <Skeleton className="h-[22.5rem] rounded-xl lg:col-span-5" />
      </div>
      <Skeleton className="h-[8.5rem] w-full rounded-xl" />
    </div>
  );
}
