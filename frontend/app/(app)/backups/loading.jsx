import { Skeleton } from "@/components/ui/skeleton";

/**
 * Mirrors the Backups section shell: title, tabs, and the coverage card.
 *
 * There was none here, so `/backups` fell through to the `(app)` skeleton
 * while a force-dynamic page waited on two API calls — and since the layout
 * itself fetches restores before rendering anything, that wait is the whole
 * page, not just its contents.
 *
 * The tab strip is drawn because it is layout, not data: it is identical
 * whichever tab you land on, so showing it is honest. The restore banner is
 * not, for the opposite reason — it appears only while a restore is running,
 * and a skeleton that reserves space for it would promise a state the page is
 * almost never in.
 */
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      <div className="space-y-1">
        <Skeleton className="h-8 w-32" />
        <Skeleton className="h-4 w-80" />
      </div>

      {/* Overview / History / Restores. */}
      <Skeleton className="h-11 w-[22rem] rounded-lg" />

      <div className="space-y-4">
        {/* The two banners the overview leads with. */}
        <Skeleton className="h-20 w-full rounded-xl" />
        {/* Filters. */}
        <Skeleton className="h-10 w-full rounded-lg" />
        {/* The coverage table. */}
        <Skeleton className="h-[26rem] w-full rounded-xl" />
      </div>
    </div>
  );
}
