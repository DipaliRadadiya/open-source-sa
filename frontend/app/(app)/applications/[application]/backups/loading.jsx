import { Skeleton } from "@/components/ui/skeleton";

/**
 * Mirrors `BackupsPanel`.
 *
 * There was no `loading.jsx` here at all, so this route fell through to the
 * one at `applications/[application]/`, which draws the site DASHBOARD: a
 * two-column grid with a 20rem rail. Backups has no rail — it is two stacked
 * cards, full width — so the placeholder invented a column and then collapsed
 * it when the real page arrived.
 *
 * Heights are taken from the rendered cards (245px and 280px) rather than
 * guessed. The active-restore banner is deliberately absent: it appears only
 * while a restore is running, and a skeleton that shows it would promise a
 * state the page is usually not in.
 */
export default function Loading() {
  return (
    <div className="space-y-6" aria-busy="true">
      {/* PageHeader: back button, title, subtitle. */}
      <div className="space-y-3">
        <Skeleton className="h-8 w-36" />
        <div className="space-y-1">
          <Skeleton className="h-8 w-32" />
          <Skeleton className="h-4 w-96" />
        </div>
      </div>

      {/* Is this site protected, and what has been kept. */}
      <Skeleton className="h-[15.5rem] w-full rounded-xl" />
      <Skeleton className="h-[17.5rem] w-full rounded-xl" />
    </div>
  );
}
