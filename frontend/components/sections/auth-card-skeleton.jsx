import { Skeleton } from "@/components/ui/skeleton";

/**
 * The sign-in and sign-up card while their page resolves (both await the session
 * and branding), so the card slot is never empty on a cold start.
 * `fields` is the only difference: sign-in has two, sign-up four.
 */
export function AuthCardSkeleton({ fields = 2 }) {
  return (
    <div className="space-y-6 rounded-xl border bg-card p-6 shadow-sm" aria-busy="true">
      <div className="space-y-2">
        <Skeleton className="mx-auto size-10 rounded-lg" />
        <Skeleton className="mx-auto h-6 w-40" />
        <Skeleton className="mx-auto h-4 w-56" />
      </div>

      <div className="space-y-4">
        {Array.from({ length: fields }).map((_, i) => (
          <div key={i} className="space-y-2">
            <Skeleton className="h-4 w-24" />
            <Skeleton className="h-9 w-full" />
          </div>
        ))}
      </div>

      <Skeleton className="h-9 w-full" />
    </div>
  );
}
