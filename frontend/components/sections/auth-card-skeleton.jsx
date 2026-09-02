import { Skeleton } from "@/components/ui/skeleton";

/**
 * The sign-in and sign-up card while their page resolves.
 *
 * Both await the session and the panel's branding before rendering, so on a
 * cold start the card slot sat empty inside a fully-drawn background — which
 * reads as the panel having no login form rather than as one arriving.
 *
 * `fields` is the only difference between the two screens, so it is the only
 * prop: sign-in asks for two, sign-up for four.
 */
export function AuthCardSkeleton({ fields = 2 }) {
  return (
    <div className="space-y-6 rounded-xl border bg-card p-6 shadow-sm" aria-busy="true">
      <div className="space-y-2">
        {/* The brand mark sits above the heading on both screens. */}
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
