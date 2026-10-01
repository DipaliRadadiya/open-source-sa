import { Skeleton } from "@/components/ui/skeleton";

/**
 * Mirrors the Backups section shell: title, tabs, and the coverage card.
 * The restore banner is not reserved: it appears only while a restore runs.
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
